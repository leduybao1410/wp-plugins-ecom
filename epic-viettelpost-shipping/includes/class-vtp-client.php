<?php
/**
 * Thin wrapper over ViettelPost's public Open API (v2 order/category
 * endpoints plus the v3 post-merger category endpoints).
 *
 * Mirrors the shape of the EPIC GHN Shipping Manager's client so anyone who
 * has read that file already understands this one: same "return the decoded
 * payload or a WP_Error" contract, credentials read from the plugin's
 * WooCommerce settings rather than process.env.
 *
 * Endpoint paths, request bodies and the response envelope below were
 * verified against the partner Open API docs at
 * https://partner2.viettelpost.vn/overview (and the partnerdev sandbox) on
 * 2026-09-22. ViettelPost's response envelope is
 * {status, error, message, data} — NOT GHN's {code, message, data} — and an
 * error is signalled by error=true (status 202/204) rather than a non-200
 * HTTP code, so both are checked.
 *
 * @package Epic_ViettelPost_Shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_VTP_Client {

	/** Domestic price list — the only TYPE this plugin uses. */
	const PRICE_TYPE_DOMESTIC = 1;

	/** ORDER_PAYMENT codes (who the courier collects from, and for what). */
	const ORDER_PAYMENT_NONE            = 1; // Không thu hộ.
	const ORDER_PAYMENT_GOODS_AND_FEE   = 2; // Thu hộ tiền hàng + tiền cước.
	const ORDER_PAYMENT_GOODS_ONLY      = 3; // Thu hộ tiền hàng.
	const ORDER_PAYMENT_FEE_ONLY        = 4; // Thu hộ tiền cước.

	/** PRODUCT_TYPE codes. */
	const PRODUCT_TYPE_GOODS = 'HH'; // Hàng.
	const PRODUCT_TYPE_MAIL  = 'TH'; // Thư.

	/** UpdateOrder TYPE codes (document.table.update_type). */
	const UPDATE_APPROVE    = 1; // Duyệt đơn hàng.
	const UPDATE_APPROVE_RETURN = 2; // Duyệt hoàn.
	const UPDATE_REDELIVER  = 3; // Phát tiếp.
	const UPDATE_CANCEL     = 4; // Hủy đơn hàng.
	const UPDATE_DELETE     = 5; // Xóa đơn hàng.

	/**
	 * @return array Plugin settings, one `epic_vtp_*` wp_option per field (the
	 *               same convention WooCommerce's own settings pages use).
	 */
	public static function get_settings() {
		return array(
			'environment'           => get_option( 'epic_vtp_environment', 'sandbox' ),
			'token'                 => trim( (string) get_option( 'epic_vtp_token', '' ) ),
			'username'              => trim( (string) get_option( 'epic_vtp_username', '' ) ),
			'password'              => (string) get_option( 'epic_vtp_password', '' ),
			'from_name'             => get_option( 'epic_vtp_from_name', '' ),
			'from_phone'            => get_option( 'epic_vtp_from_phone', '' ),
			'from_address'          => get_option( 'epic_vtp_from_address', '' ),
			'from_province_id'      => get_option( 'epic_vtp_from_province_id', '' ),
			'from_province_name'    => get_option( 'epic_vtp_from_province_name', '' ),
			'from_ward_id'          => get_option( 'epic_vtp_from_ward_id', '' ),
			'from_ward_name'        => get_option( 'epic_vtp_from_ward_name', '' ),
			'default_service'       => get_option( 'epic_vtp_default_service', '' ),
			'default_length_cm'     => get_option( 'epic_vtp_default_length_cm', 20 ),
			'default_width_cm'      => get_option( 'epic_vtp_default_width_cm', 15 ),
			'default_height_cm'     => get_option( 'epic_vtp_default_height_cm', 10 ),
			'default_item_weight_g' => get_option( 'epic_vtp_default_item_weight_g', 250 ),
			'webhook_secret'        => trim( (string) get_option( 'epic_vtp_webhook_secret', '' ) ),
		);
	}

	private static function base_url() {
		$settings = self::get_settings();
		return 'sandbox' === $settings['environment']
			? 'https://partnerdev.viettelpost.vn'
			: 'https://partner.viettelpost.vn';
	}

	public static function is_configured() {
		$settings = self::get_settings();
		return '' !== self::resolve_token( $settings );
	}

	/**
	 * Verifies the configured credentials end-to-end: resolves a token (which
	 * exercises the username/password login flow when used) and makes one
	 * AUTHENTICATED call that does not depend on the pickup address being set.
	 *
	 * Uses /v2/user/listInventory: it requires a valid token (unlike the
	 * public category endpoints, which would give a false positive) and
	 * doesn't need a sender address, so it isolates "is the token good?"
	 * from "is the pickup address configured?".
	 *
	 * @return array|WP_Error { count, mode } where mode is 'token' or 'login'.
	 */
	public static function test_connection() {
		$settings = self::get_settings();
		$token    = self::resolve_token( $settings );

		if ( '' === $token ) {
			return new WP_Error(
				'epic_vtp_auth',
				__( 'Could not obtain a ViettelPost token. Check the Token field, or the username/password fallback.', 'epic-viettelpost-shipping' )
			);
		}

		$inventory = self::list_inventory();
		if ( is_wp_error( $inventory ) ) {
			return $inventory;
		}

		$mode = ( '' !== $settings['token'] ) ? 'token' : 'login';

		return array(
			'count' => is_array( $inventory ) ? count( $inventory ) : 0,
			'mode'  => $mode,
		);
	}

	/**
	 * Whether an order should be booked as COD (ViettelPost collects cash on
	 * delivery) vs. prepaid (nothing to collect — already paid online).
	 * Decided strictly from the order's payment_method, never a staff judgment
	 * call at booking time. The storefront's checkout only ever sets 'cod'
	 * (pay on receive) or 'sepay' (bank transfer confirmed before the order
	 * existed); only 'cod' should collect cash at the door, so a SePay order
	 * billing the customer again would be a double-charge. Any other/
	 * unrecognized method defaults to COD — under-collecting is far easier to
	 * fix after the fact than asking a customer to pay twice.
	 */
	public static function is_cod_order( WC_Order $order ) {
		return 'sepay' !== $order->get_payment_method();
	}

	/**
	 * Buckets a raw ViettelPost numeric order status into the small set of
	 * staff-facing stages the Orders list "Shipment" column acts on. See
	 * self::status_label() for the full 24-code vocabulary.
	 *
	 * @return array { label: string, css_class: string, raw: string }
	 */
	public static function bucket_status( $raw_status ) {
		$raw_status = (string) $raw_status;

		$buckets = array(
			'created'    => array( '', '102' ),
			'pending'    => array( '103', '104', '105', '200', '202' ),
			'delivering' => array( '300', '400', '500', '508', '509', '550' ),
			'done'       => array( '501' ),
			'cancelled'  => array( '101', '107', '201', '503' ),
			'issue'      => array( '506', '507' ),
			'returning'  => array( '502', '505', '515' ),
			'returned'   => array( '504' ),
		);

		$labels = array(
			'created'    => __( 'Created', 'epic-viettelpost-shipping' ),
			'pending'    => __( 'Pending pickup', 'epic-viettelpost-shipping' ),
			'delivering' => __( 'Delivering', 'epic-viettelpost-shipping' ),
			'done'       => __( 'Delivered', 'epic-viettelpost-shipping' ),
			'cancelled'  => __( 'Cancelled', 'epic-viettelpost-shipping' ),
			'issue'      => __( 'Delivery issue', 'epic-viettelpost-shipping' ),
			'returning'  => __( 'Returning', 'epic-viettelpost-shipping' ),
			'returned'   => __( 'Returned', 'epic-viettelpost-shipping' ),
		);

		foreach ( $buckets as $key => $codes ) {
			if ( in_array( $raw_status, $codes, true ) ) {
				return array(
					'label'     => $labels[ $key ],
					'css_class' => 'epic-vtp-status-' . $key,
					'raw'       => $raw_status,
				);
			}
		}

		return array(
			'label'     => self::status_label( $raw_status ),
			'css_class' => 'epic-vtp-status-unknown',
			'raw'       => $raw_status,
		);
	}

	/**
	 * Human-readable label for a numeric ViettelPost ORDER_STATUS code.
	 * Codes 101..550 map to the 24 documented statuses; an empty/unknown code
	 * is shown as "Created" (the shipment exists and nothing has happened
	 * yet) or the raw code respectively.
	 */
	public static function status_label( $code ) {
		$code = (string) $code;

		$map = array(
			'101' => __( 'ViettelPost requested cancellation', 'epic-viettelpost-shipping' ),
			'102' => __( 'Awaiting processing', 'epic-viettelpost-shipping' ),
			'103' => __( 'Handed to post office', 'epic-viettelpost-shipping' ),
			'104' => __( 'Assigned to pickup courier', 'epic-viettelpost-shipping' ),
			'105' => __( 'Courier picked up', 'epic-viettelpost-shipping' ),
			'107' => __( 'Partner cancelled via API', 'epic-viettelpost-shipping' ),
			'200' => __( 'Received at origin post office', 'epic-viettelpost-shipping' ),
			'201' => __( 'Waybill cancelled', 'epic-viettelpost-shipping' ),
			'202' => __( 'Waybill edited', 'epic-viettelpost-shipping' ),
			'300' => __( 'Departed origin', 'epic-viettelpost-shipping' ),
			'400' => __( 'Arrived at destination hub', 'epic-viettelpost-shipping' ),
			'500' => __( 'Out for delivery', 'epic-viettelpost-shipping' ),
			'501' => __( 'Delivered', 'epic-viettelpost-shipping' ),
			'502' => __( 'Returned to origin post office', 'epic-viettelpost-shipping' ),
			'503' => __( 'Cancelled at customer request', 'epic-viettelpost-shipping' ),
			'504' => __( 'Returned to sender', 'epic-viettelpost-shipping' ),
			'505' => __( 'Return pending confirmation', 'epic-viettelpost-shipping' ),
			'506' => __( 'Undeliverable — recipient unavailable', 'epic-viettelpost-shipping' ),
			'507' => __( 'Held — recipient to collect at post office', 'epic-viettelpost-shipping' ),
			'508' => __( 'Re-attempted delivery', 'epic-viettelpost-shipping' ),
			'509' => __( 'Transferred to another post office', 'epic-viettelpost-shipping' ),
			'515' => __( 'Return approved', 'epic-viettelpost-shipping' ),
			'550' => __( 'Redelivery requested by customer', 'epic-viettelpost-shipping' ),
		);

		if ( '' === $code ) {
			return __( 'Created', 'epic-viettelpost-shipping' );
		}

		return isset( $map[ $code ] ) ? $map[ $code ] : sprintf(
			/* translators: %s: raw numeric status code */
			__( 'Status %s', 'epic-viettelpost-shipping' ),
			$code
		);
	}

	/**
	 * Resolves the bearer token to send as the `Token` header.
	 *
	 * 1. The stored long-lived token, if set (the normal case — a token
	 *    generated at viettelpost.vn → Cấu hình tài khoản, or the 1-year token
	 *    minted below).
	 * 2. Otherwise username/password, following the documented two-step flow:
	 *    POST /v2/user/Login for a short-lived token, then POST
	 *    /v2/user/ownerconnect with that short-lived token in the `Token`
	 *    header and the same credentials in the body, for a long-lived
	 *    (~1 year) token. If ownerconnect fails, the short-lived token is used
	 *    as-is.
	 *
	 * Tokens are cached in a transient so an admin page load doesn't perform a
	 * login round-trip every time.
	 */
	private static function resolve_token( array $settings ) {
		if ( '' !== $settings['token'] ) {
			return $settings['token'];
		}

		if ( '' === $settings['username'] || '' === $settings['password'] ) {
			return '';
		}

		$cache_key = 'epic_vtp_login_' . $settings['environment'] . '_' . md5( $settings['username'] );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$credentials = array(
			'USERNAME' => $settings['username'],
			'PASSWORD' => $settings['password'],
		);

		// Step 1: short-lived token.
		$login = self::raw_request( '/v2/user/Login', 'POST', $credentials, '' );
		if ( is_wp_error( $login ) || empty( $login['data']['token'] ) ) {
			return '';
		}
		$short_token = (string) $login['data']['token'];

		// Step 2: exchange it for the long-lived owner token.
		$owner = self::raw_request( '/v2/user/ownerconnect', 'POST', $credentials, $short_token );
		if ( ! is_wp_error( $owner ) && ! empty( $owner['data']['token'] ) ) {
			$token = (string) $owner['data']['token'];
			set_transient( $cache_key, $token, 30 * DAY_IN_SECONDS );
			return $token;
		}

		// Fallback: the short-lived login token.
		set_transient( $cache_key, $short_token, 20 * MINUTE_IN_SECONDS );
		return $short_token;
	}

	/**
	 * @param string $path Path under the API host, e.g. "/v2/order/createOrder".
	 * @param string $method GET|POST.
	 * @param array|null $body JSON body.
	 * @param string $token Bearer token ('' to resolve from settings).
	 * @return array|WP_Error The full decoded envelope {status,error,message,data}.
	 */
	private static function raw_request( $path, $method = 'GET', $body = null, $token = null ) {
		$settings = self::get_settings();

		if ( null === $token ) {
			$token = self::resolve_token( $settings );
			if ( '' === $token ) {
				return new WP_Error(
					'epic_vtp_config',
					__( 'A ViettelPost Token (or username/password) must be set under WooCommerce → Settings → ViettelPost Shipping before this action can run.', 'epic-viettelpost-shipping' )
				);
			}
		}

		$headers = array( 'Content-Type' => 'application/json' );
		// An explicitly empty token means "anonymous" (the login endpoint) —
		// only omit the header in that case, never for a resolved-but-missing
		// credential (that's handled above).
		if ( '' !== $token ) {
			$headers['Token'] = $token;
		}

		$args = array(
			'method'  => $method,
			'timeout' => 25,
			'headers' => $headers,
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::base_url() . $path, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$json      = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $json ) ) {
			return new WP_Error(
				'epic_vtp_api',
				sprintf(
					/* translators: 1: API path, 2: HTTP status code */
					__( 'ViettelPost request to %1$s returned an unreadable response (HTTP %2$d).', 'epic-viettelpost-shipping' ),
					$path,
					$http_code
				)
			);
		}

		// ViettelPost signals failure with error=true (often on an HTTP 200),
		// so the envelope's own flag is authoritative, not the HTTP status.
		$is_error = ! empty( $json['error'] );

		if ( $is_error || $http_code < 200 || $http_code >= 300 ) {
			$message = self::extract_error_message( $json );
			if ( '' === $message ) {
				$message = sprintf(
					/* translators: 1: API path, 2: HTTP status code */
					__( 'ViettelPost request to %1$s failed (HTTP %2$d).', 'epic-viettelpost-shipping' ),
					$path,
					$http_code
				);
			}
			$message .= ' ' . sprintf(
				/* translators: %s: API base URL that was called */
				__( '(Called %s — double check the token and environment.)', 'epic-viettelpost-shipping' ),
				self::base_url()
			);
			return new WP_Error( 'epic_vtp_api', $message, $json );
		}

		return $json;
	}

	/**
	 * Returns just the `data` payload of a successful envelope.
	 *
	 * @return mixed|WP_Error
	 */
	private static function request( $path, $method = 'GET', $body = null ) {
		$json = self::raw_request( $path, $method, $body );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		return array_key_exists( 'data', $json ) ? $json['data'] : $json;
	}

	/**
	 * ViettelPost's `message` is usually a string but validation-style errors
	 * can arrive as an array of strings — normalize either shape into one
	 * readable string instead of dumping "Array" into the UI.
	 */
	private static function extract_error_message( $json ) {
		if ( ! is_array( $json ) || empty( $json['message'] ) ) {
			return '';
		}
		if ( is_array( $json['message'] ) ) {
			return implode( ' ', array_map( 'strval', $json['message'] ) );
		}
		return (string) $json['message'];
	}

	// ------------------------------------------------------------------
	// Master data (address lookups) — cached for 12 hours. This data changes
	// rarely, and both the settings screen's from-address picker and the
	// order meta box's province resolver call these on every page load.
	// ------------------------------------------------------------------

	const MASTER_DATA_CACHE_TTL = 12 * HOUR_IN_SECONDS;

	private static function cached_request( $cache_key, $path, $method, $body = null ) {
		$settings = self::get_settings();
		$full_key = 'epic_vtp_' . $settings['environment'] . '_' . $cache_key;
		$cached   = get_transient( $full_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$result = self::request( $path, $method, $body );
		if ( is_wp_error( $result ) ) {
			return $result; // Never cache failures.
		}

		set_transient( $full_key, $result, self::MASTER_DATA_CACHE_TTL );
		return $result;
	}

	/** New-format (post-2025-merger) provinces — /v3/categories/listProvinceNew. */
	public static function get_provinces_new() {
		return self::cached_request( 'provinces_new', '/v3/categories/listProvinceNew', 'GET' );
	}

	/** New-format wards for a province — /v3/categories/listWardsNew?provinceId=. */
	public static function get_wards_new( $province_id ) {
		$province_id = (int) $province_id;
		return self::cached_request(
			'wards_new_' . $province_id,
			'/v3/categories/listWardsNew?provinceId=' . $province_id,
			'GET'
		);
	}

	/** Old-format (pre-merger) provinces — /v2/categories/listProvince. */
	public static function get_provinces_old() {
		return self::cached_request( 'provinces_old', '/v2/categories/listProvince', 'GET' );
	}

	/** Old-format districts for a province — /v2/categories/listDistrict?provinceId=. */
	public static function get_districts_old( $province_id ) {
		$province_id = (int) $province_id;
		return self::cached_request(
			'districts_old_' . $province_id,
			'/v2/categories/listDistrict?provinceId=' . $province_id,
			'GET'
		);
	}

	/** Old-format wards for a district — /v2/categories/listWards?districtId=. */
	public static function get_wards_old( $district_id ) {
		$district_id = (int) $district_id;
		return self::cached_request(
			'wards_old_' . $district_id,
			'/v2/categories/listWards?districtId=' . $district_id,
			'GET'
		);
	}

	// ------------------------------------------------------------------
	// Fee + shipment lifecycle
	// ------------------------------------------------------------------

	/**
	 * Fee by address ID — POST /v2/order/getPriceAll.
	 *
	 * @param array $args {
	 *   @type int    $sender_province_id
	 *   @type int    $sender_district_id
	 *   @type int    $sender_ward_id
	 *   @type int    $receiver_province_id
	 *   @type int    $receiver_district_id
	 *   @type int    $receiver_ward_id
	 *   @type string $product_type  self::PRODUCT_TYPE_GOODS|MAIL.
	 *   @type int    $weight_g
	 *   @type int    $product_price
	 *   @type int    $money_collection
	 *   @type int    $length_cm
	 *   @type int    $width_cm
	 *   @type int    $height_cm
	 * }
	 * @return array|WP_Error List of service options (MA_DV_CHINH, GIA_CUOC, …).
	 */
	public static function get_price_all( $args ) {
		$settings = self::get_settings();

		return self::extract_services(
			self::request(
				'/v2/order/getPriceAll',
				'POST',
				array(
					'SENDER_PROVINCE'    => (int) $args['sender_province_id'],
					'SENDER_DISTRICT'    => (int) $args['sender_district_id'],
					'SENDER_WARD'        => (int) $args['sender_ward_id'],
					'RECEIVER_PROVINCE'  => (int) $args['receiver_province_id'],
					'RECEIVER_DISTRICT'  => (int) $args['receiver_district_id'],
					'RECEIVER_WARD'      => (int) $args['receiver_ward_id'],
					'PRODUCT_TYPE'       => ! empty( $args['product_type'] ) ? $args['product_type'] : self::PRODUCT_TYPE_GOODS,
					'PRODUCT_WEIGHT'     => max( (int) $args['weight_g'], 1 ),
					'PRODUCT_PRICE'      => (int) $args['product_price'],
					'MONEY_COLLECTION'   => (string) (int) $args['money_collection'],
					'PRODUCT_LENGTH'     => (int) $args['length_cm'],
					'PRODUCT_WIDTH'      => (int) $args['width_cm'],
					'PRODUCT_HEIGHT'     => (int) $args['height_cm'],
					'TYPE'               => self::PRICE_TYPE_DOMESTIC,
				)
			)
		);
	}

	/**
	 * Fee by free-text address — POST /v2/order/getPriceAllNlp. This is the
	 * primary fee path: it only needs the receiver's province ID plus the full
	 * address strings, so it works without resolving district/ward codes.
	 *
	 * @return array|WP_Error List of service options.
	 */
	public static function get_price_all_nlp( $args ) {
		$settings = self::get_settings();

		return self::extract_services(
			self::request(
				'/v2/order/getPriceAllNlp',
				'POST',
				array(
					'SENDER_ADDRESS'   => ! empty( $args['sender_address'] ) ? $args['sender_address'] : (string) $settings['from_address'],
					'RECEIVER_ADDRESS' => (string) $args['receiver_address'],
					'RECEIVER_PROVINCE' => (int) $args['receiver_province_id'],
					'PRODUCT_TYPE'     => ! empty( $args['product_type'] ) ? $args['product_type'] : self::PRODUCT_TYPE_GOODS,
					'PRODUCT_WEIGHT'   => max( (int) $args['weight_g'], 1 ),
					'PRODUCT_PRICE'    => (int) $args['product_price'],
					'MONEY_COLLECTION' => (string) (int) $args['money_collection'],
					'PRODUCT_LENGTH'   => (int) $args['length_cm'],
					'PRODUCT_WIDTH'    => (int) $args['width_cm'],
					'PRODUCT_HEIGHT'   => (int) $args['height_cm'],
					'TYPE'             => self::PRICE_TYPE_DOMESTIC,
				)
			)
		);
	}

	/**
	 * Normalizes a fee response into a flat list of service rows. The
	 * getPriceAll / getPriceAllNlp endpoints return
	 * { SENDER_ADDRESS, RECEIVER_ADDRESS, RESULT: [ {MA_DV_CHINH, GIA_CUOC, …} ] },
	 * so the bookable services live under RESULT, not at the top level.
	 *
	 * @return array|WP_Error
	 */
	private static function extract_services( $data ) {
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( is_array( $data ) && isset( $data['RESULT'] ) && is_array( $data['RESULT'] ) ) {
			return $data['RESULT'];
		}
		return $data;
	}

	/**
	 * Picks the main service code (MA_DV_CHINH) to book with, from a
	 * getPriceAll / getPriceAllNlp result list. Prefers the configured default
	 * service if it's present, otherwise the cheapest quoted service.
	 *
	 * @return string|WP_Error
	 */
	public static function choose_service( $services ) {
		if ( is_wp_error( $services ) ) {
			return $services;
		}
		if ( ! is_array( $services ) || empty( $services ) ) {
			return new WP_Error( 'epic_vtp_no_service', __( 'ViettelPost returned no available service for this route.', 'epic-viettelpost-shipping' ) );
		}

		$settings = self::get_settings();
		$preferred = $settings['default_service'];

		if ( '' !== $preferred ) {
			foreach ( $services as $service ) {
				if ( isset( $service['MA_DV_CHINH'] ) && (string) $service['MA_DV_CHINH'] === (string) $preferred ) {
					return (string) $service['MA_DV_CHINH'];
				}
			}
		}

		$cheapest      = null;
		$cheapest_fee  = null;
		foreach ( $services as $service ) {
			if ( empty( $service['MA_DV_CHINH'] ) || ! isset( $service['GIA_CUOC'] ) ) {
				continue;
			}
			$fee = (int) $service['GIA_CUOC'];
			if ( null === $cheapest_fee || $fee < $cheapest_fee ) {
				$cheapest_fee = $fee;
				$cheapest     = (string) $service['MA_DV_CHINH'];
			}
		}

		if ( null === $cheapest ) {
			return new WP_Error( 'epic_vtp_no_service', __( 'ViettelPost returned no bookable service for this route.', 'epic-viettelpost-shipping' ) );
		}

		return $cheapest;
	}

	/**
	 * Books a shipment by address ID — POST /v2/order/createOrder.
	 *
	 * @param array $args See get_price_all() plus to_name/to_phone/to_address,
	 *                    sender fields, order_service, order_payment,
	 *                    money_collection, items, note, order_number.
	 * @return array|WP_Error { ORDER_NUMBER, … }
	 */
	public static function create_order( $args ) {
		$settings = self::get_settings();

		if ( empty( $settings['from_address'] ) || empty( $settings['from_province_id'] ) ) {
			return new WP_Error(
				'epic_vtp_config',
				__( 'Set a pickup ("from") address under WooCommerce → Settings → ViettelPost Shipping before booking shipments.', 'epic-viettelpost-shipping' )
			);
		}

		$body = array(
			'ORDER_NUMBER'      => (string) $args['order_number'],
			'CHECK_UNIQUE'      => true,
			'ORDER_TYPE'        => 1,
			'TYPE'              => 1,
			'SENDER_FULLNAME'   => $settings['from_name'],
			'SENDER_PHONE'      => $settings['from_phone'],
			'SENDER_ADDRESS'    => $settings['from_address'],
			'SENDER_PROVINCE'   => (int) $settings['from_province_id'],
			'SENDER_DISTRICT'   => null,
			'SENDER_WARD'       => (int) $settings['from_ward_id'],
			'RECEIVER_FULLNAME' => $args['to_name'],
			'RECEIVER_PHONE'    => $args['to_phone'],
			'RECEIVER_ADDRESS'  => $args['to_address'],
			'RECEIVER_PROVINCE' => (int) $args['receiver_province_id'],
			'RECEIVER_DISTRICT' => null,
			'RECEIVER_WARD'     => (int) $args['receiver_ward_id'],
			'PRODUCT_NAME'      => $args['product_name'],
			'PRODUCT_QUANTITY'  => (int) $args['quantity'],
			'PRODUCT_PRICE'     => (int) $args['product_price'],
			'PRODUCT_WEIGHT'    => max( (int) $args['weight_g'], 1 ),
			'PRODUCT_LENGTH'    => (int) $args['length_cm'],
			'PRODUCT_WIDTH'     => (int) $args['width_cm'],
			'PRODUCT_HEIGHT'    => (int) $args['height_cm'],
			'PRODUCT_TYPE'      => self::PRODUCT_TYPE_GOODS,
			'ORDER_PAYMENT'     => (int) $args['order_payment'],
			'ORDER_SERVICE'     => (string) $args['order_service'],
			'ORDER_SERVICE_ADD' => '',
			'ORDER_VOUCHER'     => '',
			'ORDER_NOTE'        => isset( $args['note'] ) ? $args['note'] : '',
			'MONEY_COLLECTION'  => (int) $args['money_collection'],
			'EXTRA_MONEY'       => 0,
			'LIST_ITEM'         => self::normalize_items( $args['items'], 'LIST_ITEM' ),
			'ENABLE_SORT_CODE'  => false,
		);

		return self::request( '/v2/order/createOrder', 'POST', $body );
	}

	/**
	 * Books a shipment by free-text address — POST /v2/order/createOrderNlp.
	 * This is the primary booking path (no district/ward codes needed).
	 *
	 * @return array|WP_Error { ORDER_NUMBER, MONEY_TOTAL, … }
	 */
	public static function create_order_nlp( $args ) {
		$settings = self::get_settings();

		if ( empty( $settings['from_address'] ) ) {
			return new WP_Error(
				'epic_vtp_config',
				__( 'Set a pickup ("from") address under WooCommerce → Settings → ViettelPost Shipping before booking shipments.', 'epic-viettelpost-shipping' )
			);
		}

		$body = array(
			'ORDER_NUMBER'      => (string) $args['order_number'],
			'SENDER_FULLNAME'   => $settings['from_name'],
			'SENDER_ADDRESS'    => $settings['from_address'],
			'SENDER_PHONE'      => $settings['from_phone'],
			'RECEIVER_FULLNAME' => $args['to_name'],
			'RECEIVER_ADDRESS'  => $args['to_address'],
			'RECEIVER_PHONE'    => $args['to_phone'],
			'PRODUCT_NAME'      => $args['product_name'],
			'PRODUCT_QUANTITY'  => (int) $args['quantity'],
			'PRODUCT_PRICE'     => (int) $args['product_price'],
			'PRODUCT_WEIGHT'    => max( (int) $args['weight_g'], 1 ),
			'PRODUCT_LENGTH'    => (int) $args['length_cm'],
			'PRODUCT_WIDTH'     => (int) $args['width_cm'],
			'PRODUCT_HEIGHT'    => (int) $args['height_cm'],
			'ORDER_PAYMENT'     => (int) $args['order_payment'],
			'ORDER_SERVICE'     => (string) $args['order_service'],
			'ORDER_SERVICE_ADD' => null,
			'PRODUCT_TYPE'      => self::PRODUCT_TYPE_GOODS,
			'ORDER_NOTE'        => isset( $args['note'] ) ? $args['note'] : '',
			'MONEY_COLLECTION'  => (int) $args['money_collection'],
			'EXTRA_MONEY'       => 0,
			'CHECK_UNIQUE'      => true,
			'PRODUCT_DETAIL'    => self::normalize_items( $args['items'], 'PRODUCT_DETAIL' ),
			'ENABLE_SORT_CODE'  => false,
		);

		return self::request( '/v2/order/createOrderNlp', 'POST', $body );
	}

	/**
	 * Updates an existing order's info — POST /v2/order/edit.
	 */
	public static function edit_order( $args ) {
		return self::request( '/v2/order/edit', 'POST', $args );
	}

	/**
	 * Changes an order's status — POST /v2/order/UpdateOrder.
	 *
	 * @param string $order_number VTP order code (ORDER_NUMBER).
	 * @param int    $type         self::UPDATE_* constant.
	 * @param string $note         Reason (max 150 chars).
	 */
	public static function update_status( $order_number, $type, $note = '' ) {
		return self::request(
			'/v2/order/UpdateOrder',
			'POST',
			array(
				'TYPE'         => (int) $type,
				'ORDER_NUMBER' => (string) $order_number,
				'NOTE'         => (string) $note,
			)
		);
	}

	/** Cancels a shipment (only valid while ORDER_STATUS < 200). */
	public static function cancel_order( $order_number, $note = '' ) {
		return self::update_status( $order_number, self::UPDATE_CANCEL, $note );
	}

	/**
	 * Generates a print token for one or more shipments —
	 * POST /v2/order/printing-code. ViettelPost returns the token in the
	 * envelope's `message` field (data is null).
	 *
	 * @return string|WP_Error
	 */
	public static function gen_print_token( array $order_numbers, $expiry_ms = null ) {
		if ( null === $expiry_ms ) {
			$expiry_ms = ( time() + DAY_IN_SECONDS ) * 1000;
		}

		$json = self::raw_request(
			'/v2/order/printing-code',
			'POST',
			array(
				'EXPIRY_TIME' => (int) $expiry_ms,
				'ORDER_ARRAY' => array_values( array_map( 'strval', $order_numbers ) ),
			)
		);

		if ( is_wp_error( $json ) ) {
			return $json;
		}

		if ( empty( $json['message'] ) ) {
			return new WP_Error( 'epic_vtp_api', __( 'ViettelPost did not return a print token.', 'epic-viettelpost-shipping' ) );
		}

		return (string) $json['message'];
	}

	/**
	 * @param string $token Print token from gen_print_token().
	 * @param string $type  Label type: '1' (A5), '2' (A6), 'a6_1', '100' (A7), '1001'.
	 */
	public static function print_url( $token, $type = '1' ) {
		return 'https://digitalize.viettelpost.vn/DigitalizePrint/report.do?type=' . rawurlencode( $type ) . '&bill=' . rawurlencode( $token ) . '&showPostage=1';
	}

	/** Lists the account's pickup warehouses — GET /v2/user/listInventory. */
	public static function list_inventory() {
		return self::request( '/v2/user/listInventory', 'GET' );
	}

	/**
	 * Normalizes a WooCommerce line-item list into the {PRODUCT_NAME,
	 * PRODUCT_QUANTITY, PRODUCT_PRICE, PRODUCT_WEIGHT} shape both create
	 * endpoints expect.
	 */
	private static function normalize_items( $items, $key ) {
		$out = array();
		foreach ( (array) $items as $item ) {
			$out[] = array(
				'PRODUCT_NAME'     => isset( $item['name'] ) ? $item['name'] : '',
				'PRODUCT_QUANTITY' => (int) ( isset( $item['quantity'] ) ? $item['quantity'] : 1 ),
				'PRODUCT_PRICE'    => (int) ( isset( $item['price'] ) ? $item['price'] : 0 ),
				'PRODUCT_WEIGHT'   => (int) ( isset( $item['weight_g'] ) ? $item['weight_g'] : 0 ),
			);
		}
		return $out;
	}
}
