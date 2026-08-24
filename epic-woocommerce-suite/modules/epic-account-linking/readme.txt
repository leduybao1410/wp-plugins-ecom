=== EPIC Account Linking ===
Contributors: epicroastery
Tags: woocommerce, account, google, orders, headless
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 8.9
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Links Google-signed-in customers to their WooCommerce order history for the headless website's account area.

== Description ==

The Next.js website has a headless account area: customers sign in with their Google account, and their order history is rendered by the site itself — not by WooCommerce's `my-account`. This plugin is what lets the site know which orders belong to which account.

**How it works**

* On sign-in the website calls `POST /wp-json/epic-account-linking/v1/accounts` with the verified Google profile (the stable Google `sub`, email, name, avatar). The plugin creates or refreshes an account row in its own `epic_accounts` table.
* **Auto-link by email** — right after that upsert, the plugin finds every order whose billing email matches the account's Google email (`wc_get_orders( [ 'billing_email' => ... ] )`) and records the links in an `epic_order_links` table. Historical guest orders placed under the same address appear in the account the moment the customer signs in.
* **Manual claim** — for orders placed under a different email (or none), the customer enters their unguessable order code (the `EPIC-XXXXXX` code from epic-order-codes) plus the email or phone they used at checkout. The plugin decodes the code, verifies the email/phone matches the order's billing address, and links it.
* **WC customer** — the plugin also creates/reuses a WooCommerce customer for the account (never used for login — Google is the only sign-in path), so orders placed while the customer is signed in carry a real `customer_id` on top of the link table.
* The website reads the account's orders over `GET .../accounts/{sub}/orders` and `GET .../accounts/{sub}/orders/{order_id}`, which return status, payment, and GHN tracking info (and full line-item detail for a single order) — never login credentials.

**Dependencies**

* Requires WooCommerce. Requires the `epic-order-codes` plugin for the manual-claim flow (it decodes the unguessable order code); the auto-link-by-email flow works without it.

**Setup**

1. Install & activate the plugin (or the EPIC WooCommerce Suite bundle).
2. Go to WooCommerce → Account Linking and set a **shared secret** — a long random string.
3. Put the same value in the website's `EPIC_ACCOUNT_SHARED_SECRET` environment variable.

== Installation ==

1. Plugins → Add New → Upload Plugin → choose `epic-account-linking.zip` → Install Now → Activate.
2. WooCommerce → Account Linking → set a shared secret, and mirror it into the website's `EPIC_ACCOUNT_SHARED_SECRET`.

== Changelog ==

= 1.0.0 =
* Initial release: accounts table (Google `sub` keyed), order-links table, auto-link by billing email on sign-in, manual order claim by order code + email/phone, WooCommerce customer creation for signed-in accounts, and shared-secret-gated REST routes for the website's account area.
