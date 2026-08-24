<?php
/**
 * "GHN Shipment" meta box on the single order edit screen.
 *
 * Registered for both HPOS (Automattic's custom order tables) and the
 * legacy wp_posts-based `shop_order` screen — see add_meta_boxes() below —
 * and reads/writes the order exclusively through WC_Order's own methods so
 * it works unmodified regardless of which storage mode is active.
 *
 * @package Epic_GHN_Shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_GHN_Order_Meta_Box {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
	}

	/**
	 * Determines which admin screen ID to register the meta box on — HPOS's
	 * `woocommerce_page_wc-orders` or the legacy `shop_order` post-type
	 * screen. Every call into WooCommerce's OrderUtil is guarded with
	 * method_exists() first: an earlier build of this plugin called
	 * `OrderUtil::custom_orders_table_usage_enabled()` (missing "_is_" —
	 * the real method is `custom_orders_table_usage_is_enabled()`) and that
	 * one-character-class of typo, called unguarded, fataled this exact
	 * screen for every order in the store. Guarding every call here means a
	 * wrong assumption about WooCommerce's API surface degrades to the safe
	 * 'shop_order' fallback instead of a site-wide crash.
	 */
	private static function order_screen_id() {
		$order_util = '\Automattic\WooCommerce\Utilities\OrderUtil';

		// Preferred: OrderUtil ships a method built for exactly this — return
		// the correct screen ID for orders regardless of HPOS state — so we
		// don't have to duplicate WooCommerce's own HPOS-vs-legacy branching.
		if ( class_exists( $order_util ) && method_exists( $order_util, 'get_order_admin_screen' ) ) {
			return $order_util::get_order_admin_screen();
		}

		// Fallback for older WooCommerce versions without that helper.
		if (
			class_exists( $order_util )
			&& method_exists( $order_util, 'custom_orders_table_usage_is_enabled' )
			&& $order_util::custom_orders_table_usage_is_enabled()
			&& function_exists( 'wc_get_page_screen_id' )
		) {
			return wc_get_page_screen_id( 'shop-order' );
		}

		return 'shop_order';
	}

	public static function add_meta_box() {
		add_meta_box(
			'epic_ghn_shipment',
			__( 'GHN Shipment', 'epic-ghn-shipping' ),
			array( __CLASS__, 'render' ),
			self::order_screen_id(),
			'side',
			'high'
		);
	}

	public static function maybe_enqueue( $hook ) {
		$order_edit_hooks = array( 'post.php', 'post-new.php', 'woocommerce_page_wc-orders' );
		if ( in_array( $hook, $order_edit_hooks, true ) ) {
			Epic_GHN_Assets::enqueue();
		}
	}

	/**
	 * @param WP_Post|WC_Order $post_or_order_object HPOS passes the order object directly; legacy screens pass the WP_Post.
	 */
	public static function render( $post_or_order_object ) {
		$order = ( $post_or_order_object instanceof WP_Post )
			? wc_get_order( $post_or_order_object->ID )
			: $post_or_order_object;

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		wp_nonce_field( 'epic_ghn_order_action', 'epic_ghn_order_nonce' );
		echo '<div class="epic-ghn-metabox" data-order-id="' . esc_attr( $order->get_id() ) . '">';

		/**
		 * Belt-and-braces: this meta box is one small widget on a page that
		 * also has to render the order's line items, notes, and every other
		 * plugin's own boxes. Nothing in here should ever be able to take
		 * the *entire* order screen down — if something unexpected throws
		 * (a malformed GHN response, a WooCommerce API change, anything),
		 * catch it, log it, and show an inline error in this box only.
		 */
		try {
			$tracking_code = $order->get_meta( '_ghn_order_code' );

			if ( $tracking_code ) {
				self::render_booked_state( $order, $tracking_code );
			} else {
				self::render_unbooked_state( $order );
			}
		} catch ( \Throwable $e ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					'GHN Shipment meta box failed to render for order ' . $order->get_id() . ': ' . $e->getMessage(),
					array( 'source' => 'epic-ghn' )
				);
			}
			echo '<div class="notice notice-error inline epic-ghn-inline-notice"><p>' .
				esc_html__( 'This box hit an unexpected error and couldn\'t render. Check WooCommerce → Status → Logs (source "epic-ghn") for details.', 'epic-ghn-shipping' ) .
				'</p></div>';
		}

		echo '</div>';
	}

	private static function render_booked_state( WC_Order $order, $tracking_code ) {
		$eta        = $order->get_meta( '_ghn_expected_delivery' );
		$status     = $order->get_meta( '_ghn_shipment_status' );
		$bundle_id  = $order->get_meta( '_ghn_bundle_id' );
		?>
		<p>
			<strong><?php esc_html_e( 'Tracking code', 'epic-ghn-shipping' ); ?>:</strong>
			<code class="epic-ghn-tracking-code"><?php echo esc_html( $tracking_code ); ?></code>
		</p>
		<p class="epic-ghn-status-row">
			<strong><?php esc_html_e( 'Status', 'epic-ghn-shipping' ); ?>:</strong>
			<span class="epic-ghn-status-value"><?php echo esc_html( $status ? $status : __( 'Unknown — try "Refresh status"', 'epic-ghn-shipping' ) ); ?></span>
		</p>
		<?php if ( $eta ) : ?>
			<p><strong><?php esc_html_e( 'Expected delivery', 'epic-ghn-shipping' ); ?>:</strong> <?php echo esc_html( $eta ); ?></p>
		<?php endif; ?>

		<?php if ( $bundle_id ) : ?>
			<div class="notice notice-info inline epic-ghn-inline-notice">
				<p>
					<?php
					// Look up the sibling order numbers for this bundle so staff don't
					// have to go find the bundle record just to see who else is on the
					// same physical parcel — class_exists() guarded the same way every
					// other cross-class dependency in this box is, so a stale/missing
					// bundle record degrades to the generic message instead of a fatal.
					$sibling_links = array();
					if ( class_exists( 'Epic_GHN_Bundle' ) ) {
						$bundle_row = Epic_GHN_Bundle::get( $bundle_id );
						$sibling_ids = ( $bundle_row && ! empty( $bundle_row['order_ids'] ) ) ? json_decode( $bundle_row['order_ids'], true ) : null;
						if ( is_array( $sibling_ids ) ) {
							foreach ( $sibling_ids as $sibling_id ) {
								$sibling_id = (int) $sibling_id;
								if ( $sibling_id === $order->get_id() ) {
									continue;
								}
								$sibling_order = wc_get_order( $sibling_id );
								if ( $sibling_order instanceof WC_Order ) {
									$sibling_links[] = '<a href="' . esc_url( $sibling_order->get_edit_order_url() ) . '">#' . esc_html( $sibling_order->get_order_number() ) . '</a>';
								}
							}
						}
					}

					if ( $sibling_links ) {
						printf(
							wp_kses_post(
								/* translators: 1: bundle ID, 2: comma-separated linked order numbers */
								__( 'This shipment is bundled with %2$s (bundle #%1$s). Cancelling it cancels the whole physical parcel for every order in the bundle.', 'epic-ghn-shipping' )
							),
							esc_html( $bundle_id ),
							implode( ', ', $sibling_links ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each link already escaped above.
						);
					} else {
						printf(
							/* translators: %s: bundle ID */
							esc_html__( 'This shipment is bundled with other orders (bundle #%s). Cancelling it cancels the whole physical parcel for every order in the bundle.', 'epic-ghn-shipping' ),
							esc_html( $bundle_id )
						);
					}
					?>
				</p>
			</div>
		<?php endif; ?>

		<p class="epic-ghn-actions">
			<button type="button" class="button epic-ghn-action" data-action="sync_status">
				<?php esc_html_e( 'Refresh status', 'epic-ghn-shipping' ); ?>
			</button>
			<button type="button" class="button epic-ghn-action" data-action="print_label">
				<?php esc_html_e( 'Print label', 'epic-ghn-shipping' ); ?>
			</button>
		</p>
		<p class="epic-ghn-actions">
			<button type="button" class="button epic-ghn-action epic-ghn-danger" data-action="cancel_shipment">
				<?php esc_html_e( 'Cancel shipment', 'epic-ghn-shipping' ); ?>
			</button>
		</p>
		<div class="epic-ghn-feedback"></div>
		<?php
	}

	private static function render_unbooked_state( WC_Order $order ) {
		if ( ! Epic_GHN_Client::is_configured() ) {
			?>
			<p>
				<?php
				printf(
					/* translators: %s: settings page URL */
					wp_kses_post( __( 'GHN isn\'t configured yet. Add your Token, Shop ID, and pickup address under <a href="%s">WooCommerce → Settings → GHN Shipping</a> first.', 'epic-ghn-shipping' ) ),
					esc_url( admin_url( 'admin.php?page=wc-settings&tab=epic_ghn_shipping' ) )
				);
				?>
			</p>
			<?php
			return;
		}

		$weight_g = self::calculate_order_weight_g( $order );

		?>
		<p>
			<strong><?php esc_html_e( 'Recipient', 'epic-ghn-shipping' ); ?>:</strong>
			<?php echo esc_html( $order->get_formatted_shipping_full_name() ); ?><br />
			<?php echo esc_html( $order->get_shipping_phone() ? $order->get_shipping_phone() : $order->get_billing_phone() ); ?><br />
			<?php echo esc_html( $order->get_shipping_address_1() ); ?>,
			<?php echo esc_html( $order->get_shipping_address_2() ); ?>,
			<?php echo esc_html( $order->get_shipping_city() ); ?>,
			<?php echo esc_html( $order->get_shipping_state() ); ?>
		</p>
		<p><strong><?php esc_html_e( 'Parcel weight', 'epic-ghn-shipping' ); ?>:</strong> <?php echo esc_html( $weight_g ); ?> g</p>

		<?php
		/**
		 * COD vs. prepaid is decided automatically from the order's payment
		 * method when the shipment is booked (see
		 * Epic_GHN_Ajax::book_single_order()) — never a staff judgment call.
		 * This line exists purely so staff see, before clicking "Ship via
		 * GHN", what that automatic decision will be — same check as the
		 * booking code, via the one shared helper (Epic_GHN_Client::is_cod_order()).
		 */
		$is_cod = Epic_GHN_Client::is_cod_order( $order );
		?>
		<p class="epic-ghn-cod-preview">
			<strong><?php esc_html_e( 'Payment', 'epic-ghn-shipping' ); ?>:</strong>
			<?php echo esc_html( $order->get_payment_method_title() ); ?> —
			<?php
			if ( $is_cod ) {
				printf(
					/* translators: %s: formatted order total to collect on delivery */
					esc_html__( 'will book as COD, collecting %s on delivery.', 'epic-ghn-shipping' ),
					wp_kses_post( wp_strip_all_tags( wc_price( $order->get_total() ) ) )
				);
			} else {
				esc_html_e( 'already paid — will book as prepaid, no COD collected.', 'epic-ghn-shipping' );
			}
			?>
		</p>

		<?php
		/**
		 * Address resolution (which calls out to GHN 1-3 times) used to run
		 * synchronously right here, blocking the order screen's own render.
		 * A slow or unreachable GHN response could then exceed the host's
		 * PHP execution time limit and take the whole page down — moved to
		 * an AJAX call (epic_ghn_resolve_address, fired by admin.js once the
		 * page has already loaded) so the worst case is now a stuck spinner
		 * in this one box, not a fatal on the entire order screen.
		 */
		?>
		<div class="epic-ghn-address-resolution" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>">
			<p class="epic-ghn-resolving"><?php esc_html_e( 'Checking address against GHN…', 'epic-ghn-shipping' ); ?></p>
		</div>

		<p class="epic-ghn-actions">
			<button type="button" class="button button-primary epic-ghn-action" data-action="ship_order" disabled="disabled">
				<?php esc_html_e( 'Ship via GHN', 'epic-ghn-shipping' ); ?>
			</button>
		</p>
		<div class="epic-ghn-feedback"></div>
		<?php
	}

	/**
	 * Sums each line item's product weight × quantity, falling back to the
	 * settings screen's configured default for any product with no weight
	 * set — same convention the website's checkout uses (see
	 * website/src/lib/data.ts Bean.weightGrams), so booking a shipment from
	 * wp-admin and from the storefront estimate weight the same way.
	 */
	public static function calculate_order_weight_g( WC_Order $order ) {
		$settings      = Epic_GHN_Client::get_settings();
		$fallback_g    = (int) $settings['default_item_weight_g'];
		$total_weight  = 0;

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			/** @var WC_Order_Item_Product $item */
			$product     = $item->get_product();
			$weight_each = $product && $product->get_weight() ? (float) $product->get_weight() : null;

			// WooCommerce stores weight in the store's configured weight unit
			// (Settings → Products → Measurements); assume grams or kilograms,
			// the two realistic choices for a small VN shop, and normalize to grams.
			if ( null !== $weight_each ) {
				$unit         = get_option( 'woocommerce_weight_unit', 'kg' );
				$weight_each_g = ( 'g' === $unit ) ? $weight_each : $weight_each * 1000;
			} else {
				$weight_each_g = $fallback_g;
			}

			$total_weight += $weight_each_g * $item->get_quantity();
		}

		return max( (int) round( $total_weight ), 1 );
	}
}
