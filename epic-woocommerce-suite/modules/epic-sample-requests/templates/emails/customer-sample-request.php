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

require_once dirname( __DIR__, 2 ) . '/includes/epic-email-i18n.php';

$taste_text = $taste_label_vi ? $taste_label_vi : $taste;
$brew_text  = $brew_label_vi ? $brew_label_vi : $brew;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

	<p><?php printf( esc_html( epic_email_str( 'sp_hi', $request_locale ) ), esc_html( $requester_name ) ); ?></p>
	<p><?php echo esc_html( epic_email_str( 'sp_thanks', $request_locale ) ); ?></p>
	<p><strong><?php echo esc_html( epic_email_str( 'sp_deliver', $request_locale ) ); ?></strong> <?php echo esc_html( $address ); ?></p>
	<?php if ( $taste_text || $brew_text ) : ?>
	<p>
		<?php if ( $taste_text ) : ?><strong><?php echo esc_html( epic_email_str( 'sp_taste', $request_locale ) ); ?></strong> <?php echo esc_html( $taste_text ); ?><?php endif; ?>
		<?php if ( $taste_text && $brew_text ) : ?><br /><?php endif; ?>
		<?php if ( $brew_text ) : ?><strong><?php echo esc_html( epic_email_str( 'sp_brew', $request_locale ) ); ?></strong> <?php echo esc_html( $brew_text ); ?><?php endif; ?>
	</p>
	<?php endif; ?>
	<p><?php echo esc_html( epic_email_str( 'sp_contact', $request_locale ) ); ?></p>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
