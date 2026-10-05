=== EPIC WooCommerce Product MCP ===
Contributors: epiccoffee
Tags: woocommerce, mcp, abilities, products, translations
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Reviewed WooCommerce product and variation editing through the authenticated WordPress MCP Adapter.

== Description ==

WooCommerce validates and saves product fields through its REST API v3 controllers. This plugin adds seven-language storefront copy, exact previews, revision checks and metadata-only audit records. It registers abilities for the existing MCP Adapter; it does not add a public write endpoint.

Abilities in the `epic-product-mcp` namespace:

* `search-products`, `get-product`
* `list-product-variations`, `get-product-variation`
* `preview-change`, `apply-change`
* `permanently-delete-variation`

New products default to draft. Product removal uses WooCommerce's Trash behavior. Routine variation removal sets status to draft; permanent removal is available only through the separately named ability and still requires a preview token.

Product translations are stored as `_epic_product_copy_vi`, `_epic_product_copy_en`, `_epic_product_copy_ru`, `_epic_product_copy_hi`, `_epic_product_copy_zh`, `_epic_product_copy_ko` and `_epic_product_copy_ja` metadata. Each value is an object containing any of `name`, `notes`, `excerpt` and `description`. A change to a text field requires that field in all seven locales in the same preview. Direct writes to these reserved keys through `meta_data` are rejected.

== Requirements and security ==

Requires WordPress 6.9+ with the Abilities API, WooCommerce and the WordPress MCP Adapter. Callers authenticate to the existing MCP endpoint with a WordPress Application Password and a user that has product-specific capabilities. Product fields are checked again by WooCommerce's own REST controller.

Every change must first use `preview-change`. The signed token is bound to the authenticated user, exact normalized payload, current record revision and a ten-minute expiry. Apply re-reads the record and rejects stale revisions. Tokens are one-use. A published product slug change is called out in the preview.

Audit entries record the WordPress actor ID, product ID, operation and changed field names. They do not contain product copy, field values, Application Passwords or other credentials.

This preview contract applies to these EPIC abilities. Other authenticated WordPress and WooCommerce APIs remain governed by their own permissions and do not require an EPIC preview token.

== Installation ==

1. Upload the `epic-product-mcp` directory (or its zip) to `/wp-content/plugins/` and activate it.
2. Ensure WooCommerce, the Abilities API and the MCP Adapter are active.
3. Connect Codex or OpenCode to `https://admin.epicroastery.coffee/wp-json/mcp/mcp-adapter-default-server` with a dedicated WordPress user and Application Password. Keep credentials in local environment configuration, not this repository.
4. Discover the abilities, then read a product before previewing any change.

== Changelog ==

= 1.0.1 =
* Ignore WooCommerce's randomized related product suggestions when checking reviewed product revisions.

= 1.0.0 =
* Initial release with reviewed product and variation changes, translation metadata and revision-bound previews.
