=== EPIC Order Codes ===
Contributors: epicroastery
Tags: woocommerce, order number, order id, sequential, security
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 8.9
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Replaces WooCommerce's sequential, guessable order numbers (#67, #68, ...) with short, unguessable letter+digit codes (EPIC-7K3M9X).

== Description ==

WooCommerce numbers orders with the sequential database ID, so `#67` tells anyone that `#66` and `#68` exist and roughly when they were placed — easy to scan, enumerate, or guess. This plugin replaces the displayed order number with a short code that mixes letters and digits and cannot be predicted from other orders' codes.

**How it works**

* The code is derived from the order ID by a **keyed Feistel cipher** (8 rounds, HMAC-SHA256 round function, 30-bit space → 6 symbols from a 32-character alphabet). It is a bijection: every order gets exactly one code and no two orders ever collide.
* Consecutive order IDs produce completely unrelated-looking codes (67 → `EPIC-ZPXE57`, 68 → `EPIC-QLHU6B`), so you can't scan forward or backward from one order to the next.
* The alphabet excludes visually ambiguous characters (`0/O`, `1/I`) so codes are safe to read aloud and type from a printed slip.
* **Reversible with the site's key** — the plugin can decode any code back to the order ID, which is how the admin search box finds orders.

**What changes**

* **Everywhere an order number is shown** — admin order lists, order edit screens, emails (including `epic-order-emails`), and order notes. This is done via WooCommerce's native `woocommerce_order_number` filter, so the code appears automatically wherever `get_order_number()` is used.
* **The Next.js website needs no changes.** The storefront reads `order.number` from the WooCommerce REST API, which returns `get_order_number()` — so the success page, SePay flow, and receipts all show the new code.
* **Shipping is unaffected.** GHN single-shipment bookings send the numeric order ID (`client_order_code`), and bundles use `BUNDLE-<id>`; neither uses the displayed number.
* **No data migration.** Because the code is deterministic from the ID, every existing order immediately has a correct, stable code.

**Admin search by code**

Typing a full code (e.g. `EPIC-7K3M9X`) into the WooCommerce → Orders search box finds the order on both storage backends — legacy posts and Custom Order Tables. Only terms starting with `EPIC-` are treated as codes, so ordinary searches (customer names, emails) are never hijacked.

**Customer order lookup (/track page)**

The website's `/track` page lets a customer enter their order code and see their order's status plus GHN shipping info (tracking code, tracking link, expected delivery, and COD amount due). The page's server proxy calls this plugin's `epic-order-codes/v1/lookup` REST route, gated by a shared secret configured under **WooCommerce → Order Codes** (mirror the same value into the website's `EPIC_ORDER_CODES_SHARED_SECRET` env var). The endpoint returns **only** status + shipping info — never the customer's name, address, phone, email, or line items.

**Security key**

The cipher is keyed by a per-site secret:

* Auto-generated and stored in `wp_options` on first load, or
* Set a fixed value via `define( 'EPIC_ORDER_CODES_KEY', '...' )` in `wp-config.php`.

Keep the key stable: changing it (or deleting the option) re-issues every order's code. Anyone with the key can decode any code back to an order ID; without it the code is one-way.

== Installation ==

1. Plugins → Add New → Upload Plugin → choose `epic-order-codes.zip` → Install Now → Activate.
2. That's it. Every order number across the store — admin, emails, the website — now shows the new format.

Optional hardening:

3. Optionally set `define( 'EPIC_ORDER_CODES_KEY', '<long random string>' )` in `wp-config.php` before going live, so the cipher key isn't stored in the database. If you change this after activation, all previously-shown codes change.

== Changelog ==

= 1.1.0 =
* New `epic-order-codes/v1/lookup` REST route (GET, shared-secret gated): decodes a code to its order and returns status + GHN tracking info only — never customer PII or line items. Backs the website's new `/track` order-lookup page.
* New settings page under WooCommerce → Order Codes for the shared secret (mirrors the website's `EPIC_ORDER_CODES_SHARED_SECRET` env var).
* Requires the Next.js site to add `EPIC_ORDER_CODES_SHARED_SECRET` (see website/.env.example).

= 1.0.0 =
* Initial release: `Epic_Order_Code` class implementing a keyed 8-round Feistel cipher (30-bit space, 32-symbol unambiguous alphabet) rendered as `EPIC-XXXXXX`.
* Hooks `woocommerce_order_number` so the code appears everywhere `get_order_number()` is used — admin, emails, and the storefront's REST API reads.
* Admin search-by-code on both legacy posts (`pre_get_posts`) and Custom Order Tables (`woocommerce_order_list_table_prepare_items_query_args`) backends, guarded to only decode terms prefixed with `EPIC-`.
* Per-site cipher key auto-generated and persisted on first load, overridable via `EPIC_ORDER_CODES_KEY`.
* Declares Custom Order Tables compatibility.
