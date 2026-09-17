=== EPIC Product Cost ===
Contributors: epicroastery
Tags: woocommerce, cost, cogs, margin, distributor
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later

Per-product cost (GIÁ VỐN) master, tracked over time, that other EPIC plugins can read.

== Description ==

Adds **WooCommerce → Product Cost**: one row per coffee, with a "current cost (250g)" and a batch
form to apply a new cost — as of a chosen date — to any products that changed. 500g and 1kg cost are
calculated automatically as 2× and 4× the 250g cost (cost scales with the weight of beans in the
bag; retail price does not, since larger sizes get a bulk discount — the two are deliberately kept
independent).

Cost is never overwritten in place. Every change is a new history row keyed by effective date, so:

* Today's cost lookups use whatever is effective on or before today.
* A ledger entry for a sale from three months ago (see EPIC Distributor Profit) uses the cost that
  was actually true three months ago, even after the cost has since changed.

A "Load starter costs" button seeds the 13 products that had a matching row in the cost sheet the
site owner supplied when this plugin was built (2026-09-12) — safe to leave visible indefinitely, it
only ever fills in a product that has no cost recorded yet.

= Integration =

This plugin has no required dependents. If **EPIC Distributor Profit** is also active, its "Import
from Order" picker calls `Epic_Product_Cost::estimate_order_cost( $order )` to auto-fill the ledger's
Cost field (still editable) — cost is looked up as of the order's own date, and scaled per line item
by the pack size actually purchased (250g/500g/1kg, from the `Trọng lượng` variation attribute; a
Simple product is assumed 250g, matching this catalog's three newest coffees).

= REST API + MCP =

Registers a REST API under `wp-json/epic-product-cost/v1` (list/search products, get/set a
product's cost + history, delete a history entry), authenticated with WordPress's own Application
Passwords — no custom API key to manage. A companion Node.js MCP server in `mcp-server/` wraps this
API as tools for any MCP client (e.g. Claude Desktop), so cost can be read and edited from a chat.
See `mcp-server/README.md` for setup. The `mcp-server/` folder is excluded from this plugin's
`.zip` — it's a separate local tool, not something WordPress needs to load.

== Changelog ==

= 1.1.0 =
* Added the `epic-product-cost/v1` REST API (list/search products, get/set cost, delete a history
  entry), authenticated via WordPress Application Passwords.
* Added a companion MCP server (`mcp-server/`) so cost can be read/edited from a chat instead of
  wp-admin.

= 1.0.0 =
* Initial build: Product Cost screen (batch entry + full history + delete), starter-cost seeder,
  and the `Epic_Product_Cost` facade for other EPIC plugins to read cost estimates from.
