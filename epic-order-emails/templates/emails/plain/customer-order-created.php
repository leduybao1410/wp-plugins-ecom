<?php
/**
 * Customer "order received" email (plain text).
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

echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";

printf(
	/* translators: %s: customer first name */
	esc_html__( 'Chao %s,', 'epic-order-emails' ),
	esc_html( $order->get_billing_first_name() )
);
echo "\n\n";
echo esc_html__( 'Cam on ban da dat hang tai EPIC Roastery. Chung toi da nhan duoc don hang cua ban va dang chuan bi.', 'epic-order-emails' ) . "\n\n";

echo "----------------------------------------\n\n";

do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

echo "----------------------------------------\n\n";

do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

echo "\n";
echo esc_html__( 'Chung toi se gui them email khi don hang duoc giao cho don vi van chuyen, kem ma van don de ban theo doi.', 'epic-order-emails' ) . "\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- same core filter every plain-text WC email template calls unescaped.
