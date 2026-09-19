<?php
/**
 * Admin "new free-sample request" notification email (plain text).
 *
 * Vietnamese-only content — see the HTML template's docblock.
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

echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";

echo esc_html( epic_email_str( 'sp_admin_intro', $epic_locale ) ) . "\n\n";

echo ( esc_html( epic_email_str( 'label_name', $epic_locale ) ) . ':' ) . ' ' . esc_html( $name ) . "\n";
echo ( esc_html( epic_email_str( 'label_phone', $epic_locale ) ) . ':' ) . ' ' . esc_html( $phone ) . "\n";
if ( $requester_email ) {
	echo ( esc_html( epic_email_str( 'label_email', $epic_locale ) ) . ':' ) . ' ' . esc_html( $requester_email ) . "\n";
}
echo ( esc_html( epic_email_str( 'sp_admin_address_label', $epic_locale ) ) . ':' ) . ' ' . esc_html( $address ? $address : ( $street . ', ' . $ward . ', ' . $province ) ) . "\n";
if ( $taste ) {
	echo ( esc_html( epic_email_str( 'sp_admin_taste_label', $epic_locale ) ) . ':' ) . ' ' . esc_html( $taste_label_vi ? $taste_label_vi : $taste ) . "\n";
}
if ( $brew ) {
	echo ( esc_html( epic_email_str( 'sp_admin_brew_label', $epic_locale ) ) . ':' ) . ' ' . esc_html( $brew_label_vi ? $brew_label_vi : $brew ) . "\n";
}
echo ( esc_html( epic_email_str( 'label_submitted_at', $epic_locale ) ) . ':' ) . ' ' . esc_html( $submitted_at );
if ( $request_locale && 'unknown' !== $request_locale ) {
	echo ' (' . esc_html( $request_locale ) . ')';
}
echo "\n";

echo "\n----------------------------------------\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- same core filter every plain-text WC email template calls unescaped.
