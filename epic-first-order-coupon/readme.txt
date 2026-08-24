=== EPIC First Order Coupon ===
Contributors: epicroastery
Tags: woocommerce, coupons, discounts
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

== Description ==

Adds one checkbox to the WooCommerce coupon editor's "Usage restriction"
tab: "First-time customers only". Turn it on for any coupon and that
coupon becomes valid only for a billing email address that has no prior
real order on this store — checked live as the customer fills in checkout,
and re-checked at the moment the order is submitted.

A "prior order" means a real one: Processing, Completed, On-hold, or
Refunded. Cancelled, Failed, and abandoned (Pending payment) orders are
ignored, so an abandoned checkout never burns a customer's first-order
discount.

This plugin only adds the restriction — it does not create the coupon
itself. Create the coupon normally in **Marketing → Coupons** (or
**WooCommerce → Coupons**), set the discount type/amount as usual (e.g.
10% off cart), and tick the new checkbox under Usage restriction.

= Setup =

1. Install and activate.
2. Go to **Marketing → Coupons → Add coupon**.
3. Set the coupon code (e.g. `WELCOME10`), discount type "Percentage
   discount", amount `10`.
4. Open the **Usage restriction** tab and check "First-time customers
   only".
5. Publish. No other configuration needed.

= Notes =

* Validation matches on billing email, not customer account — a guest
  checkout with an email that has a prior order is still blocked.
* If a customer applies the coupon before typing their email (e.g. on the
  cart page), it's allowed provisionally; it's re-validated as soon as an
  email is entered at checkout, and blocked at final order submission if
  that email turns out to have a prior order.
* Works alongside WooCommerce's own native restrictions (usage limit per
  user, minimum spend, product/category restrictions, expiry date, etc.) —
  this plugin only adds the first-order check on top.

== Changelog ==

= 1.0.0 =
* Initial release: first-time-customer-only coupon restriction.
