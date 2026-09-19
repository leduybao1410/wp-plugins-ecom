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

$epic_email_i18n = dirname( __DIR__, 2 ) . '/includes/epic-email-i18n.php';
if ( file_exists( $epic_email_i18n ) ) {
	require_once $epic_email_i18n;
}
$epic_locale = function_exists( 'epic_email_locale' ) ? epic_email_locale( $subscriber_locale ) : 'vi';

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p>
	<?php echo esc_html( epic_email_str( 'nl_admin_intro', $epic_locale ) ); ?>
</p>

<table cellspacing="0" cellpadding="6" style="width:100%; border:1px solid #e5e5e5; margin: 16px 0;">
	<tr>
		<td style="padding:12px; background:#f7f7f7; width:180px;"><strong><?php echo esc_html( epic_email_str( 'label_email', $epic_locale ) ); ?></strong></td>
		<td style="padding:12px;"><?php echo esc_html( $subscriber_email ); ?></td>
	</tr>
	<tr>
		<td style="padding:12px; background:#f7f7f7;"><strong><?php echo esc_html( epic_email_str( 'label_subscribed_at', $epic_locale ) ); ?></strong></td>
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
	<?php echo esc_html( epic_email_str( 'nl_admin_list_note', $epic_locale ) ); ?>
</p>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
