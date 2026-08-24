<?php
/**
 * Customer "thanks for subscribing" confirmation email (plain text).
 *
 * Bilingual body — English when the subscriber signed up on the English
 * storefront ('en'), Vietnamese otherwise (see the HTML template's
 * docblock).
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

$is_english = 'en' === $subscriber_locale;

echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";

if ( $is_english ) {
	echo "Thanks for subscribing to the EPIC Roastery newsletter.\n\n";
	echo "You're on the list — we'll send you new bean releases, roastery updates and any future promotion coupons as soon as they're out.\n\n";
	echo "If this wasn't you, or you'd like to unsubscribe at any time, just reply to this email and we'll take care of it.\n";
} else {
	echo "Cảm ơn bạn đã đăng ký nhận tin từ EPIC Roastery.\n\n";
	echo "Bạn đã vào danh sách — chúng tôi sẽ gửi cho bạn cà phê mới ra mắt, cập nhật từ xưởng rang và mã giảm giá trong tương lai ngay khi có.\n\n";
	echo "Nếu không phải bạn đăng ký, hoặc bạn muốn hủy nhận tin bất cứ lúc nào, chỉ cần trả lời email này và chúng tôi sẽ xử lý ngay.\n";
}

echo "\n----------------------------------------\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- same core filter every plain-text WC email template calls unescaped.
