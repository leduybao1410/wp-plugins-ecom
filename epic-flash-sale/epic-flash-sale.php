<?php
/**
 * Plugin Name: EPIC Flash Sale
 * Description: Ends a limited-quantity flash sale on a product variation automatically. A variation carrying the _epic_flash_sale_qty meta keeps its WooCommerce sale price until that stock runs out; when the last unit sells, the sale price is cleared, the regular price restored, stock management switched off and the variation left in stock so it keeps selling at its normal price. No wp-admin action required.
 * Version: 1.0.0
 * Author: EPIC Coffee Roastery
 * Text Domain: epic-flash-sale
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EPIC_FLASH_SALE_VERSION', '1.0.0' );
define( 'EPIC_FLASH_SALE_FILE', __FILE__ );
define( 'EPIC_FLASH_SALE_DIR', plugin_dir_path( __FILE__ ) );

require_once EPIC_FLASH_SALE_DIR . 'includes/class-epic-flash-sale.php';

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				EPIC_FLASH_SALE_FILE,
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
		Epic_Flash_Sale::init();
	}
);
