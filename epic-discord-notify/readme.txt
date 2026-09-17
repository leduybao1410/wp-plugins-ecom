=== EPIC Discord Order Notifications ===
Contributors: epicroastery
Tags: woocommerce, discord, webhook, notifications, orders
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 8.9
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Posts a message to a Discord channel via webhook whenever a new order is placed on EPIC Roastery.

== Description ==

Adds one settings tab — **WooCommerce → Settings → Discord Notify** — with a Discord channel webhook URL and a "send test message" button. No bot to host, no bot token, nothing running outside WordPress itself: WordPress POSTs a JSON message straight to the webhook URL whenever a new order is created.

Each notification is a Discord embed containing:

* Order number, linking straight back to the order screen in wp-admin
* Customer name
* Order total and payment method
* Customer phone (optional, on by default)
* Delivery address (optional, on by default)
* Item list (name × quantity)

**Trigger:** the same `pending → processing` / `pending → on-hold` status transitions `epic-order-emails` uses for its own "order received" email — exactly what happens the moment the Next.js checkout creates an order via the WooCommerce REST API (`status: "processing"` at creation is itself a `pending → processing` transition), and equally covers a manually placed wp-admin order. Each order notifies at most once, tracked via an order meta flag.

Off by default. Nothing is sent until you tick **Enable Discord notifications** and save a webhook URL.

== Installation ==

1. In Discord: open your server, go to **Server Settings → Integrations → Webhooks → New Webhook**. Pick (or create) the channel you want order alerts posted to, optionally rename/re-icon the webhook, then click **Copy Webhook URL**.
2. In wp-admin: **Plugins → Add New → Upload Plugin** → choose `epic-discord-notify.zip` → **Install Now** → **Activate**.
3. Go to **WooCommerce → Settings → Discord Notify**. Paste the webhook URL, tick **Enable Discord notifications**, and **Save changes**.
4. Click **Send test message** to confirm it reaches the channel before relying on it for real orders.
5. Adjust the "What gets included" checkboxes (phone, address) to taste.

== Extending ==

* `epic_discord_notify_order_payload` filters the full JSON payload (embed) for a real order notification before it's sent — add/remove fields, change the color, etc.
* `epic_discord_notify_settings` filters the settings-screen field array, for adding more options later (e.g. per-status notifications, a second webhook for a different channel).

To notify on additional events later (payment confirmed, shipped, cancelled/refunded), add another `add_action()` in `includes/class-notifier.php::init()` for the relevant WooCommerce hook (e.g. `woocommerce_order_status_changed`, or the `epic_ghn_shipment_booked` action `epic-ghn-shipping` fires) and build a payload for it the same way `build_order_payload()` does — see PLAN.md.

== Changelog ==

= 1.0.0 =
* Initial release: new-order Discord notification via channel webhook, settings screen with enable toggle, webhook URL, bot display name/avatar overrides, phone/address include toggles, and a test-message button.
