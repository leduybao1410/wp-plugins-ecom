<?php
/**
 * Plugin Name:       EPIC Agent API
 * Plugin URI:        https://epicroastery.coffee/
 * Description:       Backs the website's bot-facing open API (/api/public/v1). Provides the rate-limit + honeypot "guard" the Next.js server calls before accepting any public write (sample request, wholesale quote, newsletter, order lookup), and logs agent traffic in wp-admin (WooCommerce → Agent API). No WooCommerce order/customer is created here.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * Author:            EPIC Coffee Roaster
 * Text Domain:       epic-agent-api
 * Domain Path:       /languages
 *
 * @package Epic_Agent_Api
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'EPIC_AGENT_API_VERSION', '1.0.0' );
define( 'EPIC_AGENT_API_PLUGIN_FILE', __FILE__ );
define( 'EPIC_AGENT_API_DIR', plugin_dir_path( __FILE__ ) );
define( 'EPIC_AGENT_API_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declare HPOS compatibility — same boilerplate as every other EPIC plugin.
 * This module never touches order storage, so this is a formality.
 */
add_action(
	'before_woocommerce_init',
	function () {
		$features_util = '\Automattic\WooCommerce\Utilities\FeaturesUtil';
		if ( class_exists( $features_util ) && method_exists( $features_util, 'declare_compatibility' ) ) {
			$features_util::declare_compatibility( 'custom_order_tables', EPIC_AGENT_API_PLUGIN_FILE, true );
		}
	}
);

require_once EPIC_AGENT_API_DIR . 'includes/class-store.php';
require_once EPIC_AGENT_API_DIR . 'includes/class-rate-limiter.php';
require_once EPIC_AGENT_API_DIR . 'includes/class-settings.php';

register_activation_hook( EPIC_AGENT_API_PLUGIN_FILE, array( 'Epic_Agent_Store', 'install' ) );

add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain( 'epic-agent-api', false, dirname( plugin_basename( EPIC_AGENT_API_PLUGIN_FILE ) ) . '/languages' );

		// Defensive schema check — covers a module updated in place while
		// already active (WordPress doesn't re-fire register_activation_hook).
		if ( get_option( Epic_Agent_Store::DB_VERSION_OPTION ) !== Epic_Agent_Store::DB_VERSION ) {
			Epic_Agent_Store::install();
		}
	},
	5
);

add_action( 'admin_menu', array( 'Epic_Agent_Settings', 'add_menu' ) );
add_action( 'admin_init', array( 'Epic_Agent_Settings', 'register_setting' ) );

/**
 * The guard route must be registered unconditionally on `rest_api_init` —
 * the Next.js server calls it from a Node process, never from wp-admin.
 */
add_action(
	'rest_api_init',
	function () {
		require_once EPIC_AGENT_API_DIR . 'includes/class-rest-api.php';
		Epic_Agent_Rest_Api::register_routes();
	}
);

/**
 * Daily purge of agent-event rows older than the retention window.
 */
add_action(
	'epic_agent_purge_events',
	function () {
		Epic_Agent_Store::purge_old();
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( EPIC_AGENT_API_PLUGIN_FILE ),
	function ( $links ) {
		$settings_url  = admin_url( 'admin.php?page=epic-agent-api' );
		$settings_link = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'epic-agent-api' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}
);

if ( ! wp_next_scheduled( 'epic_agent_purge_events' ) ) {
	wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'epic_agent_purge_events' );
}
