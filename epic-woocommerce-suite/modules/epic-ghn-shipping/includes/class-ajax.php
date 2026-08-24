<?php
/**
 * AJAX endpoints backing the admin UI: the province/district/ward cascading
 * pickers (settings screen + order meta box) and the order meta box's
 * ship/cancel/print/refresh actions.
 *
 * Every handler is capability + nonce gated. Failures are logged via
 * WC_Logger (source "epic-ghn") so a store owner can diagnose a failed
 * booking under WooCommerce → Status → Logs without needing server access.
 *
 * @package Epic_GHN_Shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_GHN_Ajax {

	public static function init() {
		$actions = array(
			'epic_ghn_get_provinces'      => 'get_provinces',
			'epic_ghn_get_districts'      => 'get_districts',
			'epic_ghn_get_wards'          => 'get_wards',
			'epic_ghn_get_new_provinces'  => 'get_new_provinces',
			'epic_ghn_get_new_wards'      => 'get_new_wards',
			'epic_ghn_convert_new_address' => 'convert_new_address',
			'epic_ghn_resolve_address'    => 'resolve_address',
			'epic_ghn_ship_order'         => 'ship_order',
			'epic_ghn_cancel_shipment'    => 'cancel_shipment',
			'epic_ghn_print_label'        => 'print_label',
			'epic_ghn_sync_status'        => 'sync_status',
		);

		foreach ( $actions as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( __CLASS__, $method ) );
		}
	}

	private static function log( $message, $context = array() ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $message, array_merge( array( 'source' => 'epic-ghn' ), $context ) );
		}
	}

	private static function verify_request() {
		check_ajax_referer( 'epic_ghn_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'epic-ghn-shipping' ) ), 403 );
		}
	}

	private static function send_wp_error( WP_Error $error ) {
		self::log( $error->get_error_message() );
		wp_send_json_error( array( 'message' => $error->get_error_message() ) );
	}

	// ------------------------------------------------------------------
	// Address lookups
	// ------------------------------------------------------------------

	public static function get_provinces() {
		self::verify_request();

		$provinces = Epic_GHN_Client::get_provinces();
		if ( is_wp_error( $provinces ) ) {
			self::send_wp_error( $provinces );
		}

		wp_send_json_success( array( 'items' => self::to_options( $provinces, 'ProvinceID', 'ProvinceName' ) ) );
	}

	public static function get_districts() {
		self::verify_request();

		$province_id = isset( $_POST['province_id'] ) ? absint( $_POST['province_id'] ) : 0;
		if ( ! $province_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing province.', 'epic-ghn-shipping' ) ) );
		}

		$districts = Epic_GHN_Client::get_districts( $province_id );
		if ( is_wp_error( $districts ) ) {
			self::send_wp_error( $districts );
		}

		wp_send_json_success( array( 'items' => self::to_options( $districts, 'DistrictID', 'DistrictName' ) ) );
	}

	/**
	 * Normalizes a GHN master-data list into [{id, name}, …] — defensively,
	 * since a malformed or unexpected-shape response from GHN here (not an
	 * array, or rows missing the expected keys) used to reach array_map()
	 * unguarded and could fatal the request instead of just failing it
	 * gracefully.
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

	public static function get_wards() {
		self::verify_request();

		$district_id = isset( $_POST['district_id'] ) ? absint( $_POST['district_id'] ) : 0;
		if ( ! $district_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing district.', 'epic-ghn-shipping' ) ) );
		}

		$wards = Epic_GHN_Client::get_wards( $district_id );
		if ( is_wp_error( $wards ) ) {
			self::send_wp_error( $wards );
		}

		wp_send_json_success( array( 'items' => self::to_options( $wards, 'WardCode', 'WardName' ) ) );
	}

	// ------------------------------------------------------------------
	// New-format (post-2025-merger) address lookups — see
	// includes/class-legacy-address.php's docblock for why GHN's own API
	// still needs the old codes regardless, and why these are separate,
	// exact-code endpoints (no fuzzy matching needed: staff pick from a
	// dropdown here, unlike Epic_GHN_Address_Resolver's free-text passes).
	// ------------------------------------------------------------------

	public static function get_new_provinces() {
		self::verify_request();

		$items = array();
		foreach ( Epic_GHN_Legacy_Address::new_provinces_list() as $code => $row ) {
			$items[] = array( 'id' => $code, 'name' => $row['name_with_type'] );
		}
		wp_send_json_success( array( 'items' => $items ) );
	}

	public static function get_new_wards() {
		self::verify_request();

		$province_id = isset( $_POST['province_id'] ) ? sanitize_text_field( wp_unslash( $_POST['province_id'] ) ) : '';
		if ( ! $province_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing province.', 'epic-ghn-shipping' ) ) );
		}

		$items = array();
		foreach ( Epic_GHN_Legacy_Address::new_wards_for_province( $province_id ) as $code => $row ) {
			$items[] = array( 'id' => $code, 'name' => $row['name_with_type'] );
		}
		wp_send_json_success( array( 'items' => $items ) );
	}

	/**
	 * Converts a staff-picked new-format (post-merger) ward back to GHN's
	 * still-old province/district/ward, via Epic_GHN_Legacy_Address's
	 * bundled mapping + Epic_GHN_Address_Resolver::resolve_from_names().
	 * Backs the "Convert to pre-merger address" button rendered by
	 * Epic_GHN_Assets::render_new_address_group() on both the Settings
	 * screen's pickup-address picker and the order meta box's manual
	 * override.
	 *
	 * Two-step when the mapping is ambiguous (see
	 * Epic_GHN_Legacy_Address::old_candidates_for_new_ward()'s
	 * district_confident flag): the first call (no candidate_index) returns
	 * the distinct old areas for staff to choose from instead of silently
	 * guessing; the client re-calls with the chosen candidate_index to
	 * actually resolve it against GHN.
	 */
	public static function convert_new_address() {
		self::verify_request();

		$new_ward_code = isset( $_POST['new_ward_id'] ) ? sanitize_text_field( wp_unslash( $_POST['new_ward_id'] ) ) : '';
		if ( ! $new_ward_code ) {
			wp_send_json_error( array( 'message' => __( 'Pick a new-format province and ward first.', 'epic-ghn-shipping' ) ) );
		}

		$mapped = Epic_GHN_Legacy_Address::old_candidates_for_new_ward( $new_ward_code );
		if ( ! $mapped || empty( $mapped['candidates'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No known mapping from this new-format ward back to a pre-merger address — enter the pre-merger address manually below.', 'epic-ghn-shipping' ) ) );
		}

		$candidates      = $mapped['candidates'];
		$candidate_index = isset( $_POST['candidate_index'] ) && '' !== $_POST['candidate_index'] ? absint( $_POST['candidate_index'] ) : null;

		if ( null === $candidate_index && empty( $mapped['district_confident'] ) ) {
			$choices = array();
			$seen    = array();
			foreach ( $candidates as $i => $candidate ) {
				$key = $candidate['p'] . '|' . $candidate['d'];
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$choices[]    = array(
					'index' => $i,
					'label' => $candidate['d'] . ', ' . $candidate['p'],
				);
			}

			wp_send_json_success(
				array(
					'ambiguous' => true,
					'choices'   => $choices,
				)
			);
		}

		$chosen = ( null !== $candidate_index && isset( $candidates[ $candidate_index ] ) )
			? $candidates[ $candidate_index ]
			: $candidates[0]; // district_confident case — most-frequent candidate.

		$old = Epic_GHN_Address_Resolver::resolve_from_names( $chosen['p'], $chosen['d'], $chosen['w'] );

		if ( $old['error'] ) {
			self::send_wp_error( new WP_Error( 'epic_ghn_api', $old['error'] ) );
		}
		if ( ! $old['resolved'] ) {
			wp_send_json_error( array( 'message' => __( 'Converted to a pre-merger address GHN doesn\'t recognize either — enter it manually below.', 'epic-ghn-shipping' ) ) );
		}

		wp_send_json_success(
			array(
				'ambiguous'    => false,
				'provinceId'   => $old['province_id'],
				'provinceName' => $old['province_name'],
				'districtId'   => $old['district_id'],
				'districtName' => $old['district_name'],
				'wardCode'     => $old['ward_code'],
				'wardName'     => $old['ward_name'],
			)
		);
	}

	/**
	 * Backs the meta box's async address resolution (see the long comment in
	 * Epic_GHN_Order_Meta_Box::render_unbooked_state() for why this moved
	 * out of the page's own render path and into an AJAX round-trip).
	 */
	public static function resolve_address() {
		self::verify_request();
		$order = self::get_order_or_fail();

		$resolved = Epic_GHN_Address_Resolver::resolve( $order );

		if ( $resolved['resolved'] ) {
			$payload = array(
				'resolved'   => true,
				'provinceId' => $resolved['province_id'],
				'districtId' => $resolved['district_id'],
				'wardCode'   => $resolved['ward_code'],
				'source'     => $resolved['source'],
			);
			if ( 'new_converted' === $resolved['source'] ) {
				$payload['newProvinceName'] = $resolved['new_province_name'];
				$payload['newWardName']     = $resolved['new_ward_name'];
			}
			wp_send_json_success( $payload );
		}

		$html = Epic_GHN_Assets::render_address_group(
			'ship_' . $order->get_id(),
			array(
				'province_id'   => $resolved['province_id'],
				'province_name' => $resolved['province_name'],
				'district_id'   => $resolved['district_id'],
				'district_name' => $resolved['district_name'],
				'ward_code'     => $resolved['ward_code'],
				'ward_name'     => $resolved['ward_name'],
			)
		);

		// Offered alongside the old-format picker above regardless of
		// whether pass 2 (Epic_GHN_Address_Resolver::resolve()'s new-format
		// attempt) itself found anything — a matched-but-ambiguous new
		// province/ward still gives staff a head start over typing it again.
		$new_html = Epic_GHN_Assets::render_new_address_group(
			'ship_' . $order->get_id(),
			array(
				'province_name' => $resolved['new_province_name'] ? $resolved['new_province_name'] : '',
				'ward_name'     => $resolved['new_ward_name'] ? $resolved['new_ward_name'] : '',
			)
		);

		wp_send_json_success(
			array(
				'resolved'                => false,
				'error'                   => $resolved['error'],
				'html'                    => $html,
				'newAddressHtml'          => $new_html,
				'legacyDistrictConfident' => $resolved['legacy_district_confident'],
				'hasLegacyCandidates'     => ! empty( $resolved['legacy_candidates'] ),
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
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'epic-ghn-shipping' ) ) );
		}

		return $order;
	}

	public static function ship_order() {
		self::verify_request();
		$order = self::get_order_or_fail();

		// Manual override from the picker, if the automatic resolver couldn't
		// match the order's address (see Epic_GHN_Address_Resolver + the
		// meta box's "resolved" vs override-picker branch). Only the
		// single-order AJAX flow has this picker — the orders-list bulk
		// action (Epic_GHN_Orders_List::handle_ship_bulk_action()) always
		// calls book_single_order() with no override, since there's no
		// per-order UI in a bulk action to pick one from.
		$district_id = isset( $_POST['district_id'] ) ? absint( $_POST['district_id'] ) : 0;
		$ward_code   = isset( $_POST['ward_code'] ) ? sanitize_text_field( wp_unslash( $_POST['ward_code'] ) ) : '';

		$result = self::book_single_order( $order, $district_id, $ward_code );

		if ( is_wp_error( $result ) ) {
			self::send_wp_error( $result );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Books one order's GHN shipment — the shared core behind both the
	 * single-order "Ship via GHN" button (ship_order() above) and the
	 * orders-list "Create GHN shipment(s)" bulk action
	 * (Epic_GHN_Orders_List::handle_ship_bulk_action()), so there's exactly
	 * one place that decides COD-vs-prepaid, computes weight/fee, and
	 * writes the resulting order meta/note — the bulk action can't drift
	 * from what a staff member clicking the button one order at a time
	 * would get.
	 *
	 * @param WC_Order $order
	 * @param int      $district_id Manual override district ID. 0 = auto-resolve via Epic_GHN_Address_Resolver.
	 * @param string   $ward_code   Manual override ward code, paired with $district_id.
	 * @return array|WP_Error { trackingCode, eta, status, isCod } on success.
	 */
	public static function book_single_order( WC_Order $order, $district_id = 0, $ward_code = '' ) {
		if ( $order->get_meta( '_ghn_order_code' ) ) {
			return new WP_Error( 'epic_ghn_already_shipped', __( 'This order already has a GHN shipment.', 'epic-ghn-shipping' ) );
		}

		if ( ! $district_id || ! $ward_code ) {
			$resolved = Epic_GHN_Address_Resolver::resolve( $order );
			if ( ! $resolved['resolved'] ) {
				return new WP_Error(
					'epic_ghn_unresolved_address',
					__( 'This order\'s address couldn\'t be matched to a GHN district/ward. Pick them manually and try again.', 'epic-ghn-shipping' )
				);
			}
			$district_id = $resolved['district_id'];
			$ward_code   = $resolved['ward_code'];
		}

		$weight_g = Epic_GHN_Order_Meta_Box::calculate_order_weight_g( $order );
		$items    = self::order_items_for_ghn( $order );
		$subtotal = (float) $order->get_subtotal();
		$total    = (float) $order->get_total();

		$fee = Epic_GHN_Client::calculate_fee(
			array(
				'to_district_id'  => $district_id,
				'to_ward_code'    => $ward_code,
				'weight_g'        => $weight_g,
				'insurance_value' => $subtotal,
			)
		);
		if ( is_wp_error( $fee ) ) {
			return $fee;
		}

		$phone = $order->get_shipping_phone() ? $order->get_shipping_phone() : $order->get_billing_phone();
		$name  = $order->get_formatted_shipping_full_name();
		$address_line = trim( $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() );

		// COD vs. prepaid — see Epic_GHN_Client::is_cod_order() for why this
		// is a strict payment_method check, never a staff judgment call.
		$is_cod = Epic_GHN_Client::is_cod_order( $order );

		$shipment = Epic_GHN_Client::create_shipment(
			array(
				'to_name'           => $name ? $name : $order->get_formatted_billing_full_name(),
				'to_phone'          => $phone,
				'to_address'        => $address_line,
				'to_district_id'    => $district_id,
				'to_ward_code'      => $ward_code,
				'payment_type_id'   => $is_cod ? Epic_GHN_Client::PAYMENT_TYPE_COD : Epic_GHN_Client::PAYMENT_TYPE_PREPAID,
				'cod_amount'        => $is_cod ? $total : 0,
				'insurance_value'   => $subtotal,
				'weight_g'          => $weight_g,
				'items'             => $items,
				'note'              => sprintf( 'WooCommerce order #%s (booked from wp-admin)', $order->get_order_number() ),
				'client_order_code' => (string) $order->get_id(),
			)
		);

		if ( is_wp_error( $shipment ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: error message */
					__( 'GHN shipment booking failed: %s', 'epic-ghn-shipping' ),
					$shipment->get_error_message()
				)
			);
			return $shipment;
		}

		$order->update_meta_data( '_ghn_order_code', $shipment['order_code'] );
		$order->update_meta_data( '_ghn_expected_delivery', isset( $shipment['expected_delivery_time'] ) ? $shipment['expected_delivery_time'] : '' );
		$order->update_meta_data( '_ghn_shipment_status', 'ready_to_pick' );
		$order->update_meta_data( '_ghn_last_synced_at', current_time( 'mysql' ) );
		$order->update_meta_data( '_ghn_cod_amount', $is_cod ? $total : 0 );
		$order->save();

		$order->add_order_note(
			$is_cod
				? sprintf(
					/* translators: 1: GHN tracking code, 2: COD amount to collect */
					__( 'GHN shipment booked from wp-admin as COD. Tracking code: %1$s. Amount to collect on delivery: %2$s.', 'epic-ghn-shipping' ),
					$shipment['order_code'],
					wp_strip_all_tags( wc_price( $total ) )
				)
				: sprintf(
					/* translators: 1: GHN tracking code, 2: payment method title */
					__( 'GHN shipment booked from wp-admin as prepaid (no COD) — order was paid via %2$s. Tracking code: %1$s.', 'epic-ghn-shipping' ),
					$shipment['order_code'],
					$order->get_payment_method_title()
				)
		);

		/**
		 * Plugin-agnostic hook for anything that wants to react to a
		 * shipment being booked -- currently consumed by the
		 * epic-order-emails plugin's "your order has shipped" customer
		 * email (Epic_Email_Order_Shipped::trigger()). Fired here rather
		 * than epic-order-emails importing this class directly, so
		 * neither plugin has a hard dependency on the other: this line
		 * is a no-op with epic-order-emails deactivated, and
		 * epic-ghn-shipping keeps booking shipments fine without it.
		 *
		 * @param WC_Order $order
		 * @param string   $tracking_code GHN order_code.
		 * @param string   $eta           GHN's expected_delivery_time, or ''.
		 */
		do_action(
			'epic_ghn_shipment_booked',
			$order,
			$shipment['order_code'],
			isset( $shipment['expected_delivery_time'] ) ? $shipment['expected_delivery_time'] : ''
		);

		return array(
			'trackingCode' => $shipment['order_code'],
			'eta'          => isset( $shipment['expected_delivery_time'] ) ? $shipment['expected_delivery_time'] : '',
			'status'       => 'ready_to_pick',
			'isCod'        => $is_cod,
		);
	}

	public static function cancel_shipment() {
		self::verify_request();
		$order = self::get_order_or_fail();

		$tracking_code = $order->get_meta( '_ghn_order_code' );
		if ( ! $tracking_code ) {
			wp_send_json_error( array( 'message' => __( 'This order has no GHN shipment to cancel.', 'epic-ghn-shipping' ) ) );
		}

		if ( $order->get_meta( '_ghn_bundle_id' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'This order is part of a bundled shipment. Bundle cancellation isn\'t available yet — contact support before cancelling manually.', 'epic-ghn-shipping' ),
				)
			);
		}

		$result = Epic_GHN_Client::cancel_shipments( array( $tracking_code ) );
		if ( is_wp_error( $result ) ) {
			self::send_wp_error( $result );
		}

		$order->add_order_note(
			sprintf(
				/* translators: %s: GHN tracking code */
				__( 'GHN shipment %s cancelled from wp-admin.', 'epic-ghn-shipping' ),
				$tracking_code
			)
		);

		$order->delete_meta_data( '_ghn_order_code' );
		$order->delete_meta_data( '_ghn_expected_delivery' );
		$order->delete_meta_data( '_ghn_shipment_status' );
		$order->delete_meta_data( '_ghn_last_synced_at' );
		$order->save();

		wp_send_json_success();
	}

	public static function print_label() {
		self::verify_request();
		$order = self::get_order_or_fail();

		$tracking_code = $order->get_meta( '_ghn_order_code' );
		if ( ! $tracking_code ) {
			wp_send_json_error( array( 'message' => __( 'This order has no GHN shipment to print.', 'epic-ghn-shipping' ) ) );
		}

		$token = Epic_GHN_Client::gen_print_token( array( $tracking_code ) );
		if ( is_wp_error( $token ) ) {
			self::send_wp_error( $token );
		}

		wp_send_json_success( array( 'url' => Epic_GHN_Client::print_url( $token, 'A5' ) ) );
	}

	public static function sync_status() {
		self::verify_request();
		$order = self::get_order_or_fail();

		$tracking_code = $order->get_meta( '_ghn_order_code' );
		if ( ! $tracking_code ) {
			wp_send_json_error( array( 'message' => __( 'This order has no GHN shipment yet.', 'epic-ghn-shipping' ) ) );
		}

		$detail = Epic_GHN_Client::get_order_detail( $tracking_code );
		if ( is_wp_error( $detail ) ) {
			self::send_wp_error( $detail );
		}

		$status = isset( $detail['status'] ) ? $detail['status'] : '';

		$order->update_meta_data( '_ghn_shipment_status', $status );
		$order->update_meta_data( '_ghn_last_synced_at', current_time( 'mysql' ) );
		if ( ! empty( $detail['leadtime'] ) ) {
			$order->update_meta_data( '_ghn_expected_delivery', $detail['leadtime'] );
		}
		$order->save();

		wp_send_json_success(
			array(
				'status' => $status,
				'eta'    => $order->get_meta( '_ghn_expected_delivery' ),
			)
		);
	}

	/**
	 * @return array [{ name, quantity }] for every product line item —
	 *               GHN's create-shipment API only wants name + quantity per
	 *               item, same as website/src/lib/ghn.ts's createShipment().
	 */
	private static function order_items_for_ghn( WC_Order $order ) {
		$items = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$items[] = array(
				'name'     => $item->get_name(),
				'quantity' => $item->get_quantity(),
			);
		}
		return $items;
	}
}
