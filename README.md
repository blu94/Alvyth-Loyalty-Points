# Ovynt Loyalty Points

A loyalty programme for [Ovynt](https://github.com/blu94): a points ledger, per-customer
balances, tier standing and the rules that govern them.

It is also the **reference plugin** — the fullest worked example of what the Ovynt plugin
system can do, kept deliberately readable so you can copy it as a starting point. MIT
licensed for exactly that reason.

## Install

Download the release `.zip`, then in your Ovynt admin:

**Plugins → Install a plugin → drop the zip → Install plugin**, then switch it **Enabled**.

Enabling runs the migrations and creates the `loyalty.*` permissions. Three entries appear
in the sidebar: **Loyalty**, **Points Activity** and **Loyalty Tiers**.

Removing the plugin keeps its data by default, so reinstalling restores everything.

## What you get

| Screen | What it is for |
|---|---|
| **Loyalty → Overview** | Points issued, redeemed, expired, outstanding, and what the outstanding balance is worth |
| **Loyalty → Members** | Every enrolled customer: balance, lifetime points, tier |
| **Loyalty → Settings** | Earn rate, what points are earned on, point value, minimum redemption, redemption cap, expiry window |
| **Points Activity** | The ledger — every earn, redemption, adjustment and expiry |
| **Loyalty Tiers** | The tier ladder: a name and the lifetime points needed to reach it |

### How the numbers relate

The **ledger is the only source of truth**. A member's `balance` and `lifetime_points` are
recomputed from `SUM(points)` after every write — never incremented. An increment drifts the
first time an entry is edited, deleted or written twice by a retried request; a recompute
cannot disagree with the ledger, because it *is* the ledger.

Two totals, deliberately:

- **Balance** is what the customer can still spend. It falls when they redeem.
- **Lifetime points** counts earnings only and never falls. Tier standing is driven by
  lifetime, so redeeming a reward never demotes anyone — which is the whole point of a
  loyalty programme.

Entry types carry their own sign rule. Typing `100` against **Redeem** subtracts 100; nobody
types a minus sign for a redemption. **Adjust** keeps the sign you type, because correcting
in either direction is exactly what it is for.

**Entries are append-only.** An entry cannot be deleted, and editing one changes its reason
and nothing else — `points`, `type` and the member it belongs to are what a balance was
computed from, and rewriting them would leave every screen agreeing with a history that never
happened. Correct a mistake with an **Adjust** entry, which leaves both numbers on the record.
Every entry an operator writes also records **who wrote it**, and shows up in the Activity Log
alongside every other change made in the admin.

Editing a tier's threshold re-ranks the members it could have moved, so the standings on
screen always match the rules currently in force.

## The programme runs itself

Four listeners do the work; an operator only intervenes to correct something.

| When | What happens |
|---|---|
| An order becomes **paid** | The customer is enrolled if new, and an `earn` entry is written at your rate |
| A customer **spends points at checkout** | The order is priced with the reduction, and the `redeem` entry is written **on payment** |
| A paid order is **refunded**, **voided**, or **cancelled after payment** | Both entries are reversed with mirrored entries that point at what they undo |
| A paid order is **partly refunded** | Nothing is clawed back automatically — staff are notified so somebody can decide |

**Points are awarded on payment, not on placement.** An abandoned checkout or an unpaid
invoice would otherwise mint value you had to claw back.

**Points are deducted on payment too, not when the order is priced.** Redemption is quoted
while the customer is still deciding and may be followed by a gateway failure; debiting then
and refunding on failure would invent a reversal path for the common case rather than the rare
one. Until the order is paid, an abandoned checkout costs the customer nothing.

**The balance is checked again when the order is paid.** Quoting and debiting are separated by
however long a payment takes, and a balance can move in between — two orders placed before
either is paid, an expiry, an operator's adjustment. The debit is clamped to what the member
actually holds, so a balance can never go below zero, and a quote that could not be funded is
recorded on the order and raised with staff instead of being absorbed in silence.

### Spending points

The plugin renders a points box into the storefront's `checkout` slot — one number input
carrying `data-checkout-field="loyalty_points"`. The theme carries the value to the server and
`RedeemPointsAtCheckout` reads it back through `CheckoutAdjusting::field()`. The plugin ships
**no JavaScript**; a theme that renders the slot gets a working control.

It proposes an amount and core decides. Never more than the customer holds, never more than
the order is worth, never below your minimum, and never above your redemption cap — and when
the order is smaller than the points offered, only the points actually used are charged.
Asking to spend 999,999 points on a 5.00 order takes 5.00 off and costs 500 points, not the
balance.

Every clamp is applied to the **points**, in whole numbers, and the money is derived from the
result once. Pricing the reduction and then recovering the points from it means dividing one
binary float by another, and the round trip does not close: measured across 1,000 amounts, 125
came back a point short at a rate of 0.01 and 348 at 0.05. `redeem_value` is `decimal(8,4)`,
so the arithmetic is done in ten-thousandths of a currency unit and loses nothing.

A **suspended** member keeps their balance and stops spending it, checked in the listener and
not only in the UI. A guest sees no box.

### Why a refund cannot promote someone

Reversal entries carry `reverses_id`, a self-reference to the entry they undo. `lifetime_points`
sums earn and adjust entries that are *not* reversals, plus reversals whose target **was** an
earn — so reversing a redemption does not read as earning. A plain `SUM(points > 0)` was wrong
in both directions and let a refund raise someone into a higher tier. Tier standing must not
depend on a reason string, which is why this is a column and not prose matching.

### What points are earned on

**Settings → Points are earned on** chooses the base:

- **Merchandise total, after discounts** — the goods, net of every discount including the
  customer's own redemption. Excludes tax, which you collect and remit, and shipping, which
  you largely pass to a carrier. This is what most programmes use.
- **Everything the customer paid** — `grand_total`, tax and shipping included. The default,
  so that upgrading this package never silently changes what an existing programme costs.

**Most of one order points may cover (%)** caps a single redemption. Empty means uncapped,
which lets one order be paid for entirely in points.

### Expiry — your policy, applied when you press the button

`expiry_months` sets how long points last, in **Settings**. **Points Activity → Expire Old
Points** applies it: every member holding points older than the window gets an `expire` entry
and a recalculated balance.

The button lives on Points Activity rather than beside the setting, for two reasons. It writes
ledger rows, and that screen is the ledger — you press it and watch the entries appear. And a
custom page's renderer supports only a `save` action, with no confirmation step; the list
toolbar is where `"action": "request"` and its confirm dialog actually work. A bulk write
needs the confirmation more than it needs to sit next to its setting.

**A run is capped and reversible.** Each press retires up to 2,000 members and says whether
there are more to do — an unbounded sweep runs inside one transaction and would hold its locks
across every member in the programme. Every entry from one run is stamped with a reference, and
**Undo Last Expiry** gives the most recent one back with mirrored corrections. Because the
action retires spendable balance across the whole membership, it is gated on `loyalty.delete`
rather than inheriting `create` from the transport that carries it.

**Run by hand, not on a schedule.** A plugin registers no service provider and no console
command, so there is nowhere for this package to hang a cron entry. Rather than a setting
that silently does nothing, it is a button that says what it did — "Expired 12,400 points
across 38 members". If you want it monthly, call the endpoint from your host's cron.

**Oldest points go first.** There is no per-batch ledger and none is needed: everything
credited before the cutoff, minus everything ever spent, is by definition old *and* unspent.
A customer who earned 400 points last year, 600 this year and has already redeemed 400 loses
nothing — the redemption is treated as having used the old ones.

Expiry takes balance, never standing. `lifetime_points` counts what was earned, so nobody
drops a tier for not spending quickly enough. Running it twice does nothing the second time,
because the expiry entry is itself a debit the next pass sees.

## Structure

```
plugin.json                                   manifest — declares everything below
admin/modules/
  loyalty-members/                            "Loyalty" — the programme
    module.json                               routing, api, table, form, custom pages
    index.json  form.json                     list columns / enrol + edit form
    overview.json  settings.json              the two custom pages
  loyalty-transactions/                       "Points Activity" — the ledger
    module.json  index.json  form.json
  loyalty-tiers/                              the tier ladder
    module.json  index.json  form.json
admin/sections/
  PointsBadge.json                            page-builder section schema
admin/
  notifications.json                          the two staff alerts this package can raise
backend/
  Models/         LoyaltyMember · LoyaltyTransaction · LoyaltyTier · LoyaltySetting
  Repositories/   one per module — baseIndexQuery/create/find/update/delete/getOptions
  Listeners/      AwardPointsOnPaidOrder · SpendPointsOnPaidOrder
                  ReversePointsOnRefundedOrder · RedeemPointsAtCheckout
                  ContributeGdprExport
  Services/       Ledger — the one writer: entry + recompute, in one transaction
                  PointsExpiry — the expiry rule, testable without a screen
  Support/        CurrentCustomer · PointsBadge · RedeemBox — storefront rendering
  migrations/     four tables, plus reverses_id
frontend/blade/
  badge.blade.php  redeem.blade.php            the two customer-facing blocks
  slots/          account.blade.php · checkout.blade.php
  partials/sections/general/PointsBadgePlugin/index.php
```

`Support/` holds what both a page-builder section and a slot need, because the section render
class lives outside the plugin autoloader's reach and only exists while a section is drawing.
Keeping the work in `Support/` is what lets the same badge appear in a builder block and in the
`account` slot without two implementations.

### Things that must be exact

All are refused at install, but they are the easiest to get wrong and the failures are
confusing, so they are worth stating:

- **`"api": "/admin/modules/{type}"`** — plural. It is the endpoint Ovynt serves the module
  from, *and* the key the schema engine uses to tell a module from a form field. Omit it and
  you get `Field requires 'key', 'type', and 'label'`.
- **`"routeBase": "module/{type}"`** — **singular**. It is pushed to the admin router
  verbatim, and schema-driven modules live at `/module/:type`. Get it wrong and the list
  loads fine while the **Add** button 404s.
- **`"rules": ["required", "max:120"]`** — an **array**, never `"required|max:120"`. Laravel
  accepts the pipe string elsewhere, so it looks right; here it is silently ignored by both
  the form and the server, leaving a field that appears validated and accepts anything.

### Custom pages

A page needs three things: a top-level key in `module.json` matching its slug, a `navigation`
entry pointing at `module-type-page-slug`, and a `pageData($slug)` method on the repository.
Add `savePageData($slug, $data)` and a `"action": "save"` button to make it writable — that is
how **Settings** works. A button that is not a save posts to
`/admin/modules/{type}/page/{slug}` with `"action": "request"`, and `savePageData()` branches
on the slug — that is how **Expire old points now** works, because a plugin registers no
routes of its own.

**Every page file here is a JSON array of sections**, including single-section pages. Both
shapes render, and this package shipped one of each until they were reconciled. The array
wins because `create-plugin.md` forbids nesting a section inside a section, so a page with
several sections has nowhere to put them but the top level — and a page that grows a second
section should not have to change shape to get one.

## Data protection

A membership is a behavioural profile — what somebody bought, how often, and what they were
rewarded for it — so it belongs in a subject access response. The plugin answers core's
`GdprCollecting` event with the member's standing and **every entry in their ledger**, under
its own `plugin:loyalty-points` key. A balance without the entries behind it is a conclusion
rather than a record, and the customer cannot check it.

Erasure needs no counterpart. Core anonymises the `users` row in place and the membership hangs
off `user_id`, so the ledger survives with no name attached — which is correct: the shop's
liability is still real and is no longer about an identifiable person.

The one field capable of holding personal data nobody planned for is the **reason** an operator
types on an entry. It is exported, and the export says so.

## Tests

`tests/` ships with the package. There is no separate harness: the plugin's classes only
exist once Ovynt has installed and enabled it, so the tests run **inside a container, against
the installed copy**, using the host application's PHPUnit and its `ovynt_test` isolation.

```bash
# Install into the test database (creates the four tables)
docker exec -e DB_DATABASE=ovynt_test ovynt_app \
  php artisan plugin:import /var/www/storage/app/plugin-src-tmp/loyalty-points --enable

# Run them
docker exec ovynt_app php vendor/bin/phpunit \
  storage/app/plugins/loyalty-points/tests --no-coverage
```

They cover the ledger rules — what `recalculate()` does to balance, lifetime and tier across
earn, redeem, expiry, adjustment and refund — and the checkout listener's clamping.

`MoneyBoundaryTest` and `ProgrammeAdminTest` cover the boundary the ledger meets the world at,
which is where money is actually lost: two orders spending one balance, the exact point values
the old float round-trip got wrong, one payment delivered twice, every way an order's money can
end, the redemption cap and earn base, an append-only ledger, and the subject access export.
Reinstall after editing a test; the installed copy is what runs.

Two rules from `.agent/skills/laravel-testing.md` apply unchanged: **`DatabaseTransactions`,
never `RefreshDatabase`**, and never run two suites at once — these do DDL on `ovynt_test`.

## Requirements

Ovynt `>=1.2.0 <2.0.0`, declared in `plugin.json` and enforced at install.

## Building a release

```bash
# Optional: sign it so installs can verify the package is unaltered.
php artisan ovynt:plugin-sign /path/to/loyalty-points --key=~/keys/vendor-private.pem

# Then zip the directory — never edit a file after signing.
```

`plugin.sig`, `*.zip` and `*.pem` are git-ignored: the signature is build output that goes
stale on the next edit, and a signing key in a public repository would let anyone mint
licences.

## Writing your own plugin

See **`PLUGIN-DEVELOPMENT.md`** in the Ovynt repository — package format, every manifest
field, naming conventions, the repository contract, licensing, signing and update feeds.

## Licence

MIT — see [LICENSE](LICENSE).
