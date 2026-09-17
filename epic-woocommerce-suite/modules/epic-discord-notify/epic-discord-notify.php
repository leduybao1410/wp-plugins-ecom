<?php
/**
 * Plugin Name:       EPIC Discord Order Notifications
 * Plugin URI:        https://epicroastery.example/
 * Description:       Posts a message to a Discord channel (via webhook) whenever a new order is placed on EPIC Roastery — order number, customer, items, total, and a direct link back to the order in wp-admin. Settings live under WooCommerce → Settings → Discord Notify.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * Author:            EPIC Coffee Roaster
 * Text Domain:       epic-discord-notify
 * Domain Path:       /languages
 *
 * @package Epic_Discord_Notify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'EPIC_DISCORD_NOTIFY_VERSION', '1.0.0' );
define( 'EPIC_DISCORD_NOTIFY_PLUGIN_FILE', __FILE__ );
define( 'EPIC_DISCORD_NOTIFY_DIR', plugin_dir_path( __FILE__ ) );
define( 'EPIC_DISCORD_NOTIFY_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declare HPOS compatibility — same boilerplate as every other EPIC plugin
 * (epic-ghn-shipping, epic-order-emails, epic-payment-store). This plugin
 * only ever reads orders through WC_Order's own methods, never raw $wpdb, so
 * this is a formality rather than a functional dependency.
 */
add_action(
	'before_woocommerce_init',
	function () {
		$features_util = '\Automattic\WooCommerce\Utilities\FeaturesUtil';
		if ( class_exists( $features_util ) && method_exists( $features_util, 'declare_compatibility' ) ) {
			$features_util::declare_compatibility( 'custom_order_tables', EPIC_DISCORD_NOTIFY_PLUGIN_FILE, true );
		}
	}
);

function epic_discord_notify_woocommerce_missing_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			esc_html_e(
				'EPIC Discord Order Notifications requires WooCommerce to be installed and active.',
				'epic-discord-notify'
			);
			?>
		</p>
	</div>
	<?php
}

function epic_discord_notify_is_woocommerce_active() {
	return class_exists( 'WooCommerce' );
}

/**
 * Registers "Discord Notify" as a tab under WooCommerce → Settings.
 * Deliberately deferred to the `woocommerce_get_settings_pages` filter, not
 * required from the normal plugins_loaded include list — `extends
 * WC_Settings_Page` needs that class to already exist, and it's only ever
 * loaded by WooCommerce itself in wp-admin, by the time it's building its
 * own settings-pages list. Requiring the file any earlier risks a site-wide
 * fatal ("Class WC_Settings_Page not found") — see epic-ghn-shipping's main
 * file for the same pattern and the incident that motivated it.
 */
add_filter(
	'woocommerce_get_settings_pages',
	function ( $settings_pages ) {
		require_once EPIC_DISCORD_NOTIFY_DIR . 'includes/class-settings.php';
		if ( class_exists( 'Epic_Discord_Settings' ) ) {
			$settings_pages[] = new Epic_Discord_Settings();
		}
		return $settings_pages;
	}
);

/**
 * Boots the notifier. NOT gated behind is_admin() — the order status
 * transition this plugin listens for (pending -> processing / on-hold)
 * happens wherever the order is actually created, which for this store is
 * almost always the Next.js checkout's server-side call to the WooCommerce
 * REST API (a front-end/REST request context, never wp-admin). Same
 * reasoning as epic-order-emails/epic-order-emails.php, which hooks the
 * identical transitions for the same reason.
 */
add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain( 'epic-discord-notify', false, dirname( plugin_basename( EPIC_DISCORD_NOTIFY_PLUGIN_FILE ) ) . '/languages' );

		if ( ! epic_discord_notify_is_woocommerce_active() ) {
			if ( is_admin() ) {
				add_action( 'admin_notices', 'epic_discord_notify_woocommerce_missing_notice' );
			}
			return;
		}

		require_once EPIC_DISCORD_NOTIFY_DIR . 'includes/class-notifier.php';
		Epic_Discord_Notifier::init();

		if ( is_admin() ) {
			require_once EPIC_DISCORD_NOTIFY_DIR . 'includes/class-ajax.php';
			Epic_Discord_Ajax::init();
		}
	}
);

/**
 * Adds a "Settings" link on the Plugins list page pointing at this plugin's
 * own settings tab.
 */
add_filter(
	'plugin_action_links_' . plugin_basename( EPIC_DISCORD_NOTIFY_PLUGIN_FILE ),
	function ( $links ) {
		$settings_url  = admin_url( 'admin.php?page=wc-settings&tab=epic_discord_notify' );
		$settings_link = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'epic-discord-notify' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}
);
