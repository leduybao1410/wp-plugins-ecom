=== EPIC WooCommerce Suite ===
Contributors: leduybao1410
Tags: woocommerce, coupons, shipping, ghn, newsletter, reviews, wholesale, orders
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

All-in-one bundle of the EPIC Coffee Roastery WooCommerce plugins. One activation turns on every EPIC storefront backend module instead of fifteen separate plugins.

== Description ==

EPIC WooCommerce Suite bundles eighteen EPIC plugins into a single plugin, so a
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
* **EPIC Discord Order Notifications** — posts new-order notifications to a
  Discord channel webhook.
* **EPIC Distributor Profit** — distributor profit ledger with an
  order-picker import, per-distributor reporting, and CSV/XLSX export.
* **EPIC Email Branding** — branded header bar and a contact-info footer
  (café/roastery address, hotline, email, website, Instagram, hours) on every
  WooCommerce email, global via hooks; all values filterable.
* **EPIC First Order Coupon** — first-time-customer-only restriction on any
  WooCommerce coupon.
* **EPIC GHN Shipping Manager** — GHN shipment booking, cancellation, label
  printing, status tracking, and multi-order bundling for WooCommerce orders.
* **EPIC Image Optimize** — bulk image compression/optimization tools for the
  media library.
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
* **EPIC Product Cost** — per-250g product cost tracking with full history
  (auto-scaled to 500g/1kg) and a REST API used by the companion MCP server.
* **EPIC Product Reviews** — review submission/moderation over REST with
  aggregate rating served to the website's structured data.
* **EPIC Sample Requests** — free-coffee-sample request log (name, phone,
  structured Vietnam address, favourite taste) over a shared-secret REST
  endpoint, with an admin notification email.
* **EPIC Wholesale Inquiries** — wholesale contact-form lead log over a
  shared-secret REST endpoint.
* **EPIC Wholesale Orders** — wholesale price levels, customer allowlist, and
  the wholesale order log over shared-secret REST routes.
* **EPIC REST URL Fix** (opt-in) — rewrites rest_url() between two domains;
  only active if EPIC_REST_URL_FIX_FROM / EPIC_REST_URL_FIX_TO are defined in
  wp-config.php. See `modules/epic-rest-url-fix.php`.

Every shared secret (REST auth, GHN token/shop ID, order-code key) is stored
in the WordPress database via the normal settings screens — never hardcoded —
and each has a matching environment variable on the Next.js website
(EPIC_ACCOUNT_SHARED_SECRET, EPIC_PAYMENT_SHARED_SECRET, EPIC_COUPON_SHARED_SECRET,
EPIC_NEWSLETTER_SHARED_SECRET, EPIC_ORDER_CODES_SHARED_SECRET,
EPIC_REVIEWS_SHARED_SECRET, EPIC_SAMPLE_SHARED_SECRET,
EPIC_WHOLESALE_SHARED_SECRET,
EPIC_WHOLESALE_ORDERS_SHARED_SECRET). Shared-secret fields render as masked
password inputs: the saved value is never printed into the page, and leaving
the field blank keeps the current secret.

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

= 1.4.0 =
* Localized customer emails: new `includes/epic-email-i18n.php` provides
  customer-facing copy for en, vi, ru, hi, zh, ko and ja (region tags resolve
  to their base language; unknown locales fall back to English). Newsletter
  confirmation + broadcast footer, free-sample confirmation, and the
  order-created / order-shipped emails now render in the storefront locale the
  customer used — order emails read the `_epic_locale` order meta, with a
  Vietnamese fallback for legacy orders. Staff admin emails stay Vietnamese.

= 1.3.0 =
* Added epic-email-branding 1.0.0: a global branded header bar plus a
  contact-info footer (address, hotline, email, website, Instagram, hours) on
  every WooCommerce email, via the `woocommerce_email_header` action and
  `woocommerce_email_footer_text` filter. Also added the footer to the three
  plain-text templates that were missing it (newsletter broadcast, wholesale
  order admin/customer).

= 1.2.2 =
* epic-sample-requests 1.2.0: added an optional brew style, made email/taste/
  brew optional (only name, address and phone required), added phone
  validation (10 digits starting with 0). Schema 1.2 adds `brew` columns.

= 1.2.1 =
* epic-sample-requests 1.1.0: the free-sample form now collects an email
  address and sends the requester a bilingual thank-you confirmation
  (`Epic_Email_Sample_Confirmation`), with a second status column in the
  WooCommerce → Sample Requests log. Schema 1.1 adds `email` and
  `confirm_status` columns.

= 1.2.0 =
* Added epic-sample-requests 1.0.0: free-coffee-sample request log over a
  shared-secret REST endpoint, with an admin notification email and the
  WooCommerce → Sample Requests admin screen. Activation now creates its table.

= 1.1.0 =
* Added five modules: epic-discord-notify 1.0.0, epic-distributor-profit 1.1.0,
  epic-image-optimize 1.0.0, epic-product-cost 1.1.0, epic-wholesale-orders
  1.0.0.
* Masked shared-secret fields in every module's settings screen (password
  type, blank value, blank submit keeps the saved secret) so the value is no
  longer exposed in the page source.
* Activation now creates the distributor-profit and product-cost tables.

= 1.0.0 =
* Initial release. Bundles epic-account-linking 1.0.0, epic-advanced-coupons
  1.2.0, epic-first-order-coupon 1.0.0, epic-ghn-shipping 0.10.0,
  epic-news-product-link 1.0.0, epic-newsletter-subscription 1.2.0,
  epic-order-codes 1.1.0, epic-order-emails 1.0.1, epic-payment-store 1.0.1,
  epic-product-reviews 1.1.0, epic-wholesale-inquiries 1.1.0, and the opt-in
  rest-url-fix module.
