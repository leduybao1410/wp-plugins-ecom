<?php
/**
 * REST routes the Next.js website's `src/lib/account.ts` calls. Every route
 * is gated behind a shared secret (see class-settings.php) sent as an
 * `X-Epic-Secret` header. The account is identified by the Google `sub` that
 * the website's verified session carries — the plugin never trusts a
 * client-supplied identity, only the value the site's own (ID-token-verified)
 * session hands over.
 *
 * Routes:
 *   POST /accounts                                  upsert + auto-link + ensure WC customer
 *   GET  /accounts/{sub}/orders                     list the account's linked orders
 *   GET  /accounts/{sub}/orders/{order_id}          one linked order's detail
 *   POST /accounts/{sub}/links                      claim an order by code + email/phone
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Account_Rest_Api {

	const NAMESPACE = 'epic-account-linking/v1';

	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/accounts',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_account' ),
				'permission_callback' => array( __CLASS__, 'check_secret' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/accounts/(?P<google_sub>[a-zA-Z0-9._-]+)/orders',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_orders' ),
				'permission_callback' => array( __CLASS__, 'check_secret' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/accounts/(?P<google_sub>[a-zA-Z0-9._-]+)/orders/(?P<order_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_order' ),
				'permission_callback' => array( __CLASS__, 'check_secret' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/accounts/(?P<google_sub>[a-zA-Z0-9._-]+)/links',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'claim_order' ),
				'permission_callback' => array( __CLASS__, 'check_secret' ),
			)
		);
	}

	/** Constant-time comparison against the secret configured in WooCommerce → Account Linking. */
	public static function check_secret( \WP_REST_Request $request ) {
		$configured = Epic_Account_Settings::get_shared_secret();
		if ( empty( $configured ) ) {
			return new \WP_Error( 'epic_account_linking_not_configured', 'Shared secret not configured.', array( 'status' => 500 ) );
		}
		$provided = $request->get_header( 'x-epic-secret' );
		if ( empty( $provided ) || ! hash_equals( $configured, $provided ) ) {
			return new \WP_Error( 'epic_account_linking_forbidden', 'Invalid or missing X-Epic-Secret header.', array( 'status' => 403 ) );
		}
		return true;
	}

	/** Upsert an account from a Google sign-in, then auto-link by email. */
	public static function create_account( \WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			return new \WP_Error( 'epic_account_bad_request', 'A JSON body is required.', array( 'status' => 400 ) );
		}

		$google_sub = isset( $params['google_sub'] ) ? sanitize_text_field( (string) $params['google_sub'] ) : '';
		$email      = isset( $params['email'] ) ? strtolower( sanitize_email( (string) $params['email'] ) ) : '';
		$name       = isset( $params['name'] ) ? sanitize_text_field( (string) $params['name'] ) : '';
		$picture    = isset( $params['picture_url'] ) ? esc_url_raw( (string) $params['picture_url'] ) : '';

		if ( empty( $google_sub ) || strlen( $google_sub ) > 64 ) {
			return new \WP_Error( 'epic_account_bad_request', 'google_sub is required and must be ≤ 64 characters.', array( 'status' => 400 ) );
		}
		if ( empty( $email ) || ! is_email( $email ) ) {
			return new \WP_Error( 'epic_account_bad_request', 'A valid email is required.', array( 'status' => 400 ) );
		}

		$result = Epic_Account_Store::upsert_account(
			$google_sub,
			array(
				'email'         => $email,
				'display_name'  => $name,
				'picture_url'   => $picture,
			)
		);
		$account_id = $result['account_id'];

		// Best-effort: create/reuse a WC customer so orders placed while
		// signed in can carry a customer_id. Never blocks the sign-in.
		$wc_customer_id = Epic_Account_Service::ensure_wc_customer( $google_sub, $email, $name );
		if ( $wc_customer_id ) {
			Epic_Account_Store::set_wc_customer_id( $account_id, $wc_customer_id );
		}

		$linked = Epic_Account_Service::auto_link_by_email( $account_id, $email );

		return new \WP_REST_Response(
			array(
				'account_id'        => $account_id,
				'google_sub'        => $google_sub,
				'email'             => $email,
				'display_name'      => $name,
				'picture_url'       => $picture,
				'wc_customer_id'    => $wc_customer_id,
				'is_new'            => $result['is_new'],
				'linked_order_count' => $linked,
			),
			200
		);
	}

	public static function list_orders( \WP_REST_Request $request ) {
		$account = Epic_Account_Store::get_account_by_sub( (string) $request->get_param( 'google_sub' ) );
		if ( ! $account ) {
			return new \WP_Error( 'epic_account_not_found', 'No such account.', array( 'status' => 404 ) );
		}
		return new \WP_REST_Response(
			array( 'orders' => Epic_Account_Service::list_orders( (int) $account['id'] ) ),
			200
		);
	}

	public static function get_order( \WP_REST_Request $request ) {
		$account = Epic_Account_Store::get_account_by_sub( (string) $request->get_param( 'google_sub' ) );
		if ( ! $account ) {
			return new \WP_Error( 'epic_account_not_found', 'No such account.', array( 'status' => 404 ) );
		}
		$detail = Epic_Account_Service::get_order( (int) $account['id'], (int) $request->get_param( 'order_id' ) );
		if ( null === $detail ) {
			// 404 both when the account has no link to it AND when it doesn't
			// exist — never reveal an order the caller has no access to.
			return new \WP_Error( 'epic_account_order_not_found', 'Order not found.', array( 'status' => 404 ) );
		}
		return new \WP_REST_Response( $detail, 200 );
	}

	public static function claim_order( \WP_REST_Request $request ) {
		$account = Epic_Account_Store::get_account_by_sub( (string) $request->get_param( 'google_sub' ) );
		if ( ! $account ) {
			return new \WP_Error( 'epic_account_not_found', 'No such account.', array( 'status' => 404 ) );
		}

		$params     = $request->get_json_params();
		$order_code = isset( $params['order_code'] ) ? strtoupper( sanitize_text_field( (string) $params['order_code'] ) ) : '';
		$email      = isset( $params['email'] ) ? strtolower( sanitize_email( (string) $params['email'] ) ) : '';
		$phone      = isset( $params['phone'] ) ? sanitize_text_field( (string) $params['phone'] ) : '';

		if ( empty( $order_code ) ) {
			return new \WP_Error( 'epic_account_bad_request', 'order_code is required.', array( 'status' => 400 ) );
		}
		if ( empty( $email ) && empty( $phone ) ) {
			return new \WP_Error( 'epic_account_bad_request', 'email or phone is required to verify ownership.', array( 'status' => 400 ) );
		}

		$result = Epic_Account_Service::claim_order( (int) $account['id'], $order_code, $email, $phone );

		switch ( $result['status'] ) {
			case 'linked':
			case 'already_linked':
				return new \WP_REST_Response( $result, 200 );
			case 'not_found':
				return new \WP_Error( 'epic_account_no_such_order', 'No order found for that code.', array( 'status' => 404 ) );
			case 'no_match':
				return new \WP_Error( 'epic_account_no_match', 'That email/phone does not match the order.', array( 'status' => 403 ) );
			case 'config_error':
				return new \WP_Error( 'epic_account_config_error', $result['error'], array( 'status' => 500 ) );
		}
		return new \WP_Error( 'epic_account_bad_request', 'Unexpected state.', array( 'status' => 500 ) );
	}
}
