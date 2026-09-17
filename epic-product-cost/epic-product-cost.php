<?php
/**
 * Plugin Name:       EPIC Product Cost
 * Plugin URI:        https://epicroastery.example/
 * Description:       Tracks each coffee product's cost (per 250g, scaled automatically for 500g/1kg)
 *                     with full history so a cost change on a given date never rewrites past math.
 *                     Adds WooCommerce → Product Cost, and a REST API (namespace
 *                     epic-product-cost/v1, authenticated via WordPress Application Passwords) that
 *                     the companion MCP server in mcp-server/ uses to read/edit cost from a chat.
 *                     If EPIC Distributor Profit is also active, its "Import from Order" picker
 *                     auto-estimates the ledger Cost field from this data.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * Author:            EPIC Coffee Roaster
 * Text Domain:       epic-product-cost
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 *
 * @package Epic_Product_Cost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'EPIC_PRODUCT_COST_VERSION', '1.1.0' );
define( 'EPIC_PRODUCT_COST_PLUGIN_FILE', __FILE__ );
define( 'EPIC_PRODUCT_COST_DIR', plugin_dir_path( __FILE__ ) );
define( 'EPIC_PRODUCT_COST_URL', plugin_dir_url( __FILE__ ) );
define( 'EPIC_PRODUCT_COST_CAP', 'manage_woocommerce' );

/**
 * Declare HPOS compatibility — same boilerplate as every other EPIC plugin. This plugin only reads
 * WooCommerce products/orders; it never writes to order storage.
 */
add_action(
	'before_woocommerce_init',
	function () {
		$features_util = '\Automattic\WooCommerce\Utilities\FeaturesUtil';
		if ( class_exists( $features_util ) && method_exists( $features_util, 'declare_compatibility' ) ) {
			$features_util::declare_compatibility( 'custom_order_tables', EPIC_PRODUCT_COST_PLUGIN_FILE, true );
		}
	}
);

/**
 * Bail with an admin notice if WooCommerce isn't active — every screen here depends on WC product
 * data and the WooCommerce admin menu to attach to.
 */
function epic_product_cost_missing_wc_notice() {
	echo '<div class="notice notice-error"><p>' .
		esc_html__( 'EPIC Product Cost requires WooCommerce to be active.', 'epic-product-cost' ) .
		'</p></div>';
}

function epic_product_cost_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'epic_product_cost_missing_wc_notice' );
		return;
	}

	require_once EPIC_PRODUCT_COST_DIR . 'includes/class-store.php';
	require_once EPIC_PRODUCT_COST_DIR . 'includes/class-admin.php';
	require_once EPIC_PRODUCT_COST_DIR . 'includes/class-history-admin.php';
	require_once EPIC_PRODUCT_COST_DIR . 'includes/class-seed.php';
	require_once EPIC_PRODUCT_COST_DIR . 'includes/class-rest-api.php';

	Epic_Product_Cost_Admin::init();
	Epic_Product_Cost_History_Admin::init();
	Epic_Product_Cost_Rest_Api::init();
}
add_action( 'plugins_loaded', 'epic_product_cost_init' );

/**
 * Activation: create the cost-history table. Safe to re-run (dbDelta handles upgrades).
 */
function epic_product_cost_activate() {
	require_once EPIC_PRODUCT_COST_DIR . 'includes/class-store.php';
	Epic_Product_Cost_Store::install();
}
register_activation_hook( __FILE__, 'epic_product_cost_activate' );

/**
 * Thin, always-loaded facade other EPIC plugins can call without a hard dependency:
 *
 *   if ( class_exists( 'Epic_Product_Cost' ) ) {
 *       $estimate = Epic_Product_Cost::estimate_order_cost( $order );
 *   }
 *
 * Kept in the main plugin file (not includes/) so class_exists() works the moment plugins_loaded
 * has fired for this plugin, with no risk of a require order issue between the two plugins — each
 * loads its own classes independently on plugins_loaded, and by the time any AJAX or admin-render
 * callback actually runs, plugins_loaded has already fired for every active plugin regardless of
 * which one happened to load first.
 */
class Epic_Product_Cost {

	/**
	 * Estimate the total product cost of a WC_Order from stored per-250g cost data, scaled per
	 * line item's pack size and looked up as of the order's own date (so a later cost change never
	 * rewrites the estimate for an old order).
	 *
	 * @param WC_Order $order
	 * @return array{total:float,complete:bool}|null Null if the cost-history table isn't ready yet.
	 */
	public static function estimate_order_cost( $order ) {
		if ( ! class_exists( 'Epic_Product_Cost_Store' ) ) {
			require_once EPIC_PRODUCT_COST_DIR . 'includes/class-store.php';
		}
		return Epic_Product_Cost_Store::estimate_order_cost( $order );
	}

	/**
	 * Cost per unit (already scaled for pack size) for a single order line item, as of a given
	 * date. Exposed separately in case a caller wants per-line detail rather than an order total.
	 *
	 * @param WC_Order_Item_Product $item
	 * @param string                $as_of_date Y-m-d.
	 * @return array{unit_cost:float,line_cost:float,grams:int,known:bool}
	 */
	public static function estimate_line_item_cost( $item, $as_of_date ) {
		if ( ! class_exists( 'Epic_Product_Cost_Store' ) ) {
			require_once EPIC_PRODUCT_COST_DIR . 'includes/class-store.php';
		}
		return Epic_Product_Cost_Store::estimate_line_item_cost( $item, $as_of_date );
	}
}
