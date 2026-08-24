=== EPIC Order Emails ===
Contributors: epicroastery
Tags: woocommerce, email, smtp, ghn, giao hang nhanh
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 8.9
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Two customer-facing WooCommerce emails for EPIC Roastery: order received, and order shipped with the GHN tracking code.

== Description ==

Adds two emails, both registered as ordinary `WC_Email` classes — so the only admin screen this plugin needs is the one WooCommerce already has:

* **WooCommerce → Settings → Emails → EPIC: Order Received** — sent to the customer when their order first moves from `pending` into `processing` or `on-hold`, which is what happens the moment the Next.js checkout creates the order via the WooCommerce REST API. **Disabled by default.** WooCommerce's own built-in "Processing order" / "On-hold order" customer emails fire on the exact same transition — before enabling this one, disable those two native emails under Settings → Emails, or a customer will get both for the same order.
* **WooCommerce → Settings → Emails → EPIC: Order Shipped (GHN tracking code)** — sent once a staff member books the GHN shipment for an order, from any of the three places `epic-ghn-shipping` can do that (the order screen's "Ship via GHN" button, the Orders list's per-order or bulk booking, or a bundle confirm). Carries the GHN tracking code, a tracking link, expected delivery if GHN returned one, and — for COD orders — the amount due on delivery. **Enabled by default**, since WooCommerce has no native equivalent to collide with. A bundled shipment (one parcel, several orders) sends one email per order, each with its own order number, even though the tracking code is identical across all of them.

Orders with no billing email on file (the checkout's email field is optional — common for COD/phone-only customers) are skipped silently; check **WooCommerce → Status → Logs** (source `epic-order-emails`) to see which orders that happened for.

Requires `epic-ghn-shipping` **0.9.0+** for the "Order Shipped" email — that version is the one that fires the `epic_ghn_shipment_booked` action this plugin listens for. Without it, the Order Shipped email still shows up under Settings → Emails but never fires.

== Installation ==

1. Plugins → Add New → Upload Plugin → choose `epic-order-emails.zip` → Install Now → Activate.
2. Go to WooCommerce → Settings → Emails. Before turning on **EPIC: Order Received**, disable **Processing order** and **On-hold order** (WooCommerce's native emails) to avoid double-sending.
3. **EPIC: Order Shipped (GHN tracking code)** is on by default — customize its subject/heading if you'd like, or turn it off from the same screen.
4. Confirm outbound mail actually reaches inboxes — WooCommerce's default `wp_mail()` transport is unreliable on most hosts. An SMTP plugin (WP Mail SMTP, FluentSMTP, or WooCommerce's own "SMTP & Email Logs") is strongly recommended regardless of this plugin.
5. Before relying on the tracking link in the Order Shipped email, confirm the GHN tracking URL format against your GHN dashboard/account — see the docblock on `epic_order_emails_ghn_tracking_url()` in the main plugin file. Adjust via the `epic_order_emails_ghn_tracking_url` filter if needed.

== Changelog ==

= 1.0.1 =
* Email content changed to Vietnamese-only (was bilingual VI/EN in 1.0.0) — subjects, headings, and both HTML/plain-text templates for both emails. Admin-facing strings (settings screen titles/descriptions under WooCommerce → Settings → Emails) stay in English, since those aren't customer-facing.

= 1.0.0 =
* Initial release: `Epic_Email_Order_Created` and `Epic_Email_Order_Shipped`, both registered via `woocommerce_email_classes`.
* Vietnamese email copy, hard-coded rather than routed through a `.mo` translation file — this project's plugins don't currently ship compiled Vietnamese translations (see epic-ghn-shipping/languages, which is empty). Move to `__()`-driven translation if that pipeline gets set up later.
* No-email orders (billing email empty) are skipped silently and logged via `WC_Logger`, source `epic-order-emails` — no admin-facing flag, per project decision.
* Both emails guard against duplicate sends: Order Received via a `_epic_order_email_sent` order meta flag, Order Shipped via `_epic_ship_email_sent_code` (compares the *tracking code*, so a legitimately re-booked shipment after a cancellation still gets a fresh email).
