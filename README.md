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
| **Loyalty → Overview** | Points issued, redeemed, outstanding, and what the outstanding balance is worth |
| **Loyalty → Members** | Every enrolled customer: balance, lifetime points, tier |
| **Loyalty → Settings** | Earn rate, point value, minimum redemption, expiry window |
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

Editing a tier's threshold re-ranks every member, so the standings on screen always match the
rules currently in force.

## The programme runs itself

Four listeners do the work; an operator only intervenes to correct something.

| When | What happens |
|---|---|
| An order becomes **paid** | The customer is enrolled if new, and an `earn` entry is written at your rate |
| A customer **spends points at checkout** | The order is priced with the reduction, and the `redeem` entry is written **on payment** |
| A paid order is **refunded** | Both entries are reversed with mirrored entries that point at what they undo |

**Points are awarded on payment, not on placement.** An abandoned checkout or an unpaid
invoice would otherwise mint value you had to claw back.

**Points are deducted on payment too, not when the order is priced.** Redemption is quoted
while the customer is still deciding and may be followed by a gateway failure; debiting then
and refunding on failure would invent a reversal path for the common case rather than the rare
one. Until the order is paid, an abandoned checkout costs the customer nothing.

### Spending points

The plugin renders a points box into the storefront's `checkout` slot — one number input
carrying `data-checkout-field="loyalty_points"`. The theme carries the value to the server and
`RedeemPointsAtCheckout` reads it back through `CheckoutAdjusting::field()`. The plugin ships
**no JavaScript**; a theme that renders the slot gets a working control.

It proposes an amount and core decides. Never more than the customer holds, never more than
the order is worth, and never below your minimum — and when the order is smaller than the
points offered, only the points actually used are charged. Asking to spend 999,999 points on a
5.00 order takes 5.00 off and costs 500 points, not the balance.

A **suspended** member keeps their balance and stops spending it, checked in the listener and
not only in the UI. A guest sees no box.

### Why a refund cannot promote someone

Reversal entries carry `reverses_id`, a self-reference to the entry they undo. `lifetime_points`
sums earn and adjust entries that are *not* reversals, plus reversals whose target **was** an
earn — so reversing a redemption does not read as earning. A plain `SUM(points > 0)` was wrong
in both directions and let a refund raise someone into a higher tier. Tier standing must not
depend on a reason string, which is why this is a column and not prose matching.

### Not automatic

**Expiry.** `expiry_months` records your policy and nothing acts on it — no job in this
package writes `expire` entries.

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
backend/
  Models/         LoyaltyMember · LoyaltyTransaction · LoyaltyTier · LoyaltySetting
  Repositories/   one per module — baseIndexQuery/create/find/update/delete/getOptions
  Listeners/      AwardPointsOnPaidOrder · SpendPointsOnPaidOrder
                  ReversePointsOnRefundedOrder · RedeemPointsAtCheckout
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
how **Settings** works.

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
earn, redeem, expiry, adjustment and refund — and the checkout listener's clamping. Reinstall
after editing a test; the installed copy is what runs.

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
