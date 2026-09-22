<?php
/**
 * Plugin Name:       EPIC Order Emails
 * Plugin URI:        https://epicroastery.example/
 * Description:       Two customer-facing WooCommerce emails for EPIC Roastery: an order-received confirmation, and a "your order has shipped" email carrying the ViettelPost tracking code once a staff member books the shipment in epic-viettelpost-shipping. Both are registered as normal WC_Email classes, so WooCommerce → Settings → Emails is the only admin screen this plugin needs.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * Author:            EPIC Coffee Roaster
 * Text Domain:       epic-order-emails
 * Domain Path:       /languages
 *
 * @package Epic_Order_Emails
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'EPIC_ORDER_EMAILS_VERSION', '1.1.0' );
define( 'EPIC_ORDER_EMAILS_PLUGIN_FILE', __FILE__ );
define( 'EPIC_ORDER_EMAILS_DIR', plugin_dir_path( __FILE__ ) );
define( 'EPIC_ORDER_EMAILS_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declare HPOS compatibility — same boilerplate as epic-viettelpost-shipping and
 * epic-payment-store. Nothing in this plugin touches order storage directly
 * (everything goes through WC_Order's own methods), so this is a formality,
 * not a functional dependency.
 */
add_action(
	'before_woocommerce_init',
	function () {
		$features_util = '\Automattic\WooCommerce\Utilities\FeaturesUtil';
		if ( class_exists( $features_util ) && method_exists( $features_util, 'declare_compatibility' ) ) {
			$features_util::declare_compatibility( 'custom_order_tables', EPIC_ORDER_EMAILS_PLUGIN_FILE, true );
		}
	}
);

function epic_order_emails_woocommerce_missing_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			esc_html_e(
				'EPIC Order Emails requires WooCommerce to be installed and active.',
				'epic-order-emails'
			);
			?>
		</p>
	</div>
	<?php
}

function epic_order_emails_is_woocommerce_active() {
	return class_exists( 'WooCommerce' );
}

add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain( 'epic-order-emails', false, dirname( plugin_basename( EPIC_ORDER_EMAILS_PLUGIN_FILE ) ) . '/languages' );

		if ( ! epic_order_emails_is_woocommerce_active() ) {
			if ( is_admin() ) {
				add_action( 'admin_notices', 'epic_order_emails_woocommerce_missing_notice' );
			}
			return;
		}
	}
);

/**
 * Registers both emails with WooCommerce's own email system.
 *
 * IMPORTANT — unlike epic-viettelpost-shipping, this registration must NOT be gated
 * behind is_admin(). The "order received" email's trigger
 * (woocommerce_order_status_pending_to_processing) fires wherever the order
 * is actually created — for this store, that's almost always the Next.js
 * checkout's server-side call to the WooCommerce REST API
 * (website/src/app/api/checkout/route.ts -> src/lib/woocommerce.ts), which is
 * a front-end/REST request context, never wp-admin. Gating this plugin's
 * bootstrap behind is_admin() (the pattern epic-viettelpost-shipping uses, since
 * every one of *its* features genuinely is admin-only) would silently mean
 * the confirmation email never fires for a single real order.
 *
 * The classes are require_once'd lazily inside this filter callback rather
 * than in the unconditional include list above, mirroring how
 * epic-viettelpost-shipping/epic-viettelpost-shipping.php defers class-settings.php: WC_Email
 * is loaded early enough in WooCommerce's own bootstrap that this isn't
 * strictly necessary the way WC_Settings_Page's later loading is, but doing
 * it this way costs nothing and keeps both plugins following the same
 * "only load a WooCommerce-dependent class from inside the filter that needs
 * it" convention.
 */
add_filter(
	'woocommerce_email_classes',
	function ( $email_classes ) {
		require_once EPIC_ORDER_EMAILS_DIR . 'includes/class-email-order-created.php';
		require_once EPIC_ORDER_EMAILS_DIR . 'includes/class-email-order-shipped.php';

		if ( class_exists( 'Epic_Email_Order_Created' ) ) {
			$email_classes['epic_order_created'] = new Epic_Email_Order_Created();
		}
		if ( class_exists( 'Epic_Email_Order_Shipped' ) ) {
			$email_classes['epic_order_shipped'] = new Epic_Email_Order_Shipped();
		}

		return $email_classes;
	}
);

/**
 * Public-facing ViettelPost tracking URL for a given waybill number, used by
 * the "order shipped" email template.
 *
 * UNVERIFIED — ViettelPost's tracking page is a client-rendered app; the
 * `?billcode=` query-string form is the commonly-seen convention, but confirm
 * it deep-links to this store's shipments before relying on it — if it
 * doesn't, the email's fallback line ("or enter this code manually at
 * viettelpost.com.vn") still gets the customer to the right place. Adjust via
 * the `epic_order_emails_viettelpost_tracking_url` filter once confirmed,
 * without touching the template.
 */
function epic_order_emails_viettelpost_tracking_url( $tracking_code ) {
	$url = 'https://viettelpost.com.vn/tra-cuu-hanh-trinh-don/?billcode=' . rawurlencode( $tracking_code );
	return apply_filters( 'epic_order_emails_viettelpost_tracking_url', $url, $tracking_code );
}

/**
 * Adds a "Settings" link on the Plugins list page pointing straight at
 * WooCommerce's own Emails tab, since that's the entire admin surface this
 * plugin has.
 */
add_filter(
	'plugin_action_links_' . plugin_basename( EPIC_ORDER_EMAILS_PLUGIN_FILE ),
	function ( $links ) {
		$settings_url  = admin_url( 'admin.php?page=wc-settings&tab=email' );
		$settings_link = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'epic-order-emails' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}
);
