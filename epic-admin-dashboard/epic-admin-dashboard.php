<?php
/**
 * Plugin Name: EPIC Admin Dashboard API
 * Description: Administrator-only session bridge and business API for the EPIC Admin dashboard.
 * Version: 0.2.3
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Text Domain: epic-admin-dashboard
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'EPIC_ADMIN_DASHBOARD_VERSION', '0.2.3' );
define( 'EPIC_ADMIN_DASHBOARD_DIR', plugin_dir_path( __FILE__ ) );
require_once EPIC_ADMIN_DASHBOARD_DIR . 'includes/class-epic-admin-dashboard.php';
require_once EPIC_ADMIN_DASHBOARD_DIR . 'includes/class-epic-admin-security.php';

register_activation_hook( __FILE__, array( 'Epic_Admin_Dashboard', 'activate' ) );
add_action( 'plugins_loaded', array( 'Epic_Admin_Dashboard', 'init' ), 20 );
add_action( 'admin_menu', 'epic_admin_dashboard_add_settings_page' );
add_action( 'admin_init', 'epic_admin_dashboard_register_settings' );

function epic_admin_dashboard_add_settings_page() {
	add_options_page( __( 'EPIC Admin Dashboard', 'epic-admin-dashboard' ), __( 'EPIC Admin Dashboard', 'epic-admin-dashboard' ), 'manage_options', 'epic-admin-dashboard', 'epic_admin_dashboard_render_settings_page' );
}

function epic_admin_dashboard_register_settings() {
	register_setting( 'epic_admin_dashboard', 'epic_admin_client_secret', array( 'type' => 'string', 'sanitize_callback' => 'epic_admin_dashboard_sanitize_client_secret' ) );
	register_setting( 'epic_admin_dashboard', 'epic_admin_dashboard_callback', array( 'type' => 'string', 'sanitize_callback' => 'epic_admin_dashboard_sanitize_callback_url' ) );
	add_settings_section( 'epic_admin_dashboard_connection', __( 'Dashboard connection', 'epic-admin-dashboard' ), '__return_false', 'epic-admin-dashboard' );
	add_settings_field( 'epic_admin_client_secret', __( 'Client secret', 'epic-admin-dashboard' ), 'epic_admin_dashboard_render_secret_field', 'epic-admin-dashboard', 'epic_admin_dashboard_connection' );
	add_settings_field( 'epic_admin_dashboard_callback', __( 'Exact callback URL', 'epic-admin-dashboard' ), 'epic_admin_dashboard_render_callback_field', 'epic-admin-dashboard', 'epic_admin_dashboard_connection' );
}

function epic_admin_dashboard_sanitize_client_secret( $value ) {
	$value = is_string( $value ) ? trim( $value ) : '';
	if ( '' === $value ) { return (string) get_option( 'epic_admin_client_secret', '' ); }
	if ( strlen( $value ) < 64 || strlen( $value ) > 256 || ! preg_match( '/^[A-Za-z0-9._~-]+$/', $value ) ) {
		add_settings_error( 'epic_admin_dashboard', 'invalid_client_secret', __( 'Use a generated secret containing at least 64 URL-safe characters. The previous value is unchanged.', 'epic-admin-dashboard' ) );
		return (string) get_option( 'epic_admin_client_secret', '' );
	}
	return $value;
}

function epic_admin_dashboard_sanitize_callback_url( $value ) {
	$value = is_string( $value ) ? trim( $value ) : '';
	if ( '' === $value ) { return (string) get_option( 'epic_admin_dashboard_callback', '' ); }
	$url = esc_url_raw( $value, array( 'https' ) );
	$parts = wp_parse_url( $url );
	$host = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
	$allowed_host = 'ops.epicroastery.coffee' === $host || ( preg_match( '/^[a-z0-9-]+(?:\.[a-z0-9-]+)*\.vercel\.app$/', $host ) && substr_count( $host, '.' ) >= 2 );
	if ( ! $url || 'https' !== ( $parts['scheme'] ?? '' ) || ! $allowed_host || '/api/auth/callback' !== ( $parts['path'] ?? '' ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) ) {
		add_settings_error( 'epic_admin_dashboard', 'invalid_callback_url', __( 'Callback must be the exact HTTPS /api/auth/callback URL on ops.epicroastery.coffee or a Vercel deployment. The previous value is unchanged.', 'epic-admin-dashboard' ) );
		return (string) get_option( 'epic_admin_dashboard_callback', '' );
	}
	return untrailingslashit( $url );
}

function epic_admin_dashboard_render_secret_field() {
	$configured = defined( 'EPIC_ADMIN_CLIENT_SECRET' ) && EPIC_ADMIN_CLIENT_SECRET || get_option( 'epic_admin_client_secret', '' );
	printf( '<input type="password" name="epic_admin_client_secret" value="" class="regular-text" autocomplete="new-password" spellcheck="false" />' );
	echo '<p class="description">' . esc_html( $configured ? __( 'A secret is configured. Leave blank to keep it unchanged.', 'epic-admin-dashboard' ) : __( 'Paste the same generated secret configured in the protected dashboard deployment.', 'epic-admin-dashboard' ) ) . '</p>';
}

function epic_admin_dashboard_render_callback_field() {
	$value = defined( 'EPIC_ADMIN_DASHBOARD_CALLBACK' ) && EPIC_ADMIN_DASHBOARD_CALLBACK ? EPIC_ADMIN_DASHBOARD_CALLBACK : get_option( 'epic_admin_dashboard_callback', '' );
	printf( '<input type="url" name="epic_admin_dashboard_callback" value="%s" class="regular-text code" placeholder="https://project.vercel.app/api/auth/callback" required />', esc_attr( $value ) );
	echo '<p class="description">' . esc_html__( 'The value must match the deployed dashboard callback exactly, including protocol and path.', 'epic-admin-dashboard' ) . '</p>';
}

function epic_admin_dashboard_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'EPIC Admin Dashboard', 'epic-admin-dashboard' ); ?></h1>
		<?php settings_errors( 'epic_admin_dashboard' ); ?>
		<form action="options.php" method="post">
			<?php settings_fields( 'epic_admin_dashboard' ); do_settings_sections( 'epic-admin-dashboard' ); submit_button(); ?>
		</form>
		<h2><?php esc_html_e( 'Dashboard sessions', 'epic-admin-dashboard' ); ?></h2>
		<?php if ( isset( $_GET['sessions_revoked'] ) ) { echo '<p>' . esc_html__( 'Your dashboard sessions have been revoked.', 'epic-admin-dashboard' ) . '</p>'; } ?>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="epic_admin_revoke_all" />
			<?php wp_nonce_field( 'epic_admin_revoke_all' ); ?>
			<?php submit_button( __( 'Revoke all my dashboard sessions', 'epic-admin-dashboard' ), 'secondary' ); ?>
		</form>
	</div>
	<?php
}
