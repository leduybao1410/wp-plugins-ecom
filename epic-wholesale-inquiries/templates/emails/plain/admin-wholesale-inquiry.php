<?php
/**
 * Admin "new wholesale inquiry" notification email (plain text).
 *
 * Vietnamese-only content — see the HTML template's docblock.
 *
 * @var string   $business_name
 * @var string   $phone
 * @var string   $contact
 * @var string   $topic
 * @var string   $topic_label_vi
 * @var string   $details
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

echo esc_html__( 'Có một yêu cầu báo giá sỉ mới được gửi từ website.', 'epic-wholesale-inquiries' ) . "\n\n";

echo esc_html__( 'Tên doanh nghiệp:', 'epic-wholesale-inquiries' ) . ' ' . esc_html( $business_name ) . "\n";
echo esc_html__( 'Số điện thoại:', 'epic-wholesale-inquiries' ) . ' ' . esc_html( $phone ) . "\n";
echo esc_html__( 'Liên hệ:', 'epic-wholesale-inquiries' ) . ' ' . esc_html( $contact ) . "\n";
echo esc_html__( 'Loại yêu cầu:', 'epic-wholesale-inquiries' ) . ' ' . esc_html( $topic_label_vi ? $topic_label_vi : $topic ) . "\n";
if ( $details ) {
	echo esc_html__( 'Nội dung:', 'epic-wholesale-inquiries' ) . "\n" . esc_html( $details ) . "\n";
}
echo esc_html__( 'Thời gian gửi:', 'epic-wholesale-inquiries' ) . ' ' . esc_html( $submitted_at );
if ( $lead_locale && 'unknown' !== $lead_locale ) {
	echo ' (' . esc_html( $lead_locale ) . ')';
}
echo "\n";

echo "\n----------------------------------------\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- same core filter every plain-text WC email template calls unescaped.
