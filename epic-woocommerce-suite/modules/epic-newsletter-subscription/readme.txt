=== EPIC Newsletter Subscription ===
Contributors: EPIC Coffee Roaster
Tags: newsletter, email, rest-api
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Backs the Next.js website's footer newsletter subscription box: receives an email over a shared-secret-authenticated REST endpoint, stores it, and notifies the store's marketing team.

== Description ==

The website (Next.js) has a subscription box in its footer where visitors enter an email to receive brand news and future promotion coupons. This WordPress plugin is the backend for that box:

* Exposes a `POST /wp-json/epic-newsletter/v1/subscribe` REST endpoint, authenticated by a shared secret sent as the `X-Epic-Secret` header (constant-time compared via `hash_equals()`).
* Records every subscription in its own table (`wp_epic_newsletter_subscribers`) so wp-admin always has the full list — WooCommerce → Newsletter Subscribers — even if a notification email fails, bounces, or is disabled.
* Sends two emails, both standard `WC_Email` classes under WooCommerce → Settings → Emails, each independently enable/disable-able:
  * **"EPIC: Newsletter Subscriber"** — admin notification to the store team (recipient/subject/heading editable).
  * **"EPIC: Newsletter Confirmation"** — a thank-you sent straight back to the subscriber's own address, confirming the signup. The body is bilingual (English or Vietnamese) and is chosen from the storefront locale the visitor subscribed in.
* Is idempotent: an address that's already subscribed is accepted silently (no duplicate row, no duplicate notification or confirmation email).
* "Export CSV" / "Export XLSX" buttons on WooCommerce → Newsletter Subscribers download the full subscriber list (email, subscribed date, locale, both delivery statuses) — not just the current on-screen page — for handing to a bulk-email tool or a one-off mailing.
* **WooCommerce → Send Newsletter** composes one bilingual (Vietnamese/English) email and delivers it to all subscribers, or just those on the Vietnamese or English storefront. Sending runs in the background in small batches (Action Scheduler, bundled with WooCommerce) rather than one long request, so it won't time out or trip a mail provider's rate limits on a large list. Every campaign keeps a permanent per-recipient delivery log (sent/failed, exportable as CSV/XLSX) and supports a "send test" preview before committing to a real send.

The website calls this endpoint from `src/app/api/subscribe/route.ts` via `src/lib/subscription.ts`. Set the same secret in this plugin's settings screen and in the website's `EPIC_NEWSLETTER_SHARED_SECRET` environment variable.

= Storing subscriber data =

The subscribers table stores email addresses (PII) with no automatic expiry. Review WooCommerce → Newsletter Subscribers periodically and delete rows you no longer need to retain. Deleting a row is also how you unsubscribe somebody from future mailings — bulk campaign emails currently rely on this same manual process (a reply-to-unsubscribe line is included in every campaign email, but there's no automatic one-click unsubscribe link yet; see PLAN-bulk-newsletter-email.md if that becomes necessary at a larger sending volume).

== Installation ==

1. Upload the `epic-newsletter-subscription` folder to `/wp-content/plugins/`, or install the plugin zip through Plugins → Add New.
2. Activate the plugin (WooCommerce must be active).
3. Open WooCommerce → Newsletter Subscribers, generate a long random value, and paste it into the "Shared secret" field.
4. Copy the same value into the website's `.env` as `EPIC_NEWSLETTER_SHARED_SECRET`.
5. If you want a notification email, check WooCommerce → Settings → Emails → EPIC: Newsletter Subscriber — the recipient defaults to the site admin address. The thank-you email to subscribers (EPIC: Newsletter Confirmation) ships enabled and needs no configuration.

== Frequently Asked Questions ==

= Does this create a WooCommerce customer? =

No. A subscriber is not a WooCommerce order or customer — it's a plain record in the plugin's own table, plus an admin notification email.

= How do I unsubscribe someone? =

Delete their row under WooCommerce → Newsletter Subscribers.

= Where is the subscriber list? =

WooCommerce → Newsletter Subscribers, with sortable columns, pagination, and per-row delete.

== Changelog ==

= 1.2.0 =
* Added WooCommerce → Send Newsletter: compose one bilingual email and send it to all/VI/EN subscribers, delivered in background batches via Action Scheduler. Each campaign keeps a resumable per-recipient delivery log, a "send test" preview, and a CSV/XLSX export of that log.

= 1.1.0 =
* Added "Export CSV" / "Export XLSX" buttons to WooCommerce → Newsletter Subscribers — downloads the full subscriber list, independent of the on-screen page/sort.

= 1.0.0 =
* Initial release: REST endpoint, subscriber list, admin notification email, customer confirmation email.
