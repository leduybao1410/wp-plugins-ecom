<?php
/**
 * Customer "thanks for your sample request" email (HTML).
 *
 * Bilingual body — English when the request was submitted on the English
 * storefront ('en'), Vietnamese otherwise (the store's primary customer
 * base; also the fallback for an unknown/blank locale). See the docblock on
 * class-email-sample-confirmation.php for the full reasoning.
 *
 * No $order here — this only ever uses `woocommerce_email_header`/`_footer`.
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

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<?php if ( $is_english ) : ?>
	<p><?php echo esc_html( sprintf( 'Hi %s,', $requester_name ) ); ?></p>
	<p>Thank you for requesting a free sample from EPIC Coffee Roaster. We've received your request and will send it out to you shortly.</p>
	<p><strong>Delivering to:</strong> <?php echo esc_html( $address ); ?></p>
	<?php if ( $taste_text || $brew_text ) : ?>
	<p>
		<?php if ( $taste_text ) : ?><strong>Favourite taste:</strong> <?php echo esc_html( $taste_text ); ?><?php endif; ?>
		<?php if ( $taste_text && $brew_text ) : ?><br /><?php endif; ?>
		<?php if ( $brew_text ) : ?><strong>Brew style:</strong> <?php echo esc_html( $brew_text ); ?><?php endif; ?>
	</p>
	<?php endif; ?>
	<p>Our team will contact you by phone if we need to confirm anything about the delivery. If any of the details above look wrong, just reply to this email and we'll fix it.</p>
<?php else : ?>
	<p><?php echo esc_html( sprintf( 'Chào %s,', $requester_name ) ); ?></p>
	<p>Cảm ơn bạn đã đăng ký nhận mẫu cà phê miễn phí từ EPIC Coffee Roaster. Chúng tôi đã nhận được yêu cầu và sẽ gửi mẫu đến bạn trong thời gian sớm nhất.</p>
	<p><strong>Địa chỉ nhận mẫu:</strong> <?php echo esc_html( $address ); ?></p>
	<?php if ( $taste_text || $brew_text ) : ?>
	<p>
		<?php if ( $taste_text ) : ?><strong>Khẩu vị bạn chọn:</strong> <?php echo esc_html( $taste_text ); ?><?php endif; ?>
		<?php if ( $taste_text && $brew_text ) : ?><br /><?php endif; ?>
		<?php if ( $brew_text ) : ?><strong>Cách pha:</strong> <?php echo esc_html( $brew_text ); ?><?php endif; ?>
	</p>
	<?php endif; ?>
	<p>Nếu cần xác nhận thông tin giao hàng, đội ngũ của chúng tôi sẽ liên hệ với bạn qua số điện thoại đã cung cấp. Nếu có thông tin nào chưa đúng, bạn chỉ cần trả lời email này và chúng tôi sẽ điều chỉnh.</p>
<?php endif; ?>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
