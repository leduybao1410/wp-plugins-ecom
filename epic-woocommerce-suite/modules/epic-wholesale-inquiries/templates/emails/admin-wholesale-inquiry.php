<?php
/**
 * Admin "new wholesale inquiry" notification email (HTML).
 *
 * Vietnamese-only content — see the docblock on class-email-wholesale-inquiry.php's
 * constructor for why (same decision as epic-order-emails' customer emails).
 *
 * No $order here (see class-email-wholesale-inquiry.php's docblock) — this
 * only ever uses `woocommerce_email_header`/`_footer`, not the
 * `woocommerce_email_order_details`/`_customer_details` hooks other EPIC
 * emails call, since those require a WC_Order.
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

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p>
	<?php esc_html_e( 'Có một yêu cầu báo giá sỉ mới được gửi từ website.', 'epic-wholesale-inquiries' ); ?>
</p>

<table cellspacing="0" cellpadding="6" style="width:100%; border:1px solid #e5e5e5; margin: 16px 0;">
	<tr>
		<td style="padding:12px; background:#f7f7f7; width:180px;"><strong><?php esc_html_e( 'Tên doanh nghiệp', 'epic-wholesale-inquiries' ); ?></strong></td>
		<td style="padding:12px;"><?php echo esc_html( $business_name ); ?></td>
	</tr>
	<tr>
		<td style="padding:12px; background:#f7f7f7;"><strong><?php esc_html_e( 'Số điện thoại', 'epic-wholesale-inquiries' ); ?></strong></td>
		<td style="padding:12px;"><?php echo esc_html( $phone ); ?></td>
	</tr>
	<tr>
		<td style="padding:12px; background:#f7f7f7;"><strong><?php esc_html_e( 'Liên hệ', 'epic-wholesale-inquiries' ); ?></strong></td>
		<td style="padding:12px;"><?php echo esc_html( $contact ); ?></td>
	</tr>
	<tr>
		<td style="padding:12px; background:#f7f7f7;"><strong><?php esc_html_e( 'Loại yêu cầu', 'epic-wholesale-inquiries' ); ?></strong></td>
		<td style="padding:12px;"><?php echo esc_html( $topic_label_vi ? $topic_label_vi : $topic ); ?></td>
	</tr>
	<?php if ( $details ) : ?>
	<tr>
		<td style="padding:12px; background:#f7f7f7; vertical-align:top;"><strong><?php esc_html_e( 'Nội dung', 'epic-wholesale-inquiries' ); ?></strong></td>
		<td style="padding:12px; white-space:pre-wrap;"><?php echo esc_html( $details ); ?></td>
	</tr>
	<?php endif; ?>
	<tr>
		<td style="padding:12px; background:#f7f7f7;"><strong><?php esc_html_e( 'Thời gian gửi', 'epic-wholesale-inquiries' ); ?></strong></td>
		<td style="padding:12px;">
			<?php
			echo esc_html( $submitted_at );
			if ( $lead_locale && 'unknown' !== $lead_locale ) {
				echo ' &middot; ' . esc_html( $lead_locale ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static separator, dynamic part escaped above.
			}
			?>
		</td>
	</tr>
</table>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
