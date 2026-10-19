<?php
/**
 * Plugin Name: EPIC Support Chat Archive
 * Description: Stores redacted support conversations for the authenticated EPIC operations dashboard.
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: epic-support-chat
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'EPIC_SUPPORT_CHAT_VERSION', '0.1.0' );
define( 'EPIC_SUPPORT_CHAT_FILE', __FILE__ );
define( 'EPIC_SUPPORT_CHAT_DIR', plugin_dir_path( __FILE__ ) );

require_once EPIC_SUPPORT_CHAT_DIR . 'includes/class-store.php';
require_once EPIC_SUPPORT_CHAT_DIR . 'includes/class-rest-api.php';

register_activation_hook( EPIC_SUPPORT_CHAT_FILE, array( 'Epic_Support_Chat_Store', 'install' ) );
register_deactivation_hook( EPIC_SUPPORT_CHAT_FILE, array( 'Epic_Support_Chat_Store', 'deactivate' ) );
add_action( 'plugins_loaded', array( 'Epic_Support_Chat_Store', 'maybe_upgrade' ) );
add_action( 'rest_api_init', array( 'Epic_Support_Chat_Rest_Api', 'register_routes' ) );
add_action( 'epic_support_chat_purge', array( 'Epic_Support_Chat_Store', 'purge_expired' ) );
