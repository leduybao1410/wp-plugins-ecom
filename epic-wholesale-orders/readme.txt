=== EPIC Wholesale Orders ===
Contributors: epicroastery
Tags: woocommerce, wholesale, orders, b2b, pricing
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 8.9
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Wholesale ordering for whitelisted customers: per-product wholesale prices, a dedicated ordering page on the storefront, and a wp-admin queue — with no stock reduction, no payment, and no shipping.

== Description ==

Sells your products wholesale to a curated list of customers. The storefront (Next.js) shows whitelisted users a wholesale ordering page; the seller manages everything in WooCommerce:

* **WooCommerce → Wholesale Orders** — the order queue. Every submission from the storefront appears here (newest first, sortable/paginated), with order status (Pending / Done / Cancelled), payment status, line items, totals, and each row's email delivery status. Per-row Delete, plus View/Edit which opens the order's detail screen.
* **WooCommerce → Wholesale Orders → settings (same screen)** — pick which registered users are wholesale customers (a searchable customer picker) and set the shared secret that authenticates the website's calls into this plugin's REST routes.
* **Product editor — "Wholesale" box** — per-product: enable wholesale ordering and set the wholesale price. Variable products get the same two fields per variation. Wholesale prices are shown only on the wholesale page, never on the regular storefront.
* **Order detail screen** — line items, the customer's note, and two statuses: order status and payment status. Marking an order **Done** auto-sets payment to *Waiting for payment* (unless already *Paid*). Cancelling/unapproving requires a reason and auto-sets payment to *Canceled*. Payment status is editable directly (Waiting for payment / Paid / Pending / Canceled).
* **WooCommerce → Settings → Emails → EPIC: Wholesale Order** and **EPIC: Wholesale Order — Customer** — the seller and buyer notifications, registered as ordinary `WC_Email`s. Vietnamese content, editable subject/heading/recipient.

Important by design (see the plugin's PLAN.md):

* A wholesale order is a **custom post type, not a WooCommerce order** — stock is **never** reduced and no `shop_order` is created.
* **No payment and no shipping** — no checkout, no billing/shipping addresses, no payment method. The order's note field is the customer↔seller channel; the emails handle the rest.

== Installation ==

1. Plugins → Add New → Upload Plugin → choose `epic-wholesale-orders.zip` → Install Now → Activate.
2. Go to **WooCommerce → Wholesale Orders**, add the wholesale customers, and set a long random shared secret. Copy the same value into the website's `EPIC_WHOLESALE_ORDERS_SHARED_SECRET` environment variable and redeploy the site.
3. Open each product you want to offer wholesale: enable the **"Wholesale"** box and set the wholesale price (variable products: per variation).
4. Go to **WooCommerce → Settings → Emails** and confirm/adjust the two notifications (**EPIC: Wholesale Order** and **EPIC: Wholesale Order — Customer**). Both are on by default. An SMTP plugin (WP Mail SMTP, FluentSMTP, …) is strongly recommended, same as every other EPIC plugin.
5. Have a whitelisted user submit a test order from the wholesale page and confirm it appears under **WooCommerce → Wholesale Orders** with email statuses of "Sent".

== Frequently Asked Questions ==

= Why doesn't a wholesale order reduce stock? =

Because wholesale orders are agreements, not fulfillment records — the seller handles fulfillment separately. The plugin deliberately never creates a WooCommerce order and never calls WooCommerce's stock hooks.

= Can anyone see wholesale prices? =

No. Only whitelisted customers, and only on the dedicated wholesale page. Nothing on the regular catalog/cart shows wholesale prices.

= What happens when I cancel an order? =

You must enter a reason; the order status becomes *Cancelled* and payment status auto-sets to *Canceled*.

== Changelog ==

= 1.0.0 =
* Initial release: wholesale-customer whitelist, per-product (and per-variation) wholesale prices, wholesale order CPT (pending/done/cancelled), payment status (WAITING_FOR_PAYMENT/PAID/PENDING/CANCELED) with the order→payment auto-set workflow, shared-secret REST API (products / submit / history), and seller + customer notification emails.
