<?php
/**
 * Plugin Name:       EPIC Wholesale Orders
 * Plugin URI:        https://epicroastery.example/
 * Description:       Wholesale ordering for whitelisted customers. Admin sets a wholesale price per product and picks which users can buy wholesale; a whitelisted user gets a dedicated ordering page on the Next.js storefront (served over shared-secret REST routes). Submissions are recorded as a custom post type — deliberately NOT a WooCommerce order, so stock is never reduced and there is no payment or shipping. Seller and customer each get a notification email; the seller tracks fulfillment (pending/done/cancelled) and payment (WAITING_FOR_PAYMENT/PAID/PENDING/CANCELED) in wp-admin.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * Author:            EPIC Coffee Roaster
 * Text Domain:       epic-wholesale-orders
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 *
 * @package Epic_Wholesale_Orders
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'EPIC_WHOLESALE_ORDERS_VERSION', '1.0.0' );
define( 'EPIC_WHOLESALE_ORDERS_PLUGIN_FILE', __FILE__ );
define( 'EPIC_WHOLESALE_ORDERS_DIR', plugin_dir_path( __FILE__ ) );
define( 'EPIC_WHOLESALE_ORDERS_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declare HPOS compatibility — same boilerplate as every other EPIC plugin.
 * This plugin never touches order storage (wholesale orders live in their own
 * CPT, not shop_order), so this is a formality, not a functional dependency.
 */
add_action(
	'before_woocommerce_init',
	function () {
		$features_util = '\Automattic\WooCommerce\Utilities\FeaturesUtil';
		if ( class_exists( $features_util ) && method_exists( $features_util, 'declare_compatibility' ) ) {
			$features_util::declare_compatibility( 'custom_order_tables', EPIC_WHOLESALE_ORDERS_PLUGIN_FILE, true );
		}
	}
);

function epic_wholesale_orders_woocommerce_missing_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			esc_html_e(
				'EPIC Wholesale Orders requires WooCommerce to be installed and active (it uses WooCommerce\'s product, customer, and WC_Email systems).',
				'epic-wholesale-orders'
			);
			?>
		</p>
	</div>
	<?php
}

function epic_wholesale_orders_is_woocommerce_active() {
	return class_exists( 'WooCommerce' );
}

require_once EPIC_WHOLESALE_ORDERS_DIR . 'includes/class-store.php';
require_once EPIC_WHOLESALE_ORDERS_DIR . 'includes/class-product-pricing.php';
require_once EPIC_WHOLESALE_ORDERS_DIR . 'includes/class-settings.php';

// CPT + custom post statuses must be registered before anything else can save
// or query them. Safe on the front-end too (REST callbacks rely on it).
add_action( 'init', array( 'Epic_Wholesale_Orders_Store', 'register_post_type' ) );

add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain( 'epic-wholesale-orders', false, dirname( plugin_basename( EPIC_WHOLESALE_ORDERS_PLUGIN_FILE ) ) . '/languages' );

		if ( ! epic_wholesale_orders_is_woocommerce_active() ) {
			if ( is_admin() ) {
				add_action( 'admin_notices', 'epic_wholesale_orders_woocommerce_missing_notice' );
			}
			return;
		}

		// Product wholesale pricing fields (metabox on simple products +
		// per-variation fields) hook into WooCommerce product screens.
		Epic_Wholesale_Product_Pricing::init();

		// The order-detail metabox on the CPT's edit screen — only meaningful
		// in wp-admin, so it's wired from inside this admin-only gate.
		if ( is_admin() ) {
			require_once EPIC_WHOLESALE_ORDERS_DIR . 'includes/class-meta-box.php';
			Epic_Wholesale_Order_Meta_Box::init();
		}
	},
	5
);

add_action( 'admin_menu', array( 'Epic_Wholesale_Settings', 'add_menu' ) );
add_action( 'admin_init', array( 'Epic_Wholesale_Settings', 'register_setting' ) );
add_action( 'admin_enqueue_scripts', array( 'Epic_Wholesale_Settings', 'enqueue_admin_assets' ) );

/**
 * The REST route must be registered unconditionally on `rest_api_init`
 * (never gated behind is_admin()) — the Next.js website calls these from a
 * Node process, never from wp-admin. See includes/class-rest-api.php.
 */
add_action(
	'rest_api_init',
	function () {
		require_once EPIC_WHOLESALE_ORDERS_DIR . 'includes/class-rest-api.php';
		Epic_Wholesale_Orders_Rest_Api::register_routes();
	}
);

/**
 * Registers the two notification emails with WooCommerce's own email system,
 * same pattern as every other EPIC plugin — lazily require_once'd inside the
 * filter callback rather than the unconditional include list above.
 */
add_filter(
	'woocommerce_email_classes',
	function ( $email_classes ) {
		require_once EPIC_WHOLESALE_ORDERS_DIR . 'includes/class-email-wholesale-order-admin.php';
		require_once EPIC_WHOLESALE_ORDERS_DIR . 'includes/class-email-wholesale-order-customer.php';

		if ( class_exists( 'Epic_Email_Wholesale_Order_Admin' ) ) {
			$email_classes['epic_wholesale_order_admin'] = new Epic_Email_Wholesale_Order_Admin();
		}
		if ( class_exists( 'Epic_Email_Wholesale_Order_Customer' ) ) {
			$email_classes['epic_wholesale_order_customer'] = new Epic_Email_Wholesale_Order_Customer();
		}

		return $email_classes;
	}
);

/**
 * Adds a "Settings" link on the Plugins list page pointing at this plugin's
 * own screen (WooCommerce → Wholesale Orders). The notification emails' own
 * subject/heading/recipient live under WooCommerce → Settings → Emails, same
 * as every other EPIC email.
 */
add_filter(
	'plugin_action_links_' . plugin_basename( EPIC_WHOLESALE_ORDERS_PLUGIN_FILE ),
	function ( $links ) {
		$settings_url  = admin_url( 'admin.php?page=epic-wholesale-orders' );
		$settings_link = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'epic-wholesale-orders' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}
);
