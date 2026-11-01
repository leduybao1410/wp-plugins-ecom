<?php
/**
 * Plugin Name: EPIC Ops Console
 * Description: Serves the EPIC operations dashboard directly from this WordPress site at /ops, replacing the separate operations host.
 * Version: 0.1.2
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: EPIC Coffee Roastery
 * Text Domain: epic-ops-console
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'EPIC_OPS_CONSOLE_VERSION', '0.1.2' );
define( 'EPIC_OPS_CONSOLE_DIR', plugin_dir_path( __FILE__ ) );
define( 'EPIC_OPS_CONSOLE_URL', plugin_dir_url( __FILE__ ) );

require_once EPIC_OPS_CONSOLE_DIR . 'includes/class-epic-ops-session.php';
require_once EPIC_OPS_CONSOLE_DIR . 'includes/class-epic-ops-template.php';
require_once EPIC_OPS_CONSOLE_DIR . 'includes/class-epic-ops-console.php';

register_activation_hook( __FILE__, array( 'Epic_Ops_Console', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Epic_Ops_Console', 'deactivate' ) );
add_action( 'plugins_loaded', array( 'Epic_Ops_Console', 'init' ) );
