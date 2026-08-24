# EPIC Advanced Coupons — build plan

Status: **draft, awaiting review before build** (same convention as
`epic-ghn-shipping` and `epic-order-emails`: plan first, code after
sign-off).

## Goal

Replace the single-purpose `epic-first-order-coupon` plugin with a broader
`epic-advanced-coupons` plugin that adds several extra rule types and
behaviors on top of native WooCommerce coupons — without duplicating or
replacing anything WooCommerce already does. A coupon created by this
plugin is still a completely normal WooCommerce coupon (same `shop_coupon`
post type, same `WC_Coupon` class, same discount type/amount/expiry/usage
limits, same admin screen under **Marketing → Coupons**). This plugin only
adds one new tab, **Advanced Rules**, to the existing coupon editor, plus
one generator tool for bulk unique codes.

Nothing here creates a parallel coupon system — every feature is either
(a) an extra *validity condition* checked alongside WooCommerce's own
(usage limit, expiry, min spend, etc.), or (b) an extra *cart-time effect*
applied only while one of these coupons is in the applied-coupons list.

## Directory layout

```
epic-advanced-coupons/
  epic-advanced-coupons.php       bootstrap, activation/compat checks
  includes/
    class-admin-tab.php           renders + saves the "Advanced Rules" tab
    class-restrictions.php        first-order-only, allowlist, day/time gate
    class-bxgy.php                Buy X Get Y / bundle discount cart effect
    class-auto-apply.php          auto-apply / auto-remove logic
    class-bulk-generate.php       bulk unique single-use code generator
  readme.txt
  PLAN.md
```

## Feature 1 — First-time customers only

Carried over unchanged from `epic-first-order-coupon` (already built and
reviewed): valid only if the billing email has no prior order in
Processing / Completed / On-hold / Refunded status. Checked live as the
customer types their email at checkout, and again as a hard stop at order
submission. `epic-first-order-coupon` gets folded into this plugin and
retired (see **Migration** below).

## Feature 2 — Customer allowlist (email *and* phone)

WooCommerce already has a native "Allowed emails" restriction field
(exact match + `*@domain.com` wildcards). This project's customer base
skips billing email often — COD checkouts frequently have no email at all
(noted in the order-emails plan) — so an email-only allowlist would miss
most repeat/VIP customers you'd actually want to target.

**Plan:** one textarea, one entry per line, accepting either an email
(with wildcard support, same syntax as native) or a phone number. Phone
numbers are normalized before comparing (strip spaces/dashes/parens,
compare the last 9–10 digits so `+84901234567`, `0901234567`, and
`901234567` all match each other). A cart/order matches the allowlist if
*either* its billing email or billing phone matches any line.

*Assumption to confirm:* email+phone combined list, OR-matched. If you'd
rather keep this to email-only (and just rely on WooCommerce's native
field for that), this feature can be dropped entirely and Feature 2 skipped.

## Feature 3 — Day/time schedule

Native WooCommerce only has a single start-date/expiry-date range — no
recurring "only on weekends" or "only 2–5pm" support.

**Plan:** checkboxes for Mon–Sun (default: all checked, meaning no
restriction) plus optional start time / end time (site's local timezone,
from the WordPress Settings → General timezone). An end time earlier than
the start time is treated as wrapping past midnight (e.g. 22:00–02:00).
Blank start/end = no time-of-day restriction, day-of-week checkboxes only.
Checked at the same two checkpoints as the other restrictions.

## Feature 4 — Buy X Get Y / bundle discount

Not possible with native coupons at all — this is the most involved piece.

**Plan:** per coupon, configure:
- **Trigger**: a product or category + quantity required (e.g. "2× any
  product in Robusta Natural category").
- **Reward**: a product or category + reward quantity + reward type
  (100% off / a specific % off / a fixed amount off).
- **Max repeats per order** (default 1) — e.g. if the customer buys the
  trigger qty 3× over, whether the deal can apply 3 times in one order.

**Mechanism:** rather than rewriting individual cart line-item prices
(WooCommerce prices a whole line per-unit, so partial-quantity discounts
— e.g. "2 free out of 5 in the cart" — can't be expressed as a single
per-unit price change), the computed discount value is applied as a
**negative cart fee** ("Buy X Get Y discount", via `WC()->cart->add_fee()`)
on `woocommerce_cart_calculate_fees`, recalculated fresh every time the
cart changes. This is the standard, safe way to do partial/conditional
cart discounts in WooCommerce without fighting its per-line pricing model.

*Assumption to confirm:* reward shows as a line in the cart totals table
("Buy X Get Y discount: −₫X") rather than visually changing the product's
own line price. If you specifically want the reward product's row itself
to visually show ₫0 or a struck-through price, that needs the line-split
approach instead (more complex, more fragile across payment gateways/
CANCEL — worth confirming before starting since it changes the build).

## Feature 5 — Auto-apply / no code needed

**Plan:** a toggle on the coupon; when enabled, the condition reused is
WooCommerce's own native **Minimum spend** restriction field (no duplicate
field needed) plus an optional "cart must contain category" filter. On
every cart/checkout recalculation, all published auto-apply coupons are
checked: if conditions are newly met and not already applied, apply
automatically; if conditions stop being met, remove automatically. A
session flag remembers when *the customer* explicitly removed an
auto-applied coupon, so it won't be silently reinstated for the rest of
that session even if the cart still qualifies — respects the customer's
choice rather than fighting them.

*Assumption to confirm:* minimum-spend-only trigger (+ optional category)
is enough. If you want other trigger types (e.g. "customer is logged in",
"cart contains N+ items"), say which and I'll add them.

## Feature 6 — Bulk-generate unique single-use codes

**Plan:** on any existing coupon's edit screen, a new panel: "Generate
unique one-time codes" with Quantity, optional Prefix, and code length.
Clicking Generate clones that coupon's full settings (discount type/
amount, all restrictions above, expiry, everything) into N new coupons,
each with a random unique code and usage limit forced to 1 (so every
generated code works exactly once, store-wide, regardless of the
template's own usage-limit setting — since "single-use" was the explicit
ask). After generation, a CSV of the new codes is offered for download
(for handing out to influencers/giveaway entrants).

## Migration from `epic-first-order-coupon`

Once this is built and activated, `epic-first-order-coupon` should be
deactivated and its folder/zip moved to `wordpress-plugins/_to_delete/`
(matching how superseded plugins are already handled in this project). No
data migration needed — no coupon currently has the old plugin's checkbox
enabled yet (nothing shipped to production).

## Open items before I start building

1. Confirm or adjust the email+phone allowlist assumption (Feature 2).
2. Confirm the negative-cart-fee approach for BXGY is fine, vs. wanting
   the reward item's own row to visually show the discount (Feature 4).
3. Confirm minimum-spend (+ optional category) is enough for auto-apply
   triggers, or name others you want (Feature 5).
4. Any specific format preference for generated codes (Feature 6) — e.g.
   a particular prefix convention, or should quantity have a sane cap
   (e.g. 500 per batch) to avoid accidentally generating thousands of
   posts.
