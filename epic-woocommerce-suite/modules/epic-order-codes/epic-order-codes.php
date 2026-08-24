<?php
/**
 * Plugin Name: EPIC Order Codes
 * Description: Replaces WooCommerce's sequential, guessable numeric order numbers (e.g. #67, #68) with short, mixed letter+digit codes that can't be scanned or guessed (e.g. EPIC-7K3M9X). The code is derived from the order ID via a reversible, keyed Feistel cipher, so it is unique, non-sequential, and can be decoded back to the order ID — the native admin Orders search box accepts the new code on both the legacy posts and Custom Order Tables backends. No order data is migrated: every order, new or existing, gets its code deterministically from its ID, and everything that shows an order number (emails, admin, the Next.js website via the REST API) picks it up automatically.
 * Version: 1.1.0
 * Author: EPIC Coffee Roastery
 * Text Domain: epic-order-codes
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EPIC_ORDER_CODES_VERSION', '1.1.0' );
define( 'EPIC_ORDER_CODES_FILE', __FILE__ );
define( 'EPIC_ORDER_CODES_DIR', plugin_dir_path( __FILE__ ) );

require_once EPIC_ORDER_CODES_DIR . 'includes/class-order-code.php';
require_once EPIC_ORDER_CODES_DIR . 'includes/class-rest-lookup.php';
require_once EPIC_ORDER_CODES_DIR . 'includes/class-settings.php';

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				EPIC_ORDER_CODES_FILE,
				true
			);
		}
	}
);

// Ensure a stable site key exists so codes never change between loads.
add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		Epic_Order_Code::maybe_generate_key();
		Epic_Order_Code::init();
	}
);

add_action( 'rest_api_init', array( 'Epic_Order_Code_Lookup', 'register_routes' ) );
add_action( 'plugins_loaded', array( 'Epic_Order_Code_Settings', 'init' ) );
