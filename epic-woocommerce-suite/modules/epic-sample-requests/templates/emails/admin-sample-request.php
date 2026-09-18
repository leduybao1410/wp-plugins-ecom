<?php
/**
 * Admin "new free-sample request" notification email (HTML).
 *
 * Vietnamese-only content — see the docblock on
 * class-email-sample-request.php's constructor for why.
 *
 * No $order here — this only ever uses `woocommerce_email_header`/`_footer`.
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

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p>
	<?php esc_html_e( 'Có một yêu cầu nhận mẫu cà phê miễn phí mới được gửi từ website.', 'epic-sample-requests' ); ?>
</p>

<table cellspacing="0" cellpadding="6" style="width:100%; border:1px solid #e5e5e5; margin: 16px 0;">
	<tr>
		<td style="padding:12px; background:#f7f7f7; width:180px;"><strong><?php esc_html_e( 'Họ tên', 'epic-sample-requests' ); ?></strong></td>
		<td style="padding:12px;"><?php echo esc_html( $name ); ?></td>
	</tr>
	<tr>
		<td style="padding:12px; background:#f7f7f7;"><strong><?php esc_html_e( 'Số điện thoại', 'epic-sample-requests' ); ?></strong></td>
		<td style="padding:12px;"><?php echo esc_html( $phone ); ?></td>
	</tr>
	<?php if ( $requester_email ) : ?>
	<tr>
		<td style="padding:12px; background:#f7f7f7;"><strong><?php esc_html_e( 'Email', 'epic-sample-requests' ); ?></strong></td>
		<td style="padding:12px;"><?php echo esc_html( $requester_email ); ?></td>
	</tr>
	<?php endif; ?>
	<tr>
		<td style="padding:12px; background:#f7f7f7; vertical-align:top;"><strong><?php esc_html_e( 'Địa chỉ nhận mẫu', 'epic-sample-requests' ); ?></strong></td>
		<td style="padding:12px; white-space:pre-wrap;"><?php echo esc_html( $address ? $address : ( $street . ', ' . $ward . ', ' . $province ) ); ?></td>
	</tr>
	<?php if ( $taste ) : ?>
	<tr>
		<td style="padding:12px; background:#f7f7f7;"><strong><?php esc_html_e( 'Khẩu vị yêu thích', 'epic-sample-requests' ); ?></strong></td>
		<td style="padding:12px;"><?php echo esc_html( $taste_label_vi ? $taste_label_vi : $taste ); ?></td>
	</tr>
	<?php endif; ?>
	<?php if ( $brew ) : ?>
	<tr>
		<td style="padding:12px; background:#f7f7f7;"><strong><?php esc_html_e( 'Cách pha', 'epic-sample-requests' ); ?></strong></td>
		<td style="padding:12px;"><?php echo esc_html( $brew_label_vi ? $brew_label_vi : $brew ); ?></td>
	</tr>
	<?php endif; ?>
	<tr>
		<td style="padding:12px; background:#f7f7f7;"><strong><?php esc_html_e( 'Thời gian gửi', 'epic-sample-requests' ); ?></strong></td>
		<td style="padding:12px;">
			<?php
			echo esc_html( $submitted_at );
			if ( $request_locale && 'unknown' !== $request_locale ) {
				echo ' &middot; ' . esc_html( $request_locale ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static separator, dynamic part escaped above.
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
