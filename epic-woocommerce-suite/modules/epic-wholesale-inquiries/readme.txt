=== EPIC Wholesale Inquiries ===
Contributors: epicroastery
Tags: woocommerce, email, rest-api, wholesale, leads
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 8.9
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Receives wholesale contact-form leads from the Next.js website over REST, logs every one in wp-admin, and emails the wholesale team a real notification.

== Description ==

Backs the website's `/wholesale` page contact form. The form sends a "topic" (wholesale supply / private label OEM / café setup & training / something else) along with business name, phone number, contact (email or Zalo), and an optional message to the site's `/api/wholesale` route, which forwards it into WordPress over a shared-secret-authenticated REST endpoint this plugin exposes:

* **`POST /wp-json/epic-wholesale/v1/inquiry`** — requires an `X-Epic-Secret` header matching the secret set under **WooCommerce → Wholesale Inquiries**. Validates the payload, records it in this plugin's own database table, then fires the `epic_wholesale_inquiry_received` action.
* **WooCommerce → Wholesale Inquiries** — the log. Every inquiry ever submitted, newest first, sortable by date or business name, with each row's business/phone/contact/topic/message and its email delivery status (Sent / Failed / Email disabled / Pending). This is the permanent record — it exists independently of whether the notification email below actually reached anyone, so a bounce, a spam-folder delivery, or a disabled email setting never means the lead is lost. Each row has a Delete action for removing inquiries you no longer need to retain (this table holds lead PII — phone number and email/Zalo contact — with no automatic expiry; review and prune periodically).
* **WooCommerce → Settings → Emails → EPIC: Wholesale Inquiry** — an ordinary `WC_Email`, registered via `woocommerce_email_classes` like every other EPIC email, that listens for the same action and sends the actual notification. Recipient defaults to the site's admin email but is editable on that same settings screen (comma-separated list supported). **Enabled by default.**

This plugin creates no WooCommerce order, customer, or any other WooCommerce-native record — inquiries live only in this plugin's own table. Nothing here touches the product catalog or checkout.

== Installation ==

1. Plugins → Add New → Upload Plugin → choose `epic-wholesale-inquiries.zip` → Install Now → Activate. (Activation creates the plugin's inquiries table automatically.)
2. Go to **WooCommerce → Wholesale Inquiries** and set a long random shared secret. Copy the same value into the website's `EPIC_WHOLESALE_SHARED_SECRET` environment variable (see `.env.example`) and redeploy/restart the website so it picks it up.
3. Go to **WooCommerce → Settings → Emails → EPIC: Wholesale Inquiry** and confirm/adjust the recipient address(es), subject, and heading. It's on by default.
4. Confirm outbound mail actually reaches inboxes — WooCommerce's default `wp_mail()` transport is unreliable on most hosts. An SMTP plugin (WP Mail SMTP, FluentSMTP, or WooCommerce's own "SMTP & Email Logs") is strongly recommended regardless of this plugin, same note as epic-order-emails. Even without SMTP configured, submissions still show up in the **WooCommerce → Wholesale Inquiries** log — only the email notification depends on mail delivery working.
5. Submit a test inquiry from the live `/wholesale` page and confirm it appears under **WooCommerce → Wholesale Inquiries** with an email status of "Sent". If it's stuck at "Failed", check **WooCommerce → Status → Logs**, source `epic-wholesale-inquiries`.

== Changelog ==

= 1.1.0 =
* Added persistent storage: every inquiry is now recorded in its own `{$wpdb->prefix}epic_wholesale_inquiries` table (`Epic_Wholesale_Store`) before the notification email is even attempted, so a failed/bounced/disabled email no longer means the lead is lost.
* Added the **WooCommerce → Wholesale Inquiries** log screen (`Epic_Wholesale_List_Table`, a standard `WP_List_Table`) — sortable, paginated, shows each row's email delivery status, with a per-row Delete action. `business_name` is the declared primary column (`get_primary_column_name()`) so it matches where the Delete row action actually lives. The table also overrides WP_List_Table's default `table-layout: fixed` back to `auto` — `fixed` layout takes a column's `width: 1%` hint literally (1% of the table, nowhere near enough for a timestamp or phone number) instead of the usual "shrink to content" meaning, which is what caused columns to visually overlap/garble.
* The notification email now reports its delivery outcome (sent / failed / disabled) back onto the stored row.
* The website's wholesale form gained a required phone number field, threaded through end-to-end (REST payload, stored row, notification email).
* Email content (subject, heading, body) switched to Vietnamese-only, matching epic-order-emails' customer emails — settings-screen labels stay in English.

= 1.0.0 =
* Initial release: `Epic_Wholesale_Rest_Api` (shared-secret REST endpoint) + `Epic_Email_Wholesale_Inquiry` (WC_Email admin notification), connected via the `epic_wholesale_inquiry_received` action.
