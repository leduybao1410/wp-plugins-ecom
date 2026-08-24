=== EPIC Advanced Coupons ===
Contributors: epicroastery
Tags: woocommerce, coupons, discounts, bogo, automatic discounts
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later

== Description ==

Adds one new "Advanced Rules" tab (plus a "Generate Codes" tab) to the
normal WooCommerce coupon editor. Every coupon stays a completely normal
WooCommerce coupon — same post type, same admin screen, same discount
type/amount/expiry/usage limits — this plugin only layers extra
conditions and effects on top:

* **First-time customers only** — valid only if the billing email has no
  prior real order (Processing, Completed, On-hold, or Refunded).
  Abandoned/cancelled orders don't count against the customer.
* **Customer allowlist** — restrict to a list of emails and/or phone
  numbers (one per line; supports `*@domain.com` wildcards for email).
  Matches on billing email OR billing phone, since many checkouts here
  skip email entirely.
* **Day/time schedule** — recurring day-of-week and/or time-of-day
  window (e.g. weekends only, or 14:00-18:00 daily), on top of
  WooCommerce's native single start/expiry date range.
* **Buy X Get Y / bundle discount** — e.g. buy 2 of a category, get the
  3rd free, or buy product A get product B at a discount. Applied as a
  separate "Buy X Get Y discount" line in cart totals (not a visual
  change to the reward product's own row) so partial-quantity deals price
  correctly no matter how WooCommerce's per-line pricing works.
* **Auto-apply (no code needed)** — applies automatically once the cart
  reaches the coupon's native "Minimum spend" (optionally also requiring
  a category in the cart), and removes itself automatically if the cart
  drops back below — unless the customer removed it themselves, in which
  case it stays off for the rest of that session.
* **Bulk-generate unique single-use codes** — from any saved coupon
  (used as a template), generate a batch of brand-new coupons that copy
  every setting from it but each get their own random code and a usage
  limit of 1 (works once, store-wide). Useful for giveaways or influencer
  campaigns. Offers a CSV download of the generated codes.
* **Redemptions report** (Marketing → Coupons → Redemptions) — a
  searchable, filterable, paginated log of every coupon actually used on
  an order (code, which bulk-generate batch it came from, the order,
  discount amount, customer email/phone, redeemed date), with its own
  CSV export. Backed by a dedicated database table kept in sync
  automatically whenever an order is created or updated — not
  WooCommerce's native per-coupon usage count, which has no single place
  to ask "how many of this giveaway batch got redeemed" without checking
  every generated code one at a time.

Supersedes the earlier single-purpose `epic-first-order-coupon` plugin —
deactivate and remove that one after this plugin is confirmed working;
its one feature is fully covered by the "First-time customers only"
checkbox here.

= Setup =

0. If any older copy of this plugin is already installed (active or not),
   deactivate and fully delete its folder from `wp-content/plugins/`
   first — including via FTP/File Manager if needed, not just the
   in-admin delete — before uploading a new zip. Uploading a new version
   without removing the old folder makes WordPress extract it into a
   second folder (e.g. `epic-advanced-coupons 2`), and PHP cannot run two
   copies of the same plugin at once: the exact same class names get
   declared twice, which is always fatal no matter which of the two
   folders you try to activate. There is no code-level fix for this —
   it's a "don't have two copies on disk at the same time" rule.
1. Install and activate.
2. Create or edit a coupon as usual under **Marketing → Coupons**.
3. Open the new **Advanced Rules** tab to configure any of the rules
   above. Native settings (discount type/amount, usage limits, expiry,
   minimum spend, etc.) still live in their usual native tabs.
4. To bulk-generate unique codes from a coupon: save it first, then open
   the **Generate Codes** tab.
5. To review usage: **Marketing → Coupons → Redemptions**.

= A note on the Redemptions report =

This report only ever shows what actually happened on real orders — if
your checkout never sends a coupon code through when it creates an
order (headless/custom checkouts sometimes don't), the report will
stay empty regardless of how many coupons are configured. It's a
reporting layer, not a coupon-application mechanism.

= Headless / custom checkout integration =

If your store's checkout is a custom app (not WooCommerce's own cart and
checkout pages) — as with EPIC's own Next.js website — none of the rules
above fire on their own, since they all hook `WC_Cart`/the native
checkout form. This plugin also registers a
`POST /wp-json/epic/v1/coupon/quote` REST endpoint specifically for that
case: it runs the exact same restriction/discount/Buy-X-Get-Y/auto-apply
logic directly against a plain list of cart items, no cart or session
required, so a headless frontend can preview a coupon before checkout and
apply it for real at order creation (via `coupon_lines` for native
discounts, `fee_lines` for Buy X Get Y).

1. Go to **WooCommerce → Coupon Quote API** and set a shared secret (a
   long random string).
2. Put that same value in your website's `EPIC_COUPON_SHARED_SECRET`
   environment variable.
3. See `PLAN_HEADLESS_INTEGRATION.md` in this plugin's own folder for the
   full request/response contract and the website-side integration.

== Changelog ==

= 1.2.0 =
* Added `POST /wp-json/epic/v1/coupon/quote` — a headless-checkout REST
  endpoint (see "Headless / custom checkout integration" above) that runs
  every rule (restrictions, native discount math, Buy X Get Y, auto-apply)
  against a plain items array, no WC_Cart/session needed. New
  **WooCommerce → Coupon Quote API** settings page for its shared secret.
* Fixed: the first-order-only and customer-allowlist restrictions read the
  customer's email/phone from the PHP session, which is empty for a
  server-to-server REST order-creation call — meaning both restrictions
  silently never blocked a headless order even when configured. Now reads
  the order's own billing email/phone directly when the coupon is being
  validated against a `WC_Order` (REST `coupon_lines` or a manual
  wp-admin order edit) rather than a `WC_Cart`.
* Internal refactor: Buy X Get Y and auto-apply eligibility now run
  against a plain items array rather than requiring a real `WC_Cart` —
  what makes the new quote endpoint possible. No behavior change for the
  existing native-checkout/admin cart-based path.

= 1.1.1 =
* Internal requires now resolve via `__DIR__` instead of a shared plugin-
  path constant. This doesn't make it safe to have two copies of the
  plugin's folder on disk at once (that's still always fatal — see
  Setup step 0) — but it removes the spurious "constant already defined"
  warnings that scenario produced, and turns the failure into an
  immediate, clearly-labeled "Cannot redeclare class ... previously
  declared in [the other folder]" error instead of a confusing
  "file not found" pointing at the wrong directory.

= 1.1.0 =
* Added the coupon redemption log: a dedicated `epic_coupon_redemptions`
  table, synced automatically on order create/update, plus a
  searchable/filterable "Redemptions" admin screen and CSV export.

= 1.0.0 =
* Initial release: first-order-only, allowlist, day/time schedule,
  Buy X Get Y, auto-apply, bulk unique code generator.
