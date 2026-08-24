# EPIC Order Emails — WordPress Plugin Plan

**Plugin slug:** `epic-order-emails`
**Target:** WooCommerce (HPOS-compatible), sits alongside `epic-ghn-shipping` and `epic-payment-store` as a third, independent plugin.
**Scope:** two customer-facing emails — (1) order received/created, (2) shipment booked with GHN tracking code — built as proper `WC_Email` subclasses so they behave like any other WooCommerce order email (toggle, subject/heading fields, resend button, theme template overrides).

This plan assumes no code is written yet. It exists to be reviewed before the plugin is built, the same way `epic-ghn-shipping/PLAN.md` was reviewed before that plugin was built.

---

## 1. Why a new plugin, not an addition to the existing two

- `epic-payment-store` is explicitly a payment-provider-agnostic handoff cache — its own docblock says "no WooCommerce order is ever created from data this plugin holds." Adding order-email logic there would blur that boundary.
- `epic-ghn-shipping` is shipping-specific; the "order created" email has nothing to do with GHN, and tangling it in there makes the plugin harder to reason about and disable independently.
- A dedicated plugin keeps both emails' lifecycle (enable/disable, uninstall) independent of shipping and payment concerns, and can be turned off entirely without touching either of the other two.

`epic-ghn-shipping` is still where the *trigger* for Email 2 lives (see §4) — this plugin only owns the email-sending code itself, called from a hook, not from being spliced into that plugin's files.

---

## 2. File / folder structure

```
epic-order-emails/
├── epic-order-emails.php              # Main plugin file, header, bootstrap, HPOS compat declaration
├── includes/
│   ├── class-email-order-created.php  # WC_Email subclass — "order received" customer email
│   └── class-email-order-shipped.php  # WC_Email subclass — "order shipped, tracking code" customer email
├── languages/                          # en_US + vi, matches the site's existing EN/VI split
└── readme.txt
```

No settings screen, no AJAX, no custom DB table — both classes are pure `WC_Email` subclasses, so WooCommerce's own Settings → Emails screen is the entire admin surface. That's the whole point of building on `WC_Email` instead of raw `wp_mail()`.

**HPOS:** declare `custom_order_tables` compatibility in `before_woocommerce_init`, same boilerplate as the other two plugins. Not that it matters much here — everything reads/writes through `WC_Order`'s own methods, never raw `$wpdb`.

---

## 3. Email 1 — "Order received" (order created successfully)

### 3.1 What already happens today, before writing any code

Orders are created by `website/src/app/api/checkout/route.ts` → `createOrder()` in `src/lib/woocommerce.ts`, which POSTs to `/wc/v3/orders` with `status: input.status ?? "processing"`. A brand-new `WC_Order`'s default status is `pending`, so setting it to `processing` at creation *is* a status transition (`pending` → `processing`) as far as WooCommerce's `status_transition()` internals are concerned — which means **WooCommerce's built-in "Processing order" customer email already fires today**, provided:

1. It's enabled under **WooCommerce → Settings → Emails → Processing order**, and
2. The order has a billing email (`customer.email` is an optional field in the checkout request body — COD/Vietnamese customers commonly skip it).

**First concrete step before building anything: check that setting.** If it's already enabled and the copy/branding is acceptable, Email 1 may need zero new code — just confirm SMTP delivery is configured (per the earlier SMTP question) so it actually leaves the server.

### 3.2 If the native email isn't sufficient

Register `Epic_Order_Emails_Created extends WC_Email`, added via the `woocommerce_email_classes` filter, hooked to the same transition WooCommerce's own class uses (`woocommerce_order_status_pending_to_processing`) — or more broadly `woocommerce_new_order` if you want it to fire regardless of what status the order lands in, not just `processing`. Recommend the transition hook, not `woocommerce_new_order`, so this doesn't double up with a status-specific flow later.

Content: order number, item list (WooCommerce's own `email-order-details.php` template part can be reused), delivery address, payment method (COD amount due vs. already paid), and a note that a separate email with the tracking code will follow once the order ships.

### 3.3 No-email orders

Per your call: **skip silently, log only.** `Epic_Order_Emails_Created::trigger()` (or the native email, same behavior already) checks `$order->get_billing_email()`; if empty, log via `wc_get_logger()->info(..., ['source' => 'epic-order-emails'])` and return without attempting to send. No admin-visible flag — staff would need to check **WooCommerce → Status → Logs** if they want to confirm why a given order got no email.

---

## 4. Email 2 — "Your order has shipped" (admin confirms shipment creation)

### 4.1 Trigger points — both inside `epic-ghn-shipping`, unchanged

Two call sites currently write `_ghn_order_code` on success and both need to fire the same email:

1. **`Epic_GHN_Ajax::book_single_order()`** (`includes/class-ajax.php`, ~line 426) — the single-order "Ship via GHN" button, and the orders-list per-order bulk booking. Hook point: immediately after `$order->save()`, alongside the existing `add_order_note()` call.
2. **`Epic_GHN_Bundle::confirm()`** (`includes/class-bundle.php`) — the bundle review screen's confirm step, which (per `PLAN.md` §5.2 step 5) writes `_ghn_order_code` / `_ghn_bundle_id` onto every order in the bundle. Same hook point, once per constituent order.

Rather than importing `epic-order-emails` classes directly into `epic-ghn-shipping` (a cross-plugin dependency neither plugin should assume the other is active), fire a plugin-agnostic action from both call sites:

```php
do_action( 'epic_ghn_shipment_booked', $order, $shipment['order_code'], $shipment['expected_delivery_time'] ?? '' );
```

`epic-order-emails` hooks `add_action( 'epic_ghn_shipment_booked', ... )` and sends from there. This keeps `epic-ghn-shipping` fully functional (still writes meta, still adds the order note) even if `epic-order-emails` is deactivated — it just won't fire an email nobody's listening for. This is a small, additive change to `epic-ghn-shipping` (one `do_action()` line in two places), not a rewrite.

### 4.2 Email content

Order number, GHN tracking code, a customer-facing GHN tracking link (need to confirm the exact public tracking URL format against your GHN dashboard — likely something like `https://donhang.ghn.vn/?order_code={code}`, distinct from the merchant-facing API), expected delivery if GHN returned one, and — if `Epic_GHN_Client::is_cod_order( $order )` — the COD amount due on delivery, so the customer knows how much cash to have ready.

### 4.3 Bundle case

**Send one email per order**, not one shared email across the bundle — each referencing its own order number, even though the tracking code is identical across all of them. The bundling is an internal fulfillment optimization; a customer who ordered once shouldn't need to understand that their parcel merged with someone else's.

### 4.4 No-email orders

Same rule as Email 1: skip silently, log only, via the shared `wc_get_logger()` source `epic-ghn` (matching what `epic-ghn-shipping` already uses) or a new `epic-order-emails` source — logging from whichever plugin actually attempted the send keeps the trail in one place.

### 4.5 Idempotency

`_ghn_order_code` is written once per order under normal operation, but the plan should not assume that's airtight forever (retries, future re-booking flows). Before sending, check-and-set a `_epic_ship_email_sent` order meta flag so a duplicate `epic_ghn_shipment_booked` fire (if one is ever added) can't double-email a customer.

---

## 5. Shared plumbing

- Both emails extend `WC_Email` and are registered via `woocommerce_email_classes` — this is the only integration point WooCommerce needs; no custom settings screen, no AJAX.
- Whatever SMTP plugin you land on (from the earlier SMTP question) sits underneath both automatically — all WooCommerce emails funnel through `wp_mail()`, so nothing here depends on that choice.
- i18n: text domain `epic-order-emails`, `languages/epic-order-emails-vi.mo` shipped alongside `en_US`, matching the EN/VI split already used by `epic-ghn-shipping`.
- Logging: `wc_get_logger()`, source `epic-order-emails`, for every skip (no email on file) and every send failure — visible under **WooCommerce → Status → Logs** without server access, same convention as the other two plugins.

---

## 6. Open items to confirm before build starts

1. **Native "Processing order" email**: is it currently enabled in WooCommerce → Settings → Emails? If yes and the copy is fine as-is, Email 1 may not need a custom `WC_Email` subclass at all — just an SMTP check.
2. **GHN public tracking URL**: exact format to link customers to, from your GHN merchant dashboard.
3. **Email copy/branding**: EN + VI wording for both emails — subject lines, tone, whether to include your logo/header image (WooCommerce's global email settings already control the header image/colors site-wide, so this may be free).
4. **`epic_ghn_shipment_booked` action**: confirms you're fine with the small addition to `epic-ghn-shipping` (one `do_action()` call in two places) described in §4.1, rather than a fully separate mechanism.

---

## 7. Rollout plan

1. **Phase 1** — confirm §6.1 (native email may cover Email 1 entirely); if not, build `Epic_Order_Emails_Created`.
2. **Phase 2** — add the `epic_ghn_shipment_booked` action to `epic-ghn-shipping` (both call sites), build `Epic_Order_Emails_Shipped` in the new plugin, wire the idempotency flag.
3. **Phase 3** — EN/VI copy pass, confirm SMTP delivery end-to-end with a real test order in both COD and prepaid flows, confirm the bundle case sends N separate emails correctly.
