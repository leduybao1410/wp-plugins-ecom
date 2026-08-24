<?php
/**
 * Plugin Name:       EPIC Newsletter Subscription
 * Plugin URI:        https://epicroastery.example/
 * Description:       Backs the Next.js website's footer newsletter subscription box — receives an email over a shared-secret-authenticated REST endpoint, records it in its own table, emails the store's newsletter notification address via a normal WC_Email class, and sends the subscriber a thank-you confirmation email. WooCommerce → Newsletter Subscribers shows the full list (with each email's delivery status, and CSV/XLSX export), regardless of whether the notification email itself succeeded. WooCommerce → Send Newsletter composes one bilingual email and delivers it to all/VI/EN subscribers in background batches. No WooCommerce order/customer record is created from a submission.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * Author:            EPIC Coffee Roaster
 * Text Domain:       epic-newsletter-subscription
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 *
 * @package Epic_Newsletter_Subscription
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'EPIC_NEWSLETTER_SUBSCRIPTION_VERSION', '1.2.0' );
define( 'EPIC_NEWSLETTER_SUBSCRIPTION_PLUGIN_FILE', __FILE__ );
define( 'EPIC_NEWSLETTER_SUBSCRIPTION_DIR', plugin_dir_path( __FILE__ ) );
define( 'EPIC_NEWSLETTER_SUBSCRIPTION_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declare HPOS compatibility — same boilerplate as every other EPIC plugin.
 * This plugin never touches order storage at all (a subscriber isn't a
 * WooCommerce entity), so this is a formality, not a functional dependency.
 */
add_action(
	'before_woocommerce_init',
	function () {
		$features_util = '\Automattic\WooCommerce\Utilities\FeaturesUtil';
		if ( class_exists( $features_util ) && method_exists( $features_util, 'declare_compatibility' ) ) {
			$features_util::declare_compatibility( 'custom_order_tables', EPIC_NEWSLETTER_SUBSCRIPTION_PLUGIN_FILE, true );
		}
	}
);

function epic_newsletter_subscription_woocommerce_missing_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			esc_html_e(
				'EPIC Newsletter Subscription requires WooCommerce to be installed and active (it uses WooCommerce\'s WC_Email system to send the notification email).',
				'epic-newsletter-subscription'
			);
			?>
		</p>
	</div>
	<?php
}

function epic_newsletter_subscription_is_woocommerce_active() {
	return class_exists( 'WooCommerce' );
}

require_once EPIC_NEWSLETTER_SUBSCRIPTION_DIR . 'includes/class-store.php';
require_once EPIC_NEWSLETTER_SUBSCRIPTION_DIR . 'includes/class-settings.php';
require_once EPIC_NEWSLETTER_SUBSCRIPTION_DIR . 'includes/class-campaign-store.php';
require_once EPIC_NEWSLETTER_SUBSCRIPTION_DIR . 'includes/class-broadcast-sender.php';
require_once EPIC_NEWSLETTER_SUBSCRIPTION_DIR . 'includes/class-broadcast-admin.php';

register_activation_hook(
	EPIC_NEWSLETTER_SUBSCRIPTION_PLUGIN_FILE,
	function () {
		Epic_Newsletter_Store::install();
		Epic_Newsletter_Campaign_Store::install();
	}
);

add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain( 'epic-newsletter-subscription', false, dirname( plugin_basename( EPIC_NEWSLETTER_SUBSCRIPTION_PLUGIN_FILE ) ) . '/languages' );

		// Defensive schema check, in addition to the activation hook above —
		// covers the case where this plugin's files get updated in place
		// while already active (WordPress doesn't re-fire
		// register_activation_hook for that), so a future schema change
		// still lands without requiring a deactivate/reactivate cycle.
		if ( get_option( Epic_Newsletter_Store::DB_VERSION_OPTION ) !== Epic_Newsletter_Store::DB_VERSION ) {
			Epic_Newsletter_Store::install();
		}
		if ( get_option( Epic_Newsletter_Campaign_Store::DB_VERSION_OPTION ) !== Epic_Newsletter_Campaign_Store::DB_VERSION ) {
			Epic_Newsletter_Campaign_Store::install();
		}

		if ( ! epic_newsletter_subscription_is_woocommerce_active() ) {
			if ( is_admin() ) {
				add_action( 'admin_notices', 'epic_newsletter_subscription_woocommerce_missing_notice' );
			}
			return;
		}
	},
	5
);

add_action( 'admin_menu', array( 'Epic_Newsletter_Settings', 'add_menu' ) );
add_action( 'admin_init', array( 'Epic_Newsletter_Settings', 'register_setting' ) );

/**
 * "Send Newsletter" — the bulk-campaign composer/sender admin screen. See
 * includes/class-broadcast-admin.php.
 */
add_action( 'admin_menu', array( 'Epic_Newsletter_Broadcast_Admin', 'add_menu' ) );

/**
 * The background hook that actually delivers a campaign's recipients in
 * batches — fired by Action Scheduler (or the WP-Cron fallback) rather than
 * from any page load. See includes/class-broadcast-sender.php's docblock for
 * why sending is batched instead of one synchronous loop.
 */
add_action( Epic_Newsletter_Broadcast_Sender::AS_HOOK, array( 'Epic_Newsletter_Broadcast_Sender', 'process_batch' ) );

/**
 * The CSV/XLSX "Export" links on WooCommerce → Newsletter Subscribers must
 * run on admin_init (before wp-admin's page chrome starts printing) so the
 * download's headers can be sent — see includes/class-export.php. It no-ops
 * for every admin request except the plugin's own export links, so it's
 * safe to hook unconditionally like class-settings.php's setting registration above.
 */
add_action(
	'admin_init',
	function () {
		require_once EPIC_NEWSLETTER_SUBSCRIPTION_DIR . 'includes/class-export.php';
		Epic_Newsletter_Export::handle();
	}
);

/**
 * The REST route must be registered unconditionally on `rest_api_init`
 * (never gated behind is_admin()) — the Next.js website's server-side
 * `/api/subscribe` route calls this from a Node process, never from
 * wp-admin. See includes/class-rest-api.php.
 */
add_action(
	'rest_api_init',
	function () {
		require_once EPIC_NEWSLETTER_SUBSCRIPTION_DIR . 'includes/class-rest-api.php';
		Epic_Newsletter_Rest_Api::register_routes();
	}
);

/**
 * Registers the plugin's emails with WooCommerce's own email system, same
 * pattern as epic-order-emails — the classes are require_once'd lazily
 * inside this filter callback rather than the unconditional include list
 * above, following this codebase's "only load a WooCommerce-dependent class
 * from inside the filter that needs it" convention.
 *
 * Two emails, both listening on the same `epic_newsletter_subscription_received`
 * action (see includes/class-rest-api.php):
 *  - "EPIC: Newsletter Subscriber" — admin notification to the store team.
 *  - "EPIC: Newsletter Confirmation" — thank-you sent back to the
 *    subscriber's own address (customer-facing).
 */
add_filter(
	'woocommerce_email_classes',
	function ( $email_classes ) {
		require_once EPIC_NEWSLETTER_SUBSCRIPTION_DIR . 'includes/class-email-newsletter-subscription.php';
		require_once EPIC_NEWSLETTER_SUBSCRIPTION_DIR . 'includes/class-email-newsletter-confirmation.php';

		if ( class_exists( 'Epic_Email_Newsletter_Subscription' ) ) {
			$email_classes['epic_newsletter_subscription'] = new Epic_Email_Newsletter_Subscription();
		}
		if ( class_exists( 'Epic_Email_Newsletter_Confirmation' ) ) {
			$email_classes['epic_newsletter_confirmation'] = new Epic_Email_Newsletter_Confirmation();
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
	'plugin_action_links_' . plugin_basename( EPIC_NEWSLETTER_SUBSCRIPTION_PLUGIN_FILE ),
	function ( $links ) {
		$settings_url  = admin_url( 'admin.php?page=epic-newsletter-subscription' );
		$settings_link = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'epic-newsletter-subscription' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}
);
