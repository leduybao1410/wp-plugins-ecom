<?php
/**
 * Plugin Name: EPIC First Order Coupon
 * Description: Adds a "first-time customers only" restriction option to WooCommerce coupons. When enabled on a coupon, the coupon is only valid for a billing email address that has no prior real order on the store — checked at cart/checkout time and again at order submission. Use this together with a normal WooCommerce coupon (e.g. WELCOME10, 10% off) to run a first-order-only discount without any other plugin.
 * Version: 1.0.0
 * Author: EPIC Coffee Roastery
 * Text Domain: epic-first-order-coupon
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EPIC_FIRST_ORDER_COUPON_VERSION', '1.0.0' );
define( 'EPIC_FIRST_ORDER_COUPON_FILE', __FILE__ );
define( 'EPIC_FIRST_ORDER_COUPON_DIR', plugin_dir_path( __FILE__ ) );

require_once EPIC_FIRST_ORDER_COUPON_DIR . 'includes/class-restriction.php';

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				EPIC_FIRST_ORDER_COUPON_FILE,
				true
			);
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		Epic_First_Order_Coupon_Restriction::init();
	}
);
