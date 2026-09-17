=== EPIC Distributor Profit ===
Contributors: epicroastery
Tags: woocommerce, distributor, commission, profit, reporting
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later

Internal admin ledger for tracking cost, revenue, profit and distributor commission per sale.

== Description ==

Adds two screens under WooCommerce:

* **Distributors** — name, contact, commission %, active/inactive.
* **Distributor Profit** — the ledger. Add an entry by importing a real WooCommerce order (auto-fills
  product, quantity, revenue, shipping, and — if the EPIC Product Cost plugin is active — an
  estimated cost) or by typing one in manually for off-platform sales (Shopee, offline). Filter by
  month or a custom date range, by distributor, or by channel. Export the filtered view to CSV or
  XLSX. A one-time historical importer brings in the T09 tab of the old "BÁO CÁO BH.xlsx" report as a
  starting point.

Gross profit = revenue − cost − shipping − other cost.
Commission = gross profit × the distributor's commission %.
Net profit = gross profit − commission.

v1 scope deliberately excluded a maintained product-cost master — cost was a manual field per entry.
As of 1.1.0 that master exists as a separate plugin, **EPIC Product Cost**; when it's active this
plugin's Cost field auto-fills from it (still editable) but nothing here requires it. The T08 (Shopee)
historical import and any distributor-facing login remain out of scope. See PLAN.md for the full list
of decisions and what's deferred.

== Changelog ==

= 1.1.0 =
* "Import from Order" now auto-fills the Cost field (still editable) when the EPIC Product Cost
  plugin is active, using its per-product cost history as of the order's own date. No hard
  dependency — everything works exactly as before if that plugin isn't installed.

= 1.0.0 =
* Initial build: Distributors CRUD, Distributor Profit ledger (import-from-order + manual entry,
  filters, totals, CSV/XLSX export), T09 historical importer.
