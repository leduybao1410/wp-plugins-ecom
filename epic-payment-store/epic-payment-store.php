<?php
/**
 * Plugin Name: EPIC Payment Store
 * Description: Backs the Next.js website's prepaid checkout (currently SePay bank-transfer QR) — holds pending (unpaid) checkout data until the payment webhook confirms a transfer, and the resulting order/tracking info afterwards. No WooCommerce order is ever created from data this plugin holds; it is purely a short-lived, payment-provider-agnostic handoff store the site's own checkout + webhook routes read and write over REST. Requires no external database service.
 * Version: 1.0.1
 * Author: EPIC Coffee Roastery
 * Text Domain: epic-payment-store
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EPIC_PAYMENT_STORE_VERSION', '1.0.1' );
define( 'EPIC_PAYMENT_STORE_FILE', __FILE__ );
define( 'EPIC_PAYMENT_STORE_DIR', plugin_dir_path( __FILE__ ) );

require_once EPIC_PAYMENT_STORE_DIR . 'includes/class-store.php';
require_once EPIC_PAYMENT_STORE_DIR . 'includes/class-rest-api.php';
require_once EPIC_PAYMENT_STORE_DIR . 'includes/class-settings.php';

// Declared for forward-compatibility even though this plugin never reads or
// writes WooCommerce order data directly — it only stores handoff records
// the website's own order-creation code (via the WooCommerce REST API)
// depends on being available at checkout/webhook time.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				EPIC_PAYMENT_STORE_FILE,
				true
			);
		}
	}
);

register_activation_hook( EPIC_PAYMENT_STORE_FILE, array( 'Epic_Payment_Store', 'install' ) );

add_action( 'plugins_loaded', array( 'Epic_Payment_Settings', 'init' ) );
add_action( 'rest_api_init', array( 'Epic_Payment_Rest_Api', 'register_routes' ) );

// Daily housekeeping — delete expired pending/completed rows so the tables
// don't grow unbounded. Not load-bearing (claim/get already filter on
// expires_at), purely table hygiene.
register_activation_hook(
	EPIC_PAYMENT_STORE_FILE,
	function () {
		if ( ! wp_next_scheduled( 'epic_payment_purge_expired' ) ) {
			wp_schedule_event( time(), 'daily', 'epic_payment_purge_expired' );
		}
	}
);
register_deactivation_hook(
	EPIC_PAYMENT_STORE_FILE,
	function () {
		wp_clear_scheduled_hook( 'epic_payment_purge_expired' );
	}
);
add_action( 'epic_payment_purge_expired', array( 'Epic_Payment_Store', 'purge_expired' ) );
