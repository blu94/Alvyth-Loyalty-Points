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

## Points are issued by an operator, not automatically

**This plugin cannot award points at checkout.** The Ovynt plugin system lets a plugin
register an autoloader and permissions — it has no hook for event listeners or service
providers (see `PLUGIN-SYSTEM-SPEC.md`, "deliberately deferred"). Nothing a plugin ships can
observe an order being paid.

So points are posted under **Points Activity**, by hand or by whatever you build against the
API. The same applies to **expiry**: the setting records your policy and is there for a
future integration to read, but no scheduled job in this package acts on it.

`points_per_currency` and `redeem_value` exist for the same reason — they are the programme's
published rates, ready for the moment core can call into a plugin.

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
backend/
  Models/         LoyaltyMember · LoyaltyTransaction · LoyaltyTier · LoyaltySetting
  Repositories/   one per module — baseIndexQuery/create/find/update/delete/getOptions
  migrations/     four tables
```

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
