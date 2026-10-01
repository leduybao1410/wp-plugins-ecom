<?php
/**
 * Admin "new contact request" notification email (HTML).
 *
 * Vietnamese-only content — this is staff-facing, so the copy is hard-coded
 * here rather than routed through the shared epic-email-i18n.php map (see
 * the docblock on class-email-contact-request.php's constructor for why).
 *
 * No $order here — this only ever uses `woocommerce_email_header`/`_footer`,
 * not the order/customer detail hooks other EPIC emails call.
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

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p>
	<?php esc_html_e( 'Có một yêu cầu liên hệ mới được gửi từ website.', 'epic-contact-requests' ); ?>
</p>

<table cellspacing="0" cellpadding="6" style="width:100%; border:1px solid #e5e5e5; margin: 16px 0;">
	<tr>
		<td style="padding:12px; background:#f7f7f7; width:180px;"><strong><?php esc_html_e( 'Họ tên', 'epic-contact-requests' ); ?></strong></td>
		<td style="padding:12px;"><?php echo esc_html( $name ); ?></td>
	</tr>
	<tr>
		<td style="padding:12px; background:#f7f7f7;"><strong><?php esc_html_e( 'Số điện thoại', 'epic-contact-requests' ); ?></strong></td>
		<td style="padding:12px;"><?php echo esc_html( $phone ); ?></td>
	</tr>
	<?php if ( $lead_email ) : ?>
	<tr>
		<td style="padding:12px; background:#f7f7f7;"><strong><?php esc_html_e( 'Email', 'epic-contact-requests' ); ?></strong></td>
		<td style="padding:12px;"><?php echo esc_html( $lead_email ); ?></td>
	</tr>
	<?php endif; ?>
	<tr>
		<td style="padding:12px; background:#f7f7f7;"><strong><?php esc_html_e( 'Tư vấn trực tiếp', 'epic-contact-requests' ); ?></strong></td>
		<td style="padding:12px;">
			<?php echo $direct_consult ? esc_html__( 'Có — khách muốn tư vấn trực tiếp tại TP. Hồ Chí Minh', 'epic-contact-requests' ) : esc_html__( 'Không', 'epic-contact-requests' ); ?>
		</td>
	</tr>
	<?php if ( $direct_consult && $address_line ) : ?>
	<tr>
		<td style="padding:12px; background:#f7f7f7; vertical-align:top;"><strong><?php esc_html_e( 'Địa chỉ hẹn tư vấn', 'epic-contact-requests' ); ?></strong></td>
		<td style="padding:12px;"><?php echo esc_html( $address_line ); ?></td>
	</tr>
	<?php endif; ?>
	<?php if ( $message ) : ?>
	<tr>
		<td style="padding:12px; background:#f7f7f7; vertical-align:top;"><strong><?php esc_html_e( 'Nội dung', 'epic-contact-requests' ); ?></strong></td>
		<td style="padding:12px; white-space:pre-wrap;"><?php echo esc_html( $message ); ?></td>
	</tr>
	<?php endif; ?>
	<tr>
		<td style="padding:12px; background:#f7f7f7;"><strong><?php esc_html_e( 'Thời gian gửi', 'epic-contact-requests' ); ?></strong></td>
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
