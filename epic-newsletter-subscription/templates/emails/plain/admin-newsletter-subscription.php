<?php
/**
 * Admin "new newsletter subscriber" notification email (plain text).
 *
 * Vietnamese-only content — see the HTML template's docblock.
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

echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";

echo esc_html( epic_email_str( 'nl_admin_intro', $epic_locale ) ) . "\n\n";

echo ( esc_html( epic_email_str( 'label_email', $epic_locale ) ) . ':' ) . ' ' . esc_html( $subscriber_email ) . "\n";
echo ( esc_html( epic_email_str( 'label_subscribed_at', $epic_locale ) ) . ':' ) . ' ' . esc_html( $subscribed_at );
if ( $subscriber_locale && 'unknown' !== $subscriber_locale ) {
	echo ' (' . esc_html( $subscriber_locale ) . ')';
}
echo "\n";

echo esc_html( epic_email_str( 'nl_admin_list_note', $epic_locale ) ) . "\n";

echo "\n----------------------------------------\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- same core filter every plain-text WC email template calls unescaped.
