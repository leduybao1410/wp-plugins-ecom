<?php
/**
 * Plugin Name: EPIC Support FAQ
 * Description: Administrator-managed FAQ records, compiled context bundles and the website FAQ source.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Text Domain: epic-support-faq
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'EPIC_SUPPORT_FAQ_VERSION', '0.1.0' );
define( 'EPIC_SUPPORT_FAQ_FILE', __FILE__ );
define( 'EPIC_SUPPORT_FAQ_DIR', plugin_dir_path( __FILE__ ) );

require_once EPIC_SUPPORT_FAQ_DIR . 'includes/class-store.php';
require_once EPIC_SUPPORT_FAQ_DIR . 'includes/class-rest-api.php';

register_activation_hook( EPIC_SUPPORT_FAQ_FILE, static function () { Epic_Support_Faq_Store::install( true ); } );
add_action( 'plugins_loaded', array( 'Epic_Support_Faq_Store', 'maybe_upgrade' ) );
add_action( 'rest_api_init', array( 'Epic_Support_Faq_Rest_Api', 'register_routes' ) );

add_action( 'admin_menu', 'epic_support_faq_add_settings_page' );
add_action( 'admin_init', 'epic_support_faq_register_settings' );

function epic_support_faq_add_settings_page() {
	add_options_page( __( 'EPIC Support FAQ', 'epic-support-faq' ), __( 'EPIC Support FAQ', 'epic-support-faq' ), 'manage_options', 'epic-support-faq', 'epic_support_faq_render_settings_page' );
}

function epic_support_faq_register_settings() {
	register_setting( 'epic_support_faq', 'epic_support_faq_translate_url', array( 'type' => 'string', 'sanitize_callback' => 'epic_support_faq_sanitize_translate_url' ) );
	register_setting( 'epic_support_faq', 'epic_support_faq_translate_secret', array( 'type' => 'string', 'sanitize_callback' => 'epic_support_faq_sanitize_translate_secret' ) );
	add_settings_section( 'epic_support_faq_translation', __( 'Automatic translation', 'epic-support-faq' ), '__return_false', 'epic-support-faq' );
	add_settings_field( 'epic_support_faq_translate_url', __( 'n8n webhook URL', 'epic-support-faq' ), 'epic_support_faq_render_translate_url', 'epic-support-faq', 'epic_support_faq_translation' );
	add_settings_field( 'epic_support_faq_translate_secret', __( 'Webhook secret', 'epic-support-faq' ), 'epic_support_faq_render_translate_secret', 'epic-support-faq', 'epic_support_faq_translation' );
}

function epic_support_faq_sanitize_translate_url( $value ) {
	$value = is_string( $value ) ? trim( $value ) : '';
	if ( '' === $value ) { return ''; }
	$url = esc_url_raw( $value, array( 'https' ) );
	if ( ! $url || 0 !== strpos( $url, 'https://' ) ) {
		add_settings_error( 'epic_support_faq', 'invalid_translate_url', __( 'Use the exact HTTPS n8n webhook URL.', 'epic-support-faq' ) );
		return (string) get_option( 'epic_support_faq_translate_url', '' );
	}
	return $url;
}

function epic_support_faq_sanitize_translate_secret( $value ) {
	$value = is_string( $value ) ? trim( $value ) : '';
	if ( '' === $value ) { return (string) get_option( 'epic_support_faq_translate_secret', '' ); }
	if ( strlen( $value ) < 16 || strlen( $value ) > 256 ) {
		add_settings_error( 'epic_support_faq', 'invalid_translate_secret', __( 'The webhook secret must be 16–256 characters. The previous value is unchanged.', 'epic-support-faq' ) );
		return (string) get_option( 'epic_support_faq_translate_secret', '' );
	}
	return $value;
}

function epic_support_faq_render_translate_url() {
	$value = defined( 'EPIC_SUPPORT_FAQ_TRANSLATE_URL' ) && EPIC_SUPPORT_FAQ_TRANSLATE_URL ? (string) EPIC_SUPPORT_FAQ_TRANSLATE_URL : (string) get_option( 'epic_support_faq_translate_url', '' );
	printf( '<input type="url" class="regular-text" name="epic_support_faq_translate_url" value="%s" placeholder="https://n8n.example.com/webhook/epic-faq-translate" />', esc_attr( $value ) );
	echo '<p class="description">' . esc_html__( 'Webhook that translates the Vietnamese FAQ into en, ru, hi, zh, ko and ja. Configure the same secret as the n8n header credential.', 'epic-support-faq' ) . '</p>';
}

function epic_support_faq_render_translate_secret() {
	$configured = '' !== (string) get_option( 'epic_support_faq_translate_secret', '' ) || ( defined( 'EPIC_SUPPORT_FAQ_TRANSLATE_SECRET' ) && EPIC_SUPPORT_FAQ_TRANSLATE_SECRET );
	printf( '<input type="password" class="regular-text" name="epic_support_faq_translate_secret" value="" autocomplete="new-password" placeholder="%s" />', $configured ? esc_attr__( 'Đã cấu hình — để trống để giữ nguyên', 'epic-support-faq' ) : '' );
	echo '<p class="description">' . esc_html__( 'Sent as the X-EPIC-Faq-Key header. The saved value is never shown.', 'epic-support-faq' ) . '</p>';
}

function epic_support_faq_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	$config = Epic_Support_Faq_Store::translation_config();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'EPIC Support FAQ', 'epic-support-faq' ); ?></h1>
		<p><?php esc_html_e( 'FAQ records are edited in the operations console at /ops. This page only configures automatic translation.', 'epic-support-faq' ); ?></p>
		<p><strong><?php echo $config['url'] && '' !== $config['secret'] ? esc_html__( 'Translation webhook is configured.', 'epic-support-faq' ) : esc_html__( 'Translation webhook is not configured yet.', 'epic-support-faq' ); ?></strong></p>
		<form method="post" action="options.php">
			<?php settings_fields( 'epic_support_faq' ); do_settings_sections( 'epic-support-faq' ); submit_button(); ?>
		</form>
	</div>
	<?php
}
