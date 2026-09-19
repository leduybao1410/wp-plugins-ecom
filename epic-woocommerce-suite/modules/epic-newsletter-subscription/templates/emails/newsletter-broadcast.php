<?php
/**
 * Bulk newsletter broadcast (HTML). Content ($email_heading, $broadcast_body)
 * comes from a campaign row composed in wp-admin → WooCommerce → Send
 * Newsletter, not from a WooCommerce Settings screen — see
 * class-email-newsletter-broadcast.php's docblock for why this WC_Email
 * subclass has no settings tab of its own.
 *
 * $broadcast_body is the admin's own wp_editor() HTML for whichever
 * language bucket this recipient falls into (chosen before this template
 * runs, in class-broadcast-sender.php::send_one()) — this template does not
 * re-localize it, only the fixed unsubscribe line below.
 *
 * No $order here, same reasoning as customer-newsletter-subscribed.php:
 * a bulk subscriber isn't tied to a WC_Order, so only the plain
 * woocommerce_email_header/_footer hooks apply.
 *
 * @var string   $email_heading    Campaign subject, reused as the email heading.
 * @var string   $broadcast_body   Raw HTML from the composer's wp_editor() field.
 * @var string   $broadcast_locale 'en' or 'vi'.
 * @var bool     $plain_text
 * @var WC_Email $email
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__, 2 ) . '/includes/epic-email-i18n.php';

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<?php
// $broadcast_body is deliberately admin-authored rich text from a
// wp_editor() field (not user-submitted input), so wp_kses_post() here is a
// defense-in-depth pass, not the primary trust boundary — same treatment
// class-email-newsletter-confirmation.php gives $additional_content.
echo wp_kses_post( $broadcast_body );
?>

<p style="font-size: 12px; color: #999999;">
	<?php echo esc_html( epic_email_str( 'bc_unsub', $broadcast_locale ) ); ?>
</p>

<?php
do_action( 'woocommerce_email_footer', $email );
