<?php
/**
 * Plugin Name:       EPIC Mail MCP
 * Description:       Test and send approved email templates through the site's existing WP Mail SMTP configuration, via authenticated WordPress MCP abilities.
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            EPIC Coffee Roaster
 * Text Domain:       epic-mail-mcp
 *
 * @package Epic_Mail_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EPIC_MAIL_MCP_VERSION', '1.0.0' );
define( 'EPIC_MAIL_MCP_FILE', __FILE__ );
define( 'EPIC_MAIL_MCP_DIR', plugin_dir_path( __FILE__ ) );

require_once EPIC_MAIL_MCP_DIR . 'includes/class-audit.php';
require_once EPIC_MAIL_MCP_DIR . 'includes/class-templates.php';
require_once EPIC_MAIL_MCP_DIR . 'includes/class-sender.php';
require_once EPIC_MAIL_MCP_DIR . 'includes/class-abilities.php';

register_activation_hook( __FILE__, array( 'Epic_Mail_MCP_Audit', 'install' ) );

add_action(
	'plugins_loaded',
	static function () {
		Epic_Mail_MCP_Abilities::init();
	},
	20
);
