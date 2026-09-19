<?php
/**
 * Customer "thanks for subscribing" confirmation email (HTML).
 *
 * Bilingual body — English when the subscriber signed up on the English
 * storefront ('en'), Vietnamese otherwise (the store's primary customer
 * base; also the fallback for an unknown/blank locale). See the docblock on
 * class-email-newsletter-confirmation.php for the full reasoning.
 *
 * No $order here (see class-email-newsletter-confirmation.php's docblock) —
 * this only ever uses `woocommerce_email_header`/`_footer`, not the
 * `woocommerce_email_order_details`/`_customer_details` hooks other EPIC
 * emails call, since those require a WC_Order.
 *
 * @var string   $subscriber_email
 * @var string   $subscriber_locale
 * @var string   $subscribed_at
 * @var string   $email_heading
 * @var string   $additional_content
 * @var bool     $sent_to_admin
 * @var bool     $plain_text
 * @var WC_Email $email
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__, 2 ) . '/includes/epic-email-i18n.php';

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p><?php echo esc_html( epic_email_str( 'nl_thanks', $subscriber_locale ) ); ?></p>
<p><?php echo esc_html( epic_email_str( 'nl_body', $subscriber_locale ) ); ?></p>

<p>
	<?php echo esc_html( epic_email_str( 'nl_unsub', $subscriber_locale ) ); ?>
</p>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
