<?php
/**
 * REST route the Next.js website's order-lookup page calls. The website's
 * server proxy (`src/app/api/order-lookup`) forwards a customer's order code
 * here with an `X-Epic-Secret` header; this endpoint decodes the code back to
 * an order ID and returns ONLY status + shipping/tracking info — never the
 * customer's name, address, phone, email, or line items. The code is the
 * credential (unguessable by design, see class-order-code.php), and the shared
 * secret prevents the endpoint being probed directly.
 *
 * Response shape (200):
 *   {
 *     found: true,
 *     order_number: "EPIC-ZPXE57",
 *     status: "processing",             // WooCommerce status slug
 *     date_created: "2026-08-23T10:00:00+07:00" | null,
 *     is_paid: bool,
 *     payment_method_title: "COD" | "...",
 *     cod_amount: 500000,               // amount due on delivery, only >0 for unpaid COD
 *     tracking_code: "L86BD7" | null,
 *     tracking_url: "https://donhang.ghn.vn/?order_code=..." | null,
 *     expected_delivery: "..." | null
 *   }
 * Not-found (unknown or invalid code): 404 { found: false }.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Order_Code_Lookup {

	const NAMESPACE = 'epic-order-codes/v1';

	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/lookup',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'lookup' ),
				'permission_callback' => array( __CLASS__, 'check_secret' ),
				'args'                => array(
					'code' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/** Constant-time comparison against the secret configured in WooCommerce → Order Codes. */
	public static function check_secret( \WP_REST_Request $request ) {
		$configured = Epic_Order_Code_Settings::get_shared_secret();
		if ( empty( $configured ) ) {
			return new \WP_Error( 'epic_order_codes_not_configured', 'Shared secret not configured.', array( 'status' => 500 ) );
		}
		$provided = $request->get_header( 'x-epic-secret' );
		if ( empty( $provided ) || ! hash_equals( $configured, $provided ) ) {
			return new \WP_Error( 'epic_order_codes_forbidden', 'Invalid or missing X-Epic-Secret header.', array( 'status' => 403 ) );
		}
		return true;
	}

	public static function lookup( \WP_REST_Request $request ) {
		$code = (string) $request->get_param( 'code' );

		$order_id = Epic_Order_Code::decode( $code );
		if ( ! $order_id ) {
			return self::not_found();
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || 'shop_order' !== $order->get_type() ) {
			return self::not_found();
		}

		// The code is the only thing the caller knows — nothing here (name,
		// address, phone, email, items) leaves this endpoint.
		$is_cod   = 'cod' === $order->get_payment_method();
		$is_paid  = $order->is_paid();
		$tracking = (string) $order->get_meta( '_ghn_order_code' );
		$eta      = (string) $order->get_meta( '_ghn_expected_delivery' );

		return new \WP_REST_Response(
			array(
				'found'               => true,
				'order_number'        => $order->get_order_number(),
				'status'              => $order->get_status(),
				'date_created'        => $order->get_date_created() ? $order->get_date_created()->format( 'c' ) : null,
				'is_paid'             => $is_paid,
				'payment_method_title' => (string) $order->get_payment_method_title(),
				'cod_amount'          => ( $is_cod && ! $is_paid ) ? (float) $order->get_total() : 0,
				'tracking_code'       => $tracking ? $tracking : null,
				'tracking_url'        => $tracking ? self::tracking_url( $tracking ) : null,
				'expected_delivery'   => $eta ? $eta : null,
			),
			200
		);
	}

	/** GHN public tracking link — same format as epic-order-emails uses; filterable. */
	private static function tracking_url( $tracking_code ) {
		$url = 'https://donhang.ghn.vn/?order_code=' . rawurlencode( $tracking_code );
		return apply_filters( 'epic_order_codes_ghn_tracking_url', $url, $tracking_code );
	}

	private static function not_found() {
		return new \WP_REST_Response( array( 'found' => false ), 404 );
	}
}
