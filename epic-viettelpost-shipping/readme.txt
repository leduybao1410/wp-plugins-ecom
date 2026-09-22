=== EPIC ViettelPost Shipping Manager ===
Contributors: epicroastery
Tags: woocommerce, viettelpost, shipping, cod, vietnam
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
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

= 0.1.0 =
* Initial release: settings, single-order booking/cancel/print, orders-list
  actions, webhook status sync.
