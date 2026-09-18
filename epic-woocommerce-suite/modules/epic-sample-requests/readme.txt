=== EPIC Sample Requests ===
Contributors: epicroastery
Tags: woocommerce, email, rest-api, samples, leads
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 8.9
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Receives free-coffee-sample requests from the Next.js website over REST, logs every one in wp-admin, and emails the store team a real notification.

== Description ==

Backs the website's `/roastery` page free-sample form. The form sends a name, phone number and structured Vietnam delivery address (post-merger province + ward + street) — all required — plus optional email, favourite taste (Robusta Natural / Robusta Honey / Arabica Việt Nam) and brew style (Pha Phin / Espresso / Cold Brew / Pour Over) to the site's `/api/sample` route, which forwards it into WordPress over a shared-secret-authenticated REST endpoint this module exposes:

Phone numbers are validated as 10 digits starting with 0 (separators like spaces/dashes are stripped before validation and storage).

* **`POST /wp-json/epic-sample/v1/request`** — requires an `X-Epic-Secret` header matching the secret set under **WooCommerce → Sample Requests**. Validates the payload (including a valid email address), records it in this module's own database table, then fires the `epic_sample_request_received` action.
* **WooCommerce → Sample Requests** — the log. Every request ever submitted, newest first, sortable by date or name, with each row's name/phone/email/address/taste and both delivery statuses (Admin email, Thank-you: Sent / Failed / Email disabled / Pending). This is the permanent record — it exists independently of whether either email below actually reached anyone. Each row has a Delete action for removing requests you no longer need to retain (this table holds lead PII — name, email, phone number and delivery address — with no automatic expiry; review and prune periodically).
* **WooCommerce → Settings → Emails → EPIC: Sample Request** — an ordinary `WC_Email`, registered via `woocommerce_email_classes` like every other EPIC email, that listens for the same action and sends the admin notification. Recipient defaults to the site's admin email but is editable on that same settings screen (comma-separated list supported). **Enabled by default.**
* **WooCommerce → Settings → Emails → EPIC: Sample Thank-you** — the customer-facing confirmation, sent back to the requester's own address. Bilingual: English when the request came from the English storefront, Vietnamese otherwise. **Enabled by default.**

This module creates no WooCommerce order, customer, or any other WooCommerce-native record — requests live only in this module's own table. Nothing here touches the product catalog or checkout.

== Installation ==

1. Ships inside `epic-woocommerce-suite` (`modules/epic-sample-requests/`). Activate the suite (or re-activate it after an update) so the module's table is created.
2. Go to **WooCommerce → Sample Requests** and set a long random shared secret. Copy the same value into the website's `EPIC_SAMPLE_SHARED_SECRET` environment variable (see `.env.example`) and redeploy/restart the website so it picks it up.
3. Go to **WooCommerce → Settings → Emails → EPIC: Sample Request** and confirm/adjust the recipient address(es), subject, and heading. It's on by default.
4. Confirm outbound mail actually reaches inboxes — WooCommerce's default `wp_mail()` transport is unreliable on most hosts. An SMTP plugin is strongly recommended. Even without SMTP configured, submissions still show up in the **WooCommerce → Sample Requests** log — only the email notification depends on mail delivery working.
5. Submit a test request from the live `/roastery` page and confirm it appears under **WooCommerce → Sample Requests** with an email status of "Sent". If it's stuck at "Failed", check **WooCommerce → Status → Logs**, source `epic-sample-requests`.

== Changelog ==

= 1.2.0 =
* Added an optional "brew style" field (Pha Phin / Espresso / Cold Brew / Pour Over), shown in the log, the admin notification and the thank-you email.
* Made email, favourite taste and brew style optional; only name, the full delivery address (province/ward/street) and phone are required.
* Added phone validation: 10 digits starting with 0 (separators stripped).
* Schema 1.2: added `brew` and `brew_label_vi` columns; `confirm_status` gains a `skipped` value ("No email") when the requester left no email address.

= 1.1.0 =
* Added the requester's email address to the form, REST payload and stored row.
* Added `Epic_Email_Sample_Confirmation` — a customer-facing, bilingual thank-you email sent back to the requester (English for `en`, Vietnamese otherwise). Ships enabled; independently toggleable under WooCommerce → Settings → Emails → EPIC: Sample Thank-you.
* The log screen now shows the email address and a second status column (Thank-you) alongside the existing admin-email status.
* Schema 1.1: added `email` and `confirm_status` columns to `{$wpdb->prefix}epic_sample_requests`.

= 1.0.0 =
* Initial release: `Epic_Sample_Rest_Api` (shared-secret REST endpoint) + `Epic_Sample_Store` (persistent table + admin log) + `Epic_Email_Sample_Request` (WC_Email admin notification), connected via the `epic_sample_request_received` action.
