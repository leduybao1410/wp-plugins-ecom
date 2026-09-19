<?php
/**
 * Customer "order received" email (HTML).
 *
 * Reuses WooCommerce's own core template parts for the header/footer and the
 * order/address details tables (emails/email-order-details.php,
 * emails/email-addresses.php) rather than re-building an item table by hand
 * — same content WooCommerce's native order emails render, just wrapped in
 * EPIC's own greeting/heading instead of the stock copy.
 *
 * Locale-aware: the storefront locale captured at checkout is stored on the
 * order as `_epic_locale` meta, and the body copy is looked up from
 * includes/epic-email-i18n.php via epic_email_str(). Unknown/legacy orders
 * fall back to Vietnamese, preserving the previous behaviour.
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
	<?php echo esc_html( epic_email_str( 'or_thanks', $epic_locale ) ); ?>
</p>

<?php
/**
 * Core WooCommerce template part — order number/date, item table, totals.
 * Same one every native WooCommerce order email uses.
 */
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

/**
 * Core WooCommerce template part — billing + shipping address blocks.
 */
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );
?>

<p>
	<?php echo esc_html( epic_email_str( 'or_ship_note', $epic_locale ) ); ?>
</p>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
