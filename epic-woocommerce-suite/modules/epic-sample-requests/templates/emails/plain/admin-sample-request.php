<?php
/**
 * Admin "new free-sample request" notification email (plain text).
 *
 * Vietnamese-only content — see the HTML template's docblock.
 *
 * @var string   $name
 * @var string   $requester_email
 * @var string   $phone
 * @var string   $province
 * @var string   $ward
 * @var string   $street
 * @var string   $address
 * @var string   $taste
 * @var string   $taste_label_vi
 * @var string   $brew
 * @var string   $brew_label_vi
 * @var string   $request_locale
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

echo esc_html__( 'Có một yêu cầu nhận mẫu cà phê miễn phí mới được gửi từ website.', 'epic-sample-requests' ) . "\n\n";

echo esc_html__( 'Họ tên:', 'epic-sample-requests' ) . ' ' . esc_html( $name ) . "\n";
echo esc_html__( 'Số điện thoại:', 'epic-sample-requests' ) . ' ' . esc_html( $phone ) . "\n";
if ( $requester_email ) {
	echo esc_html__( 'Email:', 'epic-sample-requests' ) . ' ' . esc_html( $requester_email ) . "\n";
}
echo esc_html__( 'Địa chỉ nhận mẫu:', 'epic-sample-requests' ) . ' ' . esc_html( $address ? $address : ( $street . ', ' . $ward . ', ' . $province ) ) . "\n";
if ( $taste ) {
	echo esc_html__( 'Khẩu vị yêu thích:', 'epic-sample-requests' ) . ' ' . esc_html( $taste_label_vi ? $taste_label_vi : $taste ) . "\n";
}
if ( $brew ) {
	echo esc_html__( 'Cách pha:', 'epic-sample-requests' ) . ' ' . esc_html( $brew_label_vi ? $brew_label_vi : $brew ) . "\n";
}
echo esc_html__( 'Thời gian gửi:', 'epic-sample-requests' ) . ' ' . esc_html( $submitted_at );
if ( $request_locale && 'unknown' !== $request_locale ) {
	echo ' (' . esc_html( $request_locale ) . ')';
}
echo "\n";

echo "\n----------------------------------------\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- same core filter every plain-text WC email template calls unescaped.
