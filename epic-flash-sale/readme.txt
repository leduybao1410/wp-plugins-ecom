=== EPIC Flash Sale ===
Contributors: epicroastery
Requires at least: 6.5
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.0.0

Ends a limited-quantity flash sale on a product variation automatically.

== Description ==

Runs a "first N units at the sale price, then back to the regular price"
promotion without any wp-admin step at the end.

A variation opts in with two meta keys:

* `_epic_flash_sale_qty` — units in the promotion (e.g. `10`)
* `_epic_flash_sale_regular_price` — price to restore afterwards (e.g. `50000`)

While stock remains, WooCommerce's normal sale price applies. When the last
promoted unit is sold, the plugin clears the sale price, restores the regular
price, turns stock management off and leaves the variation in stock, so it
keeps selling at its normal price. Both meta keys are deleted afterwards, so
the switch runs once per variation.

== Changelog ==

= 1.0.0 =
* First release: order-driven and stock-update-driven switch back to the
  regular price after the promoted quantity sells out.
