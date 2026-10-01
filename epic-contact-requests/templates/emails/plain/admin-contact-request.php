<?php
/**
 * Admin "new contact request" notification email (plain text).
 *
 * Vietnamese-only content — see the HTML template's docblock.
 *
 * @var string   $name
 * @var string   $lead_email
 * @var string   $phone
 * @var bool     $direct_consult
 * @var string   $address_line
 * @var string   $message
 * @var string   $lead_locale
 * @var string   $submitted_at
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

echo esc_html__( 'Có một yêu cầu liên hệ mới được gửi từ website.', 'epic-contact-requests' ) . "\n\n";

echo esc_html__( 'Họ tên', 'epic-contact-requests' ) . ': ' . esc_html( $name ) . "\n";
echo esc_html__( 'Số điện thoại', 'epic-contact-requests' ) . ': ' . esc_html( $phone ) . "\n";
if ( $lead_email ) {
	echo esc_html__( 'Email', 'epic-contact-requests' ) . ': ' . esc_html( $lead_email ) . "\n";
}
echo esc_html__( 'Tư vấn trực tiếp', 'epic-contact-requests' ) . ': ' . ( $direct_consult ? esc_html__( 'Có — khách muốn tư vấn trực tiếp tại TP. Hồ Chí Minh', 'epic-contact-requests' ) : esc_html__( 'Không', 'epic-contact-requests' ) ) . "\n";
if ( $direct_consult && $address_line ) {
	echo esc_html__( 'Địa chỉ hẹn tư vấn', 'epic-contact-requests' ) . ': ' . esc_html( $address_line ) . "\n";
}
if ( $message ) {
	echo esc_html__( 'Nội dung', 'epic-contact-requests' ) . ":\n" . esc_html( $message ) . "\n";
}
echo esc_html__( 'Thời gian gửi', 'epic-contact-requests' ) . ': ' . esc_html( $submitted_at );
if ( $lead_locale && 'unknown' !== $lead_locale ) {
	echo ' (' . esc_html( $lead_locale ) . ')';
}
echo "\n";

echo "\n----------------------------------------\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- same core filter every plain-text WC email template calls unescaped.
