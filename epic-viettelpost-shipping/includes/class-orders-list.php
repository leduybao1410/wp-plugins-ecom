<?php
/**
 * Orders list additions: a "Shipment" status column, a "COD" chip, an
 * "Action" column (one-click Create Shipment / Print label), and a
 * "Create ViettelPost shipment(s)" bulk action that books each selected order
 * as its own separate parcel.
 *
 * Registered for both the legacy shop_order list table and the HPOS orders
 * list, which use different WP_List_Table screen IDs — see list_screen_id().
 *
 * @package Epic_ViettelPost_Shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_VTP_Orders_List {

	const BULK_SHIP_ACTION = 'epic_vtp_bulk_ship';

	/**
	 * handle_ship_bulk_action() runs synchronously inside one request, so a
	 * big selection means many sequential API round-trips (up to 3 per order:
	 * address resolve, fee, create). This caps a single run well short of
	 * typical host PHP execution-time limits.
	 */
	const BULK_SHIP_MAX_ORDERS = 25;

	const SHIPMENT_COLUMN = 'epic_vtp_shipment';
	const COD_COLUMN      = 'epic_vtp_cod';
	const ACTION_COLUMN   = 'epic_vtp_action';

	public static function init() {
		add_action( 'current_screen', array( __CLASS__, 'register_bulk_action_hooks' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notices' ) );

		// Legacy (wp_posts-based) shop_order screen.
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_columns' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_column_legacy' ), 10, 2 );

		// HPOS orders screen.
		add_filter( 'woocommerce_shop_order_list_table_columns', array( __CLASS__, 'add_columns' ) );
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( __CLASS__, 'render_column_hpos' ), 10, 2 );
	}

	public static function is_hpos() {
		$order_util = '\Automattic\WooCommerce\Utilities\OrderUtil';
		return class_exists( $order_util )
			&& method_exists( $order_util, 'custom_orders_table_usage_is_enabled' )
			&& $order_util::custom_orders_table_usage_is_enabled();
	}

	private static function list_screen_id() {
		return self::is_hpos() ? 'woocommerce_page_wc-orders' : 'edit-shop_order';
	}

	public static function register_bulk_action_hooks( $screen ) {
		if ( ! $screen || self::list_screen_id() !== $screen->id ) {
			return;
		}

		add_filter( 'bulk_actions-' . $screen->id, array( __CLASS__, 'add_bulk_action' ) );
		add_filter( 'handle_bulk_actions-' . $screen->id, array( __CLASS__, 'handle_bulk_action' ), 10, 3 );
	}

	public static function maybe_enqueue() {
		$screen = get_current_screen();
		if ( $screen && self::list_screen_id() === $screen->id ) {
			Epic_VTP_Assets::enqueue();
		}
	}

	public static function add_bulk_action( $actions ) {
		$actions[ self::BULK_SHIP_ACTION ] = __( 'Create ViettelPost shipment(s)', 'epic-viettelpost-shipping' );
		return $actions;
	}

	public static function handle_bulk_action( $redirect_to, $action, $order_ids ) {
		if ( self::BULK_SHIP_ACTION !== $action ) {
			return $redirect_to;
		}
		return self::handle_ship_bulk_action( $redirect_to, $order_ids );
	}

	/**
	 * Books an individual ViettelPost shipment for every selected order — each
	 * order gets its own parcel and tracking code; nothing is combined. Reuses
	 * the same per-order booking logic as the single-order button via
	 * Epic_VTP_Ajax::book_single_order(), so behaviour is identical whether
	 * staff ship one order at a time or select a page of them here.
	 *
	 * handle_bulk_actions-{screen} must return a redirect URL, not stream
	 * progress, so results are stashed in a short-lived per-user transient and
	 * rendered by render_notices() after the redirect.
	 */
	private static function handle_ship_bulk_action( $redirect_to, $order_ids ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $redirect_to;
		}

		$order_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $order_ids ) ) ) );

		if ( empty( $order_ids ) ) {
			return $redirect_to;
		}

		if ( count( $order_ids ) > self::BULK_SHIP_MAX_ORDERS ) {
			return add_query_arg(
				array(
					'epic_vtp_bulk_ship_error' => 'too_many',
					'epic_vtp_bulk_ship_max'   => self::BULK_SHIP_MAX_ORDERS,
				),
				$redirect_to
			);
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit
		}

		$booked = 0;
		$failed = array();

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				$failed[] = array(
					'order'   => (string) $order_id,
					'message' => __( 'order not found', 'epic-viettelpost-shipping' ),
				);
				continue;
			}

			$result = Epic_VTP_Ajax::book_single_order( $order );

			if ( is_wp_error( $result ) ) {
				$failed[] = array(
					'order'   => $order->get_order_number(),
					'message' => $result->get_error_message(),
				);
				continue;
			}

			++$booked;
		}

		set_transient( self::bulk_ship_transient_key(), array( 'booked' => $booked, 'failed' => $failed ), 5 * MINUTE_IN_SECONDS );

		return add_query_arg( 'epic_vtp_bulk_ship_done', 1, $redirect_to );
	}

	private static function bulk_ship_transient_key() {
		return 'epic_vtp_bulk_ship_' . get_current_user_id();
	}

	public static function render_notices() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flags, no state change.
		if ( ! empty( $_GET['epic_vtp_bulk_ship_error'] ) && 'too_many' === $_GET['epic_vtp_bulk_ship_error'] ) {
			$max = isset( $_GET['epic_vtp_bulk_ship_max'] ) ? absint( $_GET['epic_vtp_bulk_ship_max'] ) : self::BULK_SHIP_MAX_ORDERS; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			?>
			<div class="notice notice-error is-dismissible">
				<p>
				<?php
				printf(
					/* translators: %d: maximum orders per bulk run */
					esc_html__( 'Select %d orders or fewer to create ViettelPost shipments in one go — run the bulk action again for the rest.', 'epic-viettelpost-shipping' ),
					esc_html( $max )
				);
				?>
				</p>
			</div>
			<?php
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flag; the data came from this user's own short-lived transient.
		if ( ! empty( $_GET['epic_vtp_bulk_ship_done'] ) ) {
			$key     = self::bulk_ship_transient_key();
			$summary = get_transient( $key );
			delete_transient( $key );

			if ( is_array( $summary ) ) {
				$booked = isset( $summary['booked'] ) ? (int) $summary['booked'] : 0;
				$failed = isset( $summary['failed'] ) && is_array( $summary['failed'] ) ? $summary['failed'] : array();

				if ( $booked ) {
					?>
					<div class="notice notice-success is-dismissible">
						<p>
						<?php
						printf(
							/* translators: %d: number of orders booked */
							esc_html(
								_n(
									'Booked %d ViettelPost shipment.',
									'Booked %d ViettelPost shipments.',
									$booked,
									'epic-viettelpost-shipping'
								)
							),
							esc_html( $booked )
						);
						?>
						</p>
					</div>
					<?php
				}

				if ( $failed ) {
					?>
					<div class="notice notice-warning is-dismissible">
						<p><strong>
						<?php
						printf(
							/* translators: %d: number of orders that failed to book */
							esc_html(
								_n(
									'%d order could not be shipped via ViettelPost:',
									'%d orders could not be shipped via ViettelPost:',
									count( $failed ),
									'epic-viettelpost-shipping'
								)
							),
							esc_html( count( $failed ) )
						);
						?>
						</strong></p>
						<ul class="epic-vtp-bundle-dropped">
							<?php foreach ( $failed as $failure ) : ?>
								<li>
								<?php
								printf(
									/* translators: 1: order number, 2: failure reason */
									esc_html__( 'Order #%1$s — %2$s', 'epic-viettelpost-shipping' ),
									esc_html( $failure['order'] ),
									esc_html( $failure['message'] )
								);
								?>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
					<?php
				}
			}
		}
	}

	// ------------------------------------------------------------------
	// COD / Shipment / Action columns
	// ------------------------------------------------------------------

	public static function add_columns( $columns ) {
		$new      = array();
		$inserted = false;

		$ours = array(
			self::COD_COLUMN      => __( 'COD', 'epic-viettelpost-shipping' ),
			self::SHIPMENT_COLUMN => __( 'Shipment', 'epic-viettelpost-shipping' ),
			self::ACTION_COLUMN   => __( 'Action', 'epic-viettelpost-shipping' ),
		);

		foreach ( $columns as $key => $label ) {
			if ( ! $inserted && in_array( $key, array( 'wc_actions', 'order_actions' ), true ) ) {
				$new      = array_merge( $new, $ours );
				$inserted = true;
			}
			$new[ $key ] = $label;
		}

		if ( ! $inserted ) {
			$new = array_merge( $new, $ours );
		}

		return $new;
	}

	private static function is_our_column( $column ) {
		return in_array( $column, array( self::COD_COLUMN, self::SHIPMENT_COLUMN, self::ACTION_COLUMN ), true );
	}

	public static function render_column_legacy( $column, $post_id ) {
		if ( ! self::is_our_column( $column ) ) {
			return;
		}
		$order = wc_get_order( $post_id );
		if ( $order instanceof WC_Order ) {
			self::render_column( $column, $order );
		}
	}

	public static function render_column_hpos( $column, $order ) {
		if ( self::is_our_column( $column ) && $order instanceof WC_Order ) {
			self::render_column( $column, $order );
		}
	}

	private static function render_column( $column, WC_Order $order ) {
		switch ( $column ) {
			case self::COD_COLUMN:
				self::render_cod_cell( $order );
				break;
			case self::SHIPMENT_COLUMN:
				self::render_shipment_cell( $order );
				break;
			case self::ACTION_COLUMN:
				self::render_action_cell( $order );
				break;
		}
	}

	private static function render_cod_cell( WC_Order $order ) {
		$is_cod = Epic_VTP_Client::is_cod_order( $order );

		printf(
			'<span class="epic-vtp-cod-chip %1$s" title="%2$s">%3$s</span>',
			esc_attr( $is_cod ? 'epic-vtp-cod-yes' : 'epic-vtp-cod-no' ),
			esc_attr( $order->get_payment_method_title() ),
			esc_html( $is_cod ? __( 'Yes', 'epic-viettelpost-shipping' ) : __( 'No', 'epic-viettelpost-shipping' ) )
		);
	}

	private static function render_shipment_cell( WC_Order $order ) {
		$tracking_code = $order->get_meta( Epic_VTP_Order_Meta_Box::META_ORDER_NUMBER );

		if ( ! $tracking_code ) {
			echo '<span class="epic-vtp-shipment-cell epic-vtp-status-none" title="' . esc_attr__( 'No ViettelPost shipment booked yet.', 'epic-viettelpost-shipping' ) . '">&#8212;</span>';
			return;
		}

		$status = Epic_VTP_Client::bucket_status( $order->get_meta( Epic_VTP_Order_Meta_Box::META_STATUS ) );

		printf(
			'<span class="epic-vtp-shipment-cell %1$s" title="%2$s">%3$s</span>',
			esc_attr( $status['css_class'] ),
			esc_attr(
				sprintf(
					/* translators: 1: tracking code, 2: raw status code */
					__( 'Tracking %1$s — ViettelPost status: %2$s', 'epic-viettelpost-shipping' ),
					$tracking_code,
					$status['raw'] ? $status['raw'] : __( 'not yet synced', 'epic-viettelpost-shipping' )
				)
			),
			esc_html( $status['label'] )
		);
	}

	/**
	 * For an unshipped order: a one-click "Create Shipment" button wired up in
	 * admin.js to the same epic_vtp_ship_order AJAX action as the order meta
	 * box's own button. No per-row address-override picker here (no room in a
	 * list-table cell), so this always auto-resolves the address.
	 *
	 * For an already-shipped order: a "Print label" button.
	 */
	private static function render_action_cell( WC_Order $order ) {
		if ( $order->get_meta( Epic_VTP_Order_Meta_Box::META_ORDER_NUMBER ) ) {
			printf(
				'<button type="button" class="button button-small epic-vtp-list-print" data-order-id="%1$d">%2$s</button><br /><span class="epic-vtp-list-print-feedback epic-vtp-feedback"></span>',
				esc_attr( $order->get_id() ),
				esc_html__( 'Print label', 'epic-viettelpost-shipping' )
			);
			return;
		}

		if ( ! Epic_VTP_Client::is_configured() ) {
			printf(
				'<a href="%1$s">%2$s</a>',
				esc_url( admin_url( 'admin.php?page=wc-settings&tab=epic_vtp_shipping' ) ),
				esc_html__( 'Configure ViettelPost', 'epic-viettelpost-shipping' )
			);
			return;
		}

		printf(
			'<button type="button" class="button button-small epic-vtp-list-ship" data-order-id="%1$d">%2$s</button><br /><span class="epic-vtp-list-ship-feedback epic-vtp-feedback"></span>',
			esc_attr( $order->get_id() ),
			esc_html__( 'Create Shipment', 'epic-viettelpost-shipping' )
		);
	}
}
