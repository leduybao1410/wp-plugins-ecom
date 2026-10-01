<?php
/**
 * "ViettelPost Shipment" meta box on the single order edit screen.
 *
 * Registered for both HPOS (custom order tables) and the legacy
 * wp_posts-based `shop_order` screen, and reads/writes the order exclusively
 * through WC_Order's own methods so it works unmodified regardless of which
 * storage mode is active.
 *
 * @package Epic_ViettelPost_Shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_VTP_Order_Meta_Box {

	const META_ORDER_NUMBER   = '_vtp_order_number';
	const META_EXPECTED       = '_vtp_expected_delivery';
	const META_STATUS         = '_vtp_shipment_status';
	const META_STATUS_DATE    = '_vtp_status_date';
	const META_LAST_SYNCED    = '_vtp_last_synced_at';
	const META_COD_AMOUNT     = '_vtp_cod_amount';
	const META_FEE            = '_vtp_fee';
	const META_SERVICE        = '_vtp_service';
	const META_PROVINCE_ID    = '_vtp_province_id';
	const META_PROVINCE_NAME  = '_vtp_province_name';
	const META_NEEDS_ACTION   = '_vtp_needs_action';

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
	}

	/**
	 * Which admin screen ID to register the meta box on — HPOS's
	 * `woocommerce_page_wc-orders` or the legacy `shop_order` post-type
	 * screen. Every call into WooCommerce's OrderUtil is guarded with
	 * method_exists() first so a wrong assumption about WooCommerce's API
	 * surface degrades to the safe 'shop_order' fallback instead of a
	 * site-wide fatal.
	 */
	private static function order_screen_id() {
		$order_util = '\Automattic\WooCommerce\Utilities\OrderUtil';

		if ( class_exists( $order_util ) && method_exists( $order_util, 'get_order_admin_screen' ) ) {
			return $order_util::get_order_admin_screen();
		}

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
			'epic_vtp_shipment',
			__( 'ViettelPost Shipment', 'epic-viettelpost-shipping' ),
			array( __CLASS__, 'render' ),
			self::order_screen_id(),
			'side',
			'high'
		);
	}

	public static function maybe_enqueue( $hook ) {
		$order_edit_hooks = array( 'post.php', 'post-new.php', 'woocommerce_page_wc-orders' );
		if ( in_array( $hook, $order_edit_hooks, true ) ) {
			Epic_VTP_Assets::enqueue();
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

		// No inline nonce: every action is sent over AJAX with the
		// `epic_vtp_admin` nonce issued via wp_localize_script (class-assets.php)
		// and verified by Epic_VTP_Ajax::verify_request().
		echo '<div class="epic-vtp-metabox" data-order-id="' . esc_attr( $order->get_id() ) . '">';

		// Nothing in here should ever be able to take the entire order screen
		// down — catch, log, and show an inline error in this box only.
		try {
			$tracking_code = $order->get_meta( self::META_ORDER_NUMBER );

			if ( $tracking_code ) {
				self::render_booked_state( $order, $tracking_code );
			} else {
				self::render_unbooked_state( $order );
			}
		} catch ( \Throwable $e ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					'ViettelPost Shipment meta box failed to render for order ' . $order->get_id() . ': ' . $e->getMessage(),
					array( 'source' => 'epic-vtp' )
				);
			}
			echo '<div class="notice notice-error inline epic-vtp-inline-notice"><p>' .
				esc_html__( 'This box hit an unexpected error and couldn\'t render. Check WooCommerce → Status → Logs (source "epic-vtp") for details.', 'epic-viettelpost-shipping' ) .
				'</p></div>';
		}

		echo '</div>';
	}

	private static function render_booked_state( WC_Order $order, $tracking_code ) {
		$eta      = $order->get_meta( self::META_EXPECTED );
		$status   = (string) $order->get_meta( self::META_STATUS );
		$bucket   = Epic_VTP_Client::bucket_status( $status );
		$synced   = $order->get_meta( self::META_LAST_SYNCED );
		$fee      = (string) $order->get_meta( self::META_FEE );
		$needs    = (string) $order->get_meta( self::META_NEEDS_ACTION );
		$quoted   = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
		?>
		<?php if ( '' !== $needs ) : ?>
			<div class="notice notice-warning inline epic-vtp-inline-notice"><p>
				<?php
				printf(
					/* translators: %s: action keyword (return/issue) */
					esc_html__( 'Action needed: this shipment hit a %s — check restock/refund and resolve before closing.', 'epic-viettelpost-shipping' ),
					esc_html( $needs )
				);
				?>
			</p></div>
		<?php endif; ?>

		<p>
			<strong><?php esc_html_e( 'Tracking code', 'epic-viettelpost-shipping' ); ?>:</strong>
			<code class="epic-vtp-tracking-code"><?php echo esc_html( $tracking_code ); ?></code>
		</p>
		<p class="epic-vtp-status-row">
			<strong><?php esc_html_e( 'Status', 'epic-viettelpost-shipping' ); ?>:</strong>
			<span class="epic-vtp-status-value <?php echo esc_attr( $bucket['css_class'] ); ?>"><?php echo esc_html( $bucket['label'] ); ?></span>
		</p>
		<?php if ( $eta ) : ?>
			<p><strong><?php esc_html_e( 'Expected delivery', 'epic-viettelpost-shipping' ); ?>:</strong> <?php echo esc_html( $eta ); ?></p>
		<?php endif; ?>
		<?php if ( '' !== $fee ) : ?>
			<p><strong><?php esc_html_e( 'Courier fee', 'epic-viettelpost-shipping' ); ?>:</strong> <?php echo esc_html( wp_strip_all_tags( wc_price( (float) $fee ) ) ); ?>
				<?php
				// Fee reconciliation: under the sender-pays model the store bills
				// the customer the order's shipping line and settles ViettelPost's
				// actual fee, so a delta here is real margin (or loss).
				$delta = (float) $fee - $quoted;
				if ( abs( $delta ) >= 1 ) {
					printf(
						' <em class="%1$s">(%2$s %3$s)</em>',
						esc_attr( $delta > 0 ? 'epic-vtp-delta-loss' : 'epic-vtp-delta-gain' ),
						esc_html( $delta > 0 ? __( 'over the shipping charged:', 'epic-viettelpost-shipping' ) : __( 'under the shipping charged:', 'epic-viettelpost-shipping' ) ),
						esc_html( wp_strip_all_tags( wc_price( abs( $delta ) ) ) )
					);
				}
				?>
			</p>
		<?php endif; ?>
		<?php if ( $synced ) : ?>
			<p class="epic-vtp-last-synced"><em>
				<?php
				printf(
					/* translators: %s: datetime */
					esc_html__( 'Last status update: %s', 'epic-viettelpost-shipping' ),
					esc_html( $synced )
				);
				?>
			</em></p>
		<?php endif; ?>

		<p class="description">
			<?php esc_html_e( 'Status is kept current by the ViettelPost webhook. If a call was missed, set the last-known status below.', 'epic-viettelpost-shipping' ); ?>
		</p>

		<p class="epic-vtp-manual-status">
			<label for="epic-vtp-set-status-<?php echo esc_attr( $order->get_id() ); ?>"><?php esc_html_e( 'Set status', 'epic-viettelpost-shipping' ); ?></label>
			<select id="epic-vtp-set-status-<?php echo esc_attr( $order->get_id() ); ?>" class="epic-vtp-status-select">
				<?php foreach ( Epic_VTP_Client::status_map() as $code => $label ) : ?>
					<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $code, $status ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="button" class="button epic-vtp-action" data-action="set_status"><?php esc_html_e( 'Update', 'epic-viettelpost-shipping' ); ?></button>
		</p>

		<p class="epic-vtp-actions">
			<button type="button" class="button epic-vtp-action" data-action="print_label">
				<?php esc_html_e( 'Print label', 'epic-viettelpost-shipping' ); ?>
			</button>
			<button type="button" class="button epic-vtp-action epic-vtp-danger" data-action="cancel_shipment">
				<?php esc_html_e( 'Cancel shipment', 'epic-viettelpost-shipping' ); ?>
			</button>
		</p>
		<div class="epic-vtp-feedback"></div>
		<?php
	}

	private static function render_unbooked_state( WC_Order $order ) {
		if ( ! Epic_VTP_Client::is_configured() ) {
			?>
			<p>
				<?php
				printf(
					/* translators: %s: settings page URL */
					wp_kses_post( __( 'ViettelPost isn\'t configured yet. Add your Token and pickup address under <a href="%s">WooCommerce → Settings → ViettelPost Shipping</a> first.', 'epic-viettelpost-shipping' ) ),
					esc_url( admin_url( 'admin.php?page=wc-settings&tab=epic_vtp_shipping' ) )
				);
				?>
			</p>
			<?php
			return;
		}

		$weight_g = self::calculate_order_weight_g( $order );
		?>
		<p>
			<strong><?php esc_html_e( 'Recipient', 'epic-viettelpost-shipping' ); ?>:</strong>
			<?php echo esc_html( $order->get_formatted_shipping_full_name() ); ?><br />
			<?php echo esc_html( $order->get_shipping_phone() ? $order->get_shipping_phone() : $order->get_billing_phone() ); ?><br />
			<?php echo esc_html( $order->get_shipping_address_1() ); ?>,
			<?php echo esc_html( $order->get_shipping_address_2() ); ?>,
			<?php echo esc_html( $order->get_shipping_city() ); ?>,
			<?php echo esc_html( $order->get_shipping_state() ); ?>
		</p>
		<p><strong><?php esc_html_e( 'Parcel weight', 'epic-viettelpost-shipping' ); ?>:</strong> <?php echo esc_html( $weight_g ); ?> g</p>

		<?php
		$is_cod = Epic_VTP_Client::is_cod_order( $order );
		?>
		<p class="epic-vtp-cod-preview">
			<strong><?php esc_html_e( 'Payment', 'epic-viettelpost-shipping' ); ?>:</strong>
			<?php echo esc_html( $order->get_payment_method_title() ); ?> —
			<?php
			if ( $is_cod ) {
				// Mirrors Epic_VTP_Ajax::book_single_order()'s $cod_amount: the
				// full order total (goods + the shipping fee already inside the
				// order price), collected once. ViettelPost's own fee is billed
				// to the sender, so nothing is added at the door.
				printf(
					/* translators: %s: formatted order total to collect on delivery */
					esc_html__( 'will book as COD, collecting %s on delivery — the full order total (shipping included), matching what the customer was shown at checkout. ViettelPost\'s own shipping fee is billed to us, so nothing is added at the door.', 'epic-viettelpost-shipping' ),
					wp_kses_post( wp_strip_all_tags( wc_price( $order->get_total() ) ) )
				);
			} else {
				esc_html_e( 'already paid — will book as prepaid, no COD collected.', 'epic-viettelpost-shipping' );
			}
			?>
		</p>

		<?php
		// Address resolution (a call out to ViettelPost) runs over AJAX once the
		// page has loaded, so a slow/unreachable API can't block the order
		// screen's own render.
		?>
		<div class="epic-vtp-address-resolution" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>">
			<p class="epic-vtp-resolving"><?php esc_html_e( 'Checking address against ViettelPost…', 'epic-viettelpost-shipping' ); ?></p>
		</div>

		<p class="epic-vtp-actions">
			<button type="button" class="button button-primary epic-vtp-action" data-action="ship_order" disabled="disabled">
				<?php esc_html_e( 'Ship via ViettelPost', 'epic-viettelpost-shipping' ); ?>
			</button>
		</p>
		<div class="epic-vtp-feedback"></div>
		<?php
	}

	/**
	 * Sums each line item's product weight × quantity, falling back to the
	 * settings screen's configured default for any product with no weight
	 * set, so booking from wp-admin and from the storefront estimate weight
	 * the same way.
	 */
	public static function calculate_order_weight_g( WC_Order $order ) {
		$settings     = Epic_VTP_Client::get_settings();
		$fallback_g   = (int) $settings['default_item_weight_g'];
		$total_weight = 0;

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			/** @var WC_Order_Item_Product $item */
			$product     = $item->get_product();
			$weight_each = $product && $product->get_weight() ? (float) $product->get_weight() : null;

			if ( null !== $weight_each ) {
				$unit          = get_option( 'woocommerce_weight_unit', 'kg' );
				$weight_each_g = ( 'g' === $unit ) ? $weight_each : $weight_each * 1000;
			} else {
				$weight_each_g = $fallback_g;
			}

			$total_weight += $weight_each_g * $item->get_quantity();
		}

		return max( (int) round( $total_weight ), 1 );
	}
}
