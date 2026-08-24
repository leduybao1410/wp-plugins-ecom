<?php
/**
 * Customer "thanks for subscribing" confirmation email (HTML).
 *
 * Bilingual body — English when the subscriber signed up on the English
 * storefront ('en'), Vietnamese otherwise (the store's primary customer
 * base; also the fallback for an unknown/blank locale). See the docblock on
 * class-email-newsletter-confirmation.php for the full reasoning.
 *
 * No $order here (see class-email-newsletter-confirmation.php's docblock) —
 * this only ever uses `woocommerce_email_header`/`_footer`, not the
 * `woocommerce_email_order_details`/`_customer_details` hooks other EPIC
 * emails call, since those require a WC_Order.
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

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<?php if ( $is_english ) : ?>
	<p>Thanks for subscribing to the EPIC Roastery newsletter.</p>
	<p>You're on the list — we'll send you new bean releases, roastery updates and any future promotion coupons as soon as they're out.</p>
<?php else : ?>
	<p>Cảm ơn bạn đã đăng ký nhận tin từ EPIC Roastery.</p>
	<p>Bạn đã vào danh sách — chúng tôi sẽ gửi cho bạn cà phê mới ra mắt, cập nhật từ xưởng rang và mã giảm giá trong tương lai ngay khi có.</p>
<?php endif; ?>

<p>
	<?php
	if ( $is_english ) {
		esc_html_e( 'If this wasn\'t you, or you\'d like to unsubscribe at any time, just reply to this email and we\'ll take care of it.', 'epic-newsletter-subscription' );
	} else {
		esc_html_e( 'Nếu không phải bạn đăng ký, hoặc bạn muốn hủy nhận tin bất cứ lúc nào, chỉ cần trả lời email này và chúng tôi sẽ xử lý ngay.', 'epic-newsletter-subscription' );
	}
	?>
</p>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
