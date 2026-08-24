<?php
/**
 * Plugin Name: EPIC Advanced Coupons
 * Description: Adds an "Advanced Rules" tab to the normal WooCommerce coupon editor with extra rule types WooCommerce doesn't ship: first-time-customer-only, an email+phone allowlist, a recurring day/time schedule, Buy X Get Y bundle discounts, auto-apply (no code needed) coupons, and a bulk unique single-use code generator — plus a "Redemptions" report (Marketing → Coupons → Redemptions) tracking which coupon/generated code was used on which order, with search/filter and CSV export. Every coupon is still a completely normal WooCommerce coupon (same post type, same admin screen) — this plugin only layers extra conditions, effects, and reporting on top. Also exposes a POST /wp-json/epic/v1/coupon/quote REST route (WooCommerce → Coupon Quote API for the shared secret) so the headless Next.js website's checkout — which never uses WC_Cart or the native checkout form — can preview and apply every rule above on a real order.
 * Version: 1.2.0
 * Author: EPIC Coffee Roastery
 * Text Domain: epic-advanced-coupons
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Guarded, and every require below uses __DIR__ rather than these constants
// for its own path: __DIR__ is resolved per-file at compile time from that
// file's own real location, so it can never be hijacked by an unrelated
// same-named constant left over from another copy of this plugin (e.g. an
// old "epic-advanced-coupons" install still active alongside a freshly
// uploaded "epic-advanced-coupons 2" folder after an update-without-
// removing-the-old-one) — the constants are kept only for other code
// (themes, mu-plugins) that might reasonably want this plugin's own path.
if ( ! defined( 'EPIC_ADV_COUPONS_VERSION' ) ) {
	define( 'EPIC_ADV_COUPONS_VERSION', '1.2.0' );
}
if ( ! defined( 'EPIC_ADV_COUPONS_FILE' ) ) {
	define( 'EPIC_ADV_COUPONS_FILE', __FILE__ );
}
if ( ! defined( 'EPIC_ADV_COUPONS_DIR' ) ) {
	define( 'EPIC_ADV_COUPONS_DIR', plugin_dir_path( __FILE__ ) );
}

require_once __DIR__ . '/includes/class-meta.php';
require_once __DIR__ . '/includes/class-admin-tab.php';
require_once __DIR__ . '/includes/class-restrictions.php';
require_once __DIR__ . '/includes/class-bxgy.php';
require_once __DIR__ . '/includes/class-auto-apply.php';
require_once __DIR__ . '/includes/class-bulk-generate.php';
require_once __DIR__ . '/includes/class-redemption-log.php';
require_once __DIR__ . '/includes/class-redemption-admin.php';
require_once __DIR__ . '/includes/class-quote-settings.php';
require_once __DIR__ . '/includes/class-rest-quote.php';

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		Epic_Adv_Coupons_Admin_Tab::init();
		Epic_Adv_Coupons_Restrictions::init();
		Epic_Adv_Coupons_Bxgy::init();
		Epic_Adv_Coupons_Auto_Apply::init();
		Epic_Adv_Coupons_Bulk_Generate::init();
		Epic_Adv_Coupons_Redemption_Log::init();
		Epic_Adv_Coupons_Redemption_Admin::init();
		Epic_Adv_Coupons_Quote_Settings::init();
		Epic_Adv_Coupons_Rest_Quote::init();

		// Belt-and-suspenders: if this plugin is ever updated in place while
		// already active (a plain file overwrite, no deactivate/reactivate),
		// the activation hook below never fires — so also create the table
		// here if it's missing. dbDelta() is safe to re-run any number of
		// times, so this is cheap and never touches existing data.
		if ( get_option( Epic_Adv_Coupons_Redemption_Log::DB_VERSION_OPTION ) !== Epic_Adv_Coupons_Redemption_Log::DB_VERSION ) {
			Epic_Adv_Coupons_Redemption_Log::install();
		}
	}
);

// Creates the epic_coupon_redemptions table on activation. Uses dbDelta, so
// it's also safe to re-run (e.g. on a future version bump) without dropping
// existing data.
register_activation_hook( __FILE__, array( 'Epic_Adv_Coupons_Redemption_Log', 'install' ) );
