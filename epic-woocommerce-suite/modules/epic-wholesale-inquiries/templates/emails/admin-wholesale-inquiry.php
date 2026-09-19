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

$epic_email_i18n = dirname( __DIR__, 2 ) . '/includes/epic-email-i18n.php';
if ( file_exists( $epic_email_i18n ) ) {
	require_once $epic_email_i18n;
}
$epic_locale = function_exists( 'epic_email_locale' ) ? epic_email_locale( $lead_locale ) : 'vi';

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p>
	<?php echo esc_html( epic_email_str( 'wi_admin_intro', $epic_locale ) ); ?>
</p>

<table cellspacing="0" cellpadding="6" style="width:100%; border:1px solid #e5e5e5; margin: 16px 0;">
	<tr>
		<td style="padding:12px; background:#f7f7f7; width:180px;"><strong><?php echo esc_html( epic_email_str( 'wi_admin_business_label', $epic_locale ) ); ?></strong></td>
		<td style="padding:12px;"><?php echo esc_html( $business_name ); ?></td>
	</tr>
	<tr>
		<td style="padding:12px; background:#f7f7f7;"><strong><?php echo esc_html( epic_email_str( 'label_phone', $epic_locale ) ); ?></strong></td>
		<td style="padding:12px;"><?php echo esc_html( $phone ); ?></td>
	</tr>
	<tr>
		<td style="padding:12px; background:#f7f7f7;"><strong><?php echo esc_html( epic_email_str( 'wi_admin_contact_label', $epic_locale ) ); ?></strong></td>
		<td style="padding:12px;"><?php echo esc_html( $contact ); ?></td>
	</tr>
	<tr>
		<td style="padding:12px; background:#f7f7f7;"><strong><?php echo esc_html( epic_email_str( 'wi_admin_topic_label', $epic_locale ) ); ?></strong></td>
		<td style="padding:12px;"><?php echo esc_html( $topic_label_vi ? $topic_label_vi : $topic ); ?></td>
	</tr>
	<?php if ( $details ) : ?>
	<tr>
		<td style="padding:12px; background:#f7f7f7; vertical-align:top;"><strong><?php echo esc_html( epic_email_str( 'wi_admin_details_label', $epic_locale ) ); ?></strong></td>
		<td style="padding:12px; white-space:pre-wrap;"><?php echo esc_html( $details ); ?></td>
	</tr>
	<?php endif; ?>
	<tr>
		<td style="padding:12px; background:#f7f7f7;"><strong><?php echo esc_html( epic_email_str( 'label_submitted_at', $epic_locale ) ); ?></strong></td>
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
