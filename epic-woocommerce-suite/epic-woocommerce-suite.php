<?php
/**
 * Plugin Name:       EPIC WooCommerce Suite
 * Plugin URI:        https://github.com/leduybao1410/wp-plugins-ecom
 * Description:       All-in-one bundle of the EPIC Coffee Roastery WooCommerce plugins: advanced coupon rules, first-order coupon restriction, GHN shipping manager, news↔product links, newsletter subscriptions, unguessable order codes, order emails, payment store, product reviews, and wholesale inquiries. One plugin to activate instead of ten.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * Author:            EPIC Coffee Roaster
 * Text Domain:       epic-woocommerce-suite
 * Requires Plugins:  woocommerce
 *
 * @package Epic_WooCommerce_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'EPIC_SUITE_VERSION', '1.0.0' );
define( 'EPIC_SUITE_FILE', __FILE__ );
define( 'EPIC_SUITE_DIR', plugin_dir_path( __FILE__ ) );
define( 'EPIC_SUITE_MODULES_DIR', EPIC_SUITE_DIR . 'modules/' );

/**
 * The bundled modules, keyed by their standalone plugin slug.
 *
 * 'main'       — the module's main file, relative to EPIC_SUITE_MODULES_DIR.
 * 'standalone' — the plugin basename of the standalone copy of the same
 *                module (used for conflict detection).
 * 'sentinel'   — a constant the standalone copy defines when it loads, so we
 *                can also detect a copy that is loaded but not in
 *                active_plugins (e.g. network-activated).
 * 'label'      — human-readable name for admin notices.
 */
function epic_suite_modules() {
	return array(
		'epic-advanced-coupons'        => array(
			'main'       => 'epic-advanced-coupons/epic-advanced-coupons.php',
			'standalone' => 'epic-advanced-coupons/epic-advanced-coupons.php',
			'sentinel'   => 'EPIC_ADV_COUPONS_VERSION',
			'label'      => 'EPIC Advanced Coupons',
		),
		'epic-first-order-coupon'      => array(
			'main'       => 'epic-first-order-coupon/epic-first-order-coupon.php',
			'standalone' => 'epic-first-order-coupon/epic-first-order-coupon.php',
			'sentinel'   => 'EPIC_FIRST_ORDER_COUPON_VERSION',
			'label'      => 'EPIC First Order Coupon',
		),
		'epic-ghn-shipping'            => array(
			'main'       => 'epic-ghn-shipping/epic-ghn-shipping.php',
			'standalone' => 'epic-ghn-shipping/epic-ghn-shipping.php',
			'sentinel'   => 'EPIC_GHN_VERSION',
			'label'      => 'EPIC GHN Shipping Manager',
		),
		'epic-news-product-link'       => array(
			'main'       => 'epic-news-product-link/epic-news-product-link.php',
			'standalone' => 'epic-news-product-link/epic-news-product-link.php',
			'sentinel'   => 'EPIC_NEWS_PRODUCT_LINK_VERSION',
			'label'      => 'EPIC News ↔ Product Link',
		),
		'epic-newsletter-subscription' => array(
			'main'       => 'epic-newsletter-subscription/epic-newsletter-subscription.php',
			'standalone' => 'epic-newsletter-subscription/epic-newsletter-subscription.php',
			'sentinel'   => 'EPIC_NEWSLETTER_SUBSCRIPTION_VERSION',
			'label'      => 'EPIC Newsletter Subscription',
		),
		'epic-order-codes'             => array(
			'main'       => 'epic-order-codes/epic-order-codes.php',
			'standalone' => 'epic-order-codes/epic-order-codes.php',
			'sentinel'   => 'EPIC_ORDER_CODES_VERSION',
			'label'      => 'EPIC Order Codes',
		),
		'epic-order-emails'            => array(
			'main'       => 'epic-order-emails/epic-order-emails.php',
			'standalone' => 'epic-order-emails/epic-order-emails.php',
			'sentinel'   => 'EPIC_ORDER_EMAILS_VERSION',
			'label'      => 'EPIC Order Emails',
		),
		'epic-payment-store'           => array(
			'main'       => 'epic-payment-store/epic-payment-store.php',
			'standalone' => 'epic-payment-store/epic-payment-store.php',
			'sentinel'   => 'EPIC_PAYMENT_STORE_VERSION',
			'label'      => 'EPIC Payment Store',
		),
		'epic-product-reviews'         => array(
			'main'       => 'epic-product-reviews/epic-product-reviews.php',
			'standalone' => 'epic-product-reviews/epic-product-reviews.php',
			'sentinel'   => 'EPIC_PRODUCT_REVIEWS_VERSION',
			'label'      => 'EPIC Product Reviews',
		),
		'epic-wholesale-inquiries'     => array(
			'main'       => 'epic-wholesale-inquiries/epic-wholesale-inquiries.php',
			'standalone' => 'epic-wholesale-inquiries/epic-wholesale-inquiries.php',
			'sentinel'   => 'EPIC_WHOLESALE_INQUIRIES_VERSION',
			'label'      => 'EPIC Wholesale Inquiries',
		),
	);
}

/**
 * Load every bundled module by requiring its main file, exactly as WordPress
 * would if it were an active plugin on its own.
 *
 * If the standalone copy of a module is still active (or otherwise already
 * loaded), the bundled copy is skipped — loading both would redeclare every
 * one of the module's classes and fatal the whole site. The skip is surfaced
 * as an admin notice telling the user to deactivate the standalone copy.
 */
function epic_suite_require_modules() {
	$active_plugins = (array) get_option( 'active_plugins', array() );
	$skipped        = array();

	foreach ( epic_suite_modules() as $module ) {
		if ( in_array( $module['standalone'], $active_plugins, true ) || defined( $module['sentinel'] ) ) {
			$skipped[] = $module['label'];
			continue;
		}
		require_once EPIC_SUITE_MODULES_DIR . $module['main'];
	}

	if ( $skipped ) {
		$GLOBALS['epic_suite_skipped_modules'] = $skipped;
		add_action( 'admin_notices', 'epic_suite_standalone_conflict_notice' );
	}
}

function epic_suite_standalone_conflict_notice() {
	if ( empty( $GLOBALS['epic_suite_skipped_modules'] ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p>' . esc_html(
		sprintf(
			'EPIC WooCommerce Suite: these modules are still active as standalone plugins, so their bundled copies were not loaded: %s. Deactivate the standalone plugins to avoid duplicate functionality.',
			implode( ', ', $GLOBALS['epic_suite_skipped_modules'] )
		)
	) . '</p></div>';
}

epic_suite_require_modules();

/**
 * Site-specific REST URL rewrite (previously a mu-plugin). Bundled as an
 * opt-in module: it does nothing unless EPIC_REST_URL_FIX_FROM and
 * EPIC_REST_URL_FIX_TO are defined (e.g. in wp-config.php). See the module
 * file for why.
 */
require_once EPIC_SUITE_MODULES_DIR . 'epic-rest-url-fix.php';

/**
 * Declare HPOS compatibility for the suite as a whole.
 */
add_action(
	'before_woocommerce_init',
	function () {
		$features_util = '\Automattic\WooCommerce\Utilities\FeaturesUtil';
		if ( class_exists( $features_util ) && method_exists( $features_util, 'declare_compatibility' ) ) {
			$features_util::declare_compatibility( 'custom_order_tables', EPIC_SUITE_FILE, true );
		}
	}
);

/**
 * Activation.
 *
 * The modules' own register_activation_hook() calls are keyed to their own
 * main files, so they never fire when only this plugin is activated. Run
 * every module's install routine here instead. All of them are idempotent
 * dbDelta-based table creates, so re-running on a future version bump is
 * safe. None of them depend on WooCommerce being loaded (they use $wpdb /
 * dbDelta only), so this works during the activation request itself.
 */
function epic_suite_activate() {
	if ( class_exists( 'Epic_Adv_Coupons_Redemption_Log' ) ) {
		Epic_Adv_Coupons_Redemption_Log::install();
	}

	if ( defined( 'EPIC_GHN_PLUGIN_DIR' ) ) {
		require_once EPIC_GHN_PLUGIN_DIR . 'includes/class-install.php';
		if ( class_exists( 'Epic_GHN_Install' ) ) {
			Epic_GHN_Install::activate();
		}
	}

	if ( class_exists( 'Epic_Newsletter_Store' ) ) {
		Epic_Newsletter_Store::install();
	}
	if ( class_exists( 'Epic_Newsletter_Campaign_Store' ) ) {
		Epic_Newsletter_Campaign_Store::install();
	}

	if ( class_exists( 'Epic_Payment_Store' ) ) {
		Epic_Payment_Store::install();
	}
	if ( ! wp_next_scheduled( 'epic_payment_purge_expired' ) ) {
		wp_schedule_event( time(), 'daily', 'epic_payment_purge_expired' );
	}

	if ( class_exists( 'Epic_Reviews_Store' ) ) {
		Epic_Reviews_Store::install();
	}

	if ( class_exists( 'Epic_Wholesale_Store' ) ) {
		Epic_Wholesale_Store::install();
	}
}
register_activation_hook( __FILE__, 'epic_suite_activate' );

/**
 * Deactivation — clear the payment-store purge cron (the module's own
 * deactivation hook won't fire under the suite).
 */
function epic_suite_deactivate() {
	wp_clear_scheduled_hook( 'epic_payment_purge_expired' );
}
register_deactivation_hook( __FILE__, 'epic_suite_deactivate' );

/**
 * In-place-update fallback for epic-ghn-shipping, mirroring the defensive
 * schema check every other module already does on plugins_loaded. Epic_GHN_Install
 * is only loaded in admin (see the module's own main file), so this no-ops on
 * front-end requests.
 */
add_action(
	'plugins_loaded',
	function () {
		if ( class_exists( 'Epic_GHN_Install' ) && get_option( 'epic_ghn_db_version' ) !== Epic_GHN_Install::DB_VERSION ) {
			Epic_GHN_Install::activate();
		}
	},
	20
);
