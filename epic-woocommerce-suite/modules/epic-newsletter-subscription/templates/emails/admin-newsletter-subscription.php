<?php
/**
 * Admin "new newsletter subscriber" notification email (HTML).
 *
 * Vietnamese-only content — see the docblock on
 * class-email-newsletter-subscription.php's constructor for why (same
 * decision as every other EPIC email).
 *
 * No $order here (see class-email-newsletter-subscription.php's docblock) —
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

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p>
	<?php esc_html_e( 'Có một người mới đăng ký nhận tin từ website.', 'epic-newsletter-subscription' ); ?>
</p>

<table cellspacing="0" cellpadding="6" style="width:100%; border:1px solid #e5e5e5; margin: 16px 0;">
	<tr>
		<td style="padding:12px; background:#f7f7f7; width:180px;"><strong><?php esc_html_e( 'Email', 'epic-newsletter-subscription' ); ?></strong></td>
		<td style="padding:12px;"><?php echo esc_html( $subscriber_email ); ?></td>
	</tr>
	<tr>
		<td style="padding:12px; background:#f7f7f7;"><strong><?php esc_html_e( 'Thời gian đăng ký', 'epic-newsletter-subscription' ); ?></strong></td>
		<td style="padding:12px;">
			<?php
			echo esc_html( $subscribed_at );
			if ( $subscriber_locale && 'unknown' !== $subscriber_locale ) {
				echo ' &middot; ' . esc_html( $subscriber_locale ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static separator, dynamic part escaped above.
			}
			?>
		</td>
	</tr>
</table>

<p>
	<?php esc_html_e( 'Danh sách người đăng ký đầy đủ nằm trong WooCommerce → Newsletter Subscribers.', 'epic-newsletter-subscription' ); ?>
</p>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
