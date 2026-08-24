<?php
/**
 * Plugin Name:       EPIC Wholesale Inquiries
 * Plugin URI:        https://epicroastery.example/
 * Description:       Backs the Next.js website's wholesale contact form (/wholesale) — receives a lead over a shared-secret-authenticated REST endpoint, records it in its own table, and emails the store's wholesale-inquiry notification address via a normal WC_Email class. WooCommerce → Wholesale Inquiries shows the full log (with each row's email delivery status), regardless of whether the notification email itself succeeded. No WooCommerce order/customer record is created from a submission.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * Author:            EPIC Coffee Roaster
 * Text Domain:       epic-wholesale-inquiries
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 *
 * @package Epic_Wholesale_Inquiries
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'EPIC_WHOLESALE_INQUIRIES_VERSION', '1.1.0' );
define( 'EPIC_WHOLESALE_INQUIRIES_PLUGIN_FILE', __FILE__ );
define( 'EPIC_WHOLESALE_INQUIRIES_DIR', plugin_dir_path( __FILE__ ) );
define( 'EPIC_WHOLESALE_INQUIRIES_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declare HPOS compatibility — same boilerplate as every other EPIC plugin
 * (epic-order-emails, epic-ghn-shipping, epic-payment-store). This plugin
 * never touches order storage at all (it has no concept of a WooCommerce
 * order), so this is a formality, not a functional dependency.
 */
add_action(
	'before_woocommerce_init',
	function () {
		$features_util = '\Automattic\WooCommerce\Utilities\FeaturesUtil';
		if ( class_exists( $features_util ) && method_exists( $features_util, 'declare_compatibility' ) ) {
			$features_util::declare_compatibility( 'custom_order_tables', EPIC_WHOLESALE_INQUIRIES_PLUGIN_FILE, true );
		}
	}
);

function epic_wholesale_inquiries_woocommerce_missing_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			esc_html_e(
				'EPIC Wholesale Inquiries requires WooCommerce to be installed and active (it uses WooCommerce\'s WC_Email system to send the notification email).',
				'epic-wholesale-inquiries'
			);
			?>
		</p>
	</div>
	<?php
}

function epic_wholesale_inquiries_is_woocommerce_active() {
	return class_exists( 'WooCommerce' );
}

require_once EPIC_WHOLESALE_INQUIRIES_DIR . 'includes/class-store.php';
require_once EPIC_WHOLESALE_INQUIRIES_DIR . 'includes/class-settings.php';

register_activation_hook( EPIC_WHOLESALE_INQUIRIES_PLUGIN_FILE, array( 'Epic_Wholesale_Store', 'install' ) );

add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain( 'epic-wholesale-inquiries', false, dirname( plugin_basename( EPIC_WHOLESALE_INQUIRIES_PLUGIN_FILE ) ) . '/languages' );

		// Defensive schema check, in addition to the activation hook above —
		// covers the case where this plugin's files get updated in place
		// while already active (WordPress doesn't re-fire
		// register_activation_hook for that), so a future schema change
		// still lands without requiring a deactivate/reactivate cycle.
		if ( get_option( Epic_Wholesale_Store::DB_VERSION_OPTION ) !== Epic_Wholesale_Store::DB_VERSION ) {
			Epic_Wholesale_Store::install();
		}

		if ( ! epic_wholesale_inquiries_is_woocommerce_active() ) {
			if ( is_admin() ) {
				add_action( 'admin_notices', 'epic_wholesale_inquiries_woocommerce_missing_notice' );
			}
			return;
		}
	},
	5
);

add_action( 'admin_menu', array( 'Epic_Wholesale_Settings', 'add_menu' ) );
add_action( 'admin_init', array( 'Epic_Wholesale_Settings', 'register_setting' ) );

/**
 * The REST route must be registered unconditionally on `rest_api_init`
 * (never gated behind is_admin()) — the Next.js website's server-side
 * `/api/wholesale` route calls this from a Node process, never from
 * wp-admin. See includes/class-rest-api.php.
 */
add_action(
	'rest_api_init',
	function () {
		require_once EPIC_WHOLESALE_INQUIRIES_DIR . 'includes/class-rest-api.php';
		Epic_Wholesale_Rest_Api::register_routes();
	}
);

/**
 * Registers the admin notification email with WooCommerce's own email
 * system, same pattern as epic-order-emails — the class is require_once'd
 * lazily inside this filter callback rather than the unconditional include
 * list above, following this codebase's "only load a WooCommerce-dependent
 * class from inside the filter that needs it" convention.
 */
add_filter(
	'woocommerce_email_classes',
	function ( $email_classes ) {
		require_once EPIC_WHOLESALE_INQUIRIES_DIR . 'includes/class-email-wholesale-inquiry.php';

		if ( class_exists( 'Epic_Email_Wholesale_Inquiry' ) ) {
			$email_classes['epic_wholesale_inquiry'] = new Epic_Email_Wholesale_Inquiry();
		}

		return $email_classes;
	}
);

/**
 * Adds a "Settings" link on the Plugins list page pointing at this plugin's
 * own settings screen (the shared secret) — the notification email's own
 * subject/heading/recipient live under WooCommerce → Settings → Emails
 * instead, same as every other EPIC email.
 */
add_filter(
	'plugin_action_links_' . plugin_basename( EPIC_WHOLESALE_INQUIRIES_PLUGIN_FILE ),
	function ( $links ) {
		$settings_url  = admin_url( 'admin.php?page=epic-wholesale-inquiries' );
		$settings_link = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'epic-wholesale-inquiries' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}
);
