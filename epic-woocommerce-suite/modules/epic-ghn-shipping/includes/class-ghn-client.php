<?php
/**
 * Thin wrapper over GHN's v2 public REST API.
 *
 * Deliberately mirrors the shape of the Next.js website's src/lib/ghn.ts so
 * anyone who has read that file already understands this one: same
 * endpoints, same request bodies, same "throw/return on non-200 GHN code"
 * behavior — just WP_Error instead of a thrown JS Error, and credentials
 * read from the plugin's WooCommerce settings instead of process.env.
 *
 * Endpoint paths verified against api.ghn.vn/home/docs/detail (ids 47, 64,
 * 67, 73, 99) on 2026-08-19 — GHN has changed field names between API
 * revisions before, so re-check against your dashboard if requests start
 * failing after a GHN-side update.
 *
 * @package Epic_GHN_Shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_GHN_Client {

	/** Standard (non-express) nationwide delivery — GHN's default service. */
	const STANDARD_SERVICE_TYPE_ID = 2;

	/**
	 * @return array Plugin settings, read from their individual `epic_ghn_*`
	 *               wp_options (one row per field — the same convention
	 *               WooCommerce's own settings pages use) so the Settings
	 *               screen can save each field with WC_Admin_Settings'
	 *               normal per-option handling instead of juggling one big
	 *               serialized array.
	 */
	public static function get_settings() {
		return array(
			'environment'           => get_option( 'epic_ghn_environment', 'sandbox' ),
			// trim(): a token/shop ID pasted with a stray leading/trailing
			// space or newline (easy to do from a copy-paste) is silently
			// wrong in a way that's very hard to spot by eye, and GHN's
			// resulting error message ("token not recognized") gives no hint
			// that whitespace is the actual problem.
			'token'                 => trim( (string) get_option( 'epic_ghn_token', '' ) ),
			'shop_id'               => trim( (string) get_option( 'epic_ghn_shop_id', '' ) ),
			'from_name'             => get_option( 'epic_ghn_from_name', '' ),
			'from_phone'            => get_option( 'epic_ghn_from_phone', '' ),
			'from_province_id'      => get_option( 'epic_ghn_from_province_id', '' ),
			'from_province_name'    => get_option( 'epic_ghn_from_province_name', '' ),
			'from_district_id'      => get_option( 'epic_ghn_from_district_id', '' ),
			'from_district_name'    => get_option( 'epic_ghn_from_district_name', '' ),
			'from_ward_code'        => get_option( 'epic_ghn_from_ward_code', '' ),
			'from_ward_name'        => get_option( 'epic_ghn_from_ward_name', '' ),
			'from_address'          => get_option( 'epic_ghn_from_address', '' ),
			'service_type_id'       => get_option( 'epic_ghn_service_type_id', self::STANDARD_SERVICE_TYPE_ID ),
			'default_length_cm'     => get_option( 'epic_ghn_default_length_cm', 20 ),
			'default_width_cm'      => get_option( 'epic_ghn_default_width_cm', 15 ),
			'default_height_cm'     => get_option( 'epic_ghn_default_height_cm', 10 ),
			'default_item_weight_g' => get_option( 'epic_ghn_default_item_weight_g', 250 ),
		);
	}

	private static function api_base() {
		$settings = self::get_settings();
		return 'sandbox' === $settings['environment']
			? 'https://dev-online-gateway.ghn.vn/shiip/public-api'
			: 'https://online-gateway.ghn.vn/shiip/public-api';
	}

	/** Base URL for the separate label-printing gateway (no /shiip/public-api prefix). */
	private static function print_base() {
		$settings = self::get_settings();
		return 'sandbox' === $settings['environment']
			? 'https://dev-online-gateway.ghn.vn'
			: 'https://online-gateway.ghn.vn';
	}

	public static function is_configured() {
		$settings = self::get_settings();
		return ! empty( $settings['token'] ) && ! empty( $settings['shop_id'] );
	}

	/**
	 * Whether an order should be booked as COD (GHN collects cash on
	 * delivery) vs. prepaid (nothing to collect — already paid online).
	 * Decided strictly from the order's payment_method, never a staff
	 * judgment call at booking time. The storefront's own checkout only
	 * ever sets payment_method to one of two values (see
	 * website/src/lib/woocommerce.ts's COD_PAYMENT_METHOD and
	 * website/src/app/api/sepay/webhook/route.ts): 'cod' for "pay on
	 * receive" and 'sepay' for a SePay bank transfer confirmed before the
	 * order was even created. Only 'cod' should ever collect cash on
	 * delivery — a SePay order billing the customer again at the door
	 * would be a double-charge. Any other/unrecognized payment method
	 * (e.g. a manual wp-admin order, or a future gateway) defaults to
	 * COD — the safer side, since under-collecting is far easier to fix
	 * after the fact than a customer being asked to pay twice.
	 *
	 * Used everywhere a shipment gets booked — the single-order AJAX
	 * handler, the order meta box's pre-booking preview, and (by refusing
	 * to bundle a COD order at all — see Epic_GHN_Bundle::load_orders())
	 * the bundle flow — so all three stay in agreement.
	 */
	public static function is_cod_order( WC_Order $order ) {
		return 'sepay' !== $order->get_payment_method();
	}

	/**
	 * Buckets a raw GHN shipment status (as returned by
	 * /v2/shipping-order/detail and stored in each order's
	 * _ghn_shipment_status meta) into a small set of staff-facing labels
	 * for the Orders list "Shipment" column and similar summary UI. GHN's
	 * own status vocabulary (api.ghn.vn/home/docs/detail?id=73) has ~20
	 * fine-grained values across the pick/transport/deliver/return
	 * lifecycle — too many to usefully tell apart at a glance in a list
	 * column, so this groups them into the stages staff actually act on.
	 *
	 * An empty $raw_status (an order booked by the storefront, which only
	 * ever writes `_ghn_order_code` — see website/src/lib/woocommerce.ts's
	 * attachShipmentToOrder() — never `_ghn_shipment_status`; or an order
	 * booked from wp-admin whose status hasn't been refreshed since) is
	 * treated the same as GHN's own 'ready_to_pick': the shipment exists
	 * and nothing has happened at GHN yet.
	 *
	 * @return array { label: string, css_class: string, raw: string } css_class is
	 *                one of epic-ghn-status-{created,pending,delivering,done,
	 *                cancelled,issue,returning,returned,unknown} for
	 *                assets/admin.css to color.
	 */
	public static function bucket_status( $raw_status ) {
		$raw_status = (string) $raw_status;

		$buckets = array(
			'created'    => array( '', 'ready_to_pick' ),
			'pending'    => array( 'picking', 'money_collect_picking' ),
			'delivering' => array( 'picked', 'storing', 'transporting', 'sorting', 'delivering', 'money_collect_delivering' ),
			'done'       => array( 'delivered' ),
			'cancelled'  => array( 'cancel' ),
			'issue'      => array( 'delivery_fail', 'exception', 'damage', 'lost' ),
			'returning'  => array( 'waiting_to_return', 'return', 'return_transporting', 'return_sorting', 'returning', 'return_fail' ),
			'returned'   => array( 'returned' ),
		);

		$labels = array(
			'created'    => __( 'Created', 'epic-ghn-shipping' ),
			'pending'    => __( 'Pending pickup', 'epic-ghn-shipping' ),
			'delivering' => __( 'Delivering', 'epic-ghn-shipping' ),
			'done'       => __( 'Done', 'epic-ghn-shipping' ),
			'cancelled'  => __( 'Cancelled', 'epic-ghn-shipping' ),
			'issue'      => __( 'Delivery issue', 'epic-ghn-shipping' ),
			'returning'  => __( 'Returning', 'epic-ghn-shipping' ),
			'returned'   => __( 'Returned', 'epic-ghn-shipping' ),
		);

		foreach ( $buckets as $key => $codes ) {
			if ( in_array( $raw_status, $codes, true ) ) {
				return array(
					'label'     => $labels[ $key ],
					'css_class' => 'epic-ghn-status-' . $key,
					'raw'       => $raw_status,
				);
			}
		}

		// Unrecognized status — a GHN API revision added a new code this
		// plugin doesn't know about yet. Show it (titlecased) rather than
		// silently hiding it, so staff still see *something* changed.
		return array(
			'label'     => $raw_status ? ucwords( str_replace( '_', ' ', $raw_status ) ) : __( 'Unknown', 'epic-ghn-shipping' ),
			'css_class' => 'epic-ghn-status-unknown',
			'raw'       => $raw_status,
		);
	}

	/**
	 * @param string $path             Path under /shiip/public-api, e.g. "/v2/shipping-order/create".
	 * @param string $method           GET|POST.
	 * @param array|null $body         Request body — sent as JSON.
	 * @param bool   $requires_shop_id GHN's master-data endpoints (province/district/ward)
	 *                                 only require the Token header — sending a ShopId that
	 *                                 doesn't belong to that token's account (or is simply
	 *                                 blank/wrong) can itself trigger an auth-flavored error
	 *                                 from GHN even though the shop ID isn't what that call
	 *                                 actually needs. Everything shipment-related (fee, create,
	 *                                 cancel, etc.) genuinely does need it. Default true.
	 * @return array|WP_Error Decoded `data` payload on success.
	 */
	private static function request( $path, $method = 'GET', $body = null, $requires_shop_id = true ) {
		$settings = self::get_settings();

		if ( '' === $settings['token'] ) {
			return new WP_Error(
				'epic_ghn_config',
				__( 'GHN Token must be set under WooCommerce → Settings → GHN Shipping before this action can run.', 'epic-ghn-shipping' )
			);
		}
		if ( $requires_shop_id && '' === $settings['shop_id'] ) {
			return new WP_Error(
				'epic_ghn_config',
				__( 'GHN Shop ID must be set under WooCommerce → Settings → GHN Shipping before this action can run.', 'epic-ghn-shipping' )
			);
		}

		$headers = array(
			'Content-Type' => 'application/json',
			'Token'        => $settings['token'],
		);
		// Only attach ShopId when the call actually needs it (see docblock) —
		// and even then, only if one is actually configured, so a
		// province/district/ward lookup still works for a store that hasn't
		// filled in Shop ID yet.
		if ( '' !== $settings['shop_id'] ) {
			$headers['ShopId'] = (string) $settings['shop_id'];
		}

		$args = array(
			'method'  => $method,
			'timeout' => 25,
			'headers' => $headers,
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::api_base() . $path, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$json      = json_decode( wp_remote_retrieve_body( $response ), true );

		$ghn_code_ok = is_array( $json ) && isset( $json['code'] ) && 200 === (int) $json['code'];

		if ( $http_code < 200 || $http_code >= 300 || ! is_array( $json ) || ! $ghn_code_ok ) {
			$message = self::extract_error_message( $json );
			if ( null === $message ) {
				/* translators: 1: API path, 2: HTTP status code */
				$message = sprintf( __( 'GHN request to %1$s failed (HTTP %2$d).', 'epic-ghn-shipping' ), $path, $http_code );
			}
			// Environment mismatch is the single most common cause of a
			// "token not recognized"-style GHN error: sandbox and production
			// tokens belong to entirely separate GHN accounts, so a valid
			// token for one gateway reads as invalid on the other. Surface
			// which gateway was actually called so that's easy to spot.
			$message .= ' ' . sprintf(
				/* translators: %s: API base URL that was called */
				__( '(Called %s — double check this token belongs to that environment.)', 'epic-ghn-shipping' ),
				self::api_base()
			);

			return new WP_Error( 'epic_ghn_api', $message, $json );
		}

		return isset( $json['data'] ) ? $json['data'] : $json;
	}

	/**
	 * GHN's `message` field is usually a string, but validation-style errors
	 * sometimes come back as an array of strings — normalize either shape
	 * into one readable string instead of dumping "Array" into the UI.
	 */
	private static function extract_error_message( $json ) {
		if ( ! is_array( $json ) || empty( $json['message'] ) ) {
			return null;
		}
		if ( is_array( $json['message'] ) ) {
			return implode( ' ', array_map( 'strval', $json['message'] ) );
		}
		return (string) $json['message'];
	}

	// ------------------------------------------------------------------
	// Master data (address lookups) — cached for 12 hours. This data changes
	// rarely, and both the settings screen's from-address picker and the
	// order meta box's address resolver/override picker call these on every
	// page load; caching keeps the order edit screen from waiting on 1-3
	// GHN round-trips every time it opens.
	// ------------------------------------------------------------------

	const MASTER_DATA_CACHE_TTL = 12 * HOUR_IN_SECONDS;

	private static function cached_request( $cache_key, $path, $method, $body = null, $requires_shop_id = false ) {
		$settings   = self::get_settings();
		$full_key   = 'epic_ghn_' . $settings['environment'] . '_' . $cache_key;
		$cached     = get_transient( $full_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$result = self::request( $path, $method, $body, $requires_shop_id );
		if ( is_wp_error( $result ) ) {
			return $result; // Never cache failures.
		}

		set_transient( $full_key, $result, self::MASTER_DATA_CACHE_TTL );
		return $result;
	}

	// Master-data lookups only require Token per GHN's own docs (verified
	// against api.ghn.vn/home/docs/detail?id=60/91) — no Shop ID needed, so
	// these work even before a store has filled in Shop ID.
	public static function get_provinces() {
		return self::cached_request( 'provinces', '/master-data/province', 'GET' );
	}

	public static function get_districts( $province_id ) {
		$province_id = (int) $province_id;
		return self::cached_request(
			'districts_' . $province_id,
			'/master-data/district',
			'POST',
			array( 'province_id' => $province_id )
		);
	}

	public static function get_wards( $district_id ) {
		$district_id = (int) $district_id;
		return self::cached_request(
			'wards_' . $district_id,
			'/master-data/ward',
			'POST',
			array( 'district_id' => $district_id )
		);
	}

	// ------------------------------------------------------------------
	// Fee + shipment lifecycle
	// ------------------------------------------------------------------

	/**
	 * @param array $args {
	 *   @type int    $to_district_id
	 *   @type string $to_ward_code
	 *   @type int    $weight_g       Total parcel weight in grams.
	 *   @type int    $insurance_value Declared value for insurance/fee tiers.
	 * }
	 */
	public static function calculate_fee( $args ) {
		$settings = self::get_settings();

		return self::request(
			'/v2/shipping-order/fee',
			'POST',
			array(
				'shop_id'         => (int) $settings['shop_id'],
				'service_type_id' => (int) $settings['service_type_id'],
				'to_district_id'  => (int) $args['to_district_id'],
				'to_ward_code'    => (string) $args['to_ward_code'],
				'weight'          => max( (int) $args['weight_g'], 1 ),
				'insurance_value' => (int) $args['insurance_value'],
			)
		);
	}

	/** GHN payment_type_id: who pays the shipper. 2 = recipient (COD). 1 = shop/seller (prepaid orders — nothing to collect on delivery). */
	const PAYMENT_TYPE_COD     = 2;
	const PAYMENT_TYPE_PREPAID = 1;

	/**
	 * @param array $args {
	 *   @type string $to_name
	 *   @type string $to_phone
	 *   @type string $to_address
	 *   @type int    $to_district_id
	 *   @type string $to_ward_code
	 *   @type int    $payment_type_id    self::PAYMENT_TYPE_COD or self::PAYMENT_TYPE_PREPAID.
	 *                                    Defaults to PAYMENT_TYPE_COD if omitted, matching this
	 *                                    method's original (COD-only) behavior.
	 *   @type int    $cod_amount         Cash the shipper collects on delivery. Must be 0 when
	 *                                    $payment_type_id is PAYMENT_TYPE_PREPAID — GHN otherwise
	 *                                    collects money from a customer who already paid.
	 *   @type int    $insurance_value
	 *   @type int    $weight_g
	 *   @type int    $length_cm
	 *   @type int    $width_cm
	 *   @type int    $height_cm
	 *   @type array  $items          [{ name, quantity }]
	 *   @type string $note
	 *   @type string $client_order_code  Optional idempotency key — pass the
	 *                                    WooCommerce order number so retrying
	 *                                    a failed request never double-books.
	 * }
	 * @return array|WP_Error { order_code, total_fee, expected_delivery_time }
	 */
	public static function create_shipment( $args ) {
		$settings = self::get_settings();

		if ( empty( $settings['from_district_id'] ) || empty( $settings['from_ward_code'] ) ) {
			return new WP_Error(
				'epic_ghn_config',
				__( 'Set a pickup ("from") address under WooCommerce → Settings → GHN Shipping before booking shipments.', 'epic-ghn-shipping' )
			);
		}

		$payment_type_id = isset( $args['payment_type_id'] ) ? (int) $args['payment_type_id'] : self::PAYMENT_TYPE_COD;

		$body = array(
			'payment_type_id'  => $payment_type_id,
			'service_type_id'  => (int) $settings['service_type_id'],
			'required_note'    => 'KHONGCHOXEMHANG', // Recipient cannot open the parcel before paying.
			'from_name'          => $settings['from_name'],
			'from_phone'         => $settings['from_phone'],
			'from_address'       => $settings['from_address'],
			'from_ward_name'     => $settings['from_ward_name'],
			'from_district_name' => $settings['from_district_name'],
			'from_province_name' => $settings['from_province_name'],
			'to_name'          => $args['to_name'],
			'to_phone'         => $args['to_phone'],
			'to_address'       => $args['to_address'],
			'to_district_id'   => (int) $args['to_district_id'],
			'to_ward_code'     => (string) $args['to_ward_code'],
			'cod_amount'       => (int) round( $args['cod_amount'] ),
			'insurance_value'  => (int) round( $args['insurance_value'] ),
			'weight'           => max( (int) $args['weight_g'], 1 ),
			'length'           => ! empty( $args['length_cm'] ) ? (int) $args['length_cm'] : (int) $settings['default_length_cm'],
			'width'            => ! empty( $args['width_cm'] ) ? (int) $args['width_cm'] : (int) $settings['default_width_cm'],
			'height'           => ! empty( $args['height_cm'] ) ? (int) $args['height_cm'] : (int) $settings['default_height_cm'],
			'note'             => isset( $args['note'] ) ? $args['note'] : '',
			'items'            => array_map(
				function ( $item ) {
					return array(
						'name'     => $item['name'],
						'quantity' => (int) $item['quantity'],
					);
				},
				$args['items']
			),
		);

		if ( ! empty( $args['client_order_code'] ) ) {
			$body['client_order_code'] = (string) $args['client_order_code'];
		}

		return self::request( '/v2/shipping-order/create', 'POST', $body );
	}

	/** Full order detail + status log by GHN order_code. */
	public static function get_order_detail( $order_code ) {
		return self::request(
			'/v2/shipping-order/detail',
			'POST',
			array( 'order_code' => (string) $order_code )
		);
	}

	/** Cancels one or more GHN shipments. Cancelling one code cancels that whole physical parcel. */
	public static function cancel_shipments( array $order_codes ) {
		return self::request(
			'/v2/switch-status/cancel',
			'POST',
			array( 'order_codes' => array_values( array_map( 'strval', $order_codes ) ) )
		);
	}

	/** Corrects the COD amount on an already-created shipment. Max 5,000,000 VND per GHN's own limit. */
	public static function update_cod( $order_code, $cod_amount ) {
		return self::request(
			'/v2/shipping-order/updateCOD',
			'POST',
			array(
				'order_code' => (string) $order_code,
				'cod_amount' => (int) round( $cod_amount ),
			)
		);
	}

	/**
	 * Generates a 30-minute print token for one or more shipments.
	 * @return string|WP_Error The token, or an error.
	 */
	public static function gen_print_token( array $order_codes ) {
		$data = self::request(
			'/v2/a5/gen-token',
			'POST',
			array( 'order_codes' => array_values( array_map( 'strval', $order_codes ) ) )
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( empty( $data['token'] ) ) {
			return new WP_Error( 'epic_ghn_api', __( 'GHN did not return a print token.', 'epic-ghn-shipping' ) );
		}

		return $data['token'];
	}

	/**
	 * @param string $token GHN print token from gen_print_token().
	 * @param string $size  'A5' | '80x80' | '52x70'.
	 */
	public static function print_url( $token, $size = 'A5' ) {
		$paths = array(
			'A5'    => '/a5/public-api/printA5',
			'80x80' => '/a5/public-api/print80x80',
			'52x70' => '/a5/public-api/print52x70',
		);
		$path = isset( $paths[ $size ] ) ? $paths[ $size ] : $paths['A5'];

		return self::print_base() . $path . '?token=' . rawurlencode( $token );
	}
}
