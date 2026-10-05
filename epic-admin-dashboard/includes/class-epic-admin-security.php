<?php
/** Security boundaries for the private dashboard API. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Epic_Admin_Security {
	public static function init() {
		add_action( 'after_password_reset', array( __CLASS__, 'password_reset' ) );
		add_action( 'profile_update', array( __CLASS__, 'profile_update' ), 10, 2 );
		add_action( 'deleted_user_meta', array( __CLASS__, 'deleted_user_meta' ), 10, 4 );
		add_action( 'delete_user', array( __CLASS__, 'invalidate_user' ) );
		add_action( 'admin_post_epic_admin_revoke_all', array( __CLASS__, 'revoke_all_action' ) );
		add_action( 'epic_admin_security_cleanup', array( __CLASS__, 'cleanup' ) );
		add_action( 'send_headers', array( __CLASS__, 'browser_headers' ) );
		add_action( 'login_init', array( __CLASS__, 'browser_headers' ) );
		add_action( 'admin_init', array( __CLASS__, 'browser_headers' ) );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'check_origin' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'response_headers' ), 20, 3 );
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'private_cors' ), 20, 4 );
		if ( ! wp_next_scheduled( 'epic_admin_security_cleanup' ) ) { wp_schedule_event( time() + 3600, 'hourly', 'epic_admin_security_cleanup' ); }
	}

	public static function install() {
		global $wpdb;
		$table = $wpdb->prefix . 'epic_admin_limits';
		$collate = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE $table (
			bucket char(64) NOT NULL,
			attempts int unsigned NOT NULL DEFAULT 0,
			expires_at bigint unsigned NOT NULL,
			PRIMARY KEY  (bucket),
			KEY expires_at (expires_at)
		) $collate;" );
	}

	public static function session_version( $user ) {
		return hash_hmac( 'sha256', (string) $user->user_pass . '|' . (string) get_user_meta( $user->ID, '_epic_admin_auth_epoch', true ), wp_salt( 'auth' ) );
	}

	public static function invalidate_user( $user_id ) {
		global $wpdb;
		$user_id = (int) $user_id;
		$epoch = wp_generate_password( 64, false );
		update_user_meta( $user_id, '_epic_admin_auth_epoch', $epoch );
		$tables = Epic_Admin_Dashboard::tables();
		$sessions = $wpdb->delete( $tables['sessions'], array( 'user_id' => $user_id ), array( '%d' ) );
		$grants = $wpdb->delete( $tables['grants'], array( 'user_id' => $user_id ), array( '%d' ) );
		$ok = hash_equals( $epoch, (string) get_user_meta( $user_id, '_epic_admin_auth_epoch', true ) ) && false !== $sessions && false !== $grants;
		self::audit( $user_id, 'auth.revoke-all', $ok ? 'success' : 'failed' );
		if ( ! $ok ) { error_log( 'EPIC admin session revocation failed; administrator recovery required.' ); }
		return $ok;
	}

	public static function password_reset( $user ) { self::invalidate_user( $user->ID ); }
	public static function profile_update( $user_id, $old_user ) {
		$user = get_user_by( 'id', $user_id );
		if ( $user && ! hash_equals( (string) $old_user->user_pass, (string) $user->user_pass ) ) { self::invalidate_user( $user_id ); }
	}
	public static function deleted_user_meta( $meta_ids, $user_id, $key, $value ) {
		if ( 'session_tokens' === $key ) { self::invalidate_user( $user_id ); }
	}

	public static function revoke_all_action() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Administrator access required.', '', array( 'response' => 403 ) ); }
		check_admin_referer( 'epic_admin_revoke_all' );
		if ( ! self::invalidate_user( get_current_user_id() ) ) { wp_die( 'Dashboard sessions could not be fully revoked. Retry after checking the database.', '', array( 'response' => 503 ) ); }
		wp_safe_redirect( admin_url( 'options-general.php?page=epic-admin-dashboard&sessions_revoked=1' ) );
		exit;
	}

	public static function rate_limit( $request, $flow ) {
		global $wpdb;
		$source = (string) $request->get_header( 'x-epic-admin-source' );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $source ) ) { $source = hash_hmac( 'sha256', (string) ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ), wp_salt( 'auth' ) ); }
		$now = time(); $window = (int) floor( $now / 60 ); $expires = ( $window + 1 ) * 60;
		$limits = array( 'global' => 'authorize' === $flow ? 60 : 120, 'source:' . $source => 'authorize' === $flow ? 10 : 30 );
		$table = $wpdb->prefix . 'epic_admin_limits';
		foreach ( $limits as $scope => $limit ) {
			$bucket = hash( 'sha256', $flow . '|' . $scope . '|' . $window );
			// Atomic shared storage; counters do not depend on a Vercel process.
			$saved = $wpdb->query( $wpdb->prepare( "INSERT INTO $table (bucket, attempts, expires_at) VALUES (%s, 1, %d) ON DUPLICATE KEY UPDATE attempts = LEAST(attempts + 1, 1000000)", $bucket, $expires ) );
			$count = $wpdb->get_var( $wpdb->prepare( "SELECT attempts FROM $table WHERE bucket = %s", $bucket ) );
			if ( false === $saved || null === $count ) { return new WP_Error( 'auth_limit_unavailable', 'Sign-in protection is temporarily unavailable.', array( 'status' => 503 ) ); }
			if ( (int) $count > $limit ) { return new WP_Error( 'auth_rate_limited', 'Too many sign-in attempts. Try again shortly.', array( 'status' => 429, 'retry_after' => max( 1, $expires - $now ) ) ); }
		}
		return true;
	}

	public static function cleanup() {
		global $wpdb;
		$table = $wpdb->prefix . 'epic_admin_limits';
		$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE expires_at < %d", time() ) );
		$tables = Epic_Admin_Dashboard::tables();
		foreach ( array( 'sessions', 'grants' ) as $key ) { $wpdb->query( $wpdb->prepare( "DELETE FROM {$tables[$key]} WHERE expires_at < %s", gmdate( 'Y-m-d H:i:s' ) ) ); }
	}

	private static function is_private( $request ) {
		$route = $request->get_route();
		return '/epic-admin/v1' === $route || 0 === strpos( $route, '/epic-admin/v1/' );
	}
	public static function check_origin( $result, $server, $request ) {
		if ( ! self::is_private( $request ) ) { return $result; }
		$origin = (string) $request->get_header( 'origin' );
		if ( '' === $origin ) { return $result; }
		$parts = wp_parse_url( admin_url() );
		$expected = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		if ( ! hash_equals( $expected, $origin ) ) { return new WP_Error( 'invalid_origin', 'Cross-origin dashboard API access is not allowed.', array( 'status' => 403 ) ); }
		return $result;
	}
	public static function response_headers( $response, $server, $request ) {
		if ( self::is_private( $request ) ) {
			$response->header( 'Cache-Control', 'private, no-store' );
			$response->header( 'CDN-Cache-Control', 'no-store' );
			if ( 429 === $response->get_status() ) { $data = $response->get_data(); $response->header( 'Retry-After', (string) ( $data['data']['retry_after'] ?? 60 ) ); }
		}
		return $response;
	}
	public static function private_cors( $served, $response, $request, $server ) {
		self::browser_headers();
		if ( self::is_private( $request ) ) {
			foreach ( array( 'Access-Control-Allow-Origin', 'Access-Control-Allow-Credentials', 'Access-Control-Allow-Methods', 'Access-Control-Allow-Headers', 'Access-Control-Expose-Headers' ) as $header ) { header_remove( $header ); }
			header( 'Cache-Control: private, no-store' );
		}
		return $served;
	}

	public static function browser_headers() {
		if ( headers_sent() || 'admin.epicroastery.coffee' !== strtolower( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) ) ) { return; }
		if ( is_ssl() ) { header( 'Strict-Transport-Security: max-age=300' ); }
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: same-origin' );
		$path = wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
		if ( is_admin() || '/wp-login.php' === $path ) {
			header( "Content-Security-Policy: frame-ancestors 'self'; object-src 'none'; base-uri 'self'" );
			header( "Content-Security-Policy-Report-Only: default-src 'self'; script-src 'self' 'report-sample'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'" );
		}
	}
	public static function audit( $user_id, $action, $outcome ) {
		global $wpdb;
		$wpdb->insert( Epic_Admin_Dashboard::tables()['audit'], array( 'user_id' => (int) $user_id, 'action' => $action, 'resource' => 'auth', 'record_id' => '', 'fields' => '[]', 'outcome' => $outcome, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	}
}
