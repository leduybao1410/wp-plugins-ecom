<?php
/**
 * Orders list additions: a "Shipment" status column, plus two bulk actions —
 * "Bundle & ship via GHN" (redirects to the review/confirm screen,
 * class-bundle-admin-page.php, since bundling has real bookkeeping
 * consequences — PLAN.md §6 — that need a confirm step first) and
 * "Create GHN shipment(s)" (books each selected order as its own separate
 * GHN parcel immediately, right from this list — no combining, no review
 * screen, since each order's own booking logic already fully automatic —
 * see Epic_GHN_Ajax::book_single_order()).
 *
 * Registered for both the legacy shop_order list table and the HPOS orders
 * list, which use different WP_List_Table screen IDs — see
 * list_screen_id() below. Same defensive method_exists() guarding around
 * OrderUtil as Epic_GHN_Order_Meta_Box::order_screen_id() — see the long
 * comment there for why an unguarded call once fataled every order screen
 * in this plugin.
 *
 * @package Epic_GHN_Shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_GHN_Orders_List {

	const BULK_ACTION      = 'epic_ghn_bundle';
	const BULK_SHIP_ACTION = 'epic_ghn_bulk_ship';

	/**
	 * handle_ship_bulk_action() runs entirely synchronously inside this one
	 * request (same as the single-order "Ship via GHN" button and the
	 * bundle confirm screen — this plugin has no background-job queue), so
	 * a big selection means a lot of sequential GHN API round-trips (up to
	 * 3 per order: address resolve, fee, create). This caps a single run
	 * well short of typical host PHP execution-time limits; a bigger batch
	 * just means running the action again on the next page of orders.
	 */
	const BULK_SHIP_MAX_ORDERS = 25;

	const SHIPMENT_COLUMN = 'epic_ghn_shipment';
	const COD_COLUMN      = 'epic_ghn_cod';
	const ACTION_COLUMN   = 'epic_ghn_action';

	/**
	 * Column key of WooCommerce's own built-in "Origin" column (its Order
	 * Attribution feature, WC 8.5+ — shows where each order's traffic came
	 * from: "Organic", "Direct", "Referral", a UTM campaign, etc.). Not one
	 * of ours, but staff asked for it off the Orders list, so this plugin
	 * strips it — see remove_origin_column() below.
	 *
	 * NOT verified against a live WooCommerce admin screen at write time,
	 * same caveat as the HPOS column hooks below: 'origin' is the column key
	 * WooCommerce's Order Attribution controller is documented to register.
	 * If the "Origin" column is still visible after activating this version,
	 * re-check the exact key for your installed WooCommerce version (open
	 * the browser's element inspector on that column's <th>; its `class`
	 * attribute is `column-{key}`).
	 */
	const ORIGIN_COLUMN = 'origin';

	public static function init() {
		add_action( 'current_screen', array( __CLASS__, 'register_bulk_action_hooks' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notices' ) );

		// COD / Shipment / Action columns — legacy (wp_posts-based) shop_order screen.
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_columns' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_column_legacy' ), 10, 2 );

		/**
		 * Same columns, HPOS orders screen. WooCommerce added these
		 * generic list-table hooks specifically so extensions can support
		 * both storage engines from one code path (see WooCommerce's HPOS
		 * extension developer guide) — $order arrives as a WC_Order object
		 * already, unlike the legacy action's raw post ID. Registering
		 * both sets of hooks unconditionally (rather than branching on
		 * is_hpos()) is deliberate and harmless: only the pair matching
		 * the screen actually rendering ever fires, same reasoning as the
		 * defensive method_exists() guarding elsewhere in this plugin.
		 *
		 * NOT verified against a live WooCommerce admin screen at write
		 * time (unlike the GHN endpoint paths in class-ghn-client.php,
		 * which were checked against api.ghn.vn's docs directly) — these
		 * hook names/signatures are from WooCommerce's own HPOS extension
		 * migration guide, not a live test. If these columns show up empty
		 * on the HPOS orders screen specifically (legacy screen unaffected
		 * either way), re-check the exact filter/action names and the
		 * $order vs. $post_id argument shape for your installed
		 * WooCommerce version before assuming the column logic itself is
		 * wrong.
		 */
		add_filter( 'woocommerce_shop_order_list_table_columns', array( __CLASS__, 'add_columns' ) );
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( __CLASS__, 'render_column_hpos' ), 10, 2 );

		/**
		 * Strips WooCommerce's own "Origin" column (see ORIGIN_COLUMN above)
		 * from both screens. Hooked at a very late priority deliberately:
		 * WooCommerce's Order Attribution controller adds this column via
		 * the same two filters this plugin uses for its own columns, and
		 * this plugin doesn't control — and shouldn't have to guess —
		 * whether that runs before or after add_columns() above. Running
		 * last means it always wins regardless of registration order,
		 * rather than only removing the column on some page loads.
		 */
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'remove_origin_column' ), 999 );
		add_filter( 'woocommerce_shop_order_list_table_columns', array( __CLASS__, 'remove_origin_column' ), 999 );
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

	/** URL of the orders list itself — used for the bundle review screen's "Back to Orders" link and both bulk actions' post-run redirect. */
	public static function orders_list_url() {
		return self::is_hpos() ? admin_url( 'admin.php?page=wc-orders' ) : admin_url( 'edit.php?post_type=shop_order' );
	}

	/**
	 * bulk_actions-{screen}/handle_bulk_actions-{screen} are only meaningful
	 * on the actual orders list screen, so this hooks itself in on
	 * current_screen rather than registering unconditionally on every admin
	 * request.
	 */
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
			Epic_GHN_Assets::enqueue();
		}
	}

	public static function add_bulk_action( $actions ) {
		$actions[ self::BULK_ACTION ]      = __( 'Bundle & ship via GHN', 'epic-ghn-shipping' );
		$actions[ self::BULK_SHIP_ACTION ] = __( 'Create GHN shipment(s)', 'epic-ghn-shipping' );
		return $actions;
	}

	public static function handle_bulk_action( $redirect_to, $action, $order_ids ) {
		if ( self::BULK_ACTION === $action ) {
			return self::handle_bundle_bulk_action( $redirect_to, $order_ids );
		}
		if ( self::BULK_SHIP_ACTION === $action ) {
			return self::handle_ship_bulk_action( $redirect_to, $order_ids );
		}
		return $redirect_to;
	}

	/**
	 * Redirects to the review screen with the selected order IDs — never
	 * books anything here. The <2-orders guard is enforced server-side even
	 * though admin.js also disables the dropdown option client-side (see
	 * initOrdersListBulkAction() in assets/admin.js) once 2+ rows are
	 * checked, since a <select> bulk action can still be submitted without
	 * JS running at all.
	 */
	private static function handle_bundle_bulk_action( $redirect_to, $order_ids ) {
		$order_ids = array_values( array_filter( array_map( 'absint', (array) $order_ids ) ) );

		if ( count( $order_ids ) < 2 ) {
			return add_query_arg( 'epic_ghn_bundle_error', 'need_two', $redirect_to );
		}

		return admin_url( 'admin.php?page=epic-ghn-bundle&orders=' . implode( ',', $order_ids ) );
	}

	/**
	 * Books an individual GHN shipment for every selected order — unlike
	 * "Bundle & ship via GHN", each order gets its own separate parcel and
	 * tracking code; nothing is combined, so (unlike bundling) there's
	 * nothing that needs a review/confirm screen first. Runs synchronously
	 * (see BULK_SHIP_MAX_ORDERS docblock for why that's capped) and reuses
	 * exactly the same per-order booking logic as the single-order "Ship
	 * via GHN" button via Epic_GHN_Ajax::book_single_order(), so COD vs.
	 * prepaid, weight, and address resolution all behave identically
	 * whether staff ship one order at a time or select a page of them here.
	 *
	 * WordPress's handle_bulk_actions-{screen} filter must return a
	 * redirect URL, not stream progress — so results (booked count + any
	 * per-order failures) are stashed in a short-lived per-user transient
	 * and rendered by render_notices() after the redirect, the same
	 * pattern WooCommerce's own bulk actions (e.g. bulk order emails) use.
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
					'epic_ghn_bulk_ship_error' => 'too_many',
					'epic_ghn_bulk_ship_max'   => self::BULK_SHIP_MAX_ORDERS,
				),
				$redirect_to
			);
		}

		// Best-effort only — many hosts disable set_time_limit() entirely
		// (it's a no-op, not a fatal, when disabled), and a batch of GHN
		// round-trips must not be allowed to fatal the request either way.
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
					'message' => __( 'order not found', 'epic-ghn-shipping' ),
				);
				continue;
			}

			$result = Epic_GHN_Ajax::book_single_order( $order );

			if ( is_wp_error( $result ) ) {
				$failed[] = array(
					'order'   => $order->get_order_number(),
					'message' => $result->get_error_message(),
				);
				continue;
			}

			++$booked;
		}

		// 5 minutes is plenty for "finished processing -> redirected ->
		// rendered the notice on the very next page load"; short-lived on
		// purpose so a stale summary can't resurface on some unrelated
		// later page view if the transient somehow outlives its redirect.
		set_transient( self::bulk_ship_transient_key(), array( 'booked' => $booked, 'failed' => $failed ), 5 * MINUTE_IN_SECONDS );

		return add_query_arg( 'epic_ghn_bulk_ship_done', 1, $redirect_to );
	}

	private static function bulk_ship_transient_key() {
		return 'epic_ghn_bulk_ship_' . get_current_user_id();
	}

	public static function render_notices() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flags, no state change.
		if ( ! empty( $_GET['epic_ghn_bundle_error'] ) && 'need_two' === $_GET['epic_ghn_bundle_error'] ) {
			?>
			<div class="notice notice-error is-dismissible">
				<p><?php esc_html_e( 'Select at least 2 orders to bundle & ship via GHN.', 'epic-ghn-shipping' ); ?></p>
			</div>
			<?php
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flags, no state change.
		if ( ! empty( $_GET['epic_ghn_bundle_success'] ) ) {
			$bundle_id = isset( $_GET['epic_ghn_bundle_id'] ) ? absint( $_GET['epic_ghn_bundle_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$tracking  = isset( $_GET['epic_ghn_bundle_tracking'] ) ? sanitize_text_field( wp_unslash( $_GET['epic_ghn_bundle_tracking'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			?>
			<div class="notice notice-success is-dismissible">
				<p>
				<?php
				printf(
					/* translators: 1: bundle ID, 2: GHN tracking code */
					esc_html__( 'Bundle #%1$d booked with GHN — tracking code %2$s.', 'epic-ghn-shipping' ),
					esc_html( $bundle_id ),
					esc_html( $tracking )
				);
				?>
				</p>
			</div>
			<?php
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flags, no state change.
		if ( ! empty( $_GET['epic_ghn_bulk_ship_error'] ) && 'too_many' === $_GET['epic_ghn_bulk_ship_error'] ) {
			$max = isset( $_GET['epic_ghn_bulk_ship_max'] ) ? absint( $_GET['epic_ghn_bulk_ship_max'] ) : self::BULK_SHIP_MAX_ORDERS; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			?>
			<div class="notice notice-error is-dismissible">
				<p>
				<?php
				printf(
					/* translators: %d: maximum orders per bulk run */
					esc_html__( 'Select %d orders or fewer to create GHN shipments in one go — run the bulk action again for the rest.', 'epic-ghn-shipping' ),
					esc_html( $max )
				);
				?>
				</p>
			</div>
			<?php
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flag; the actual data it reveals came from this same user's own short-lived transient, not from the query string.
		if ( ! empty( $_GET['epic_ghn_bulk_ship_done'] ) ) {
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
									'Booked %d GHN shipment.',
									'Booked %d GHN shipments.',
									$booked,
									'epic-ghn-shipping'
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
									'%d order could not be shipped via GHN:',
									'%d orders could not be shipped via GHN:',
									count( $failed ),
									'epic-ghn-shipping'
								)
							),
							esc_html( count( $failed ) )
						);
						?>
						</strong></p>
						<ul class="epic-ghn-bundle-dropped">
							<?php foreach ( $failed as $failure ) : ?>
								<li>
								<?php
								printf(
									/* translators: 1: order number, 2: failure reason */
									esc_html__( 'Order #%1$s — %2$s', 'epic-ghn-shipping' ),
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

	/**
	 * Inserts all three columns — COD, Shipment, Action, in that order — as
	 * one contiguous block right before the row-actions column (wc_actions
	 * on HPOS, order_actions on legacy) so they don't get shuffled in among
	 * WooCommerce's own columns as those change between versions; falls
	 * back to appending at the end if neither is present (e.g. a heavily
	 * customized list table).
	 */
	public static function add_columns( $columns ) {
		$new      = array();
		$inserted = false;

		$ours = array(
			self::COD_COLUMN      => __( 'COD', 'epic-ghn-shipping' ),
			self::SHIPMENT_COLUMN => __( 'Shipment', 'epic-ghn-shipping' ),
			self::ACTION_COLUMN   => __( 'Action', 'epic-ghn-shipping' ),
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

	/**
	 * See ORIGIN_COLUMN's docblock above for what this column is and why
	 * it's removed. unset() is a no-op if the key isn't present, so this is
	 * harmless on a WooCommerce version that doesn't add it (older than
	 * 8.5, or Order Attribution disabled) — no version check needed.
	 */
	public static function remove_origin_column( $columns ) {
		unset( $columns[ self::ORIGIN_COLUMN ] );
		return $columns;
	}

	private static function is_our_column( $column ) {
		return in_array( $column, array( self::COD_COLUMN, self::SHIPMENT_COLUMN, self::ACTION_COLUMN ), true );
	}

	/**
	 * Legacy shop_order screen — fires once per registered column per row
	 * (i.e. for every WooCommerce/WordPress column too, not just ours), with
	 * the raw post ID rather than a loaded order. Bailing on is_our_column()
	 * before calling wc_get_order() matters here: skipping it would mean
	 * loading the order 3 extra times per row (once per irrelevant column
	 * this callback still gets invoked for) for nothing.
	 */
	public static function render_column_legacy( $column, $post_id ) {
		if ( ! self::is_our_column( $column ) ) {
			return;
		}
		$order = wc_get_order( $post_id );
		if ( $order instanceof WC_Order ) {
			self::render_column( $column, $order );
		}
	}

	/**
	 * HPOS screen — WooCommerce already passes the loaded WC_Order object,
	 * so there's no extra query to skip here; the is_our_column() check is
	 * just to avoid falling through render_column()'s switch for nothing.
	 */
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

	/**
	 * "Yes" (COD — recipient pays the shipper) or "No" (prepaid — already
	 * paid online) chip, from the exact same strict payment_method check
	 * every booking decision in this plugin uses — see
	 * Epic_GHN_Client::is_cod_order() for why this is never a guess.
	 */
	private static function render_cod_cell( WC_Order $order ) {
		$is_cod = Epic_GHN_Client::is_cod_order( $order );

		printf(
			'<span class="epic-ghn-cod-chip %1$s" title="%2$s">%3$s</span>',
			esc_attr( $is_cod ? 'epic-ghn-cod-yes' : 'epic-ghn-cod-no' ),
			esc_attr( $order->get_payment_method_title() ),
			esc_html( $is_cod ? __( 'Yes', 'epic-ghn-shipping' ) : __( 'No', 'epic-ghn-shipping' ) )
		);
	}

	private static function render_shipment_cell( WC_Order $order ) {
		$tracking_code = $order->get_meta( '_ghn_order_code' );

		if ( ! $tracking_code ) {
			echo '<span class="epic-ghn-shipment-cell epic-ghn-status-none" title="' . esc_attr__( 'No GHN shipment booked yet.', 'epic-ghn-shipping' ) . '">&#8212;</span>';
			return;
		}

		$bundle_id = $order->get_meta( '_ghn_bundle_id' );
		$status    = Epic_GHN_Client::bucket_status( $order->get_meta( '_ghn_shipment_status' ) );

		printf(
			'<span class="epic-ghn-shipment-cell %1$s" title="%2$s">%3$s</span>',
			esc_attr( $status['css_class'] ),
			esc_attr(
				sprintf(
					/* translators: 1: GHN tracking code, 2: raw GHN status code */
					__( 'Tracking %1$s — GHN status: %2$s', 'epic-ghn-shipping' ),
					$tracking_code,
					$status['raw'] ? $status['raw'] : __( 'not yet synced', 'epic-ghn-shipping' )
				)
			),
			esc_html( $status['label'] )
		);

		if ( $bundle_id ) {
			echo ' <span class="epic-ghn-shipment-bundle-badge" title="' . esc_attr__( 'Part of a bundled shipment.', 'epic-ghn-shipping' ) . '">' . esc_html__( 'Bundle', 'epic-ghn-shipping' ) . '</span>';
		}
	}

	/**
	 * For an unshipped order: a one-click "Create Shipment" button — wired
	 * up in admin.js (initOrdersListRowShip()) to the same
	 * epic_ghn_ship_order AJAX action, and so ultimately the same
	 * Epic_GHN_Ajax::book_single_order() logic, as the order meta box's own
	 * "Ship via GHN" button and the "Create GHN shipment(s)" bulk action.
	 * There's no per-row address-override picker here (no room for one in
	 * a list-table cell), so — like the bulk action — this always
	 * auto-resolves the address; an order whose address can't be
	 * auto-matched still needs its own order screen, where the manual
	 * district/ward picker lives.
	 *
	 * For an already-shipped order: a "Print label" button, moved here from
	 * (well, added alongside — it's still there too) the order meta box, so
	 * staff can print a label without leaving the list. Same
	 * epic_ghn_print_label AJAX action and Epic_GHN_Client::gen_print_token()
	 * / print_url() as the meta box's own "Print label" button — wired up in
	 * admin.js (initOrdersListRowPrint()), which opens the returned URL in a
	 * new tab rather than reloading the page, since printing doesn't change
	 * any order state the other columns need to reflect.
	 */
	private static function render_action_cell( WC_Order $order ) {
		if ( $order->get_meta( '_ghn_order_code' ) ) {
			printf(
				'<button type="button" class="button button-small epic-ghn-list-print" data-order-id="%1$d">%2$s</button><br /><span class="epic-ghn-list-print-feedback epic-ghn-feedback"></span>',
				esc_attr( $order->get_id() ),
				esc_html__( 'Print label', 'epic-ghn-shipping' )
			);
			return;
		}

		if ( ! Epic_GHN_Client::is_configured() ) {
			printf(
				'<a href="%1$s">%2$s</a>',
				esc_url( admin_url( 'admin.php?page=wc-settings&tab=epic_ghn_shipping' ) ),
				esc_html__( 'Configure GHN', 'epic-ghn-shipping' )
			);
			return;
		}

		printf(
			'<button type="button" class="button button-small epic-ghn-list-ship" data-order-id="%1$d">%2$s</button><br /><span class="epic-ghn-list-ship-feedback epic-ghn-feedback"></span>',
			esc_attr( $order->get_id() ),
			esc_html__( 'Create Shipment', 'epic-ghn-shipping' )
		);
	}
}
