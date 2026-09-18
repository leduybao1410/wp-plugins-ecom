<?php
/**
 * Customer "thanks for your sample request" email (plain text).
 *
 * Bilingual body — see the HTML template's docblock.
 *
 * @var string   $requester_name
 * @var string   $requester_email
 * @var string   $taste
 * @var string   $taste_label_vi
 * @var string   $brew
 * @var string   $brew_label_vi
 * @var string   $address
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

$is_english = 'en' === $request_locale;
$taste_text = $taste_label_vi ? $taste_label_vi : $taste;
$brew_text  = $brew_label_vi ? $brew_label_vi : $brew;

echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";

if ( $is_english ) {
	echo esc_html( sprintf( 'Hi %s,', $requester_name ) ) . "\n\n";
	echo esc_html( 'Thank you for requesting a free sample from EPIC Coffee Roaster. We\'ve received your request and will send it out to you shortly.' ) . "\n\n";
	echo esc_html( 'Delivering to:' ) . ' ' . esc_html( $address ) . "\n";
	if ( $taste_text ) {
		echo esc_html( 'Favourite taste:' ) . ' ' . esc_html( $taste_text ) . "\n";
	}
	if ( $brew_text ) {
		echo esc_html( 'Brew style:' ) . ' ' . esc_html( $brew_text ) . "\n";
	}
	echo "\n";
	echo esc_html( 'Our team will contact you by phone if we need to confirm anything about the delivery. If any of the details above look wrong, just reply to this email and we\'ll fix it.' ) . "\n\n";
} else {
	echo esc_html( sprintf( 'Chào %s,', $requester_name ) ) . "\n\n";
	echo esc_html( 'Cảm ơn bạn đã đăng ký nhận mẫu cà phê miễn phí từ EPIC Coffee Roaster. Chúng tôi đã nhận được yêu cầu và sẽ gửi mẫu đến bạn trong thời gian sớm nhất.' ) . "\n\n";
	echo esc_html( 'Địa chỉ nhận mẫu:' ) . ' ' . esc_html( $address ) . "\n";
	if ( $taste_text ) {
		echo esc_html( 'Khẩu vị bạn chọn:' ) . ' ' . esc_html( $taste_text ) . "\n";
	}
	if ( $brew_text ) {
		echo esc_html( 'Cách pha:' ) . ' ' . esc_html( $brew_text ) . "\n";
	}
	echo "\n";
	echo esc_html( 'Nếu cần xác nhận thông tin giao hàng, đội ngũ của chúng tôi sẽ liên hệ với bạn qua số điện thoại đã cung cấp. Nếu có thông tin nào chưa đúng, bạn chỉ cần trả lời email này và chúng tôi sẽ điều chỉnh.' ) . "\n\n";
}

echo "\n----------------------------------------\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- same core filter every plain-text WC email template calls unescaped.
