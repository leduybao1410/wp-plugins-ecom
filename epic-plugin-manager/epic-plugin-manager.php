<?php
/**
 * Plugin Name:       EPIC Plugin Manager MCP
 * Plugin URI:        https://github.com/leduybao1410/wp-plugins-ecom
 * Description:       Exposes WordPress plugin management as MCP abilities through the WordPress Abilities API and the MCP Adapter: list installed plugins, install from WordPress.org or a zip (URL/base64 upload), activate, deactivate, update and remove. Lets an AI agent manage the plugins on admin.epicroastery.coffee over MCP.
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            EPIC Coffee Roaster
 * Text Domain:       epic-plugin-manager
 *
 * @package Epic_Plugin_Manager
 *
 * Why this exists:
 *
 * The MCP Adapter exposes anything registered with the WordPress Abilities
 * API as MCP tools. WordPress core ships abilities for content, and
 * WooCommerce ships abilities for orders/products, but there is no ability
 * to manage *plugins themselves* — so an AI agent connected over MCP can
 * edit the storefront's content but cannot install or roll a plugin.
 *
 * This plugin registers those abilities in the `epic-plugin-manager`
 * namespace and marks them `meta.public` so the default MCP server
 * (wp-json/mcp/mcp-adapter-default-server) can discover and execute them:
 *
 *   epic-plugin-manager/list-plugins      — installed plugins, status, updates
 *   epic-plugin-manager/install-plugin    — install from a .org slug or zip URL
 *   epic-plugin-manager/upload-plugin     — install from a base64-encoded zip
 *   epic-plugin-manager/activate-plugin   — activate (optionally network-wide)
 *   epic-plugin-manager/deactivate-plugin — deactivate
 *   epic-plugin-manager/update-plugin     — update to the newest available version
 *   epic-plugin-manager/delete-plugin     — remove (force deactivates first)
 *
 * Security: every ability checks a real WordPress capability
 * (install_plugins / activate_plugins / delete_plugins) and the usual
 * DISALLOW_FILE_MODS guard (install/upload/update/delete), so the MCP user must
 * be a genuine administrator. Activate/deactivate require the `activate_plugins`
 * capability (checked inside the controller too). Every destructive operation is
 * audit-logged, and the install/upload/delete abilities can be hidden from the
 * tool list entirely via EPIC_PLUGIN_MANAGER_ALLOW_DESTRUCTIVE = false.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'EPIC_PLUGIN_MANAGER_VERSION', '1.0.0' );
define( 'EPIC_PLUGIN_MANAGER_FILE', __FILE__ );
define( 'EPIC_PLUGIN_MANAGER_DIR', plugin_dir_path( __FILE__ ) );

require_once EPIC_PLUGIN_MANAGER_DIR . 'includes/class-plugin-controller.php';
require_once EPIC_PLUGIN_MANAGER_DIR . 'includes/class-abilities.php';

add_action(
	'plugins_loaded',
	function () {
		Epic_Plugin_Manager_Abilities::init();
	}
);
