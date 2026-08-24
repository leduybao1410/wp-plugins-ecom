<?php
/**
 * Admin "new newsletter subscriber" notification email (plain text).
 *
 * Vietnamese-only content — see the HTML template's docblock.
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

echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";

echo esc_html__( 'Có một người mới đăng ký nhận tin từ website.', 'epic-newsletter-subscription' ) . "\n\n";

echo esc_html__( 'Email:', 'epic-newsletter-subscription' ) . ' ' . esc_html( $subscriber_email ) . "\n";
echo esc_html__( 'Thời gian đăng ký:', 'epic-newsletter-subscription' ) . ' ' . esc_html( $subscribed_at );
if ( $subscriber_locale && 'unknown' !== $subscriber_locale ) {
	echo ' (' . esc_html( $subscriber_locale ) . ')';
}
echo "\n";

echo esc_html__( 'Danh sách người đăng ký đầy đủ nằm trong WooCommerce → Newsletter Subscribers.', 'epic-newsletter-subscription' ) . "\n";

echo "\n----------------------------------------\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- same core filter every plain-text WC email template calls unescaped.
