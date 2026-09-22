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
500–509, 515, 550 → 24 documented statuses (see
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
| `_vtp_cod_amount` | Cash to collect for goods |
| `_vtp_expected_delivery` | ETA string |

---

## 5. COD vs. prepaid

Decided strictly from `payment_method`: `sepay` = prepaid (no COD,
`ORDER_PAYMENT = 1`); anything else = COD (`ORDER_PAYMENT = 2` = goods + fee).
The COD amount is the **goods-only** portion (order total minus WooCommerce's
own shipping total), since ViettelPost separately collects its own shipping
fee from the recipient — mirroring the GHN plugin's double-charge avoidance.

---

## 6. Webhook

`POST /wp-json/epic-vtp/v1/webhook`. If a secret is configured, the
`Authorization` header must match it (constant-time compare). The handler is
idempotent: it updates `_vtp_shipment_status` / `_vtp_last_synced_at` /
`_vtp_expected_delivery` / `_vtp_fee`, notes only genuine status changes, and
always returns HTTP 200 so ViettelPost stops retrying. Auto-completing the
WooCommerce order on delivery is opt-in via the
`epic_vtp_auto_complete_on_delivered` filter.

---

## 7. Next phases

1. **Bundling** — combine N orders into one parcel (the `wp_epic_vtp_bundles`
   table already exists), reusing `Epic_VTP_Ajax::book_single_order()`'s
   economics with a review/confirm screen, as in the GHN plugin.
2. **Shipments dashboard** — a WooCommerce submenu listing every booking with
   filters and bulk label printing.
3. **Old-format (pre-merger) address mode** — a setting to book via
   `createOrder` (ID-based) when a store prefers exact codes.
