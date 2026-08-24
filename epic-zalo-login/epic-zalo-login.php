<?php
/**
 * Plugin Name:       EPIC Zalo Login
 * Plugin URI:        https://epicroastery.example/
 * Description:       Adds a "Sign in with Zalo" button (Zalo Login v4 — OAuth 2.0 + PKCE) so visitors can register and log in with their Zalo account, the same way they would with Google sign-in. On first login it auto-creates a WordPress user and stores the Zalo ID, so the same Zalo account maps to the same WordPress user on every subsequent visit.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            EPIC Coffee Roaster
 * Text Domain:       epic-zalo-login
 * Domain Path:       /languages
 *
 * @package Epic_Zalo_Login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'EPIC_ZALO_LOGIN_VERSION', '0.1.0' );
define( 'EPIC_ZALO_LOGIN_PLUGIN_FILE', __FILE__ );
define( 'EPIC_ZALO_LOGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EPIC_ZALO_LOGIN_URL', plugin_dir_url( __FILE__ ) );

require_once EPIC_ZALO_LOGIN_DIR . 'includes/class-settings.php';
require_once EPIC_ZALO_LOGIN_DIR . 'includes/class-oauth.php';
require_once EPIC_ZALO_LOGIN_DIR . 'includes/class-login.php';

/**
 * Settings screen (Settings → Zalo Login) — holds the Zalo app's ID and
 * secret key. See includes/class-settings.php.
 */
add_action( 'admin_menu', array( 'Epic_Zalo_Settings', 'add_menu' ) );
add_action( 'admin_init', array( 'Epic_Zalo_Settings', 'register_setting' ) );

/**
 * The `[epic_zalo_login]` shortcode — renders the "Sign in with Zalo" button
 * anywhere (login page, page content, footer, etc.).
 */
add_shortcode( 'epic_zalo_login', array( 'Epic_Zalo_Login', 'render_button' ) );

/**
 * The OAuth handshake runs through `admin-post.php` (works for both logged-in
 * and anonymous users via the nopriv variants):
 *  - `epic_zalo_login`    → builds the Zalo authorize URL and redirects there.
 *  - `epic_zalo_callback` → Zalo's redirect_uri target; exchanges the code,
 *    fetches the profile, and logs the user in.
 */
add_action( 'admin_post_epic_zalo_login', array( 'Epic_Zalo_Login', 'start_login' ) );
add_action( 'admin_post_nopriv_epic_zalo_login', array( 'Epic_Zalo_Login', 'start_login' ) );
add_action( 'admin_post_epic_zalo_callback', array( 'Epic_Zalo_Login', 'handle_callback' ) );
add_action( 'admin_post_nopriv_epic_zalo_callback', array( 'Epic_Zalo_Login', 'handle_callback' ) );

/**
 * Adds a "Settings" link on the Plugins list page, same pattern as every
 * other EPIC plugin.
 */
add_filter(
	'plugin_action_links_' . plugin_basename( EPIC_ZALO_LOGIN_PLUGIN_FILE ),
	function ( $links ) {
		$settings_url  = admin_url( 'options-general.php?page=epic-zalo-login' );
		$settings_link = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'epic-zalo-login' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}
);
