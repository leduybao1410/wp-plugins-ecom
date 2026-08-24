=== EPIC Zalo Login ===
Contributors: EPIC Coffee Roaster
Tags: zalo, login, oauth, social-login
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds a "Sign in with Zalo" button (Zalo Login v4 — OAuth 2.0 + PKCE) so visitors can register and log in with their Zalo account, the same way they would with Google sign-in.

== Description ==

This plugin lets users log in to your WordPress site with their Zalo account, mirroring a "Sign in with Google" button:

* Renders a "Sign in with Zalo" button anywhere via the `[epic_zalo_login]` shortcode.
* Implements the full Zalo Login v4 flow (OAuth 2.0 + PKCE) server-side — the app secret never touches the browser.
* On first login, auto-creates a WordPress user (unless disabled in settings) and stores the Zalo ID as user meta, so the same Zalo account always maps to the same WordPress user on subsequent visits.
* Stores the Zalo avatar URL as user meta for themes that want to display it.
* Uses transients for PKCE verifiers and nonce-based state to prevent CSRF.

== Requirements ==

* A Zalo app registered at developers.zalo.me (Zalo Login v4 is the only supported version; v3 was fully decommissioned).
* The app must be approved by Zalo for production use. The default profile fields are `id`, `name`, `picture`; requesting sensitive fields such as phone number or email requires additional scope approval.
* Whitelist the plugin's Callback URL (shown on Settings → Zalo Login) under the Zalo app's "Quản lý ứng dụng → Đăng nhập".

== Installation ==

1. Upload the `epic-zalo-login` folder to `/wp-content/plugins/`, or install the plugin zip through Plugins → Add New.
2. Activate the plugin.
3. Create a Zalo app at developers.zalo.me and request Login approval.
4. Open Settings → Zalo Login, paste the App ID and App Secret Key, and note the Callback URL.
5. Add the Callback URL to the Zalo app's "Đăng nhập" settings.
6. Place `[epic_zalo_login]` on any page, the login screen, or a widget area to show the button.

== Frequently Asked Questions ==

= Do users need a separate WordPress account? =

No. If "Create users automatically" is enabled, a WordPress user is created on first Zalo login and linked to the Zalo ID.

= Can the same Zalo account create duplicate users? =

No. The Zalo ID is stored as user meta and looked up on every login, so a Zalo account always resolves to the same WordPress user.

= Does the plugin work with WooCommerce? =

It does not depend on WooCommerce. Any site with standard WordPress users can use it.

= What user data does it store? =

The Zalo numeric ID (used as the stable mapping key), the display name, and the avatar URL. A generated password is set so the account also works with normal password login; no Zalo token is stored long-term.

== Changelog ==

= 0.1.0 =
* Initial sketch: settings screen, Zalo OAuth v4 client with PKCE, login button shortcode, and callback handler that maps Zalo IDs to WordPress users.
