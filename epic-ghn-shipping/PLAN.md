# EPIC GHN Shipping Manager — WordPress Plugin Plan

**Plugin slug:** `epic-ghn-shipping`
**Target:** WooCommerce (HPOS-compatible) + GHN (Giao Hàng Nhanh) v2 public API
**Scope (per your answers):** bundling multiple orders into one GHN shipment, *plus* a general GHN order-management screen (single-order booking, cancel, print label, status sync) for orders the storefront couldn't auto-book.

This plan assumes the plugin lives entirely in wp-admin, independent of the `website/` Next.js app. The Next.js checkout keeps auto-booking single-order shipments exactly as it does today (`src/app/api/checkout/route.ts`); this plugin is for everything that happens *after* an order lands in WooCommerce — combining orders, retrying failures, and tracking status.

---

## 1. How this avoids conflicting with the existing website flow

The website already writes `_ghn_order_code` / `_ghn_expected_delivery` meta onto any order it successfully auto-books, and sets status `on-hold` with a note when GHN booking fails. The plugin reuses those exact meta keys so both systems agree on "has this order shipped." Concretely:

- An order with `_ghn_order_code` already set is **shipped** — the plugin shows it read-only (tracking, status, print, cancel) and excludes it from bundle selection unless the tracking is first cancelled.
- An order with **no** `_ghn_order_code` is **bookable** — either individually (single "Ship via GHN" action) or as part of a bundle.
- This means the plugin never double-books a shipment the website already created.

---

## 2. File / folder structure

```
epic-ghn-shipping/
├── epic-ghn-shipping.php          # Main plugin file, header, bootstrap, HPOS compat declaration
├── includes/
│   ├── class-ghn-client.php       # Thin wrapper over GHN v2 REST API (mirrors src/lib/ghn.ts)
│   ├── class-settings.php         # WooCommerce Settings tab ("GHN Shipping")
│   ├── class-order-meta-box.php   # GHN box on single order edit screen
│   ├── class-orders-list.php      # Orders list column + bulk action + row action
│   ├── class-bundle.php           # Bundle domain logic (validation, aggregation, persistence)
│   ├── class-bundle-admin-page.php# "Review & confirm bundle" screen
│   ├── class-shipments-page.php   # "GHN Shipments" dashboard (WooCommerce submenu)
│   ├── class-webhook.php          # REST route consuming GHN's callback (id=47)
│   ├── class-ajax.php             # Nonce-protected AJAX endpoints backing the admin screens
│   └── class-install.php          # Custom table creation / upgrade routine
├── assets/
│   ├── admin.js                   # Bundle review screen, AJAX calls, address-mismatch UI
│   └── admin.css
├── languages/                     # en_US + vi (matches the site's existing EN/VI split)
└── readme.txt                     # Standard WP plugin readme (installable via Plugins > Upload)
```

**HPOS:** WooCommerce's order tables (`wp_wc_orders`) are the default in current WooCommerce versions. The plugin must declare compatibility explicitly:

```php
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables', __FILE__, true
        );
    }
} );
```
All order reads/writes go through `wc_get_order()` / `CRUD` meta methods, never raw `$wpdb` on `wp_postmeta`, so it works whether HPOS or legacy post-based orders are active.

---

## 3. Data model

### 3.1 Order meta (per `WC_Order`)

| Meta key | Set by | Meaning |
|---|---|---|
| `_ghn_order_code` | website *or* plugin | GHN tracking code for this order's shipment |
| `_ghn_expected_delivery` | website *or* plugin | ETA string from GHN |
| `_ghn_bundle_id` | plugin only | FK into the bundle table, if this order shipped as part of a bundle |
| `_ghn_shipment_status` | plugin (via webhook) | Last known GHN status string (`ready_to_pick`, `picking`, `delivering`, `delivered`, `return`, …) |
| `_ghn_last_synced_at` | plugin | Timestamp of last webhook/poll update |

### 3.2 New table: `wp_epic_ghn_bundles`

A bundle is one physical parcel covering N orders — it needs its own row because "the shipment" is a concept that outlives any single order.

```sql
CREATE TABLE {$prefix}epic_ghn_bundles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ghn_order_code VARCHAR(64) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'draft',   -- draft | booked | cancelled | failed
  order_ids TEXT NOT NULL,                        -- JSON array of WC order IDs
  recipient_name VARCHAR(191),
  recipient_phone VARCHAR(32),
  recipient_address TEXT,
  total_weight_g INT,
  package_length INT, package_width INT, package_height INT,
  items_subtotal BIGINT,                          -- VND, sum of all bundled orders' item subtotals
  shipping_fee BIGINT,                             -- the ONE combined GHN fee (see §6)
  cod_amount BIGINT,
  created_by BIGINT UNSIGNED,                      -- wp_users.ID of the staff member
  created_at DATETIME,
  updated_at DATETIME,
  error_message TEXT NULL
) ;
```

A custom table (not a CPT) is used because bundles are queried/filtered operationally (by status, date, staff), not authored as content — this matches how WooCommerce itself models things like webhooks and log entries.

---

## 4. Settings screen

New tab under **WooCommerce → Settings → GHN Shipping** (`class-settings.php`, `WC_Settings_Page`):

- **Environment** — Production / Sandbox toggle (swaps `GHN_API_BASE` between `online-gateway.ghn.vn` and `dev-online-gateway.ghn.vn`, matching what `src/lib/ghn.ts` already documents as prod vs. `5sao.ghn.dev` staging).
- **Token** / **Shop ID** — same GHN account as the website's `.env`, pasted here (per your answer, this plugin is self-contained and doesn't read the Next.js `.env`).
- **From address** (province/district/ward, name, phone) — the warehouse/pickup address GHN ships *from*. Cascading selects populated from `/master-data/province|district|ward`, same pattern as `src/app/api/ghn/*`.
- **Default service type** — defaults to `2` (standard), matches `STANDARD_SERVICE_TYPE_ID` in `ghn.ts`.
- **Default package dimensions** (L×W×H cm) and **default per-item weight fallback** (grams) — used only when a WooCommerce product has no `_weight` set, mirroring the site's 250 g fallback.
- **Webhook secret / signature check** (if GHN's callback supports one — verify against your dashboard once you register the callback URL; otherwise restrict by source IP or a shared query-string token).
- **Auto status sync** — on/off toggle for the webhook listener + a manual "Sync now" button that polls `/v2/shipping-order/detail` for any order/bundle still in-flight (fallback if webhook delivery is unreliable on your host).

---

## 5. Admin UI flows

### 5.1 Orders list (`class-orders-list.php`)

- New **GHN** column: shows tracking code + colored status badge, or a "Not booked" pill with a one-click **Ship** row action for unbooked orders.
- New **bulk action**: "Bundle & ship via GHN" — enabled when 2+ orders are checked. Selecting it does **not** book anything immediately; it redirects to the review screen (§5.2) with the selected order IDs in the query string, because bundling has consequences (COD reconciliation, address validation) that need a confirm step.
- Orders already shipped (`_ghn_order_code` set) are visually flagged and excluded from re-bundling; if staff selects one anyway, the bulk action shows a warning and drops it from the batch rather than failing silently.

### 5.2 Bundle review & confirm screen (`class-bundle-admin-page.php`)

A dedicated admin page (`admin.php?page=epic-ghn-bundle&orders=12,14,17`) that:

1. **Validates the group** — all selected orders must share the same recipient phone *and* the same GHN address codes (province/district/ward + street). If they don't match, the page blocks confirmation and lists exactly which orders disagree and how (e.g. "Order #1042 ward differs: Phường 3 vs Phường 4") — no silent best-guess merging. A manual override checkbox lets staff proceed anyway for known-good edge cases (e.g. minor text formatting difference in the same real address), logged as a note on every order in the bundle.
2. **Aggregates**: line items across all orders (grouped/summed by product), total weight (`Σ product weight × qty`, using each `WC_Product::get_weight()` with the settings fallback), and items subtotal.
3. **Calls `calculate_fee()` once** for the combined weight/address — this single fee is what actually gets charged, replacing the sum of each order's originally-quoted individual fee (see §6 for why).
4. Staff can override package L×W×H (defaults from settings, since a combined parcel is usually bigger than one bag) and add a note.
5. **Confirm** → server creates the bundle row (`draft`), calls `create_shipment()` once, then on success: writes `_ghn_order_code` / `_ghn_bundle_id` / `_ghn_shipment_status` onto every constituent order, adds an identical order note to each ("Shipped as part of bundle #{id}, GHN {code}, combined with orders #A, #B"), and reconciles each order's `shipping_lines` total per the rule in §6. On failure, the bundle row is marked `failed` with `error_message` and nothing is written to the orders — safe to retry.

### 5.3 Single order edit screen — GHN meta box (`class-order-meta-box.php`)

Registered via `add_meta_box` on the order edit screen (works for both HPOS and legacy screens via `woocommerce_page_wc-orders` / `shop_order`):

- If unbooked: address preview (pulled from the order's shipping fields, resolved against GHN province/district/ward by name match — flagging if no confident match, since GHN needs numeric codes not free text) + a **Ship via GHN** button that calls `calculate_fee()` then `create_shipment()` for this order alone.
- If booked: tracking code (linked to GHN tracking page), current status badge, expected delivery, **Print label** button (opens the A5/80×80/52×70 label URL via the `a5/gen-token` flow, §7), **Cancel shipment** button (confirms, then calls `/v2/switch-status/cancel`, clears the GHN meta, and — if this order was part of a bundle — walks the whole bundle: cancelling one order's shipment cancels the *entire* physical parcel, so the UI must say this plainly and offer to cancel the whole bundle rather than silently leaving sibling orders pointing at a dead tracking code).
- If part of a bundle: shows "Bundled with #A, #B, #C" with links, since editing this one order's shipping isn't meaningful in isolation.

### 5.4 GHN Shipments dashboard (`class-shipments-page.php`)

New WooCommerce submenu page listing every booking (single + bundled) as one operational queue: tracking code, order(s), recipient, status, COD amount, created date, with filters by status and a bulk "print labels" action (batches multiple `order_codes` into one `a5/gen-token` call, since that endpoint accepts an array). This is the screen staff live in day-to-day, separate from the one-off bundling workflow.

---

## 6. Bundling economics — the part that's easy to get wrong

The entire point of bundling is to charge **one** shipping fee for one physical parcel instead of N fees for N parcels. If the plugin just summed each order's already-stored `shipping_lines` total, it would overcharge the customer and misreport revenue. So:

- The bundle's `shipping_fee` is a **fresh** `calculate_fee()` call against the combined weight/address — not a sum of the individual orders' original fees.
- On confirm, each constituent order's WooCommerce `shipping_lines` total is rewritten: the *first* order in the bundle carries the real combined fee, the rest are zeroed out with a line-item note "shipping included in bundle #{id}, see order #{first order}." This keeps `Σ order totals` in WooCommerce reports accurate and avoids duplicate revenue counting, while still being auditable back to one order.
- `cod_amount` sent to GHN = `Σ(all orders' item subtotals) + the one combined shipping fee`. This is the actual cash the courier will collect on delivery.
- All of this is written to an order note on every order in the bundle at confirm time, so the paper trail is visible in wp-admin without needing to open the bundle record.

This is a judgment call worth you sanity-checking against how your bookkeeping actually wants bundled shipping revenue attributed — flag if you'd rather split the fee proportionally across orders instead of assigning it to one.

---

## 7. GHN API endpoint map (verified against `api.ghn.vn/home/docs/detail`)

| Purpose | Method & path | Used by |
|---|---|---|
| Provinces/districts/wards | `POST /master-data/{province,district,ward}` | Settings (from-address picker), bundle address validation |
| Calculate fee | `POST /v2/shipping-order/fee` | Bundle review screen, single-order booking |
| Create shipment | `POST /v2/shipping-order/create` | Bundle confirm, single-order booking |
| Order detail / status | `POST /v2/shipping-order/detail` | Manual "Sync now", shipments dashboard refresh |
| Update COD | `POST /v2/shipping-order/updateCOD` | If a bundle's COD needs correcting post-booking (rare, e.g. staff-side price fix) |
| Cancel order | `POST /v2/switch-status/cancel` (`order_codes: []`) | Cancel button (meta box + shipments dashboard) |
| Generate print token | `POST /v2/a5/gen-token` (`order_codes: []`, token expires 30 min) | Print label button — token is then appended as a query param to GHN's A5/80×80/52×70 print URL, opened in a new tab |
| Status webhook (inbound) | GHN POSTs to a URL you register with GHN support, per env (staging/prod) | `class-webhook.php` registers `register_rest_route('epic-ghn/v1', '/webhook', ...)`, verifies the request, updates `_ghn_shipment_status` / bundle status, and — for terminal statuses (`delivered`, `return`) — transitions the WooCommerce order status and adds a note |

Webhook payload includes `OrderCode`, `Status`, `CODAmount`, `Description`, `Reason`, a status `log` history, and message `Type` (`Create`, `Switch_status`, `Update_weight`, `Update_cod`, `Update_fee`) — the handler switches on `Type`/`Status` rather than assuming every callback is a terminal delivery event. Because GHN retries failed callbacks 10× at 5s intervals, the handler must be idempotent (safe to process the same `OrderCode`+`Status` twice) and always return HTTP 200 once processed, even if the "update" turns out to be a no-op.

---

## 8. Error handling & resilience

- Every GHN/WooCommerce call goes through a single logger (`WC_Logger`, source `epic-ghn`) so failures are visible under **WooCommerce → Status → Logs** without needing server SSH access.
- Bundle creation is two-phase: the DB row is written as `draft` *before* calling GHN, flipped to `booked` only after a successful response, and to `failed` (with the raw error message) otherwise — so a page reload or timeout never leaves ambiguous state, and failed bundles can be edited and retried from the same screen rather than starting over.
- Network/API failures never change an order's shipping/COD data speculatively — writes to order meta only happen after GHN confirms success.

## 9. Security & permissions

- All admin screens/actions gated behind `manage_woocommerce` capability (same as native WooCommerce order management).
- AJAX endpoints use `check_ajax_referer` nonces scoped per-screen.
- GHN token/shop ID stored via `WC_Settings` (standard `wp_options`, no plaintext in code); recommend restricting the settings page further to `administrator` only, since this token can create real COD shipments.
- Webhook route validates payload shape and (if GHN supports it for your account) a signature/secret before trusting any status change.

## 10. i18n

Plugin text domain `epic-ghn-shipping`, `languages/epic-ghn-shipping-vi.mo` shipped alongside `en_US`, matching the site's existing EN/VI split — since your staff likely operate the WordPress admin in Vietnamese.

## 11. Rollout plan

1. **Phase 1** — settings screen, GHN client class, single-order meta box (ship/cancel/print/status for currently-unbooked or `on-hold` orders). This alone fixes today's "GHN booking failed, flagged on-hold" gap.
2. **Phase 2** — bundling (orders list bulk action, review screen, bundle table, economics reconciliation from §6).
3. **Phase 3** — GHN Shipments dashboard + webhook status sync, so tracking stays current without manual polling.
4. **Phase 4 (optional)** — bulk label printing, proportional-fee-split option if you decide against the "first order carries the fee" convention in §6.

## 12. Open questions for you before build starts

- **From-address**: what warehouse/pickup address should ship from (needed for the settings screen and for every fee/shipment call)?
- **Bundle fee attribution**: assign the combined shipping fee to the first order in the bundle (as planned in §6), or split proportionally across all bundled orders?
- **Webhook reachability**: is the WordPress site's URL publicly reachable over HTTPS for GHN to POST callbacks to, or should Phase 3 rely on polling (`Sync now` / a WP-Cron job hitting `/v2/shipping-order/detail`) instead?
- **Package dimensions default**: reasonable default L×W×H for a single coffee bag box, so the settings screen ships with a sane default rather than 0s.

---

Once you confirm the open questions in §12 (or tell me to just pick sensible defaults), I'll build Phase 1 as an installable `epic-ghn-shipping.zip` you can upload directly in **Plugins → Add New → Upload Plugin**.
