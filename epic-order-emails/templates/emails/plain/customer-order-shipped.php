<?php
/**
 * Customer "order shipped" email (plain text).
 *
 * @var WC_Order $order
 * @var string   $tracking_code
 * @var string   $eta
 * @var bool     $is_cod
 * @var float    $cod_amount
 * @var string   $email_heading
 * @var string   $additional_content
 * @var bool     $sent_to_admin
 * @var bool     $plain_text
 * @var WC_Email $email
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tracking_url = function_exists( 'epic_order_emails_ghn_tracking_url' ) ? epic_order_emails_ghn_tracking_url( $tracking_code ) : '';

echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";

printf(
	/* translators: %s: customer first name */
	esc_html__( 'Chao %s,', 'epic-order-emails' ),
	esc_html( $order->get_billing_first_name() )
);
echo "\n\n";

printf(
	/* translators: %s: order number */
	esc_html__( 'Don hang #%s cua ban da duoc ban giao cho don vi van chuyen GHN (Giao Hang Nhanh).', 'epic-order-emails' ),
	esc_html( $order->get_order_number() )
);
echo "\n\n";

echo esc_html__( 'Ma van don GHN:', 'epic-order-emails' ) . ' ' . esc_html( $tracking_code ) . "\n";
if ( $tracking_url ) {
	echo esc_html__( 'Theo doi don hang:', 'epic-order-emails' ) . ' ' . esc_url( $tracking_url ) . "\n";
}
if ( $eta ) {
	echo esc_html__( 'Du kien giao hang:', 'epic-order-emails' ) . ' ' . esc_html( $eta ) . "\n";
}
if ( $is_cod ) {
	echo esc_html__( 'So tien thanh toan khi nhan hang (COD):', 'epic-order-emails' ) . ' ' . wp_strip_all_tags( wc_price( $cod_amount ) ) . "\n";
}
echo "\n----------------------------------------\n\n";

do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- same core filter every plain-text WC email template calls unescaped.
