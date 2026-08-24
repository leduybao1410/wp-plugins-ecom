<?php
/**
 * Plugin Name:       EPIC Product Reviews
 * Plugin URI:        https://epicroastery.example/
 * Description:       Backs the Next.js website's product-review section (product detail page) — receives a customer review over a shared-secret-authenticated REST endpoint, stores it in its own table pending moderation, and serves approved reviews back (plus the aggregate rating) over a public read-only REST endpoint that the website renders on the page AND injects into its Product structured data (aggregateRating/review) for Google Search Console. WooCommerce → Product Reviews is where staff approve or delete submissions before they go live.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * Author:            EPIC Coffee Roaster
 * Text Domain:       epic-product-reviews
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 *
 * @package Epic_Product_Reviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'EPIC_PRODUCT_REVIEWS_VERSION', '1.1.0' );
define( 'EPIC_PRODUCT_REVIEWS_PLUGIN_FILE', __FILE__ );
define( 'EPIC_PRODUCT_REVIEWS_DIR', plugin_dir_path( __FILE__ ) );
define( 'EPIC_PRODUCT_REVIEWS_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declare HPOS compatibility — same boilerplate as every other EPIC plugin.
 * This plugin never touches order storage; the declaration is a formality.
 */
add_action(
	'before_woocommerce_init',
	function () {
		$features_util = '\Automattic\WooCommerce\Utilities\FeaturesUtil';
		if ( class_exists( $features_util ) && method_exists( $features_util, 'declare_compatibility' ) ) {
			$features_util::declare_compatibility( 'custom_order_tables', EPIC_PRODUCT_REVIEWS_PLUGIN_FILE, true );
		}
	}
);

function epic_product_reviews_woocommerce_missing_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			esc_html_e(
				'EPIC Product Reviews requires WooCommerce to be installed and active (it uses WooCommerce\'s wc_get_product() to resolve a review\'s product name in the moderation screen).',
				'epic-product-reviews'
			);
			?>
		</p>
	</div>
	<?php
}

function epic_product_reviews_is_woocommerce_active() {
	return class_exists( 'WooCommerce' );
}

require_once EPIC_PRODUCT_REVIEWS_DIR . 'includes/class-store.php';
require_once EPIC_PRODUCT_REVIEWS_DIR . 'includes/class-settings.php';

register_activation_hook( EPIC_PRODUCT_REVIEWS_PLUGIN_FILE, array( 'Epic_Reviews_Store', 'install' ) );

add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain( 'epic-product-reviews', false, dirname( plugin_basename( EPIC_PRODUCT_REVIEWS_PLUGIN_FILE ) ) . '/languages' );

		// Defensive schema check, in addition to the activation hook above —
		// covers the case where this plugin's files get updated in place
		// while already active (WordPress doesn't re-fire
		// register_activation_hook for that), so a future schema change
		// still lands without requiring a deactivate/reactivate cycle.
		if ( get_option( Epic_Reviews_Store::DB_VERSION_OPTION ) !== Epic_Reviews_Store::DB_VERSION ) {
			Epic_Reviews_Store::install();
		}

		if ( ! epic_product_reviews_is_woocommerce_active() ) {
			if ( is_admin() ) {
				add_action( 'admin_notices', 'epic_product_reviews_woocommerce_missing_notice' );
			}
			return;
		}
	},
	5
);

add_action( 'admin_menu', array( 'Epic_Reviews_Settings', 'add_menu' ) );
add_action( 'admin_init', array( 'Epic_Reviews_Settings', 'register_setting' ) );

/**
 * The REST routes must be registered unconditionally on `rest_api_init`
 * (never gated behind is_admin()) — the Next.js website's server-side
 * `src/lib/reviews.ts` calls these from a Node process, never from wp-admin.
 * See includes/class-rest-api.php.
 */
add_action(
	'rest_api_init',
	function () {
		require_once EPIC_PRODUCT_REVIEWS_DIR . 'includes/class-rest-api.php';
		Epic_Reviews_Rest_Api::register_routes();
	}
);

/**
 * Adds a "Settings" link on the Plugins list page pointing at this plugin's
 * own screen (the moderation log + shared secret), same pattern as
 * epic-wholesale-inquiries.
 */
add_filter(
	'plugin_action_links_' . plugin_basename( EPIC_PRODUCT_REVIEWS_PLUGIN_FILE ),
	function ( $links ) {
		$settings_url  = admin_url( 'admin.php?page=epic-product-reviews' );
		$settings_link = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Reviews', 'epic-product-reviews' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}
);
