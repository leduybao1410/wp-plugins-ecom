<?php
/**
 * Plugin Name: EPIC Support FAQ
 * Description: Administrator-managed FAQ records, compiled context bundles and the website FAQ source.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Text Domain: epic-support-faq
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'EPIC_SUPPORT_FAQ_VERSION', '0.1.0' );
define( 'EPIC_SUPPORT_FAQ_FILE', __FILE__ );
define( 'EPIC_SUPPORT_FAQ_DIR', plugin_dir_path( __FILE__ ) );

require_once EPIC_SUPPORT_FAQ_DIR . 'includes/class-store.php';
require_once EPIC_SUPPORT_FAQ_DIR . 'includes/class-rest-api.php';

register_activation_hook( EPIC_SUPPORT_FAQ_FILE, static function () { Epic_Support_Faq_Store::install( true ); } );
add_action( 'plugins_loaded', array( 'Epic_Support_Faq_Store', 'maybe_upgrade' ) );
add_action( 'rest_api_init', array( 'Epic_Support_Faq_Rest_Api', 'register_routes' ) );
