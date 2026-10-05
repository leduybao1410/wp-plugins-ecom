<?php
/**
 * Plugin Name:       EPIC WooCommerce Product MCP
 * Description:       Reviewed WooCommerce product and variation editing through authenticated WordPress MCP abilities.
 * Version:           1.0.1
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            EPIC Coffee Roaster
 * Text Domain:       epic-product-mcp
 *
 * @package Epic_Product_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EPIC_PRODUCT_MCP_VERSION', '1.0.1' );
define( 'EPIC_PRODUCT_MCP_DIR', plugin_dir_path( __FILE__ ) );

require_once EPIC_PRODUCT_MCP_DIR . 'includes/class-abilities.php';

add_action(
	'plugins_loaded',
	static function () {
		Epic_Product_MCP_Abilities::init();
	},
	20
);
