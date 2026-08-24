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
 * Vietnamese-only content, per the store's customer base. Hard-coded rather
 * than routed through WordPress's gettext .mo translation system: this
 * project's other plugins ship an empty /languages folder with no compiled
 * .mo (see epic-ghn-shipping/languages), so there's no existing translation
 * pipeline here to plug into. If a real i18n pipeline gets set up later
 * (e.g. to also support an English-language version of this email), these
 * hard-coded strings can move behind __() calls with an actual .mo instead.
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

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p>
	<?php
	printf(
		/* translators: %s: customer first name */
		esc_html__( 'Chào %s,', 'epic-order-emails' ),
		esc_html( $order->get_billing_first_name() )
	);
	?>
</p>
<p>
	<?php esc_html_e( 'Cảm ơn bạn đã đặt hàng tại EPIC Roastery. Chúng tôi đã nhận được đơn hàng của bạn và đang chuẩn bị.', 'epic-order-emails' ); ?>
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
	<?php esc_html_e( 'Chúng tôi sẽ gửi thêm email khi đơn hàng được giao cho đơn vị vận chuyển, kèm mã vận đơn để bạn theo dõi.', 'epic-order-emails' ); ?>
</p>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
