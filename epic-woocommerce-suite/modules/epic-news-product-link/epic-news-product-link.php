<?php
/**
 * Plugin Name:       EPIC News ↔ Product Link
 * Plugin URI:        https://epicroastery.example/
 * Description:       Adds a "Linked Coffee" meta box to the WordPress post editor so an admin can connect a journal/news post to WooCommerce product data — a specific product, a product category, and/or one or more product tags. Exposed via the REST API (post.meta) so the Next.js website's news article page can build a "related coffee" sidebar from an explicit editorial choice instead of guessing from post content.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * Author:            EPIC Coffee Roaster
 * Text Domain:       epic-news-product-link
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 *
 * @package Epic_News_Product_Link
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'EPIC_NEWS_PRODUCT_LINK_VERSION', '1.0.0' );
define( 'EPIC_NEWS_PRODUCT_LINK_FILE', __FILE__ );
define( 'EPIC_NEWS_PRODUCT_LINK_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Declare HPOS compatibility — same boilerplate as every other EPIC plugin
 * (epic-order-emails, epic-ghn-shipping, epic-wholesale-inquiries). This
 * plugin never touches order storage (it links posts to products, not
 * orders), so this is a formality, not a functional dependency.
 */
add_action(
	'before_woocommerce_init',
	function () {
		$features_util = '\Automattic\WooCommerce\Utilities\FeaturesUtil';
		if ( class_exists( $features_util ) && method_exists( $features_util, 'declare_compatibility' ) ) {
			$features_util::declare_compatibility( 'custom_order_tables', EPIC_NEWS_PRODUCT_LINK_FILE, true );
		}
	}
);

function epic_news_product_link_woocommerce_missing_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			esc_html_e(
				'EPIC News ↔ Product Link requires WooCommerce to be installed and active — the "Linked Coffee" meta box reads WooCommerce\'s own product, category, and tag data.',
				'epic-news-product-link'
			);
			?>
		</p>
	</div>
	<?php
}

require_once EPIC_NEWS_PRODUCT_LINK_DIR . 'includes/class-meta-box.php';

add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain( 'epic-news-product-link', false, dirname( plugin_basename( EPIC_NEWS_PRODUCT_LINK_FILE ) ) . '/languages' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			if ( is_admin() ) {
				add_action( 'admin_notices', 'epic_news_product_link_woocommerce_missing_notice' );
			}
			return;
		}

		Epic_News_Product_Link_Meta_Box::init();
	},
	5
);
