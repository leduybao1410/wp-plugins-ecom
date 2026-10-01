# EPIC ViettelPost Shipping Manager — WordPress Plugin Plan

**Plugin slug:** `epic-viettelpost-shipping`
**Target:** WooCommerce (HPOS-compatible) + ViettelPost Open API (`partner.viettelpost.vn`)
**Scope:** admin-side shipment management for orders that land in WooCommerce —
single-order booking, cancel, print label, status tracking — mirroring the EPIC
GHN Shipping Manager (`epic-ghn-shipping`) feature-for-feature.

---

## 1. API reference (verified 2026-09-22 against partner2.viettelpost.vn/overview)

**Base URLs:** production `https://partner.viettelpost.vn`, sandbox
`https://partnerdev.viettelpost.vn`.

**Auth:** every request carries a `Token` header. Tokens are generated in the
ViettelPost partner portal (viettelpost.vn → Cấu hình tài khoản → Thêm mới
token). A username/password fallback mints a short-lived token via
`POST /v2/user/Login`.

**Response envelope:** `{status, error, message, data}`. An error is signalled
by `error: true` (often on an HTTP 200), so the plugin checks the envelope
flag, not just the HTTP status.

| Purpose | Method & path |
|---|---|
| New-format provinces | `GET /v3/categories/listProvinceNew` |
| New-format wards | `GET /v3/categories/listWardsNew?provinceId=` |
| Old-format provinces / districts / wards | `GET /v2/categories/listProvince`, `/listDistrict?provinceId=`, `/listWards?districtId=` |
| Fee by address ID | `POST /v2/order/getPriceAll` |
| Fee by free-text address | `POST /v2/order/getPriceAllNlp` |
| Create order by ID | `POST /v2/order/createOrder` |
| Create order by address | `POST /v2/order/createOrderNlp` |
| Edit order | `POST /v2/order/edit` |
| Update/cancel status | `POST /v2/order/UpdateOrder` (`TYPE`: 1 approve, 2 approve return, 3 redeliver, 4 cancel, 5 delete) |
| Print token | `POST /v2/order/printing-code` (returns token in `message`) |
| Print link | `https://digitalize.viettelpost.vn/DigitalizePrint/report.do?type=1&bill={token}&showPostage=1` |
| Warehouses | `GET /v2/user/listInventory` |
| Webhook (inbound) | ViettelPost POSTs `{DATA:{…}, TOKEN}` with the configured SECRET in the `Authorization` header |

**Status codes** (numeric `ORDER_STATUS`): 101–107, 200–202, 300, 400,
500–509, 515, 550 → 23 documented statuses (see
`Epic_VTP_Client::status_label()` / `bucket_status()`).

---

## 2. Why the NLP ("address detail") flow

ViettelPost supports two address models: old (pre-merger, 3-level
province/district/ward IDs) and new (post-merger, 2-level province/ward IDs).
Rather than resolve every order to district/ward codes, the plugin books via
the **address-detail (NLP) endpoints** (`getPriceAllNlp` + `createOrderNlp`),
which need only the receiver's **province ID** plus the full free-text address
— ViettelPost geocodes the rest. That means:

- Address resolution is a single province-name match (accent- and
  prefix-insensitive), not a fragile 3-tier lookup.
- The storefront's existing free-text addresses work as-is.

The address-ID endpoints and old-format category lookups are still wrapped in
the client for a future phase / manual use.

---

## 3. File structure

```
epic-viettelpost-shipping/
├── epic-viettelpost-shipping.php   # Header, bootstrap, HPOS compat, lazy settings require
├── includes/
│   ├── class-vtp-client.php        # ViettelPost Open API wrapper
│   ├── class-settings.php          # WooCommerce Settings tab ("ViettelPost Shipping")
│   ├── class-assets.php            # Admin assets + province/ward picker markup
│   ├── class-address-resolver.php  # Order city/state → VTP province
│   ├── class-order-meta-box.php    # Shipment box on the order edit screen
│   ├── class-ajax.php              # Nonce-protected AJAX + shared booking core
│   ├── class-orders-list.php       # Orders list columns + bulk action
│   ├── class-webhook.php           # REST route consuming ViettelPost callbacks
│   └── class-install.php           # Options seeding + bundle table
├── assets/
│   ├── admin.js                    # Pickers, meta box actions, list row actions
│   └── admin.css
├── readme.txt
└── PLAN.md
```

---

## 4. Order meta (per `WC_Order`)

| Meta key | Meaning |
|---|---|
| `_vtp_order_number` | ViettelPost waybill number |
| `_vtp_province_id` / `_vtp_province_name` | Receiver province booked against |
| `_vtp_service` | Main service code (`MA_DV_CHINH`) |
| `_vtp_fee` | Quoted shipping fee |
| `_vtp_shipment_status` | Last known numeric `ORDER_STATUS` |
| `_vtp_last_synced_at` | Timestamp of last webhook update |
| `_vtp_cod_amount` | Full order total to collect on delivery (goods + shipping fee) |
| `_vtp_expected_delivery` | ETA string |

---

## 5. COD vs. prepaid

Decided strictly from `payment_method`: `sepay` = prepaid (no COD,
`ORDER_PAYMENT = 1`); anything else = COD (`ORDER_PAYMENT = 3` = collect goods
only). For COD the customer is charged exactly the WooCommerce **order total**
(`MONEY_COLLECTION = $order->get_total()`), which already includes the shipping
fee — so the amount shown at checkout is the amount collected at the door,
with no courier-recalculated fee added on top (that recomputation was the
source of the "collected amount ≠ displayed total" mismatch). Booking as
"collect goods only" makes ViettelPost bill its own shipping fee to the
**sender**, deducted from the COD remittance: the sender receives the order
total minus ViettelPost's fee, and the shipping component stays inside the
order price. For COD orders this also means a free-shipping order correctly
collects only the goods (the store absorbs the fee), rather than the recipient
being charged the courier's fee despite "free shipping".

**Invariant (prevents shipping being charged twice):** `MONEY_COLLECTION`
already contains the shipping fee, so `ORDER_PAYMENT` for COD must be `3`
("collect goods only"), **never `2`** ("goods + fee"). Pairing a
shipping-inclusive `MONEY_COLLECTION` with `2` makes ViettelPost add its fee on
top of the collection — the customer would pay shipping twice. Verified in the
Docker sandbox (`wp eval-file` matrix, mocked VTP API): normal / free-shipping
/ discounted COD orders all send `ORDER_PAYMENT = 3` and
`MONEY_COLLECTION = $order->get_total()` exactly.

---

## 6. Webhook

`POST /wp-json/epic-vtp/v1/webhook`. If a secret is configured, the
`Authorization` header must match it (constant-time compare). The handler is
idempotent: it updates `_vtp_shipment_status` / `_vtp_status_date` /
`_vtp_last_synced_at` / `_vtp_expected_delivery` / `_vtp_fee`, notes only
genuine status changes, and always returns HTTP 200 so ViettelPost stops
retrying. Out-of-order callbacks (older `ORDER_STATUSDATE` than the last
applied) are acknowledged but ignored. Every applied change fires
`do_action( 'epic_vtp_status_changed', $order, $status, $source )`.
Auto-completing the order on delivery is opt-in per the "Complete the order on
delivery" setting (or the legacy `epic_vtp_auto_complete_on_delivered` filter).
A return/delivery-issue status flags the order (`_vtp_needs_action`) and can
hold it (setting).

### No status-query API

ViettelPost publishes no order-status endpoint (verified against the partner
Open API route list). The webhook is the **only** inbound status channel, so:
- the order screen's "Set status" control is the manual fallback for a missed
  callback, and
- a daily WP-Cron scan (`Epic_VTP_Cron`) flags in-flight shipments with no
  webhook update within the configured window as "stale" on the dashboard.

---

## 7. Admin workflow features (0.1.6)

- **Shipments dashboard** (`WooCommerce → ViettelPost Shipments`):
  status/date filters, waybill search, "needs action"/"stale" badges, COD,
  quoted-vs-actual courier fee, bulk label printing, CSV export.
- **Bulk label printing** on the Orders list.
- **Label settings**: size (A5/A6/A7), show/hide postage.
- **Booking lock** prevents double-booking; a failed booking can hold the
  order; a transient cancel rejection is retried once.
- **Status emails**: `epic-order-emails` listens to
  `epic_vtp_status_changed` and sends the new "Order Delivered" email.

## 8. Next phases

1. **Bundling** — combine N orders into one parcel (the `wp_epic_vtp_bundles`
   table already exists), reusing `Epic_VTP_Ajax::book_single_order()`'s
   economics with a review/confirm screen, as in the GHN plugin.
2. **Old-format (pre-merger) address mode** — a setting to book via
   `createOrder` (ID-based) when a store prefers exact codes.
3. **Partial shipments / multi-parcel** — needs a repeatable order-meta model
   (today one `_vtp_order_number` per order).
4. **Pickup scheduling** — blocked: no ViettelPost pickup-booking API.
