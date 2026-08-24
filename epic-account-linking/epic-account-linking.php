<?php
/**
 * Plugin Name: EPIC Account Linking
 * Description: Backs the Next.js website's headless account area. Customers sign in with Google on the storefront; this plugin records the account (keyed by the Google sub), auto-links their historical WooCommerce orders by billing email, and lets them claim older orders manually with their order code + email/phone over REST. Order history for the account is served back to the website through shared-secret-gated REST routes.
 * Version: 1.0.0
 * Author: EPIC Coffee Roastery
 * Text Domain: epic-account-linking
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EPIC_ACCOUNT_LINKING_VERSION', '1.0.0' );
define( 'EPIC_ACCOUNT_LINKING_FILE', __FILE__ );
define( 'EPIC_ACCOUNT_LINKING_DIR', plugin_dir_path( __FILE__ ) );

require_once EPIC_ACCOUNT_LINKING_DIR . 'includes/class-account-store.php';
require_once EPIC_ACCOUNT_LINKING_DIR . 'includes/class-account-service.php';
require_once EPIC_ACCOUNT_LINKING_DIR . 'includes/class-rest-api.php';
require_once EPIC_ACCOUNT_LINKING_DIR . 'includes/class-settings.php';

// Declared for forward-compatibility. This plugin reads WooCommerce orders
// (for email auto-linking, claiming, and serving history) and writes
// customers, so it must work on both the legacy posts backend and HPOS.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				EPIC_ACCOUNT_LINKING_FILE,
				true
			);
		}
	}
);

register_activation_hook( EPIC_ACCOUNT_LINKING_FILE, array( 'Epic_Account_Store', 'install' ) );

add_action( 'plugins_loaded', array( 'Epic_Account_Settings', 'init' ) );
add_action( 'rest_api_init', array( 'Epic_Account_Rest_Api', 'register_routes' ) );
