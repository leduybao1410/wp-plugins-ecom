# Coupon redemption log — build plan

Status: **draft, awaiting review before build** (same convention as the
other plugins in this project: plan first, code after sign-off).

Addendum to `epic-advanced-coupons` (see `PLAN.md` for the original
6-feature build) — adds a custom table that logs which coupon was
redeemed on which order, primarily so bulk-generated single-use codes
(giveaways, influencer campaigns) can actually be tracked. Lives in the
same plugin rather than a new one, since it's still "coupon reporting."

## Why a custom table, not WooCommerce's native tracking

WooCommerce already tracks coupon usage natively — `usage_count` +
`_used_by` postmeta on the coupon, and a `coupon` line item on the order
— but reading it back for reporting means joining `wp_posts` →
`wp_postmeta` → `wp_woocommerce_order_items` → `wp_woocommerce_order_itemmeta`
(or the equivalent HPOS tables) for every row, which gets slow as volumes
grow, and gives no single place to ask "how many of batch VIP-* got
used" without scanning every coupon post's meta individually. A single
purpose-built table with the right columns and indexes answers every
report this project actually needs with one plain indexed `SELECT`.

## ⚠️ Dependency this plan does NOT solve

This ships the storage + sync + reporting layer, ready to go — but it
will stay **empty** until coupons are actually wired into the real
checkout. As established when checking the free-shipping request: the
Next.js checkout never sends `coupon_lines` when creating an order, so
no coupon is ever really applied to a live order today. The sync
mechanism below (reconciling to whatever coupons are on the order)
works regardless of *how* a coupon got applied — native cart checkout,
wp-admin manually adding one to an order, or a future REST `coupon_lines`
integration — so nothing here needs to change later; it just has nothing
to log until that separate piece of work happens. Flagging this so
"why is the redemptions list empty" isn't a surprise after this ships.

## Schema

```sql
CREATE TABLE {$wpdb->prefix}epic_coupon_redemptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  coupon_id BIGINT UNSIGNED NOT NULL,
  coupon_code VARCHAR(64) NOT NULL,
  generated_from BIGINT UNSIGNED NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  order_number VARCHAR(32) NULL,
  discount_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
  order_total DECIMAL(15,4) NULL,
  billing_email VARCHAR(190) NULL,
  billing_phone VARCHAR(32) NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'active',
  redeemed_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY order_coupon (order_id, coupon_id),
  KEY coupon_id (coupon_id),
  KEY coupon_code (coupon_code),
  KEY generated_from (generated_from),
  KEY redeemed_at (redeemed_at)
) {$charset_collate};
```

Design choices, and why each one is what makes this scale rather than
degrade as rows pile up:

- **Denormalized snapshot columns** (`coupon_code`, `order_number`,
  `order_total`, `billing_email`, `billing_phone`) — the reporting screen
  never needs to join back to `wp_posts`/`wp_postmeta` for every row.
  Every realistic report ("which codes from batch X got used", "who
  used VIP-3F82", "redemptions last week") is one indexed `SELECT`
  against this table alone, no N+1 joins, no cost growth as the coupon
  or order tables grow.
- **`UNIQUE KEY (order_id, coupon_id)`** — every sync is an *upsert*
  (`INSERT ... ON DUPLICATE KEY UPDATE`), so re-syncing the same order
  (edited twice, re-saved by staff, etc.) can never create duplicate or
  drifting rows. This also means write cost is O(1) per order-save
  regardless of how large the table has grown — the core property that
  keeps this scalable as order volume increases, not just at launch.
- **No redundant single-column `order_id` index** — the composite
  unique key's leading column already serves "all redemptions for this
  order" lookups; skipping a duplicate index keeps every write cheaper
  (fewer indexes to update per insert) without losing any query pattern.
- **`generated_from`** mirrors the `_epic_generated_from` coupon meta
  (which template a bulk-generated code came from) so "how many of this
  giveaway batch got redeemed" is one `WHERE generated_from = ?` query,
  not a join through every generated coupon post.
- **`status` (`active`/`removed`), never a DELETE`** — if staff remove a
  coupon from an order later, the row is marked `removed`, not deleted.
  Redemption history is business record-keeping (like the order itself),
  not ephemeral state — unlike `epic-payment-store`'s tables, there is no
  purge job here by design.
- **`DECIMAL(15,4)`** for money columns, matching WooCommerce's own
  internal precision — safe headroom even though VND itself has no
  minor unit, and avoids float rounding drift if this ever needs to
  handle another currency.

At the volumes this store will plausibly ever see (thousands to tens of
thousands of orders), this is comfortably within what a single indexed
MySQL table handles without any partitioning/archival scheme — that
would be over-engineering for a coffee roastery's order volume. If
per-row reporting ever gets slow at a much larger scale, the fix is a
periodic pre-aggregated summary table, not a rewrite of this one; not
needed now, noting it only so it's not forgotten as a future option.

## Sync mechanism (write path)

Hooks: `woocommerce_new_order` and `woocommerce_update_order` — both
fire with just an `$order_id`, work identically whether the order lives
in legacy post storage or HPOS, and cover every way a coupon could end
up on an order (created with one, edited to add/remove one later, admin
vs. any future REST integration) without needing to catch a specific
"coupon applied" event.

On each fire:
1. Load the order (`wc_get_order( $order_id )`).
2. Read its current coupon line items (`$order->get_items( 'coupon' )`) —
   this is the source of truth for "what coupons does this order have
   right now."
3. For each one: resolve `coupon_id` via `wc_get_coupon_id_by_code()`,
   and upsert a row keyed on `(order_id, coupon_id)` with the current
   discount amount, order total, billing email/phone, `status = 'active'`.
4. For any row already logged for this order whose coupon is no longer
   in that current list, flip it to `status = 'removed'` (never delete).

This reconcile-to-current-state approach is idempotent and self-healing
— it doesn't matter how many times an order gets saved, or in what
order these hooks fire relative to each other; the log always converges
to match the order's actual coupon lines.

## Reporting (read path)

A "Redemptions" submenu under Marketing → Coupons, built as a
`WP_List_Table` (WordPress's own paginated-admin-list component) reading
straight off the indexed table — filterable by coupon code, by batch
(`generated_from`), and by date range, each filter mapping directly to
one of the indexes above so pagination stays cheap regardless of table
size.

## File layout

```
epic-advanced-coupons/includes/
  class-redemption-log.php     table install (dbDelta) + sync hooks + upsert/query helpers
  class-redemption-admin.php   the "Redemptions" WP_List_Table admin screen
```

Registered in the plugin bootstrap alongside the existing classes; adds
one `register_activation_hook` call for the new table (the plugin has
none yet — only the FeaturesUtil compatibility declaration — so this is
new, following the same `dbDelta` + `update_option(..._db_version)`
pattern already used in `epic-payment-store`).

## Open items before I start building

1. Is a wp-admin list screen enough, or do you also want a CSV export
   of redemptions (useful for reconciling a giveaway batch after the
   fact)? Easy to add either way — asking so it's not missed at scope.
2. Should `billing_phone`/`billing_email` be shown in plain text on the
   admin screen, or masked/partial (e.g. `090***4567`) given they're
   customer PII sitting in a reporting list rather than an order record
   staff already expect to see PII on?
3. Confirming the empty-until-wired-up caveat above is fine to ship as
   infrastructure now, rather than bundling in the checkout-side "apply
   a coupon for real" work in the same pass (that's a separate, larger
   piece touching the Next.js checkout, not just this plugin).
