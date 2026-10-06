<?php
/**
 * AJAX endpoints backing the admin UI: the province/ward cascading pickers
 * (settings screen + order meta box) and the order meta box's ship/cancel/
 * print actions.
 *
 * Every handler is capability + nonce gated. Failures are logged via
 * WC_Logger (source "epic-vtp") so a store owner can diagnose a failed
 * booking under WooCommerce → Status → Logs without needing server access.
 *
 * @package Epic_ViettelPost_Shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_VTP_Ajax {

	/**
	 * Cap for the waybill note sent to ViettelPost's ORDER_NOTE. The customer's
	 * delivery note is stored on the WooCommerce order as `customer_note`; it
	 * is combined with the order-number reference and truncated to this many
	 * characters. ViettelPost's published Swagger does not state a limit and
	 * the live docs return 403, so 250 is a deliberately conservative value
	 * (the same cap the storefront applies at checkout).
	 */
	const ORDER_NOTE_MAX_LENGTH = 250;

	public static function init() {
		$actions = array(
			'epic_vtp_get_provinces'       => 'get_provinces',
			'epic_vtp_get_wards'           => 'get_wards',
			'epic_vtp_test_connection'     => 'test_connection',
			'epic_vtp_resolve_address'     => 'resolve_address',
			'epic_vtp_ship_order'          => 'ship_order',
			'epic_vtp_cancel_shipment'     => 'cancel_shipment',
			'epic_vtp_print_label'         => 'print_label',
			'epic_vtp_set_shipment_status' => 'set_shipment_status',
			'epic_vtp_bulk_print'          => 'bulk_print',
		);

		foreach ( $actions as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( __CLASS__, $method ) );
		}
	}

	private static function log( $message, $context = array() ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $message, array_merge( array( 'source' => 'epic-vtp' ), $context ) );
		}
	}

	private static function verify_request() {
		check_ajax_referer( 'epic_vtp_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'epic-viettelpost-shipping' ) ), 403 );
		}
	}

	private static function send_wp_error( WP_Error $error ) {
		self::log( $error->get_error_message() );
		wp_send_json_error( array( 'message' => $error->get_error_message() ) );
	}

	/**
	 * Backs the settings screen's "Test connection" button: resolves a token
	 * (exercising the username/password login flow when configured) and makes
	 * one authenticated master-data call.
	 */
	public static function test_connection() {
		self::verify_request();

		$result = Epic_VTP_Client::test_connection();
		if ( is_wp_error( $result ) ) {
			self::send_wp_error( $result );
		}

		$modes = array(
			'token' => __( 'stored Token', 'epic-viettelpost-shipping' ),
			'login' => __( 'username/password login (Login → ownerconnect)', 'epic-viettelpost-shipping' ),
		);
		$mode_label = isset( $modes[ $result['mode'] ] ) ? $modes[ $result['mode'] ] : $result['mode'];

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: auth mode, 2: number of warehouses returned */
					__( 'Connected using %1$s — authenticated OK; %2$d pickup warehouse(s) found.', 'epic-viettelpost-shipping' ),
					$mode_label,
					(int) $result['count']
				),
			)
		);
	}

	// ------------------------------------------------------------------
	// Address lookups
	// ------------------------------------------------------------------

	public static function get_provinces() {
		self::verify_request();

		$provinces = Epic_VTP_Client::get_provinces_new();
		if ( is_wp_error( $provinces ) ) {
			self::send_wp_error( $provinces );
		}

		wp_send_json_success( array( 'items' => self::to_options( $provinces, 'PROVINCE_ID', 'PROVINCE_NAME' ) ) );
	}

	public static function get_wards() {
		self::verify_request();

		$province_id = isset( $_POST['province_id'] ) ? absint( $_POST['province_id'] ) : 0;
		if ( ! $province_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing province.', 'epic-viettelpost-shipping' ) ) );
		}

		$wards = Epic_VTP_Client::get_wards_new( $province_id );
		if ( is_wp_error( $wards ) ) {
			self::send_wp_error( $wards );
		}

		wp_send_json_success( array( 'items' => self::to_options( $wards, 'WARDS_ID', 'WARDS_NAME' ) ) );
	}

	/**
	 * Normalizes a ViettelPost master-data list into [{id, name}, …],
	 * defensively — a malformed response shouldn't fatal the request.
	 */
	private static function to_options( $rows, $id_field, $name_field ) {
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$options = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row[ $id_field ], $row[ $name_field ] ) ) {
				continue;
			}
			$options[] = array( 'id' => $row[ $id_field ], 'name' => $row[ $name_field ] );
		}
		return $options;
	}

	/**
	 * Backs the meta box's async address resolution: tries to match the
	 * order's city/state to a ViettelPost province, and otherwise returns the
	 * manual picker markup for staff to choose one.
	 */
	public static function resolve_address() {
		self::verify_request();
		$order = self::get_order_or_fail();

		$resolved = Epic_VTP_Address_Resolver::resolve( $order );

		if ( $resolved['resolved'] ) {
			wp_send_json_success(
				array(
					'resolved'     => true,
					'provinceId'   => $resolved['province_id'],
					'provinceName' => $resolved['province_name'],
				)
			);
		}

		$html = Epic_VTP_Assets::render_address_group(
			'ship_' . $order->get_id(),
			array(
				'province_id'   => $resolved['province_id'],
				'province_name' => $resolved['province_name'],
			)
		);

		wp_send_json_success(
			array(
				'resolved' => false,
				'error'    => $resolved['error'],
				'html'     => $html,
			)
		);
	}

	// ------------------------------------------------------------------
	// Order actions
	// ------------------------------------------------------------------

	private static function get_order_or_fail() {
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order instanceof WC_Order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'epic-viettelpost-shipping' ) ) );
		}

		return $order;
	}

	public static function ship_order() {
		self::verify_request();
		$order = self::get_order_or_fail();

		$province_id   = isset( $_POST['province_id'] ) ? sanitize_text_field( wp_unslash( $_POST['province_id'] ) ) : '';
		$province_name = isset( $_POST['province_name'] ) ? sanitize_text_field( wp_unslash( $_POST['province_name'] ) ) : '';

		$result = self::book_single_order( $order, $province_id, $province_name );

		if ( is_wp_error( $result ) ) {
			self::send_wp_error( $result );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Books one order's ViettelPost shipment — the shared core behind both the
	 * single-order "Ship via ViettelPost" button and the orders-list bulk
	 * action, so there's exactly one place that decides COD-vs-prepaid,
	 * computes weight/fee, and writes the resulting order meta/note.
	 *
	 * @param WC_Order $order
	 * @param string   $province_id   Manual override province ID. '' = auto-resolve.
	 * @param string   $province_name Manual override province name, paired with $province_id.
	 * @return array|WP_Error { trackingCode, status, isCod, fee } on success.
	 */
	public static function book_single_order( WC_Order $order, $province_id = '', $province_name = '' ) {
		if ( $order->get_meta( Epic_VTP_Order_Meta_Box::META_ORDER_NUMBER ) ) {
			return new WP_Error( 'epic_vtp_already_shipped', __( 'This order already has a ViettelPost shipment.', 'epic-viettelpost-shipping' ) );
		}

		// Concurrency guard: a rapid double-click (or an order selected in a
		// bulk run while its own screen is booking) would otherwise create two
		// ViettelPost shipments. The lock is released in finally, and expires
		// on its own after a minute if the request dies mid-flight.
		$lock_key = 'epic_vtp_lock_' . $order->get_id();
		if ( false !== get_transient( $lock_key ) ) {
			return new WP_Error( 'epic_vtp_locked', __( 'A booking for this order is already in progress. Please wait a moment and refresh.', 'epic-viettelpost-shipping' ) );
		}
		set_transient( $lock_key, 1, MINUTE_IN_SECONDS );

		try {
			return self::do_book_single_order( $order, $province_id, $province_name );
		} finally {
			delete_transient( $lock_key );
		}
	}

	/**
	 * The actual booking — see book_single_order() for the concurrency guard.
	 *
	 * @return array|WP_Error
	 */
	private static function do_book_single_order( WC_Order $order, $province_id = '', $province_name = '' ) {
		if ( ! $province_id ) {
			$resolved = Epic_VTP_Address_Resolver::resolve( $order );
			if ( ! $resolved['resolved'] ) {
				return new WP_Error(
					'epic_vtp_unresolved_address',
					__( 'This order\'s address couldn\'t be matched to a ViettelPost province. Pick one manually and try again.', 'epic-viettelpost-shipping' )
				);
			}
			$province_id   = $resolved['province_id'];
			$province_name = $resolved['province_name'];
		}

		$settings = Epic_VTP_Client::get_settings();
		$weight_g = Epic_VTP_Order_Meta_Box::calculate_order_weight_g( $order );
		$items    = self::order_items_for_vtp( $order );
		$subtotal = (float) $order->get_subtotal();
		$total    = (float) $order->get_total();

		// The customer is charged exactly the WooCommerce order total — goods
		// plus the shipping fee already baked into the order price — so the
		// amount shown at checkout is the amount collected at the door, with
		// no courier-recalculated fee added on top (that recomputation is why
		// the amount actually collected used to differ from the total the
		// site displayed). Booking as "collect goods only"
		// (ORDER_PAYMENT = 3) makes ViettelPost bill its own shipping fee to
		// the sender instead of the recipient, deducted from the COD
		// remittance: the sender receives the order total minus ViettelPost's
		// fee, and the shipping component stays inside the order price.
		// Prepaid (SePay) orders collect nothing (ORDER_PAYMENT = 1); the
		// sender covers the fee then too.
		$is_cod        = Epic_VTP_Client::is_cod_order( $order );
		$cod_amount    = $is_cod ? max( 0, $total ) : 0;
		$order_payment = $is_cod
			? Epic_VTP_Client::ORDER_PAYMENT_GOODS_ONLY
			: Epic_VTP_Client::ORDER_PAYMENT_NONE;

		$phone = $order->get_shipping_phone() ? $order->get_shipping_phone() : $order->get_billing_phone();
		$name  = $order->get_formatted_shipping_full_name();
		if ( '' === trim( (string) $name ) ) {
			$name = $order->get_formatted_billing_full_name();
		}
		$address_line = self::order_address_line( $order );

		$price_args = array(
			'receiver_address'   => $address_line,
			'receiver_province_id' => (int) $province_id,
			'weight_g'           => $weight_g,
			'product_price'      => (int) round( $subtotal ),
			'money_collection'   => (int) round( $cod_amount ),
			'length_cm'          => (int) $settings['default_length_cm'],
			'width_cm'           => (int) $settings['default_width_cm'],
			'height_cm'          => (int) $settings['default_height_cm'],
		);

		$services = Epic_VTP_Client::get_price_all_nlp( $price_args );
		if ( is_wp_error( $services ) ) {
			self::maybe_hold_on_failure( $order, $services );
			return $services;
		}

		$service_code = Epic_VTP_Client::choose_service( $services );
		if ( is_wp_error( $service_code ) ) {
			self::maybe_hold_on_failure( $order, $service_code );
			return $service_code;
		}

		$fee = self::service_fee( $services, $service_code );

		$shipment = Epic_VTP_Client::create_order_nlp(
			array_merge(
				$price_args,
				array(
					'to_name'        => $name,
					'to_phone'       => $phone,
					'to_address'     => $address_line,
					'order_number'   => (string) $order->get_order_number(),
					'order_service'  => $service_code,
					'order_payment'  => $order_payment,
					'product_name'   => self::order_product_summary( $order ),
					'quantity'       => self::order_total_quantity( $order ),
					'items'          => $items,
					'note'           => self::booking_note( $order ),
				)
			)
		);

		if ( is_wp_error( $shipment ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: error message */
					__( 'ViettelPost shipment booking failed: %s', 'epic-viettelpost-shipping' ),
					$shipment->get_error_message()
				)
			);
			self::maybe_hold_on_failure( $order, $shipment );
			return $shipment;
		}

		// ViettelPost auto-generates the waybill and returns it as
		// ORDER_NUMBER in the create response (see docs: document.table.id-render).
		$tracking_code = ! empty( $shipment['ORDER_NUMBER'] )
			? (string) $shipment['ORDER_NUMBER']
			: (string) $order->get_order_number();

		$order->update_meta_data( Epic_VTP_Order_Meta_Box::META_ORDER_NUMBER, $tracking_code );
		$order->update_meta_data( Epic_VTP_Order_Meta_Box::META_PROVINCE_ID, (string) $province_id );
		$order->update_meta_data( Epic_VTP_Order_Meta_Box::META_PROVINCE_NAME, (string) $province_name );
		$order->update_meta_data( Epic_VTP_Order_Meta_Box::META_SERVICE, (string) $service_code );
		$order->update_meta_data( Epic_VTP_Order_Meta_Box::META_FEE, (string) $fee );
		$order->update_meta_data( Epic_VTP_Order_Meta_Box::META_STATUS, '102' ); // Awaiting processing.
		$order->update_meta_data( Epic_VTP_Order_Meta_Box::META_LAST_SYNCED, current_time( 'mysql' ) );
		$order->update_meta_data( Epic_VTP_Order_Meta_Box::META_COD_AMOUNT, $is_cod ? $cod_amount : 0 );
		$order->save();

		$order->add_order_note(
			$is_cod
				? sprintf(
					/* translators: 1: ViettelPost tracking code, 2: amount to collect on delivery (= full order total), 3: shipping service code, 4: fee */
					__( 'ViettelPost shipment booked from wp-admin as COD. Tracking code: %1$s. Amount to collect on delivery: %2$s (the full order total, shipping included) — this matches the total shown to the customer at checkout. ViettelPost\'s own shipping fee is billed to the sender and deducted from the COD remittance, so it is not added on top of what the customer pays. Service: %3$s, estimated fee: %4$s.', 'epic-viettelpost-shipping' ),
					$tracking_code,
					wp_strip_all_tags( wc_price( $cod_amount ) ),
					$service_code,
					wp_strip_all_tags( wc_price( (float) $fee ) )
				)
				: sprintf(
					/* translators: 1: ViettelPost tracking code, 2: payment method title, 3: shipping service code, 4: fee */
					__( 'ViettelPost shipment booked from wp-admin as prepaid (no COD) — order was paid via %2$s. Tracking code: %1$s. Service: %3$s, estimated fee: %4$s.', 'epic-viettelpost-shipping' ),
					$tracking_code,
					$order->get_payment_method_title(),
					$service_code,
					wp_strip_all_tags( wc_price( (float) $fee ) )
				)
		);

		/**
		 * Plugin-agnostic hook for anything that wants to react to a shipment
		 * being booked — mirrors epic-ghn-shipping's epic_ghn_shipment_booked
		 * so the epic-order-emails "your order has shipped" email can hook
		 * either carrier without a hard dependency.
		 *
		 * @param WC_Order $order
		 * @param string   $tracking_code ViettelPost waybill number.
		 * @param string   $eta           Expected delivery, or ''.
		 */
		do_action( 'epic_vtp_shipment_booked', $order, $tracking_code, '' );

		return array(
			'trackingCode' => $tracking_code,
			'status'       => '102',
			'isCod'        => $is_cod,
			'fee'          => $fee,
		);
	}

	public static function cancel_shipment() {
		self::verify_request();
		$order = self::get_order_or_fail();

		$tracking_code = $order->get_meta( Epic_VTP_Order_Meta_Box::META_ORDER_NUMBER );
		if ( ! $tracking_code ) {
			wp_send_json_error( array( 'message' => __( 'This order has no ViettelPost shipment to cancel.', 'epic-viettelpost-shipping' ) ) );
		}

		$result = Epic_VTP_Client::cancel_order(
			$tracking_code,
			__( 'Cancelled from wp-admin', 'epic-viettelpost-shipping' )
		);
		if ( is_wp_error( $result ) ) {
			self::send_wp_error( $result );
		}

		$order->add_order_note(
			sprintf(
				/* translators: %s: ViettelPost tracking code */
				__( 'ViettelPost shipment %s cancelled from wp-admin.', 'epic-viettelpost-shipping' ),
				$tracking_code
			)
		);

		foreach ( array(
			Epic_VTP_Order_Meta_Box::META_ORDER_NUMBER,
			Epic_VTP_Order_Meta_Box::META_EXPECTED,
			Epic_VTP_Order_Meta_Box::META_STATUS,
			Epic_VTP_Order_Meta_Box::META_LAST_SYNCED,
			Epic_VTP_Order_Meta_Box::META_FEE,
		) as $meta_key ) {
			$order->delete_meta_data( $meta_key );
		}
		$order->save();

		wp_send_json_success();
	}

	public static function print_label() {
		self::verify_request();
		$order = self::get_order_or_fail();

		$tracking_code = $order->get_meta( Epic_VTP_Order_Meta_Box::META_ORDER_NUMBER );
		if ( ! $tracking_code ) {
			wp_send_json_error( array( 'message' => __( 'This order has no ViettelPost shipment to print.', 'epic-viettelpost-shipping' ) ) );
		}

		wp_send_json_success( array( 'url' => self::print_url_for( array( $tracking_code ) ) ) );
	}

	/**
	 * Builds a (cached) print URL for one or more waybills. ViettelPost's print
	 * token is valid for a day, so a 10-minute transient cache avoids
	 * regenerating one for a label that's printed repeatedly.
	 *
	 * @param string[] $tracking_codes
	 * @return string
	 */
	private static function print_url_for( array $tracking_codes ) {
		$settings  = Epic_VTP_Client::get_settings();
		$cache_key = 'epic_vtp_print_' . md5( implode( ',', $tracking_codes ) . '|' . $settings['label_size'] . '|' . $settings['label_show_postage'] );
		$token     = get_transient( $cache_key );

		if ( ! is_string( $token ) || '' === $token ) {
			$token = Epic_VTP_Client::gen_print_token( $tracking_codes );
			if ( is_wp_error( $token ) ) {
				return ''; // Caller reports the error.
			}
			set_transient( $cache_key, $token, 10 * MINUTE_IN_SECONDS );
		}

		return Epic_VTP_Client::print_url( $token, $settings['label_size'], 'yes' === $settings['label_show_postage'] );
	}

	/**
	 * Manual status override for a booked shipment. ViettelPost exposes no
	 * status-query API — the webhook is the only inbound channel — so when a
	 * callback is missed staff can set the last-known status here.
	 */
	public static function set_shipment_status() {
		self::verify_request();
		$order = self::get_order_or_fail();

		if ( ! $order->get_meta( Epic_VTP_Order_Meta_Box::META_ORDER_NUMBER ) ) {
			wp_send_json_error( array( 'message' => __( 'This order has no ViettelPost shipment.', 'epic-viettelpost-shipping' ) ) );
		}

		$status = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
		if ( ! isset( Epic_VTP_Client::status_map()[ $status ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown shipment status.', 'epic-viettelpost-shipping' ) ) );
		}

		$order->update_meta_data( Epic_VTP_Order_Meta_Box::META_STATUS, $status );
		$order->update_meta_data( Epic_VTP_Order_Meta_Box::META_LAST_SYNCED, current_time( 'mysql' ) );
		$order->save();

		$order->add_order_note(
			sprintf(
				/* translators: 1: status label, 2: raw status code */
				__( 'ViettelPost status set manually from wp-admin: %1$s (%2$s).', 'epic-viettelpost-shipping' ),
				Epic_VTP_Client::status_label( $status ),
				$status
			)
		);

		do_action( 'epic_vtp_status_changed', $order, $status, 'manual' );

		wp_send_json_success(
			array(
				'status' => $status,
				'label'  => Epic_VTP_Client::status_label( $status ),
				'bucket' => Epic_VTP_Client::bucket_status( $status )['css_class'],
			)
		);
	}

	/**
	 * Builds one print URL for a batch of selected orders' waybills — used by
	 * the Shipments dashboard's "Print labels" bulk button.
	 */
	public static function bulk_print() {
		self::verify_request();

		$order_ids = isset( $_POST['order_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['order_ids'] ) ) : array();
		$waybills  = array();
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				$waybill = $order->get_meta( Epic_VTP_Order_Meta_Box::META_ORDER_NUMBER );
				if ( $waybill ) {
					$waybills[] = (string) $waybill;
				}
			}
		}

		if ( empty( $waybills ) ) {
			wp_send_json_error( array( 'message' => __( 'None of the selected orders have a booked shipment.', 'epic-viettelpost-shipping' ) ) );
		}

		$url = self::print_url_for( $waybills );
		if ( '' === $url ) {
			self::send_wp_error( new WP_Error( 'epic_vtp_print', __( 'ViettelPost did not return a print token.', 'epic-viettelpost-shipping' ) ) );
		}

		wp_send_json_success( array( 'url' => $url, 'count' => count( $waybills ) ) );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	private static function order_address_line( WC_Order $order ) {
		$parts = array_filter(
			array(
				$order->get_shipping_address_1(),
				$order->get_shipping_address_2(),
				$order->get_shipping_city(),
				$order->get_shipping_state(),
			)
		);
		return trim( implode( ', ', $parts ) );
	}

	private static function order_items_for_vtp( WC_Order $order ) {
		$items = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			/** @var WC_Order_Item_Product $item */
			$product = $item->get_product();
			$unit_price = $item->get_subtotal() / max( 1, $item->get_quantity() );
			$weight_g   = 0;
			if ( $product && $product->get_weight() ) {
				$unit        = get_option( 'woocommerce_weight_unit', 'kg' );
				$weight_g    = ( 'g' === $unit ) ? (float) $product->get_weight() : (float) $product->get_weight() * 1000;
			}
			$items[] = array(
				'name'     => $item->get_name(),
				'quantity' => $item->get_quantity(),
				'price'    => (int) round( $unit_price ),
				'weight_g' => (int) round( $weight_g ),
			);
		}
		return $items;
	}

	private static function order_product_summary( WC_Order $order ) {
		$names = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$names[] = $item->get_name();
		}
		$summary = implode( ', ', $names );
		if ( strlen( $summary ) > 190 ) {
			$summary = substr( $summary, 0, 187 ) . '...';
		}
		return $summary ? $summary : __( 'Order items', 'epic-viettelpost-shipping' );
	}

	private static function order_total_quantity( WC_Order $order ) {
		$qty = 0;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$qty += $item->get_quantity();
		}
		return max( 1, $qty );
	}

	/**
	 * Builds the waybill note (ViettelPost ORDER_NOTE) for a booking: the
	 * customer's delivery note (WooCommerce's native `customer_note`, set at
	 * storefront checkout) followed by the order-number reference. Whitespace
	 * is collapsed to a single line and the whole string is capped at
	 * self::ORDER_NOTE_MAX_LENGTH characters so a long customer note can never
	 * be rejected by (or overflow) the courier's field.
	 *
	 * @return string
	 */
	private static function booking_note( WC_Order $order ) {
		$reference = sprintf(
			/* translators: %s: WooCommerce order number */
			__( 'WooCommerce order #%s (booked from wp-admin)', 'epic-viettelpost-shipping' ),
			$order->get_order_number()
		);

		$customer_note = trim( preg_replace( '/\s+/u', ' ', (string) $order->get_customer_note() ) );

		$note = ( '' !== $customer_note ) ? $customer_note . ' — ' . $reference : $reference;

		if ( function_exists( 'mb_substr' ) ) {
			if ( mb_strlen( $note, 'UTF-8' ) > self::ORDER_NOTE_MAX_LENGTH ) {
				$note = mb_substr( $note, 0, self::ORDER_NOTE_MAX_LENGTH, 'UTF-8' );
			}
		} elseif ( strlen( $note ) > self::ORDER_NOTE_MAX_LENGTH ) {
			$note = substr( $note, 0, self::ORDER_NOTE_MAX_LENGTH );
		}

		return trim( $note );
	}

	private static function service_fee( $services, $service_code ) {
		if ( ! is_array( $services ) ) {
			return 0;
		}
		foreach ( $services as $service ) {
			if ( isset( $service['MA_DV_CHINH'], $service['GIA_CUOC'] ) && (string) $service['MA_DV_CHINH'] === (string) $service_code ) {
				return (int) $service['GIA_CUOC'];
			}
		}
		return 0;
	}

	/**
	 * When the "Hold the order if booking fails" setting is on, moves an order
	 * to on-hold after a genuine booking failure so it surfaces for manual
	 * follow-up (the failure note is added separately by book_single_order()).
	 * Configuration/already-shipped/lock errors are never "held" — those are
	 * not the courier's fault and would just create noise.
	 */
	private static function maybe_hold_on_failure( WC_Order $order, WP_Error $error ) {
		$settings = Epic_VTP_Client::get_settings();
		if ( 'yes' !== $settings['hold_on_failure'] ) {
			return;
		}
		if ( in_array( $error->get_error_code(), array( 'epic_vtp_config', 'epic_vtp_already_shipped', 'epic_vtp_locked' ), true ) ) {
			return;
		}
		if ( $order->has_status( 'on-hold' ) ) {
			return;
		}
		$order->update_status( 'on-hold', __( 'ViettelPost booking failed — held for manual handling.', 'epic-viettelpost-shipping' ) );
	}
}
