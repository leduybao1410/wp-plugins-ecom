<?php
/**
 * Plugin Name:       EPIC GHN Shipping Manager
 * Plugin URI:        https://epicroastery.example/
 * Description:       GHN (Giao Hàng Nhanh) shipment booking, cancellation, label printing, and status tracking for WooCommerce orders, including bundling multiple orders into a single GHN shipment from the Orders list.
 * Version:           0.10.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * Author:            EPIC Coffee Roaster
 * Text Domain:       epic-ghn-shipping
 * Domain Path:       /languages
 *
 * @package Epic_GHN_Shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'EPIC_GHN_VERSION', '0.10.0' );
define( 'EPIC_GHN_PLUGIN_FILE', __FILE__ );
define( 'EPIC_GHN_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EPIC_GHN_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declare HPOS (High-Performance Order Storage) compatibility so the plugin
 * works whether the store has WooCommerce's custom order tables enabled or
 * is still on the legacy wp_posts-based order storage.
 */
add_action(
	'before_woocommerce_init',
	function () {
		$features_util = '\Automattic\WooCommerce\Utilities\FeaturesUtil';
		// method_exists(), not just class_exists(): a class existing doesn't
		// guarantee a specific method does on every WooCommerce version —
		// see the long comment in Epic_GHN_Order_Meta_Box::order_screen_id()
		// for why this plugin no longer assumes otherwise anywhere.
		if ( class_exists( $features_util ) && method_exists( $features_util, 'declare_compatibility' ) ) {
			$features_util::declare_compatibility( 'custom_order_tables', EPIC_GHN_PLUGIN_FILE, true );
		}
	}
);

/**
 * Bail with an admin notice if WooCommerce isn't active — every class in
 * this plugin assumes WooCommerce's order/settings APIs are available.
 */
function epic_ghn_woocommerce_missing_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			esc_html_e(
				'EPIC GHN Shipping Manager requires WooCommerce to be installed and active.',
				'epic-ghn-shipping'
			);
			?>
		</p>
	</div>
	<?php
}

function epic_ghn_is_woocommerce_active() {
	return class_exists( 'WooCommerce' );
}

/**
 * Load plugin classes and boot each subsystem. Deliberately require_once-based
 * (no Composer autoloader) to keep the plugin a single self-contained folder
 * that installs via Plugins > Add New > Upload Plugin with no build step.
 */
function epic_ghn_load_includes() {
	$includes = array(
		'includes/class-install.php',
		'includes/class-ghn-client.php',
		'includes/class-legacy-address.php',
		'includes/class-address-resolver.php',
		'includes/class-assets.php',
		'includes/class-bundle.php',
		'includes/class-order-meta-box.php',
		'includes/class-ajax.php',
		'includes/class-orders-list.php',
		'includes/class-bundle-admin-page.php',
	);

	foreach ( $includes as $file ) {
		$path = EPIC_GHN_PLUGIN_DIR . $file;
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
}

/**
 * NOTE on includes/class-settings.php: it is deliberately NOT in the list
 * above. `Epic_GHN_Settings extends WC_Settings_Page`, and WC_Settings_Page
 * only exists in wp-admin, once WooCommerce has loaded its own settings
 * classes — never on front-end requests, and not guaranteed to exist yet
 * merely because `plugins_loaded` has fired for our plugin. Requiring that
 * file (and thus executing its `extends`) at the wrong moment throws an
 * uncaught "Class WC_Settings_Page not found" fatal that takes down every
 * page on the site, not just wp-admin — exactly the crash this plugin
 * shipped with in an earlier build.
 *
 * The fix (the pattern WooCommerce's own docs recommend for exactly this
 * situation): defer requiring the file until inside the
 * `woocommerce_get_settings_pages` filter callback itself. That filter is
 * only ever applied from within WC_Admin_Settings::get_settings_pages(), so
 * by the time this callback runs, WC_Settings_Page is guaranteed loaded.
 */
add_filter(
	'woocommerce_get_settings_pages',
	function ( $settings_pages ) {
		require_once EPIC_GHN_PLUGIN_DIR . 'includes/class-settings.php';
		if ( class_exists( 'Epic_GHN_Settings' ) ) {
			$settings_pages[] = new Epic_GHN_Settings();
		}
		return $settings_pages;
	}
);

function epic_ghn_init() {
	// Everything this plugin does in Phase 1 (settings screen, order meta
	// box, AJAX handlers) is admin-only — is_admin() is also true for
	// admin-ajax.php requests, so this doesn't block any AJAX action.
	// Gating here means none of this plugin's code runs on front-end
	// requests at all, which is both faster and removes a whole category of
	// "loaded a WooCommerce admin class too early" risk like the one above.
	if ( ! is_admin() || ! epic_ghn_is_woocommerce_active() ) {
		if ( ! epic_ghn_is_woocommerce_active() && is_admin() ) {
			add_action( 'admin_notices', 'epic_ghn_woocommerce_missing_notice' );
		}
		return;
	}

	epic_ghn_load_includes();

	Epic_GHN_Order_Meta_Box::init();
	Epic_GHN_Ajax::init();
	Epic_GHN_Orders_List::init();
	Epic_GHN_Bundle_Admin_Page::init();
}
add_action( 'plugins_loaded', 'epic_ghn_init' );

/**
 * Runs the (currently minimal) install routine — creates the bundles table
 * ahead of Phase 2 so upgrading later doesn't need a fresh activation.
 */
function epic_ghn_activate() {
	require_once EPIC_GHN_PLUGIN_DIR . 'includes/class-install.php';
	Epic_GHN_Install::activate();
}
register_activation_hook( EPIC_GHN_PLUGIN_FILE, 'epic_ghn_activate' );

/**
 * Adds a "Settings" link on the Plugins list page for convenience.
 */
add_filter(
	'plugin_action_links_' . plugin_basename( EPIC_GHN_PLUGIN_FILE ),
	function ( $links ) {
		$settings_url = admin_url( 'admin.php?page=wc-settings&tab=epic_ghn_shipping' );
		$settings_link = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'epic-ghn-shipping' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}
);
