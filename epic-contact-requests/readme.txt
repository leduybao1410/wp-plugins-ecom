=== EPIC Contact Requests ===
Contributors: epicroastery
Tags: woocommerce, email, rest-api, contact, leads
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 8.9
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Receives contact-form leads from the Next.js website over REST, logs every one in wp-admin, and emails the store team a real notification.

== Description ==

Backs the website's `/contact` page contact form. The form sends a name, phone number, an optional email, an optional message, and an optional "direct consultation" request (offered in Ho Chi Minh City only — ticking it requires a province/ward/street address) to the site's `/api/contact` route, which forwards it into WordPress over a shared-secret-authenticated REST endpoint this plugin exposes:

* **`POST /wp-json/epic-contact/v1/request`** — requires an `X-Epic-Secret` header matching the secret set under **WooCommerce → Contact Requests**. Validates the payload (name + phone required; address required and HCMC-only when a direct consultation is requested), records it in this plugin's own database table, then fires the `epic_contact_request_received` action.
* **WooCommerce → Contact Requests** — the log. Every request ever submitted, newest first, sortable by date or name, with each row's name/phone/email/direct-consult/address/message and its email delivery status (Sent / Failed / Email disabled / Pending). This is the permanent record — it exists independently of whether the notification email below actually reached anyone. Each row has a Delete action for removing requests you no longer need to retain (this table holds lead PII — name, email, phone, address — with no automatic expiry; review and prune periodically).
* **WooCommerce → Settings → Emails → EPIC: Contact Request** — an ordinary `WC_Email`, registered via `woocommerce_email_classes` like every other EPIC email, that listens for the same action and sends the actual notification. Recipient defaults to the site's admin email but is editable on that same settings screen (comma-separated list supported). **Enabled by default.**

This plugin creates no WooCommerce order, customer, or any other WooCommerce-native record — requests live only in this plugin's own table. Nothing here touches the product catalog or checkout.

== Installation ==

1. Plugins → Add New → Upload Plugin → choose `epic-contact-requests.zip` → Install Now → Activate. (Activation creates the plugin's requests table automatically.)
2. Go to **WooCommerce → Contact Requests** and set a long random shared secret. Copy the same value into the website's `EPIC_CONTACT_SHARED_SECRET` environment variable (see `.env.example`) and redeploy/restart the website so it picks it up.
3. Go to **WooCommerce → Settings → Emails → EPIC: Contact Request** and confirm/adjust the recipient address(es), subject, and heading. It's on by default.
4. Confirm outbound mail actually reaches inboxes — WooCommerce's default `wp_mail()` transport is unreliable on most hosts. An SMTP plugin (WP Mail SMTP, FluentSMTP, or WooCommerce's own "SMTP & Email Logs") is strongly recommended. Even without SMTP configured, submissions still show up in the **WooCommerce → Contact Requests** log — only the email notification depends on mail delivery working.
5. Submit a test request from the live `/contact` page and confirm it appears under **WooCommerce → Contact Requests** with an email status of "Sent". If it's stuck at "Failed", check **WooCommerce → Status → Logs**, source `epic-contact-requests`.

== Changelog ==

= 1.0.0 =
* Initial release: `Epic_Contact_Rest_Api` (shared-secret REST endpoint) + `Epic_Contact_Store` (own database table) + `Epic_Email_Contact_Request` (WC_Email admin notification), connected via the `epic_contact_request_received` action. Supports an optional direct-consultation request with an HCMC-only address.
