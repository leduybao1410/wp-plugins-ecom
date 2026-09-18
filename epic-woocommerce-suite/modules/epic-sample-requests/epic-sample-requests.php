<?php
/**
 * Plugin Name:       EPIC Sample Requests
 * Plugin URI:        https://epicroastery.example/
 * Description:       Backs the Next.js website's free-coffee-sample form on the /roastery page — receives a request (required: name, phone, full Vietnam address; optional: email, favourite taste, brew style) over a shared-secret-authenticated REST endpoint, records it in its own table, emails the store's sample-request notification address, and sends the requester a bilingual thank-you confirmation when they left an email, each via a normal WC_Email class. WooCommerce → Sample Requests shows the full log (with each row's admin-email and thank-you delivery status), regardless of whether either email succeeded. No WooCommerce order/customer record is created from a submission.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * Author:            EPIC Coffee Roaster
 * Text Domain:       epic-sample-requests
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 *
 * @package Epic_Sample_Requests
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'EPIC_SAMPLE_REQUESTS_VERSION', '1.2.0' );
define( 'EPIC_SAMPLE_REQUESTS_PLUGIN_FILE', __FILE__ );
define( 'EPIC_SAMPLE_REQUESTS_DIR', plugin_dir_path( __FILE__ ) );
define( 'EPIC_SAMPLE_REQUESTS_URL', plugin_dir_url( __FILE__ ) );

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
			$features_util::declare_compatibility( 'custom_order_tables', EPIC_SAMPLE_REQUESTS_PLUGIN_FILE, true );
		}
	}
);

function epic_sample_requests_woocommerce_missing_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			esc_html_e(
				'EPIC Sample Requests requires WooCommerce to be installed and active (it uses WooCommerce\'s WC_Email system to send the notification email).',
				'epic-sample-requests'
			);
			?>
		</p>
	</div>
	<?php
}

function epic_sample_requests_is_woocommerce_active() {
	return class_exists( 'WooCommerce' );
}

require_once EPIC_SAMPLE_REQUESTS_DIR . 'includes/class-store.php';
require_once EPIC_SAMPLE_REQUESTS_DIR . 'includes/class-settings.php';

register_activation_hook( EPIC_SAMPLE_REQUESTS_PLUGIN_FILE, array( 'Epic_Sample_Store', 'install' ) );

add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain( 'epic-sample-requests', false, dirname( plugin_basename( EPIC_SAMPLE_REQUESTS_PLUGIN_FILE ) ) . '/languages' );

		// Defensive schema check, in addition to the activation hook above —
		// covers the case where this module's files get updated in place
		// while already active (WordPress doesn't re-fire
		// register_activation_hook for that), so a future schema change
		// still lands without requiring a deactivate/reactivate cycle.
		if ( get_option( Epic_Sample_Store::DB_VERSION_OPTION ) !== Epic_Sample_Store::DB_VERSION ) {
			Epic_Sample_Store::install();
		}

		if ( ! epic_sample_requests_is_woocommerce_active() ) {
			if ( is_admin() ) {
				add_action( 'admin_notices', 'epic_sample_requests_woocommerce_missing_notice' );
			}
			return;
		}
	},
	5
);

add_action( 'admin_menu', array( 'Epic_Sample_Settings', 'add_menu' ) );
add_action( 'admin_init', array( 'Epic_Sample_Settings', 'register_setting' ) );

/**
 * The REST route must be registered unconditionally on `rest_api_init`
 * (never gated behind is_admin()) — the Next.js website's server-side
 * `/api/sample` route calls this from a Node process, never from
 * wp-admin. See includes/class-rest-api.php.
 */
add_action(
	'rest_api_init',
	function () {
		require_once EPIC_SAMPLE_REQUESTS_DIR . 'includes/class-rest-api.php';
		Epic_Sample_Rest_Api::register_routes();
	}
);

/**
 * Registers both emails with WooCommerce's own email system, same pattern as
 * epic-newsletter-subscription — the classes are require_once'd lazily inside
 * this filter callback rather than the unconditional include list above,
 * following this codebase's "only load a WooCommerce-dependent class from
 * inside the filter that needs it" convention.
 *
 * Two independent WC_Emails listen on `epic_sample_request_received`:
 * `Epic_Email_Sample_Request` (admin notification, priority 10) and
 * `Epic_Email_Sample_Confirmation` (customer thank-you, priority 20). Each
 * is enable/disable-able separately under WooCommerce → Settings → Emails.
 */
add_filter(
	'woocommerce_email_classes',
	function ( $email_classes ) {
		require_once EPIC_SAMPLE_REQUESTS_DIR . 'includes/class-email-sample-request.php';
		require_once EPIC_SAMPLE_REQUESTS_DIR . 'includes/class-email-sample-confirmation.php';

		if ( class_exists( 'Epic_Email_Sample_Request' ) ) {
			$email_classes['epic_sample_request'] = new Epic_Email_Sample_Request();
		}
		if ( class_exists( 'Epic_Email_Sample_Confirmation' ) ) {
			$email_classes['epic_sample_confirmation'] = new Epic_Email_Sample_Confirmation();
		}

		return $email_classes;
	}
);

/**
 * Adds a "Settings" link on the Plugins list page pointing at this module's
 * own screen (the shared secret) — the notification email's own
 * subject/heading/recipient live under WooCommerce → Settings → Emails
 * instead, same as every other EPIC email.
 */
add_filter(
	'plugin_action_links_' . plugin_basename( EPIC_SAMPLE_REQUESTS_PLUGIN_FILE ),
	function ( $links ) {
		$settings_url  = admin_url( 'admin.php?page=epic-sample-requests' );
		$settings_link = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'epic-sample-requests' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}
);
