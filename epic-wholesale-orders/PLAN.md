# PLAN: EPIC Wholesale Orders

Status: **Draft for review** — not yet implemented.

## 1. What this is

A self-contained WooCommerce plugin (plus a small amount of Next.js front-end) that lets the store run
**wholesale ordering** for whitelisted customers:

- Admin marks certain registered users as **wholesale customers** and sets a **wholesale price per product**.
- A whitelisted user gets a dedicated **wholesale ordering page** showing eligible products at their
  wholesale price, with a quantity-per-product form and a free-text note.
- Submitting creates a **wholesale order** record (NOT a WooCommerce order): it does **not** touch stock,
  there is **no payment**, and there is **no shipping**. It's an agreement/custom-note channel between
  customer and seller.
- Seller sees every order in wp-admin, gets a notification email; the customer gets a confirmation email
  and can see their order history on the site.

## 2. Decisions locked in from the clarifying Q&A

| Question | Decision |
|---|---|
| Where it lives | **New self-contained WooCommerce plugin** `epic-wholesale-orders`, matching the `epic-wholesale-inquiries` patterns |
| Pricing model | **Base wholesale price per product + price levels** — admin defines levels (name + % discount off the base price); each wholesale customer is assigned one level and the storefront shows prices discounted for their level |
| Eligible products | Admin **selects per product** (checkbox + price on the product edit screen) |
| User page | **Simple order form** — product list + quantity inputs + note. No cart, no checkout |
| Order record | **Custom post type** `epic_wholesale_order` |
| Whitelist | Admin **picks users** in the plugin's config screen (searchable) |
| Price visibility | Wholesale prices shown **only on the wholesale page** |
| After submit | Email admin **and** customer; order starts in order status `pending`; customer sees order history |
| Payment status | Admin-configurable per order: `WAITING_FOR_PAYMENT`, `PAID`, `PENDING`, `CANCELED` (offline/agreement tracking only — no online payment). Auto-set by the order-status workflow (see §5.3). |
| Order workflow | `pending` (on submit) → admin marks `done` ⇒ payment auto-set `WAITING_FOR_PAYMENT`; admin cancels/unapproves (note required) ⇒ payment auto-set `CANCELED` |

## 3. Architecture

The storefront is the headless **Next.js site** (`/Volumes/data/Work/EPIC/website`); WordPress/WooCommerce
is the backend. This mirrors the existing, proven split:

- **WordPress plugin** owns all data, admin screens, emails, and a shared-secret REST API
  (same pattern as `epic-wholesale-inquiries` and `epic-account-linking`).
- **Next.js** renders the wholesale page(s) inside the existing account area and calls the plugin's REST
  routes with an `X-Epic-Secret` header. The user is identified by `google_sub`/Zalo id from the verified
  session — the plugin maps that to a WooCommerce customer, exactly like `src/lib/account.ts` does.
- The plugin **never trusts a client-supplied identity or price** — it resolves the account server-side and
  recomputes every line total from the stored wholesale price.

### Why not a WordPress-rendered page?

The customer-facing storefront is Next.js (the `/wholesale` contact page, cart, and account area are all
there). A WP-rendered shortcode page would be a second, unintegrated front-end with no access to the
existing Google/Zalo session. Using the account-area + REST pattern keeps auth, styling, and i18n consistent.

## 4. Plugin layout

```
wordpress-plugins/epic-wholesale-orders/
├── epic-wholesale-orders.php                  # bootstrap: constants, HPOS compat, includes, hooks
├── readme.txt
├── includes/
│   ├── class-store.php                        # CPT registration + meta CRUD, whitelist helpers
│   ├── class-settings.php                     # WooCommerce → Wholesale Orders settings (whitelist picker)
│   ├── class-list-table.php                   # WP_List_Table for wholesale orders
│   ├── class-meta-box.php                     # order-detail metabox on the CPT (items/note/status)
│   ├── class-product-pricing.php              # product/variation "Wholesale" metabox
│   ├── class-rest-api.php                     # REST routes (products / submit / history)
│   ├── class-email-wholesale-order-admin.php      # WC_Email → seller
│   └── class-email-wholesale-order-customer.php   # WC_Email → customer
└── templates/emails/                          # html + plain templates (Vietnamese)
```

File structure, phpdoc style, `require_once`-inside-the-callback conventions, HPOS declaration block, and
"admin screens in English, email content in Vietnamese" all follow `epic-wholesale-inquiries` exactly.

## 5. Data model

### 5.1 Whitelist
- Option `epic_wholesale_orders_customers` = **array of WP user IDs** (`manage_options`-guarded).
- Helper: `Epic_Wholesale_Orders_Store::is_customer( $user_id )`.
- WooCommerce customers are WP users (role `customer`), so the same IDs work for the `epic-account-linking`
  mapping and for order attribution.

### 5.2 Wholesale price (product meta)
- `_epic_wholesale_enabled` = `yes`/`no` (checkbox on product edit screen).
- `_epic_wholesale_price` = float, empty when not set. This is the **base** wholesale price; a
  customer's effective price is `base × (1 − level_discount%)`.
- Metabox **"Wholesale"** on the product editor. Works on **simple products**; for **variable products**
  the same fields appear on each **variation** (meta lives on the variation). v1 keeps this to simple +
  variable; bundle/external product types are out of scope unless already in the catalog.

### 5.5 Price levels
- Option `epic_wholesale_orders_levels` = levels keyed by stable key (`level_1`, `level_2`, …), each
  `array( 'key', 'name', 'discount' )` where `discount` is a percentage (0–100) off the base wholesale
  price. Option `epic_wholesale_orders_default_level` holds the default level for new customers.
- Customer level = **user meta** `epic_wholesale_level` on each whitelisted WP user; missing/unknown →
  default level. Helper: `Epic_Wholesale_Orders_Store::get_customer_level()`.
- Effective price: `Epic_Wholesale_Product_Pricing::price_for_level( $id, $level_key )` =
  `base × (1 − discount/100)`. A level with 0% discount returns the base price, so a product with a base
  price is orderable at every level (fallback = base price).
- Migration: `ensure_levels()` on `plugins_loaded` creates the default level if the option is missing —
  existing products keep their base prices, existing customers fall back to the default level.
- Each order snapshots `_level_key` / `_level_name` / `_level_discount` so history shows which level
  applied; deleting a level never rewrites past orders.

### 5.3 Wholesale order (CPT `epic_wholesale_order`)
- `public => false`, `show_ui => true` under the WooCommerce menu, `manage_woocommerce` capability.
- **Post statuses** (custom): `pending` (default on submission), `done`, `cancelled`.
- **Meta** (snapshotted at submission so later price edits can't rewrite history):
  - `_customer_user_id` — WP user id
  - `_customer_name`, `_customer_email` — display snapshot
  - `_items` — serialized array: `[ { product_id, name, sku, qty, unit_price, line_total } ]`
  - `_note` — the customer/seller note (plain text, sanitized)
  - `_cancel_reason` — seller's reason, **required whenever the order is cancelled/unapproved**
  - `_total`
  - `_payment_status` — one of `WAITING_FOR_PAYMENT`, `PAID`, `PENDING`, `CANCELED`; defaults to
    `PENDING` on submission. Admin-tunable, but **auto-set by the order-status workflow** below
    (wholesale customers pay offline, so this tracks the agreement, never an online transaction).
  - `_admin_email_status`, `_customer_email_status` — `sent`/`failed`/`disabled`/`pending`
  - `_submitted_at`

**Order-status → payment-status workflow** (single source of truth, enforced in `class-meta-box.php`
on every metabox save, same logic reused by the REST layer):

| Order status change | Payment status result |
|---|---|
| (submission) | order `pending`, payment `PENDING` |
| → `done` | payment auto-set to `WAITING_FOR_PAYMENT` (admin then marks `PAID` once actually paid) |
| → `cancelled` / unapprove (must supply `_cancel_reason`) | payment auto-set to `CANCELED` |
| `done` → `pending` (undo) | payment left untouched |

Admin can still edit `_payment_status` directly (e.g. `WAITING_FOR_PAYMENT` → `PAID`) — the auto-set only
fires on the transitions above, it never overrides a manual `PAID`.

### 5.4 Stock / payment / shipping — explicit guarantees
- Creating a wholesale order **never calls `wc_reduce_stock_levels`** and never creates a `shop_order`, so
  no stock hooks fire and stock is untouched. Documented in the code.
- No billing/shipping address, no payment method, no shipping method anywhere in the form, CPT, or emails.
- Contact between the two sides is the order's note field + the email addresses on file.

## 6. Admin UI

### 6.1 `WooCommerce → Wholesale Orders`
- `WP_List_Table` (same class pattern as `Epic_Wholesale_List_Table`): columns **Order ref, Customer,
  Date, Items, Total, Payment, Status, Emails**, paginated, with a **fulfillment-status filter** and a
  **payment-status filter** dropdown (WAITING_FOR_PAYMENT / PAID / PENDING / CANCELED).
- Row actions: **View/Edit** (opens the CPT editor with the detail metabox) and **Delete** (nonce-checked).
- The detail metabox (`class-meta-box.php`) shows items (product, sku, qty, unit price, line total), the
  customer's note, and two selects: **order status** (`pending`/`done`/`cancelled` → post-status
  transition, with the payment auto-set rules from §5.3) and **payment status**
  (WAITING_FOR_PAYMENT / PAID / PENDING / CANCELED → saved to `_payment_status`). Cancelling/unapproving
  reveals a **required "Reason" textarea** that persists to `_cancel_reason` — the save is rejected if it's
  empty. (Optional v1.1: fire a customer "status updated" email on either transition.)

### 6.2 `WooCommerce → Wholesale Orders → Settings` (same screen)
- **Price levels**: create/rename/delete levels and set each level's discount % + the default level.
- **Wholesale customers** picker: searchable multi-select of users, reusing WooCommerce's
  `wc-enhanced-select` + `WC_AJAX::json_search_customers` (the same AJAX user search the order editor uses),
  plus a per-customer **level** dropdown (defaults to the default level). Saved via nonce-checked
  admin-post handlers.
- Informational text explaining how to set product prices and where the customer-facing page lives.

### 6.3 Product editor
- "Wholesale" metabox: **Enable wholesale pricing** checkbox + **Wholesale price** number input.
  Saved via the standard `woocommerce_process_product_meta` / variation save hooks.

## 7. REST API (`/wp-json/epic-wholesale-orders/v1/...`)

All routes require the `X-Epic-Secret` header (checked with `hash_equals`, like every other EPIC plugin)
and take the user identity from the verified session, never from the client alone:

| Route | Purpose |
|---|---|
| `GET /products?google_sub=…` | Eligible products for this user: id, name, sku, unit, wholesale price, image. `403` if the account isn't whitelisted. Variable products return their wholesale-enabled variations. |
| `POST /orders` | Body: `{ google_sub, items: [{product_id, qty}], note }`. Server validates whitelist, product eligibility, `qty > 0`; **recomputes all prices server-side**; creates the CPT with status `pending` and payment `PENDING`; fires `epic_wholesale_order_created`. Stock untouched. Returns the new order. |
| `GET /orders?google_sub=…` | The user's own order history (ref, date, order status, **payment status**, items, total, note). |

## 8. Emails (WC_Email, registered via `woocommerce_email_classes`)

Both follow the `epic-wholesale-inquiries` email conventions (settings labels English, content Vietnamese,
recipient editable under WooCommerce → Settings → Emails):

- **`EPIC: Wholesale Order`** (admin, `customer_email = false`) → seller on `epic_wholesale_order_created`.
- **`EPIC: Wholesale Order — Customer`** (customer) → buyer's confirmation with the line-item table, total,
  and their note. Recipient is the mapped account email.

Delivery outcome is reported back onto the CPT's `*_email_status` meta (same `sent/failed/disabled/pending`
behavior the inquiries plugin uses on its table).

## 9. Next.js front-end (website/)

| Piece | File |
|---|---|
| REST client | `src/lib/wholesaleOrders.ts` — same shape as `src/lib/account.ts`; new env `EPIC_WHOLESALE_ORDERS_SHARED_SECRET` |
| Page | `src/app/[lang]/account/wholesale/page.tsx` (server component; `readSessionCookie()`; redirects to sign-in) |
| Form component | `src/components/WholesaleOrderForm.tsx` — product list + `QuantityStepper` per product + note textarea + submit lock; then the order-history list with `OrderStatusBadge`-style statuses |
| Entry point | A "Wholesale" link/card on the account page, rendered **only when the whitelist check passes** (server-side) |
| i18n | Strings in `src/i18n/locales` for both `en` and `vi` |

Only a whitelisted, signed-in user can reach the page; a non-whitelisted user gets a "not available"
state (not a hint about the whitelist itself).

## 10. Open points to confirm at implementation time

1. **Variable products**: confirmed in scope for v1 (per-variation wholesale price), unless the catalog has
   none — then simple-products-only trims the work.
2. **Out-of-stock items**: wholesale ignores stock, so out-of-stock products remain orderable; the form
   shows current stock as *informational only*. Confirm this is acceptable.
3. **Status-change email** (v1.1): notify the customer when the seller confirms/cancels an order — not in
   v1 unless requested.
4. **Customer cancel**: v1 has no customer-side cancel; they'd message the seller in the note / contact them.
5. **Whitelist removal**: a removed user can no longer place orders but still sees past wholesale orders.
6. **Zalo accounts**: verify how a Zalo-linked account maps to a WP user id vs. Google `google_sub`, and
   generalize the identity parameter accordingly (probably add a `zalo_id`-equivalent in v1 REST).

## 11. Suggested build order

1. Plugin skeleton + CPT + whitelist option + `is_customer()` helper.
2. Product/variation wholesale metabox + price helpers.
3. Admin list table + order metabox + status transitions.
4. REST API (products / submit / history) + secret check.
5. Emails (admin + customer) wired to `epic_wholesale_order_created`.
6. Next.js: lib client, page, form component, account link, i18n.
7. End-to-end manual test + package `epic-wholesale-orders.zip`.

## 12. Verification checklist

- Place a wholesale order as a whitelisted user → CPT created with order status `pending` and payment
  `PENDING`, both emails send (or status shows `failed`/`disabled` correctly), **stock quantity unchanged**,
  no `shop_order` created.
- Non-whitelisted user → REST `403`, page shows "not available".
- Order workflow: admin sets status `done` → payment auto-flips to `WAITING_FOR_PAYMENT`; admin cancels →
  payment auto-flips to `CANCELED` and the save is rejected without a reason note; manual `PAID` is never
  overwritten by a later status edit. All visible in the list column, the filter, and the customer's history.
- Prices: admin edits wholesale price → new orders use the new price; existing orders keep their snapshot.
- Admin: filter by status, view/edit detail, transition status, delete.
- `wp-phpcs` (WordPress standard) passes on all new plugin files; `npm run lint` / typecheck pass on the
  Next.js side.
