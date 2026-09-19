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

$epic_email_i18n = dirname( __DIR__, 2 ) . '/includes/epic-email-i18n.php';
if ( file_exists( $epic_email_i18n ) ) {
	require_once $epic_email_i18n;
}
$epic_locale = function_exists( 'epic_email_locale' ) ? epic_email_locale( $request_locale ) : 'vi';

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p>
	<?php echo esc_html( epic_email_str( 'sp_admin_intro', $epic_locale ) ); ?>
</p>

<table cellspacing="0" cellpadding="6" style="width:100%; border:1px solid #e5e5e5; margin: 16px 0;">
	<tr>
		<td style="padding:12px; background:#f7f7f7; width:180px;"><strong><?php echo esc_html( epic_email_str( 'label_name', $epic_locale ) ); ?></strong></td>
		<td style="padding:12px;"><?php echo esc_html( $name ); ?></td>
	</tr>
	<tr>
		<td style="padding:12px; background:#f7f7f7;"><strong><?php echo esc_html( epic_email_str( 'label_phone', $epic_locale ) ); ?></strong></td>
		<td style="padding:12px;"><?php echo esc_html( $phone ); ?></td>
	</tr>
	<?php if ( $requester_email ) : ?>
	<tr>
		<td style="padding:12px; background:#f7f7f7;"><strong><?php echo esc_html( epic_email_str( 'label_email', $epic_locale ) ); ?></strong></td>
		<td style="padding:12px;"><?php echo esc_html( $requester_email ); ?></td>
	</tr>
	<?php endif; ?>
	<tr>
		<td style="padding:12px; background:#f7f7f7; vertical-align:top;"><strong><?php echo esc_html( epic_email_str( 'sp_admin_address_label', $epic_locale ) ); ?></strong></td>
		<td style="padding:12px; white-space:pre-wrap;"><?php echo esc_html( $address ? $address : ( $street . ', ' . $ward . ', ' . $province ) ); ?></td>
	</tr>
	<?php if ( $taste ) : ?>
	<tr>
		<td style="padding:12px; background:#f7f7f7;"><strong><?php echo esc_html( epic_email_str( 'sp_admin_taste_label', $epic_locale ) ); ?></strong></td>
		<td style="padding:12px;"><?php echo esc_html( $taste_label_vi ? $taste_label_vi : $taste ); ?></td>
	</tr>
	<?php endif; ?>
	<?php if ( $brew ) : ?>
	<tr>
		<td style="padding:12px; background:#f7f7f7;"><strong><?php echo esc_html( epic_email_str( 'sp_admin_brew_label', $epic_locale ) ); ?></strong></td>
		<td style="padding:12px;"><?php echo esc_html( $brew_label_vi ? $brew_label_vi : $brew ); ?></td>
	</tr>
	<?php endif; ?>
	<tr>
		<td style="padding:12px; background:#f7f7f7;"><strong><?php echo esc_html( epic_email_str( 'label_submitted_at', $epic_locale ) ); ?></strong></td>
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
