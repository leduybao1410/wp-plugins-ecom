<?php
/**
 * REST route the Next.js server calls before accepting a public API write
 * (src/lib/public-api/guard.ts) — `/wp-json/epic-agent/v1/guard`.
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

		$client_ip = sanitize_text_field( (string) $request->get_param( 'clientIp' ) );

		$user_agent = sanitize_text_field( (string) $request->get_param( 'userAgent' ) );
		$user_agent = substr( $user_agent, 0, self::MAX_UA_LENGTH );

		$honeypot = (bool) $request->get_param( 'honeypot' );

		$result = Epic_Agent_Rate_Limiter::check( $route, $client_ip, $user_agent, $honeypot );

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
}
