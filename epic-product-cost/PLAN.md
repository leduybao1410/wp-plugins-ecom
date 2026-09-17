# PLAN: EPIC Product Cost

Status: **v1 built.**

## 1. What this is

The deferred "Product Cost master / GIÁ VỐN breakdown screen and auto-fill" item from
`epic-distributor-profit`'s PLAN.md (section 8). A standalone plugin so it's useful on its own
(cost/margin visibility) and optional for Distributor Profit to consume — no hard dependency either
way.

## 2. Decisions locked in from the clarifying Q&A (2026-09-12)

| Question | Decision |
|---|---|
| Cost granularity | **250g only, for now.** 500g/1kg cost = 2×/4× the 250g cost, computed on the fly — not stored separately. Matches the user's own framing: "apply 250g only for now, for large size quantities, just multiply it according to the size." |
| Screen | **New bulk admin screen**, WooCommerce → Product Cost — one table, all products, batch-editable, matching how the user already updates the GIÁ WEB price sheet (paste a table of numbers, apply them all at once) rather than editing one product at a time. |
| Wire into Distributor Profit | **Yes, now.** "Import from Order" auto-fills the ledger's Cost field from this data (still editable) instead of leaving it always-manual. |
| Cost changes over time | **Full history, not a single current value** (added after the first two answers, before building): a `product_id + effective_date → cost` table. Every write is a new dated row, never an overwrite of the previous value. Lookups take an "as of" date, defaulting to today for the admin screen and to the *order's own date* for Distributor Profit's estimate — so a cost change made today doesn't silently rewrite the profit math on a sale from three months ago. |

## 3. Data model

**`epic_product_cost_history`** (one row per cost change, not one row per product)
`id, product_id (WC parent product id), cost_250g, effective_date (DATE), note, created_by,
created_at, updated_at`. `UNIQUE (product_id, effective_date)` — re-saving the same date corrects
that entry in place rather than creating a duplicate; a *different* date always adds a new row and
keeps the old one.

"Cost as of date D" = the row with the latest `effective_date <= D`. If D predates every recorded
row (e.g. Distributor Profit estimating a historical order from before cost tracking started), falls
back to the *earliest* row on file as a best-guess rather than returning nothing.

## 4. Admin screen (WooCommerce → Product Cost)

One row per WooCommerce product (Simple or Variable, any status — so a cost can be entered before a
new coffee goes live): current cost, its effective date, the calculated 500g/1kg cost, the live
250g reference price, a computed margin, an input for a new cost, and a link to that product's full
history (view + delete a mistaken entry).

The batch form has ONE effective-date field and ONE note field for the whole submission — every
non-blank cost input in that submit is recorded under that same date/note. Blank = no change for
that product. This matches the real workflow: the user has one cost sheet, dated once, covering many
products at a time.

A "Load starter costs" button seeds the 2026-09-12 cost sheet the user pasted in chat, matched to
live product IDs (see `class-seed.php`). It only fills products with zero history — safe to leave in
the UI forever, never overwrites anything a human already entered. One sheet row didn't match a live
product: **"Ethiopia Guji"** — the only live Ethiopia product on the site is Yirgacheffe (id 18,
attribute `Vùng trồng (Region)` = Yirgacheffe). Not seeded; add it manually (or as a new product)
if/when it becomes a real SKU.

## 5. Integration with EPIC Distributor Profit

No hard dependency in either direction. A thin static facade class, `Epic_Product_Cost` (defined
directly in the main plugin file, not `includes/`, so `class_exists()` is reliable regardless of
plugin load order — both plugins load their own classes independently on `plugins_loaded`, which has
already fired for every active plugin by the time any AJAX/admin callback actually runs):

```php
if ( class_exists( 'Epic_Product_Cost' ) ) {
    $estimate = Epic_Product_Cost::estimate_order_cost( $order ); // ['total' => float, 'complete' => bool]
}
```

`epic-distributor-profit`'s `class-order-picker.php` calls this when formatting an order for the
"Import from Order" search results, and `assets/admin.js`'s `fillFromOrder()` now also sets `#cost`
(still a plain editable input — nothing about the manual-entry path changed). `complete: false` means
at least one line item's product has no cost data on file as of the order date; the estimate is still
filled in (better than nothing) but the admin should double-check it.

Per-line pack size comes from the order item's own weight attribute meta (`Trọng lượng` / anything
containing "lượng"/"trọng"/"weight" in the visible order-item meta, matching what already shows on
the order screen and emails per [[product_variations]]), parsed the same way the website's
`parseWeightLabelToGrams()` does. A Simple product (no variation) is assumed 250g — true for every
Simple product in this catalog today (Kenya/Peru Gesha/Colombia).

## 6. REST API + MCP server (v1.1.0, 2026-09-12)

The user asked to be able to edit cost through an API, specifically via MCP. Two clarifying
questions, both answered with the recommended option:

| Question | Decision |
|---|---|
| API auth | **WordPress Application Passwords** (core WP feature, Users → Profile → Application Passwords) — not a custom shared secret. The request just authenticates as a real wp-admin user; `permission_callback` checks that user's `manage_woocommerce` capability like any other authenticated REST call. Nothing to build or store on the WordPress side beyond the routes themselves. |
| Where the MCP server runs | **Locally on the user's Mac**, as a small Node.js process registered in Claude Desktop's config — not a hosted service. |

**REST API** (`includes/class-rest-api.php`, namespace `epic-product-cost/v1`):
- `GET /products` — every tracked product + current cost/effective date/reference price (same data
  as the admin screen).
- `GET /products/search?q=` — name search, for resolving a product without knowing its ID.
- `GET /costs/{product_id}` — current cost (calculated 500g/1kg too) + full history. Optional
  `?date=YYYY-MM-DD` for a historical "as of" lookup, same fallback rule as §3.
- `POST /costs/{product_id}` — body `{ cost_250g, effective_date?, note? }`. Same upsert-by-date
  semantics as the admin screen (`Epic_Product_Cost_Store::upsert_cost()` — no separate code path).
- `DELETE /costs/{product_id}/history/{entry_id}` — delete one history row (checks the entry
  actually belongs to that product_id before deleting, to avoid one product's request touching
  another's row by a guessed entry_id).

All five routes are thin wrappers around the exact same `Epic_Product_Cost_Store` methods the admin
screen uses — no parallel cost logic to keep in sync.

**MCP server** (`mcp-server/`, Node.js, `@modelcontextprotocol/sdk` + `zod`): five tools —
`list_products`, `search_products`, `get_product_cost`, `set_product_cost`,
`delete_cost_history_entry`. Every product-targeting tool accepts either `product_id` or
`product_name`; a name is resolved via `/products/search` and must match exactly one product (an
ambiguous name lists the matches and asks for an exact `product_id` instead of guessing). Config is
three env vars (`EPIC_SITE_URL`, `EPIC_WP_USERNAME`, `EPIC_WP_APP_PASSWORD`) — see
`mcp-server/README.md` for the full Claude Desktop setup. Deliberately excluded from this plugin's
`.zip` (WordPress doesn't need it) — it's a separate local tool that happens to live in the same
folder as the API it wraps.

**Verification:** a full end-to-end integration test (not just unit-level) — a mock HTTP server
standing in for the WordPress REST API (mirroring `class-rest-api.php`'s exact request/response
shapes), with the *real* `@modelcontextprotocol/sdk` `Client` spawning the *real* `server.js` as a
subprocess over stdio, exactly how Claude Desktop would. 17 checks covered: all 5 tools list
correctly; `list_products` reflects seeded vs. unset costs; name search and ambiguous-name handling;
`set_product_cost` then `get_product_cost` round-trips including the calculated 500g (2×) and 1kg
(4×) values; a historical `date` lookup before any record falls back to the earliest row (§3's rule);
re-saving the same `effective_date` corrects the existing row rather than duplicating it;
`delete_cost_history_entry` removes a row and a subsequent lookup reflects that; an unknown
`product_id` surfaces the API's 404 as a readable error. All passed. This tests the MCP layer and
the *shape* of the contract thoroughly; it does not touch the real WordPress site (no live
WordPress available in this environment) — the PHP side still needs the live smoke test in §7 below,
now including a real REST API round trip via `curl` or the MCP tools before trusting it for real
edits.

## 7. Verification plan (WordPress side)

- `php -l` on every file (cloud sandbox — no PHP CLI on this Mac).
- Manual cross-check of the starter-cost mapping against the live catalog (product IDs/names/prices
  pulled via the WooCommerce REST API) before writing `class-seed.php` — see readme/this file.
- Delivered as files directly in `wordpress-plugins/epic-product-cost/` plus a zip (excluding
  `mcp-server/`). Live smoke test on admin.epicroastery.coffee: activate, run "Load starter costs",
  enter/edit one cost by hand, check history + delete, then do one real "Import from Order" pick in
  Distributor Profit and confirm the Cost field auto-fills and the number is sane against the picked
  order's products/sizes. Separately: create a real Application Password, confirm `GET
  /wp-json/epic-product-cost/v1/products` returns real data over `curl`, then run the MCP server
  against the live site (not just the mock) and try one real `set_product_cost` end to end.

## 8. Deferred

- Per-size (not just 250g) cost entry, if the linear-scaling assumption ever stops being good enough
  for a specific product.
- Bulk CSV import of a cost sheet (today: type numbers into the batch table, or extend the seeder).
- Surfacing cost/margin on the product edit screen itself (today: WooCommerce → Product Cost only).
- Write-scoping the REST API/MCP tools to a role narrower than `manage_woocommerce`, if the site
  ever wants a distributor or bookkeeper to update cost without full WooCommerce admin access.
