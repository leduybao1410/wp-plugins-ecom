<?php
/**
 * REST routes the Next.js server calls to consume shared public API/MCP
 * quotas and reserve deduplicated form submissions.
 *
 * Gated behind a shared secret (see class-settings.php) sent as an
 * `X-Epic-Secret` header, same pattern as every other EPIC module. The
 * Next.js server forwards the end-user IP as `clientIp` because WordPress
 * only sees Vercel's egress IP on its own.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Agent_Rest_Api {

	const NAMESPACE = 'epic-agent/v1';

	const MAX_ROUTE_LENGTH = 191;
	const MAX_UA_LENGTH    = 300;

	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/guard',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'guard' ),
				'permission_callback' => array( __CLASS__, 'check_secret' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/idempotency',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'idempotency' ),
				'permission_callback' => array( __CLASS__, 'check_secret' ),
			)
		);
	}

	/** Constant-time comparison against the secret configured in WooCommerce → Agent API. */
	public static function check_secret( \WP_REST_Request $request ) {
		$configured = Epic_Agent_Settings::get_shared_secret();
		if ( empty( $configured ) ) {
			return new \WP_Error( 'epic_agent_not_configured', 'Shared secret not configured.', array( 'status' => 500 ) );
		}
		$provided = $request->get_header( 'x-epic-secret' );
		if ( empty( $provided ) || ! hash_equals( $configured, $provided ) ) {
			return new \WP_Error( 'epic_agent_forbidden', 'Invalid or missing X-Epic-Secret header.', array( 'status' => 403 ) );
		}
		return true;
	}

	public static function guard( \WP_REST_Request $request ) {
		$route = sanitize_text_field( (string) $request->get_param( 'route' ) );
		$route = substr( $route, 0, self::MAX_ROUTE_LENGTH );

		// Only a valid IP (or the shared "unknown" bucket) is used for rate
		// limiting — an arbitrary caller-supplied string would let each request
		// land in its own bucket and defeat the limits entirely.
		$client_ip = Epic_Agent_Rate_Limiter::normalize_ip( (string) $request->get_param( 'clientIp' ) );

		$user_agent = sanitize_text_field( (string) $request->get_param( 'userAgent' ) );
		$user_agent = substr( $user_agent, 0, self::MAX_UA_LENGTH );

		$honeypot = (bool) $request->get_param( 'honeypot' );
		$action   = sanitize_key( (string) $request->get_param( 'action' ) );
		if ( ! in_array( $action, array( 'mcp', 'read', 'order', 'write' ), true ) ) {
			return new \WP_Error( 'epic_agent_bad_action', 'A valid guard action is required.', array( 'status' => 400 ) );
		}

		$result = Epic_Agent_Rate_Limiter::check( $route, $client_ip, $user_agent, $honeypot, $action );

		return new \WP_REST_Response(
			array(
				'allow'             => (bool) $result['allow'],
				'silent'            => (bool) $result['silent'],
				'retryAfterSeconds' => (int) $result['retryAfterSeconds'],
				'remaining'         => (int) $result['remaining'],
				'bucket'            => (string) $result['bucket'],
			),
			200
		);
	}

	/** Shared REST/MCP form deduplication backed by a unique database key. */
	public static function idempotency( \WP_REST_Request $request ) {
		$fingerprint = strtolower( (string) $request->get_param( 'fingerprint' ) );
		$operation   = sanitize_key( (string) $request->get_param( 'operation' ) );
		$reference   = sanitize_text_field( (string) $request->get_param( 'reference' ) );
		$complete    = (bool) $request->get_param( 'complete' );
		$release     = (bool) $request->get_param( 'release' );
		$allowed     = array( 'sample', 'quote', 'newsletter' );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) || ! in_array( $operation, $allowed, true ) || '' === $reference ) {
			return new \WP_Error( 'epic_agent_bad_idempotency', 'Invalid idempotency request.', array( 'status' => 400 ) );
		}
		$result = Epic_Agent_Store::idempotency( $fingerprint, $operation, $reference, $complete, $release );
		if ( false === $result ) {
			return new \WP_Error( 'epic_agent_idempotency_unavailable', 'Idempotency store unavailable.', array( 'status' => 503 ) );
		}
		return new \WP_REST_Response( $result, 200 );
	}
}
