=== EPIC WooCommerce Suite ===
Contributors: leduybao1410
Tags: woocommerce, coupons, shipping, ghn, newsletter, reviews, wholesale, orders
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

All-in-one bundle of the EPIC Coffee Roastery WooCommerce plugins. One activation turns on every EPIC storefront backend module instead of ten separate plugins.

== Description ==

EPIC WooCommerce Suite bundles ten EPIC plugins into a single plugin, so a
fresh store install activates the whole EPIC storefront backend at once. Each
module ships unchanged in `modules/` and is bootstrapped exactly as it would
be as a standalone plugin.

Deactivate the standalone EPIC plugins before activating this one — the suite
detects an active standalone copy and skips its bundled counterpart (with an
admin notice) to avoid redeclaring classes.

Bundled modules:

* **EPIC Account Linking** — Google-sign-in accounts and their linked
  WooCommerce order history (auto-linked by billing email, plus manual claim
  by order code) for the headless website's account area.
* **EPIC Advanced Coupons** — advanced coupon rules (first-time customer,
  email/phone allowlist, recurring schedule, Buy X Get Y, auto-apply, bulk
  unique codes) plus a redemptions report, and the coupon-quote REST route for
  the headless checkout.
* **EPIC First Order Coupon** — first-time-customer-only restriction on any
  WooCommerce coupon.
* **EPIC GHN Shipping Manager** — GHN shipment booking, cancellation, label
  printing, status tracking, and multi-order bundling for WooCommerce orders.
* **EPIC News ↔ Product Link** — "Linked Coffee" meta box on posts, exposed via
  the REST API.
* **EPIC Newsletter Subscription** — shared-secret-authenticated subscription
  REST endpoint, subscriber list with CSV/XLSX export, and bulk bilingual
  newsletter sending.
* **EPIC Order Codes** — replace sequential order numbers with unguessable
  EPIC-XXXXXX codes via a keyed Feistel cipher.
* **EPIC Order Emails** — order-received confirmation and shipped emails
  (carrying the GHN tracking code).
* **EPIC Payment Store** — short-lived prepaid-checkout handoff store (SePay
  bank-transfer QR) for the headless checkout.
* **EPIC Product Reviews** — review submission/moderation over REST with
  aggregate rating served to the website's structured data.
* **EPIC Wholesale Inquiries** — wholesale contact-form lead log over a
  shared-secret REST endpoint.
* **EPIC REST URL Fix** (opt-in) — rewrites rest_url() between two domains;
  only active if EPIC_REST_URL_FIX_FROM / EPIC_REST_URL_FIX_TO are defined in
  wp-config.php. See `modules/epic-rest-url-fix.php`.

Every shared secret (REST auth, GHN token/shop ID, order-code key) is stored
in the WordPress database via the normal settings screens — never hardcoded —
and each has a matching environment variable on the Next.js website
(EPIC_ACCOUNT_SHARED_SECRET, EPIC_PAYMENT_SHARED_SECRET, EPIC_COUPON_SHARED_SECRET,
EPIC_NEWSLETTER_SHARED_SECRET, EPIC_ORDER_CODES_SHARED_SECRET,
EPIC_REVIEWS_SHARED_SECRET, EPIC_WHOLESALE_SHARED_SECRET).

== Installation ==

1. Deactivate all standalone EPIC plugins.
2. Upload `epic-woocommerce-suite` to `/wp-content/plugins/` (or use Plugins →
   Add New → Upload Plugin).
3. Activate the plugin. Activation creates all module tables and schedules the
   payment-store cleanup cron.
4. Configure each module under its usual screen (WooCommerce → Settings →
   GHN Shipping, WooCommerce → Newsletter Subscribers, etc.) and set the same
   shared secrets in the Next.js website's environment variables.

== Frequently Asked Questions ==

= Why does an admin notice say a module wasn't loaded? =

A standalone EPIC plugin is still active. The suite skips that module's bundled
copy to avoid redeclaring its classes. Deactivate the standalone plugin.

= Can I run the suite alongside the standalone plugins? =

No — same modules, same class names. Use one or the other.

== Changelog ==

= 1.0.0 =
* Initial release. Bundles epic-account-linking 1.0.0, epic-advanced-coupons
  1.2.0, epic-first-order-coupon 1.0.0, epic-ghn-shipping 0.10.0,
  epic-news-product-link 1.0.0, epic-newsletter-subscription 1.2.0,
  epic-order-codes 1.1.0, epic-order-emails 1.0.1, epic-payment-store 1.0.1,
  epic-product-reviews 1.1.0, epic-wholesale-inquiries 1.1.0, and the opt-in
  rest-url-fix module.
