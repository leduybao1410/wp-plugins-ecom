<?php
/**
 * REST routes the Next.js website's `src/lib/pendingOrders.ts` calls. Every
 * route is gated behind a shared secret (see class-settings.php) sent as an
 * `X-Epic-Secret` header — this plugin never trusts an unauthenticated
 * caller with checkout data or the ability to fabricate a "payment
 * completed" record.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Payment_Rest_Api {

	const NAMESPACE = 'epic-payment/v1';

	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/pending',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'store_pending' ),
				'permission_callback' => array( __CLASS__, 'check_secret' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/pending/(?P<order_id>[\w-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'peek_pending' ),
				'permission_callback' => array( __CLASS__, 'check_secret' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/pending/(?P<order_id>[\w-]+)/claim',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'claim_pending' ),
				'permission_callback' => array( __CLASS__, 'check_secret' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/completed/(?P<order_id>[\w-]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'store_completed' ),
				'permission_callback' => array( __CLASS__, 'check_secret' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/completed/(?P<order_id>[\w-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_completed' ),
				'permission_callback' => array( __CLASS__, 'check_secret' ),
			)
		);
	}

	/** Constant-time comparison against the secret configured in WooCommerce → Settings → Payment Store. */
	public static function check_secret( \WP_REST_Request $request ) {
		$configured = Epic_Payment_Settings::get_shared_secret();
		if ( empty( $configured ) ) {
			return new \WP_Error( 'epic_payment_not_configured', 'Shared secret not configured.', array( 'status' => 500 ) );
		}
		$provided = $request->get_header( 'x-epic-secret' );
		if ( empty( $provided ) || ! hash_equals( $configured, $provided ) ) {
			return new \WP_Error( 'epic_payment_forbidden', 'Invalid or missing X-Epic-Secret header.', array( 'status' => 403 ) );
		}
		return true;
	}

	public static function store_pending( \WP_REST_Request $request ) {
		$order_id = (string) $request->get_param( 'order_id' );
		$amount   = $request->get_param( 'amount' );
		$ttl      = $request->get_param( 'ttl_seconds' );
		$payload  = $request->get_param( 'payload' );

		if ( empty( $order_id ) || ! is_numeric( $amount ) || ! is_array( $payload ) ) {
			return new \WP_Error( 'epic_payment_bad_request', 'order_id, amount and payload are required.', array( 'status' => 400 ) );
		}

		Epic_Payment_Store::put_pending( $order_id, $amount, $ttl ?: 900, $payload );
		return new \WP_REST_Response( array( 'ok' => true ), 201 );
	}

	public static function peek_pending( \WP_REST_Request $request ) {
		$payload = Epic_Payment_Store::peek_pending( (string) $request->get_param( 'order_id' ) );
		if ( null === $payload ) {
			return new \WP_Error( 'epic_payment_not_found', 'No such pending order (or it expired).', array( 'status' => 404 ) );
		}
		return new \WP_REST_Response( $payload, 200 );
	}

	public static function claim_pending( \WP_REST_Request $request ) {
		$payload = Epic_Payment_Store::claim_pending( (string) $request->get_param( 'order_id' ) );
		if ( null === $payload ) {
			// Already claimed by a prior delivery of the same webhook, or the
			// order id is unknown/expired. Not an error from the caller's
			// perspective — the webhook route treats this as "nothing to do".
			return new \WP_Error( 'epic_payment_not_found', 'Already claimed, unknown, or expired order id.', array( 'status' => 404 ) );
		}
		return new \WP_REST_Response( $payload, 200 );
	}

	public static function store_completed( \WP_REST_Request $request ) {
		$order_id = (string) $request->get_param( 'order_id' );
		$result   = $request->get_json_params();

		if ( empty( $order_id ) || ! is_array( $result ) ) {
			return new \WP_Error( 'epic_payment_bad_request', 'A JSON body is required.', array( 'status' => 400 ) );
		}

		Epic_Payment_Store::put_completed( $order_id, $result );
		return new \WP_REST_Response( array( 'ok' => true ), 201 );
	}

	public static function get_completed( \WP_REST_Request $request ) {
		$result = Epic_Payment_Store::get_completed( (string) $request->get_param( 'order_id' ) );
		if ( null === $result ) {
			return new \WP_Error( 'epic_payment_not_found', 'Not completed yet (or the record expired).', array( 'status' => 404 ) );
		}
		return new \WP_REST_Response( $result, 200 );
	}
}
