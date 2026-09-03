<?php

namespace Plugin\LoyaltyPoints\Tests;

use App\Contracts\Notification\Notifier;
use App\Events\GdprCollecting;
use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Plugin\LoyaltyPoints\Backend\Listeners\ContributeGdprExport;
use Plugin\LoyaltyPoints\Backend\Listeners\ReversePointsOnRefundedOrder;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyMember;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltySetting;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyTier;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyTransaction;
use Plugin\LoyaltyPoints\Backend\Repositories\LoyaltyMemberRepository;
use Plugin\LoyaltyPoints\Backend\Repositories\LoyaltyTierRepository;
use Plugin\LoyaltyPoints\Backend\Services\PointsExpiry;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The screens an operator runs the programme from, and the obligations around them.
 *
 * Everything here failed quietly rather than loudly: a figure that counted the wrong people,
 * an expiry that could not be taken back, a partial refund nobody was told about, and a legal
 * export that omitted a customer's own record. None of it would have shown up as an error.
 */
class ProgrammeAdminTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        LoyaltySetting::current()->fill([
            'points_per_currency'    => 1,
            'earn_base'              => LoyaltySetting::BASE_GRAND_TOTAL,
            'redeem_value'           => 0.01,
            'minimum_redemption'     => 0,
            'expiry_months'          => null,
            'max_redemption_percent' => null,
        ])->save();
    }

    // ------------------------------------------------------------------
    // LP-07 / LP-08 — who counts as a customer
    // ------------------------------------------------------------------

    #[Test]
    public function the_unenrolled_figure_counts_customers_and_not_staff_or_deleted_accounts(): void
    {
        $before = app(LoyaltyMemberRepository::class)->pageData('overview')['unenrolled_customers'];

        $customer = User::factory()->create(['status' => 'active']);

        $staff = User::factory()->create(['status' => 'active']);
        $staff->assignRole(Role::firstOrCreate(['name' => 'loyalty_test_manager', 'guard_name' => 'web']));

        $inactive = User::factory()->create(['status' => 'inactive']);

        $deleted = User::factory()->create(['status' => 'active']);
        $deleted->delete();

        $after = app(LoyaltyMemberRepository::class)->pageData('overview')['unenrolled_customers'];

        $this->assertSame(
            $before + 1,
            $after,
            'Only the one real customer may be added: staff hold a role, one is deactivated, '
            . 'and the deleted account is behind a soft-delete scope the raw query builder ignored.'
        );
    }

    #[Test]
    public function the_enrolment_picker_offers_customers_only(): void
    {
        $customer = User::factory()->create(['status' => 'active']);

        $staff = User::factory()->create(['status' => 'active']);
        $staff->assignRole(Role::firstOrCreate(['name' => 'loyalty_test_manager', 'guard_name' => 'web']));

        $enrolled = User::factory()->create(['status' => 'active']);
        LoyaltyMember::create(['user_id' => $enrolled->id, 'status' => 'active', 'joined_at' => now()]);

        $options = app(LoyaltyMemberRepository::class)->getOptions(['user_id'])['user_id'];
        $ids     = collect($options)->pluck('value')->all();

        $this->assertContains($customer->id, $ids);
        $this->assertNotContains($staff->id, $ids, 'A staff account is not a customer to enrol.');
        $this->assertNotContains($enrolled->id, $ids, 'A customer already in the programme is not a candidate.');
    }

    #[Test]
    public function the_enrolment_form_declares_the_uniqueness_the_database_enforces(): void
    {
        // `user_id` is unique in the schema, and without the matching rule the second
        // enrolment reached the insert and surfaced the driver's own message as a 500 —
        // table and index name included. The hint above the field promised the rule; only
        // this makes the endpoint keep it.
        $form = json_decode(file_get_contents(
            dirname(__DIR__) . '/admin/modules/loyalty-members/form.json'
        ), true);

        $rules = $form['elements'][0]['elements'][0]['rules'];

        $this->assertSame('user_id', $form['elements'][0]['elements'][0]['key']);
        $this->assertContains('unique:loyalty_members,user_id', $rules);
    }

    // ------------------------------------------------------------------
    // LP-06 — a partial refund is reported
    // ------------------------------------------------------------------

    #[Test]
    public function a_partial_refund_tells_staff_rather_than_doing_nothing(): void
    {
        $user  = $this->customer();
        $order = $this->paidOrder($user, 300);

        $notifier = Mockery::mock(Notifier::class);
        $notifier->shouldReceive('toStaff')
            ->once()
            ->withArgs(fn ($type, $data) => $type === 'plugin:loyalty-points.partial_refund_review'
                && $data['order_number'] === $order->order_number
                && $data['earned'] === '300')
            ->andReturn(1);
        $this->app->instance(Notifier::class, $notifier);

        app(ReversePointsOnRefundedOrder::class)->onOrderStatusChanged(
            new OrderStatusChanged($order, Order::FIELD_PAYMENT, Order::PAYMENT_PAID, Order::PAYMENT_PARTIALLY_REFUNDED)
        );

        // Reported, not guessed at: the balance is deliberately untouched, because how much
        // to claw back depends on which lines went back.
        $this->assertSame(300, (int) $this->memberFor($user)->refresh()->balance);
    }

    // ------------------------------------------------------------------
    // LP-13 / LP-14 — expiry is bounded and reversible
    // ------------------------------------------------------------------

    #[Test]
    public function an_expiry_run_stamps_a_batch_that_can_be_given_back(): void
    {
        LoyaltySetting::current()->fill(['expiry_months' => 12])->save();

        $user   = $this->customer();
        $member = $this->enrolWithOldPoints($user, 400);

        $run = app(PointsExpiry::class)->run();

        $this->assertSame(400, $run['expired_points']);
        $this->assertNotNull($run['batch']);
        $this->assertFalse($run['more'], 'One member is nowhere near the batch ceiling.');
        $this->assertSame(0, (int) $member->refresh()->balance);

        $undo = app(PointsExpiry::class)->undo($run['batch']);

        $this->assertSame(400, $undo['restored_points']);
        $this->assertSame(400, (int) $member->refresh()->balance);

        // Standing is untouched throughout: expiry never cost it, so giving it back must not
        // grant it either. The mirror carries `reverses_id`, so it is not read as earning.
        $this->assertSame(400, (int) $member->lifetime_points);
    }

    #[Test]
    public function undoing_twice_gives_the_points_back_once(): void
    {
        LoyaltySetting::current()->fill(['expiry_months' => 12])->save();

        $user   = $this->customer();
        $member = $this->enrolWithOldPoints($user, 400);

        $batch = app(PointsExpiry::class)->run()['batch'];

        app(PointsExpiry::class)->undo($batch);
        $second = app(PointsExpiry::class)->undo($batch);

        $this->assertSame(0, $second['restored_points'], 'An already-reversed batch has nothing left to give back.');
        $this->assertSame(400, (int) $member->refresh()->balance);
    }

    #[Test]
    public function the_latest_batch_is_the_one_undo_reaches_for(): void
    {
        LoyaltySetting::current()->fill(['expiry_months' => 12])->save();

        $user = $this->customer();
        $this->enrolWithOldPoints($user, 400);

        $batch = app(PointsExpiry::class)->run()['batch'];

        $this->assertSame($batch, app(PointsExpiry::class)->latestBatch());

        app(PointsExpiry::class)->undo($batch);

        $this->assertNull(
            app(PointsExpiry::class)->latestBatch(),
            'A batch already given back is not offered again.'
        );
    }

    // ------------------------------------------------------------------
    // LP-15 — a tier edit only re-ranks who could have moved
    // ------------------------------------------------------------------

    #[Test]
    public function editing_a_threshold_re_ranks_the_members_it_affects(): void
    {
        $silver = LoyaltyTier::create(['title' => 'Silver', 'threshold' => 1000, 'status' => 'active']);

        $small = $this->enrolWithBalance($this->customer(), 100);
        $big   = $this->enrolWithBalance($this->customer(), 5000);

        $this->assertNull($small->refresh()->tier_id);
        $this->assertSame($silver->id, $big->refresh()->tier_id);

        // Lowered to 50: the small member now qualifies and must be re-ranked even though the
        // pass is bounded, because the bound runs from the lower of the two thresholds.
        app(LoyaltyTierRepository::class)->update($silver->id, ['title' => 'Silver', 'threshold' => 50, 'status' => 'active']);

        $this->assertSame($silver->id, $small->refresh()->tier_id);
        $this->assertSame($silver->id, $big->refresh()->tier_id);

        // Raised back above the small member: they must lose it again.
        app(LoyaltyTierRepository::class)->update($silver->id, ['title' => 'Silver', 'threshold' => 1000, 'status' => 'active']);

        $this->assertNull($small->refresh()->tier_id);
        $this->assertSame($silver->id, $big->refresh()->tier_id);
    }

    #[Test]
    public function deleting_a_tier_demotes_the_members_who_stood_in_it(): void
    {
        $gold = LoyaltyTier::create(['title' => 'Gold', 'threshold' => 1000, 'status' => 'active']);

        $member = $this->enrolWithBalance($this->customer(), 5000);
        $this->assertSame($gold->id, $member->refresh()->tier_id);

        app(LoyaltyTierRepository::class)->delete($gold->id);

        $this->assertNull($member->refresh()->tier_id);
    }

    // ------------------------------------------------------------------
    // LP-12 — the customer's record reaches their subject access response
    // ------------------------------------------------------------------

    #[Test]
    public function a_subject_access_export_includes_the_membership_and_the_whole_ledger(): void
    {
        $user   = $this->customer();
        $member = $this->enrolWithBalance($user, 250);

        $event = new GdprCollecting($user);
        app(ContributeGdprExport::class)->onGdprCollecting($event);

        $section = $event->sections()['plugin:loyalty-points'] ?? null;

        $this->assertNotNull($section, 'A member of the programme must appear in their own export.');
        $this->assertSame(250, $section['membership']['balance']);
        $this->assertSame(250, $section['membership']['lifetime_points']);
        $this->assertCount(1, $section['points_history'], 'The entries, not just the total — a balance alone cannot be checked.');
        $this->assertSame('Opening balance', $section['points_history'][0]['reason']);
        $this->assertNotEmpty($event->caveats(), 'The file must say what the section contains.');
    }

    #[Test]
    public function someone_who_never_joined_contributes_no_empty_section(): void
    {
        $user = $this->customer();

        $event = new GdprCollecting($user);
        app(ContributeGdprExport::class)->onGdprCollecting($event);

        $this->assertArrayNotHasKey(
            'plugin:loyalty-points',
            $event->sections(),
            'An empty section in a legal document invites the reader to wonder what was withheld.'
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function customer(): User
    {
        return User::factory()->create(['status' => 'active']);
    }

    private function memberFor(User $user): ?LoyaltyMember
    {
        return LoyaltyMember::where('user_id', $user->id)->first();
    }

    private function enrolWithBalance(User $user, int $points): LoyaltyMember
    {
        $member = LoyaltyMember::create(['user_id' => $user->id, 'status' => 'active', 'joined_at' => now()]);

        LoyaltyTransaction::create([
            'member_id' => $member->id,
            'points'    => $points,
            'type'      => LoyaltyTransaction::TYPE_EARN,
            'reason'    => 'Opening balance',
        ]);

        return $member->recalculate()->refresh();
    }

    /** Points old enough for the twelve-month window to reach them. */
    private function enrolWithOldPoints(User $user, int $points): LoyaltyMember
    {
        $member = $this->enrolWithBalance($user, $points);

        LoyaltyTransaction::where('member_id', $member->id)
            ->update(['created_at' => now()->subMonths(24)]);

        return $member->refresh();
    }

    private function paidOrder(User $user, float $total): Order
    {
        $order = Order::create([
            'order_number'   => 'LOY-' . uniqid(),
            'user_id'        => $user->id,
            'status'         => Order::STATUS_COMPLETED,
            'payment_status' => Order::PAYMENT_PAID,
            'grand_total'    => $total,
            'subtotal'       => $total,
            'currency'       => 'MYR',
            'meta'           => [],
        ]);

        app(\Plugin\LoyaltyPoints\Backend\Listeners\AwardPointsOnPaidOrder::class)->onOrderStatusChanged(
            new OrderStatusChanged($order, Order::FIELD_PAYMENT, 'pending', Order::PAYMENT_PAID)
        );

        return $order;
    }
}
