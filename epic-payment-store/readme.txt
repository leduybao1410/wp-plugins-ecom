=== EPIC Payment Store ===
Contributors: epicroastery
Tags: woocommerce, sepay, payments
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

== Description ==

Backs the Next.js website's prepaid checkout — currently SePay bank-transfer
QR, previously VietQR — but this plugin is payment-provider-agnostic by
design: it never does anything VietQR- or SePay-specific, it just holds
pending (unpaid) checkout data — cart, address, computed totals — until the
website's own payment webhook confirms a transfer actually happened, then
holds the resulting order/tracking info long enough for the checkout page to
notice.

No WooCommerce order is ever created from data this plugin stores — order
creation happens in the website's own webhook handler, via the standard
WooCommerce REST API, exactly once, only after payment is confirmed. This
plugin exists purely so that "wait for payment" state has somewhere durable
to live without pulling in a third-party database service — it uses two
plain tables in your site's existing MySQL database.

= Setup =

1. Install and activate. If you're switching over from the older
   `epic-vietqr-payment` plugin, deactivate and delete that one first —
   table/option names changed (see Changelog), so the two aren't compatible,
   and any pending rows in the old tables are short-lived (~15 min TTL)
   anyway.
2. Go to **WooCommerce → Payment Store** and generate/paste a long random
   shared secret.
3. Put that same value in the website's `EPIC_PAYMENT_SHARED_SECRET`
   environment variable.

== Changelog ==

= 1.0.0 =
* Renamed from `epic-vietqr-payment` when the website switched from VietQR
  to SePay — this plugin's own logic didn't change (it was already
  provider-agnostic), only its name, class names, REST namespace
  (`epic-vietqr/v1` → `epic-payment/v1`), table names
  (`{prefix}epic_vietqr_*` → `{prefix}epic_payment_*`), and settings option
  key (`epic_vietqr_shared_secret` → `epic_payment_shared_secret`). The
  `X-Epic-Secret` header name is unchanged.
* Initial release (as epic-vietqr-payment): pending/completed handoff
  tables + REST routes.
