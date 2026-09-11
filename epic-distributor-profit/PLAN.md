# PLAN: EPIC Distributor Profit

Status: **v1 scope locked — building now.**

## 1. What this is

A self-contained WordPress/WooCommerce plugin, `epic-distributor-profit`, that gives admin a place to
track **cost, revenue, profit, and distributor commission** per sale — automating the manual "BÁO CÁO BH"
Excel workflow's order-ledger half, and adding a distributor concept Excel never had.

Not the same thing as `epic-wholesale-orders` (that's a customer-facing wholesale *ordering* channel).
This is an **internal admin ledger** — staff only.

## 2. Decisions locked in from the clarifying Q&A

| Question | Decision |
|---|---|
| Where it lives | New WooCommerce plugin, `epic-distributor-profit` — menu **WooCommerce → Distributor Profit** (+ **WooCommerce → Distributors**) |
| Sales data source | **Hybrid.** "Import from Order" pulls a real WooCommerce order (revenue/product/qty auto-filled); manual entry covers off-platform sales (Shopee, offline) |
| "Type" field | **Sales channel** (Shopee / Web / Wholesale / Offline / …, free text) |
| Distributor scope | One distributor per entry |
| Distributor records | Full CRUD — name, contact, commission %, active/inactive |
| Commission formula | `commission = (revenue − cost − shipping − other cost) × distributor commission %` — taken from gross profit |
| Access | Admin/staff only (`manage_woocommerce`). No distributor login in v1; `distributor_id` fk keeps a future portal possible without a schema change |
| Time filtering | Month dropdown **+** custom start/end date range |
| **Cost source (v1, revised)** | **No product-cost master.** Cost is a plain manual field on each entry — the GIÁ VỐN tab/breakdown is explicitly **out of scope for now** |
| **Historical import (v1, revised)** | **T08 (Shopee tab) is excluded** — the user isn't 100% sure of those numbers yet, so it's deferred rather than imported wrong. **T09 is imported**: its one combined BRAZIL+PERU order becomes a single entry under a new **"Legacy / Unassigned"** distributor at **0% commission** |
| Revenue definition (assumed) | THỰC NHẬN (net received after platform fees) — matches what the Excel itself calls "profit" against. Used for the T09 import. If this later turns out wrong, T08 can be revisited then too |

## 3. Data model (custom tables, via `dbDelta`) — v1, no cost master

**`epic_distributor_profit_distributors`**
id, name, contact (free text), commission_percent (decimal), active (bool), created_at, notes.

**`epic_distributor_profit_entries`** (the ledger — one row per sale)
id, entry_date, wc_order_id (nullable — set when "Import from Order" was used), order_code (free text,
e.g. a Shopee code or WC order number), channel (free text), distributor_id (fk), product_name (free text —
can list multiple products, e.g. "BRAZIL, PERU"), quantity (int, informational, nullable for multi-product
rows), cost (decimal, manual), revenue (decimal), shipping_cost (decimal), other_cost_label (text, nullable),
other_cost_amount (decimal, nullable), gross_profit (computed), commission_percent_snapshot (copied from
the distributor at entry time so a later rate change doesn't rewrite history), commission_amount (computed),
net_profit (computed), created_by, created_at, updated_at.

## 4. Admin screens

- **WooCommerce → Distributors** — list table (name, commission %, active, entry count) + add/edit form.
  Built with `table-layout: auto` CSS from the start (the `epic-wholesale-inquiries` layout bug came from
  WP_List_Table's forced `fixed` layout fighting a `width: 1%` rule — avoiding that pattern here entirely).
- **WooCommerce → Distributor Profit** (main ledger):
  - Filters: month dropdown, custom date range, distributor, channel.
  - Columns: date, order code/#, product, channel, qty, revenue, cost, shipping, other cost, gross profit,
    distributor, commission %, commission amount, net profit.
  - Totals row across the filtered set.
  - "Add Entry" → **Import from Order** (AJAX search of real WooCommerce orders by number/date, auto-fills
    product/qty/revenue/shipping; admin still enters cost, picks channel + distributor) or **Manual Entry**
    (every field typed).
  - **Export CSV / Export XLSX** on the filtered view (hand-built OOXML writer, same pattern as
    `epic-newsletter-subscription` — no PhpSpreadsheet dependency).

## 5. Historical import (T09 only, v1)

One-time admin-triggered import (button on the Distributor Profit screen) that:
1. Reads an uploaded copy of `BÁO CÁO BH.xlsx`, T09 tab only (a minimal PHP `.xlsx` reader via
   `ZipArchive` + `SimpleXML` — no external library, matching this project's existing no-dependency habit).
2. Creates "Legacy / Unassigned" (0% commission) if it doesn't already exist.
3. Creates one entry: date = T09's month, order_code "13G--57-A6", product_name "BRAZIL, PERU",
   channel "Web", revenue 301100 (THỰC NHẬN), cost 258000, shipping_cost 33000, gross_profit 43100.
4. T08 is **not** touched by this importer in v1 — re-add it later once the revenue-definition question
   is fully resolved.

## 6. Formulas

```
gross_profit       = revenue − cost − shipping_cost − other_cost_amount
commission_amount  = gross_profit × (distributor.commission_percent / 100)
net_profit         = gross_profit − commission_amount
```

## 7. Verification plan

- `php -l` on every file (run in the cloud sandbox — no PHP CLI on this Mac).
- A standalone `$wpdb`-stub harness testing the math above against the real T09 numbers
  (334100 / 301100 / 258000 / 43100 / 33000) and a couple of synthetic commission-% cases.
- Delivered as files directly in `wordpress-plugins/epic-distributor-profit/` (built in place on this
  machine) plus a zip for easy upload to admin.epicroastery.coffee — install/activate and do the live
  smoke test there (add a real entry, import a real order, run the T09 import for real).

## 8. Deferred to a later round

- Product Cost master / GIÁ VỐN breakdown screen and auto-fill.
- T08 (Shopee) historical import.
- Distributor-facing login/portal.
