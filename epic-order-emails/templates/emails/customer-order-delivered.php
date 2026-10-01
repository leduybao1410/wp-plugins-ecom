<?php
/**
 * Customer "order delivered" email (HTML).
 *
 * Locale-aware — same `_epic_locale` order meta + epic_email_str() lookup as
 * the other customer emails, with a Vietnamese fallback.
 *
 * @var WC_Order $order
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

$epic_locale = ( $order instanceof WC_Order ) ? (string) $order->get_meta( '_epic_locale' ) : '';
if ( '' === $epic_locale ) {
	$epic_locale = 'vi';
}

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p>
	<?php
	printf(
		/* translators: %s: customer first name */
		esc_html( epic_email_str( 'or_hi', $epic_locale ) ),
		esc_html( $order->get_billing_first_name() )
	);
	?>
</p>
<p>
	<?php
	printf(
		/* translators: %s: order number */
		esc_html( epic_email_str( 'od_delivered', $epic_locale ) ),
		esc_html( $order->get_order_number() )
	);
	?>
</p>
<p><?php echo esc_html( epic_email_str( 'od_feedback', $epic_locale ) ); ?></p>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
