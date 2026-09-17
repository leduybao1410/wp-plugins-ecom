# EPIC Discord Order Notifications — plan / decisions

**Plugin slug:** `epic-discord-notify`
**Built:** 2026-08-28, in response to: "check if a plugin already sends order notifications to Discord; if not, build one." No existing plugin (local `wordpress-plugins/` or elsewhere in the repo) referenced Discord in any form — this is new.

## Decisions (confirmed with the user before building)

1. **Webhook, not a bot token.** A Discord channel webhook is a URL that accepts a POST of JSON — no bot process to host, no bot added to the server, no always-on connection. This is what nearly every "WooCommerce → Discord" integration actually uses even when described as "a bot." A real bot (Application + bot token + gateway connection) is only needed for two-way interaction (slash commands, reacting to messages, reading channel history) — out of scope here since the ask was one-way order alerts.
2. **Trigger: new order placed only**, for v1. Payment-confirmed, shipped, and cancelled/refunded were offered but not selected — see "Extending" below for how to add them later without restructuring anything.
3. **No webhook URL was available yet** — readme.txt's Installation section documents the exact Discord steps (Server Settings → Integrations → Webhooks → New Webhook → Copy Webhook URL).

## Why this hook, not `woocommerce_new_order`

Mirrors `epic-order-emails`'s reasoning exactly (see that plugin's `includes/class-email-order-created.php` docblock and its own `PLAN.md` §3.2): the Next.js checkout creates orders via the WooCommerce REST API with `status: "processing"` already set (`website/src/lib/woocommerce.ts` → `createOrder()`), and a brand-new `WC_Order`'s implicit starting status is `pending` — so that single REST call *is* a `pending → processing` status transition as far as `WC_Order::status_transition()` is concerned. `on-hold` is the second case: `epic-ghn-shipping`'s website-side flagging uses it when GHN shipping-fee/booking fails at checkout.

Hooking the transition (`woocommerce_order_status_pending_to_processing` / `..._pending_to_on-hold`) rather than `woocommerce_new_order` avoids double-firing if a future flow creates the order as `pending` first and transitions it separately — `woocommerce_new_order` fires once per `wc_get_order()`-triggered save-of-a-new-order, which could in theory precede the status-setting write depending on how an order is built.

Used the **plain** hook names (`woocommerce_order_status_pending_to_processing`), not the `_notification`-suffixed ones `epic-order-emails` uses for its `WC_Email` subclasses. Both fire on every transition unconditionally — the `_notification` suffix is just the naming convention WooCommerce's own transactional-email dispatcher happens to use, not a gate on whether any email is enabled — but hooking the plain name doesn't imply this plugin depends on WooCommerce's email subsystem, which it doesn't.

## Duplicate-send guard

`_epic_discord_notified` order meta, checked and set exactly like `epic-order-emails`'s `_epic_order_email_sent` flag. Belt-and-suspenders: the two transition hooks can't both fire for one order (a status can only leave `pending` once), but the flag protects against any future re-trigger.

## Silent-skip cases (logged, not surfaced in admin)

- Notifications disabled (`epic_discord_enabled` != `yes`): returns immediately, no log noise.
- No webhook URL configured: logged via `wc_get_logger()`, source `epic-discord-notify`, same pattern as `epic-order-emails`'s no-billing-email case. Check **WooCommerce → Status → Logs**.
- Discord request fails or returns non-2xx: logged the same way, with the HTTP status/body from Discord's response — most common cause is a webhook that was deleted/regenerated in Discord after being pasted into WordPress.

## Extending to more events later

Add another `add_action()` in `Epic_Discord_Notifier::init()` (`includes/class-notifier.php`):

- **Payment confirmed** (if distinct from "new order" — e.g. a COD order that starts `on-hold` and later moves to `processing` once cash is collected): hook `woocommerce_order_status_on-hold_to_processing`.
- **Shipped**: `epic-ghn-shipping` fires `epic_ghn_shipment_booked` once a staff member books the GHN shipment (same action `epic-order-emails`'s shipped email listens for) — hook that instead of a WooCommerce core status hook.
- **Cancelled/refunded**: `woocommerce_order_status_cancelled` / `woocommerce_order_status_refunded`.

For each, build a small `build_*_payload( $order )` method alongside `build_order_payload()` in `class-notifier.php` (same field-building style — an array of Discord embed `fields`), and give it its own duplicate-guard meta key (e.g. `_epic_discord_shipped_notified`) so it doesn't share state with the new-order flag. Consider a second `epic_discord_webhook_url_shipped` setting if shipped notifications should go to a different channel than new-order alerts — the settings screen already follows a per-purpose-field pattern, so adding one more field + one more `get_option()` call is the whole change.

## Not done / left as-is

- No rate limiting / retry-on-failure — Discord webhooks are generous (roughly 30 requests/minute per webhook), and this store's order volume is nowhere near that. A failed post just logs and moves on; it does not retry.
- No signature/HMAC verification needed — this plugin only ever *sends* to Discord, it never receives a Discord webhook callback, so there's nothing to authenticate inbound.
