=== EPIC Email Branding ===
Contributors: epicroastery
Tags: woocommerce, email, branding, footer, contact
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 8.9
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds a branded header bar and a contact-info footer to every WooCommerce email the store sends.

== Description ==

A single, global presentation layer for all store emails — order confirmation and shipped, newsletter (admin, confirmation, broadcast), wholesale inquiries, wholesale orders, and free-sample requests. It does not send any email itself; it decorates the emails WooCommerce is already sending, so there is nothing to enable per email type.

* **Header** — hooked to WooCommerce's own `woocommerce_email_header` action (priority 20, so it prints right after WooCommerce's logo/heading), it renders a dark brand bar with the wordmark, tagline, hotline and website.
* **Footer** — hooked to the `woocommerce_email_footer_text` filter, it replaces the footer with the café and roastery addresses, hotline, email, website, Instagram and opening hours, followed by a copyright line. Because WooCommerce's HTML footer and the plain-text templates all read this one filter, both formats stay in sync.

The footer filter receives the `WC_Email` object from WooCommerce's HTML footer and nothing from the plain-text templates, which is how the module knows whether to emit HTML (`strong`/`br`/`a`, all `wp_kses_post`-safe) or plain text.

**Contact details are filterable.** Change any value without editing the module:

```
add_filter( 'epic_email_branding_contact', function ( $contact ) {
    $contact['phone'] = '0900 000 000';
    $contact['email'] = 'hello@epicroastery.coffee';
    return $contact;
} );
```

Available keys: `brand`, `tagline`, `cafe`, `roastery`, `phone`, `phone_href`, `email`, `website`, `instagram`, `hours`.

== Installation ==

1. Ships inside `epic-woocommerce-suite` (`modules/epic-email-branding/`). Activate the suite. No settings to configure — the branding applies to every email immediately.
2. Send a test email (e.g. place a test order, or subscribe via the footer) and confirm the brand bar appears at the top and the contact footer at the bottom, in both HTML and plain-text formats.
3. To change any detail, use the `epic_email_branding_contact` filter above.

== Changelog ==

= 1.0.0 =
* Initial release: global branded header bar (`woocommerce_email_header`) and contact-info footer (`woocommerce_email_footer_text`) with address, hotline, email, website, Instagram and hours, filterable via `epic_email_branding_contact`.
