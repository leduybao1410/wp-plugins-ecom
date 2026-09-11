<?php
/**
 * Plugin Name:       EPIC Distributor Profit
 * Plugin URI:        https://epicroastery.example/
 * Description:       Internal admin ledger for tracking cost, revenue, profit and distributor commission
 *                     per sale. Two screens: Distributors (name + commission %) and Distributor Profit
 *                     (the ledger — import a real WooCommerce order or enter a sale manually, filter by
 *                     month/date range/distributor/channel, export CSV/XLSX). Staff-only, no distributor
 *                     login in v1.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * Author:            EPIC Coffee Roaster
 * Text Domain:       epic-distributor-profit
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 *
 * @package Epic_Distributor_Profit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'EPIC_DISTRIBUTOR_PROFIT_VERSION', '1.0.0' );
define( 'EPIC_DISTRIBUTOR_PROFIT_PLUGIN_FILE', __FILE__ );
define( 'EPIC_DISTRIBUTOR_PROFIT_DIR', plugin_dir_path( __FILE__ ) );
define( 'EPIC_DISTRIBUTOR_PROFIT_URL', plugin_dir_url( __FILE__ ) );
define( 'EPIC_DISTRIBUTOR_PROFIT_CAP', 'manage_woocommerce' );

/**
 * Declare HPOS compatibility — same boilerplate as every other EPIC plugin.
 * This plugin only *reads* WooCommerce orders (for the "Import from Order" picker); it never writes to
 * order storage, so this is a formality, not a functional dependency.
 */
add_action(
	'before_woocommerce_init',
	function () {
		$features_util = '\Automattic\WooCommerce\Utilities\FeaturesUtil';
		if ( class_exists( $features_util ) && method_exists( $features_util, 'declare_compatibility' ) ) {
			$features_util::declare_compatibility( 'custom_order_tables', EPIC_DISTRIBUTOR_PROFIT_PLUGIN_FILE, true );
		}
	}
);

/**
 * Bail with an admin notice if WooCommerce isn't active — every screen here depends on WC order data
 * and the WooCommerce admin menu to attach to.
 */
function epic_distributor_profit_missing_wc_notice() {
	echo '<div class="notice notice-error"><p>' .
		esc_html__( 'EPIC Distributor Profit requires WooCommerce to be active.', 'epic-distributor-profit' ) .
		'</p></div>';
}

function epic_distributor_profit_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'epic_distributor_profit_missing_wc_notice' );
		return;
	}

	require_once EPIC_DISTRIBUTOR_PROFIT_DIR . 'includes/class-store.php';
	require_once EPIC_DISTRIBUTOR_PROFIT_DIR . 'includes/class-distributors-list-table.php';
	require_once EPIC_DISTRIBUTOR_PROFIT_DIR . 'includes/class-distributors-admin.php';
	require_once EPIC_DISTRIBUTOR_PROFIT_DIR . 'includes/class-entries-list-table.php';
	require_once EPIC_DISTRIBUTOR_PROFIT_DIR . 'includes/class-entries-admin.php';
	require_once EPIC_DISTRIBUTOR_PROFIT_DIR . 'includes/class-order-picker.php';
	require_once EPIC_DISTRIBUTOR_PROFIT_DIR . 'includes/class-export.php';
	require_once EPIC_DISTRIBUTOR_PROFIT_DIR . 'includes/class-import-legacy.php';

	Epic_Distributor_Profit_Distributors_Admin::init();
	Epic_Distributor_Profit_Entries_Admin::init();
	Epic_Distributor_Profit_Order_Picker::init();
	Epic_Distributor_Profit_Export::init();
	Epic_Distributor_Profit_Import_Legacy::init();
}
add_action( 'plugins_loaded', 'epic_distributor_profit_init' );

/**
 * Activation: create the two custom tables. Safe to re-run (dbDelta handles upgrades).
 */
function epic_distributor_profit_activate() {
	require_once EPIC_DISTRIBUTOR_PROFIT_DIR . 'includes/class-store.php';
	Epic_Distributor_Profit_Store::install();
}
register_activation_hook( __FILE__, 'epic_distributor_profit_activate' );
