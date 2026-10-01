<?php
/**
 * Customer "order delivered" email (plain text).
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

echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";

printf(
	/* translators: %s: customer first name */
	esc_html( epic_email_str( 'or_hi', $epic_locale ) ),
	esc_html( $order->get_billing_first_name() )
);
echo "\n\n";

printf(
	/* translators: %s: order number */
	esc_html( epic_email_str( 'od_delivered', $epic_locale ) ),
	esc_html( $order->get_order_number() )
);
echo "\n\n";

echo esc_html( epic_email_str( 'od_feedback', $epic_locale ) ) . "\n\n";

echo "\n----------------------------------------\n\n";

do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
