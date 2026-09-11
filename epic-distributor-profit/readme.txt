=== EPIC Distributor Profit ===
Contributors: epicroastery
Tags: woocommerce, distributor, commission, profit, reporting
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Internal admin ledger for tracking cost, revenue, profit and distributor commission per sale.

== Description ==

Adds two screens under WooCommerce:

* **Distributors** — name, contact, commission %, active/inactive.
* **Distributor Profit** — the ledger. Add an entry by importing a real WooCommerce order (auto-fills
  product, quantity, revenue, shipping) or by typing one in manually for off-platform sales (Shopee,
  offline). Filter by month or a custom date range, by distributor, or by channel. Export the filtered
  view to CSV or XLSX. A one-time historical importer brings in the T09 tab of the old "BÁO CÁO BH.xlsx"
  report as a starting point.

Gross profit = revenue − cost − shipping − other cost.
Commission = gross profit × the distributor's commission %.
Net profit = gross profit − commission.

v1 scope deliberately excludes: a maintained product-cost master (cost is a manual field per entry),
the T08 (Shopee) historical import, and any distributor-facing login. See PLAN.md for the full list of
decisions and what's deferred.

== Changelog ==

= 1.0.0 =
* Initial build: Distributors CRUD, Distributor Profit ledger (import-from-order + manual entry,
  filters, totals, CSV/XLSX export), T09 historical importer.
