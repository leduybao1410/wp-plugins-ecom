<?php
/**
 * Plugin Name:       EPIC ViettelPost Shipping Manager
 * Plugin URI:        https://epicroastery.coffee/
 * Description:       ViettelPost shipment booking, cancellation, label printing, and status tracking for WooCommerce orders, mirroring the EPIC GHN Shipping Manager.
 * Version:           0.1.3
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * Author:            EPIC Coffee Roaster
 * Text Domain:       epic-viettelpost-shipping
 * Domain Path:       /languages
 *
 * @package Epic_ViettelPost_Shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'EPIC_VTP_VERSION', '0.1.3' );
define( 'EPIC_VTP_PLUGIN_FILE', __FILE__ );
define( 'EPIC_VTP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EPIC_VTP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declare HPOS (High-Performance Order Storage) compatibility so the plugin
 * works whether the store has WooCommerce's custom order tables enabled or is
 * still on the legacy wp_posts-based order storage.
 */
add_action(
	'before_woocommerce_init',
	function () {
		$features_util = '\Automattic\WooCommerce\Utilities\FeaturesUtil';
		if ( class_exists( $features_util ) && method_exists( $features_util, 'declare_compatibility' ) ) {
			$features_util::declare_compatibility( 'custom_order_tables', EPIC_VTP_PLUGIN_FILE, true );
		}
	}
);

/**
 * Bail with an admin notice if WooCommerce isn't active — every class in this
 * plugin assumes WooCommerce's order/settings APIs are available.
 */
function epic_vtp_woocommerce_missing_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			esc_html_e(
				'EPIC ViettelPost Shipping Manager requires WooCommerce to be installed and active.',
				'epic-viettelpost-shipping'
			);
			?>
		</p>
	</div>
	<?php
}

function epic_vtp_is_woocommerce_active() {
	return class_exists( 'WooCommerce' );
}

/**
 * Load plugin classes. Deliberately require_once-based (no Composer
 * autoloader) to keep the plugin a single self-contained folder installable
 * via Plugins > Add New > Upload Plugin with no build step.
 */
function epic_vtp_load_includes() {
	$includes = array(
		'includes/class-vtp-client.php',
		'includes/class-install.php',
		'includes/class-assets.php',
		'includes/class-address-resolver.php',
		'includes/class-order-meta-box.php',
		'includes/class-ajax.php',
		'includes/class-orders-list.php',
		'includes/class-webhook.php',
	);

	foreach ( $includes as $file ) {
		$path = EPIC_VTP_PLUGIN_DIR . $file;
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
}

/**
 * NOTE on includes/class-settings.php: it is deliberately NOT in the list
 * above. `Epic_VTP_Settings extends WC_Settings_Page`, and WC_Settings_Page
 * only exists in wp-admin, once WooCommerce has loaded its own settings
 * classes. Requiring that file (and thus executing its `extends`) at the wrong
 * moment throws an uncaught fatal that takes down every page on the site. The
 * file is required lazily from inside the `woocommerce_get_settings_pages`
 * filter callback below, which only ever runs once WC_Settings_Page exists.
 */
add_filter(
	'woocommerce_get_settings_pages',
	function ( $settings_pages ) {
		require_once EPIC_VTP_PLUGIN_DIR . 'includes/class-settings.php';
		if ( class_exists( 'Epic_VTP_Settings' ) ) {
			$settings_pages[] = new Epic_VTP_Settings();
		}
		return $settings_pages;
	}
);

function epic_vtp_init() {
	// Everything this plugin does is admin-only (settings screen, order meta
	// box, AJAX handlers, orders list). is_admin() is also true for
	// admin-ajax.php requests, so this doesn't block any AJAX action. Gating
	// here means none of this plugin's code runs on front-end requests.
	if ( ! is_admin() || ! epic_vtp_is_woocommerce_active() ) {
		if ( ! epic_vtp_is_woocommerce_active() && is_admin() ) {
			add_action( 'admin_notices', 'epic_vtp_woocommerce_missing_notice' );
		}
		return;
	}

	epic_vtp_load_includes();

	Epic_VTP_Order_Meta_Box::init();
	Epic_VTP_Ajax::init();
	Epic_VTP_Orders_List::init();

	// The webhook REST route must be registered even for requests that reach
	// wp-json (which are not is_admin()), so it is registered separately on
	// rest_api_init below rather than here.
}
add_action( 'plugins_loaded', 'epic_vtp_init' );

/**
 * Registers the inbound ViettelPost webhook route. Runs outside the
 * is_admin()-gated init because wp-json requests are not admin requests.
 */
add_action(
	'rest_api_init',
	function () {
		if ( ! epic_vtp_is_woocommerce_active() ) {
			return;
		}
		require_once EPIC_VTP_PLUGIN_DIR . 'includes/class-vtp-client.php';
		require_once EPIC_VTP_PLUGIN_DIR . 'includes/class-order-meta-box.php';
		require_once EPIC_VTP_PLUGIN_DIR . 'includes/class-webhook.php';
		Epic_VTP_Webhook::register_routes();
	}
);

/**
 * Runs the install routine — seeds default options and the bundle table so a
 * later bundling phase needs no separate migration step.
 */
function epic_vtp_activate() {
	require_once EPIC_VTP_PLUGIN_DIR . 'includes/class-install.php';
	Epic_VTP_Install::activate();
}
register_activation_hook( EPIC_VTP_PLUGIN_FILE, 'epic_vtp_activate' );

/**
 * Adds a "Settings" link on the Plugins list page for convenience.
 */
add_filter(
	'plugin_action_links_' . plugin_basename( EPIC_VTP_PLUGIN_FILE ),
	function ( $links ) {
		$settings_url  = admin_url( 'admin.php?page=wc-settings&tab=epic_vtp_shipping' );
		$settings_link = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'epic-viettelpost-shipping' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}
);
