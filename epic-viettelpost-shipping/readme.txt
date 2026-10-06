=== EPIC ViettelPost Shipping Manager ===
Contributors: epicroastery
Tags: woocommerce, viettelpost, shipping, cod, vietnam
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

ViettelPost shipment booking, cancellation, label printing, and webhook status tracking for WooCommerce orders.

== Description ==

A WooCommerce admin tool that books, cancels, prints labels for, and tracks
ViettelPost shipments directly from the order screen and the Orders list —
mirroring the EPIC GHN Shipping Manager plugin.

Features:

* "Ship via ViettelPost" on the single order edit screen, with automatic
  COD-vs-prepaid detection from the order's payment method.
* Automatic matching of the order's city to a ViettelPost (post-merger)
  province, with a manual province/ward picker when the match is unclear.
* One-click "Create Shipment" and "Print label" actions on the Orders list,
  plus a "Create ViettelPost shipment(s)" bulk action.
* Label printing via ViettelPost's DigitalizePrint gateway.
* Inbound webhook that keeps each order's shipment status current.
* HPOS (High-Performance Order Storage) compatible.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` (or install the ZIP via
   Plugins → Add New → Upload Plugin).
2. Activate the plugin.
3. Go to WooCommerce → Settings → ViettelPost Shipping and enter your API
   token, pickup address, and (optionally) the webhook secret.

== Frequently Asked Questions ==

= Where do I get a ViettelPost token? =

Log in at viettelpost.vn → Cấu hình tài khoản → Thêm mới token and copy the
generated token. Sandbox and production tokens are different.

== Changelog ==

= 0.1.8 =
* Feature: the ViettelPost waybill note (`ORDER_NOTE`) now includes the
  order's customer note — the storefront checkout "delivery note" — so
  guidance typed by the customer is carried onto the shipment. It is
  combined with the order-number reference, collapsed to one line and capped
  at 250 characters. Applies to the single-order, bulk and admin-dashboard
  booking paths.

= 0.1.7 =
* Fix: the EPIC admin dashboard reported "ViettelPost shipping unavailable"
  and rejected booking/cancel/label actions with a 503. The dashboard reaches
  this plugin over `/wp-json/epic-admin/v1/...`, where is_admin() is false, so
  the admin-only include set was never loaded and `Epic_VTP_Ajax` did not
  exist. Those REST requests now load the include set before the dashboard's
  callbacks run (admin hooks are still registered only in wp-admin).

= 0.1.6 =
* Feature: "ViettelPost Shipments" dashboard (WooCommerce submenu) — list
  every booked shipment with status/date filters, search, "needs action" and
  "stale" badges, COD, quoted-vs-actual courier fee, bulk label printing, and
  CSV export.
* Feature: bulk "Print ViettelPost labels" action on the Orders list.
* Feature: label size (A5/A6/A7) and show/hide-postage settings.
* Feature: one-click cancel retry — a transient "status already changed"
  rejection right after booking is retried once automatically.
* Feature: manual "Set status" control on the order (ViettelPost exposes no
  status-query API, so this is the fallback when a webhook is missed).
* Feature: opt-in settings to complete the order on delivery, hold it on a
  failed booking, and hold/flag it on a return or delivery issue.
* Feature: daily stale-shipment scan flags in-flight shipments with no
  webhook update within the configured window.
* Fix: webhook now ignores out-of-order (older) status callbacks instead of
  regressing the shipment status.
* Fix: booking takes a per-order lock so a double-click can't create two
  shipments.
* New action: `epic_vtp_status_changed( $order, $status, $source )` fired on
  every applied status change (used by epic-order-emails for the new
  "delivered" email).

= 0.1.5 =
* Change: the COD amount collected at delivery is now the full WooCommerce
  order total (goods + the shipping fee already in the order price), so the
  amount charged matches the total the customer was shown at checkout.
  ViettelPost's own shipping fee is now billed to the sender instead of added
  on top of the collection (ORDER_PAYMENT 2 → 3); the sender receives the
  order total minus ViettelPost's fee. This also fixes free-shipping COD
  orders, which previously had the courier's fee collected from the recipient.
* Fix: the webhook's waybill lookup no longer uses `meta_query` on the legacy
  (non-HPOS) order datastore, which WooCommerce 9.2+ logs as unsupported and
  may stop honoring. HPOS stores keep the `meta_query` lookup; legacy stores
  now query the order postmeta directly. Found by an e2e test on a non-HPOS
  store.

= 0.1.4 =
* Fix: compose the pickup address (street + ward + province) for the
  address-detail (NLP) fee/booking calls. Sending the street text alone made
  ViettelPost return "Price does not apply to this itinerary!".

= 0.1.0 =
* Initial release: settings, single-order booking/cancel/print, orders-list
  actions, webhook status sync.
