<?php
/**
 * Plain-text counterpart to ../newsletter-broadcast.php. WC_Email calls this
 * only if a recipient's mail client requests plain text over HTML
 * (WooCommerce's multipart handling) — the composer itself doesn't expose an
 * "email type" choice for broadcasts (see class-email-newsletter-broadcast.php's
 * empty init_form_fields()), so this exists purely as the plain-text half of
 * that multipart pair.
 *
 * @var string   $email_heading
 * @var string   $broadcast_body
 * @var string   $broadcast_locale
 * @var bool     $plain_text
 * @var WC_Email $email
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_english = 'en' === $broadcast_locale;

echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n";
echo str_repeat( '-', 40 ) . "\n\n";

echo esc_html( wp_strip_all_tags( html_entity_decode( $broadcast_body ) ) ) . "\n\n";

echo esc_html(
	$is_english
		? "You're receiving this because you subscribed to the EPIC Roastery newsletter. To unsubscribe, just reply to this email and we'll take care of it."
		: 'Bạn nhận được email này vì đã đăng ký nhận tin từ EPIC Roastery. Để hủy đăng ký, chỉ cần trả lời email này và chúng tôi sẽ xử lý ngay.'
) . "\n";
