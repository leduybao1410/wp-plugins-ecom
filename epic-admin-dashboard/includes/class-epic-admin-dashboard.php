<?php
/** Administrator session bridge for the separate EPIC dashboard. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Epic_Admin_Dashboard {
	const NS = 'epic-admin/v1';
	const SESSION_TTL = 28800;
	const IDLE_TTL = 1800;
	const RESOURCES = array( 'orders', 'shipments', 'products', 'customers', 'content', 'leads', 'reviews', 'wholesale-orders', 'costs', 'distributors', 'coupons', 'newsletter', 'ledger' );
	const SOURCE_DIRECT = 'direct';
	const META_SOURCE = '_epic_order_source';
	const META_FULFILLMENT = '_epic_fulfillment';
	const META_CREATED_BY = '_epic_created_by';
	const DIRECT_ORDER_FULFILLMENTS = array( 'courier', 'self' );
	const DIRECT_ORDER_PREVIEW_TTL = 900;

	public static function init() {
		if ( get_option( 'epic_admin_schema_version' ) !== EPIC_ADMIN_DASHBOARD_VERSION ) { self::activate(); }
		Epic_Admin_Security::init();
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'admin_post_epic_admin_authorize', array( __CLASS__, 'authorize' ) );
	}

	public static function tables() {
		global $wpdb;
		return array(
			'sessions' => $wpdb->prefix . 'epic_admin_sessions',
			'grants'   => $wpdb->prefix . 'epic_admin_grants',
			'audit'    => $wpdb->prefix . 'epic_admin_audit',
			'receipts' => $wpdb->prefix . 'epic_admin_receipts',
		);
	}

	public static function activate() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$tables = self::tables();
		$collate = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$tables['sessions']} (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			token_hash char(64) NOT NULL,
			auth_version char(64) NOT NULL DEFAULT '',
			user_id bigint unsigned NOT NULL,
			created_at datetime NOT NULL,
			last_used_at datetime NOT NULL,
			expires_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY user_id (user_id),
			KEY expires_at (expires_at)
		) $collate;" );
		dbDelta( "CREATE TABLE {$tables['grants']} (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			state_hash char(64) NOT NULL,
			code_hash char(64) NULL,
			code_challenge char(43) NOT NULL,
			callback_url varchar(255) NOT NULL,
			auth_version char(64) NOT NULL DEFAULT '',
			user_id bigint unsigned NULL,
			status varchar(16) NOT NULL DEFAULT 'pending',
			expires_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY state_hash (state_hash),
			UNIQUE KEY code_hash (code_hash),
			KEY expires_at (expires_at)
		) $collate;" );
		dbDelta( "CREATE TABLE {$tables['audit']} (id bigint unsigned NOT NULL AUTO_INCREMENT, user_id bigint unsigned NOT NULL, action varchar(100) NOT NULL, resource varchar(100) NOT NULL, record_id varchar(100) NOT NULL DEFAULT '', fields text NOT NULL, outcome varchar(24) NOT NULL, created_at datetime NOT NULL, PRIMARY KEY (id), KEY created_at (created_at), KEY user_id (user_id)) $collate;" );
		dbDelta( "CREATE TABLE {$tables['receipts']} (id bigint unsigned NOT NULL AUTO_INCREMENT, receipt_key char(64) NOT NULL, user_id bigint unsigned NOT NULL, action varchar(100) NOT NULL, outcome varchar(24) NOT NULL, response longtext NULL, created_at datetime NOT NULL, PRIMARY KEY (id), UNIQUE KEY receipt_key (receipt_key)) $collate;" );
		Epic_Admin_Security::install();
		if ( $wpdb->get_var( "SHOW COLUMNS FROM {$tables['sessions']} LIKE 'auth_version'" ) && $wpdb->get_var( "SHOW COLUMNS FROM {$tables['grants']} LIKE 'auth_version'" ) && $wpdb->get_var( "SHOW COLUMNS FROM {$wpdb->prefix}epic_admin_limits LIKE 'bucket'" ) ) {
			update_option( 'epic_admin_schema_version', EPIC_ADMIN_DASHBOARD_VERSION, false );
		}
	}

	private static function secret() {
		if ( defined( 'EPIC_ADMIN_CLIENT_SECRET' ) && EPIC_ADMIN_CLIENT_SECRET ) { return (string) EPIC_ADMIN_CLIENT_SECRET; }
		return (string) get_option( 'epic_admin_client_secret', '' );
	}

	private static function callback_url() {
		if ( defined( 'EPIC_ADMIN_DASHBOARD_CALLBACK' ) && EPIC_ADMIN_DASHBOARD_CALLBACK ) { return (string) EPIC_ADMIN_DASHBOARD_CALLBACK; }
		return (string) get_option( 'epic_admin_dashboard_callback', '' );
	}

	private static function hash( $value ) { return hash( 'sha256', (string) $value ); }
	private static function error( $code, $message, $status = 400 ) { return new WP_Error( $code, $message, array( 'status' => $status ) ); }

	public static function routes() {
		register_rest_route( self::NS, '/auth/authorize', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_grant' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NS, '/auth/exchange', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'exchange' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NS, '/auth/revoke', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'revoke' ), 'permission_callback' => array( __CLASS__, 'require_admin_session' ) ) );
		register_rest_route( self::NS, '/me', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'me' ), 'permission_callback' => array( __CLASS__, 'require_admin_session' ) ) );
		register_rest_route( self::NS, '/overview', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'overview' ), 'permission_callback' => array( __CLASS__, 'require_admin_session' ) ) );
		register_rest_route( self::NS, '/health', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'health' ), 'permission_callback' => array( __CLASS__, 'require_admin_session' ) ) );
		register_rest_route( self::NS, '/audit', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'audit' ), 'permission_callback' => array( __CLASS__, 'require_admin_session' ) ) );
		register_rest_route( self::NS, '/finance/export', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'finance_export' ), 'permission_callback' => array( __CLASS__, 'require_admin_session' ) ) );
		register_rest_route( self::NS, '/coupons/redemptions', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'coupon_redemptions' ), 'permission_callback' => array( __CLASS__, 'require_admin_session' ) ) );
		register_rest_route( self::NS, '/coupons', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_coupon' ), 'permission_callback' => array( __CLASS__, 'require_admin_session' ) ) );
		register_rest_route( self::NS, '/shipments/(?P<order_id>\d+)/action', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'shipment_action' ), 'permission_callback' => array( __CLASS__, 'require_admin_session' ) ) );
		register_rest_route( self::NS, '/products/change/(?P<operation>preview|apply)', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'product_change' ), 'permission_callback' => array( __CLASS__, 'require_admin_session' ) ) );
		register_rest_route( self::NS, '/products/(?P<product_id>\d+)/variations', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'product_variations' ), 'permission_callback' => array( __CLASS__, 'require_admin_session' ) ) );
		register_rest_route( self::NS, '/products/(?P<product_id>\d+)/variations/(?P<variation_id>\d+)', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'product_variation' ), 'permission_callback' => array( __CLASS__, 'require_admin_session' ) ) );
		register_rest_route( self::NS, '/wholesale/settings', array( 'methods' => array( 'GET', 'PATCH' ), 'callback' => array( __CLASS__, 'wholesale_settings' ), 'permission_callback' => array( __CLASS__, 'require_admin_session' ) ) );
		register_rest_route( self::NS, '/newsletter/campaigns', array( 'methods' => array( 'GET', 'POST' ), 'callback' => array( __CLASS__, 'newsletter_campaigns' ), 'permission_callback' => array( __CLASS__, 'require_admin_session' ) ) );
		register_rest_route( self::NS, '/newsletter/campaigns/(?P<id>\d+)', array( 'methods' => array( 'GET', 'PATCH' ), 'callback' => array( __CLASS__, 'newsletter_campaign' ), 'permission_callback' => array( __CLASS__, 'require_admin_session' ) ) );
		register_rest_route( self::NS, '/newsletter/campaigns/(?P<id>\d+)/send', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'newsletter_send' ), 'permission_callback' => array( __CLASS__, 'require_admin_session' ) ) );
		register_rest_route( self::NS, '/records/(?P<resource>[a-z-]+)', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'records' ), 'permission_callback' => array( __CLASS__, 'require_admin_session' ), 'args' => array( 'resource' => array( 'validate_callback' => static function ( $value ) { return in_array( $value, self::RESOURCES, true ); } ) ) ) );
		register_rest_route( self::NS, '/records/(?P<resource>[a-z-]+)/(?P<id>\d+)', array( 'methods' => array( 'GET', 'PATCH', 'DELETE' ), 'callback' => array( __CLASS__, 'record' ), 'permission_callback' => array( __CLASS__, 'require_admin_session' ) ) );
		register_rest_route( self::NS, '/address/provinces', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'address_provinces' ), 'permission_callback' => array( __CLASS__, 'require_admin_session' ) ) );
		register_rest_route( self::NS, '/address/wards', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'address_wards' ), 'permission_callback' => array( __CLASS__, 'require_admin_session' ) ) );
		register_rest_route( self::NS, '/orders/preview', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'order_preview' ), 'permission_callback' => array( __CLASS__, 'require_admin_session' ) ) );
		register_rest_route( self::NS, '/orders/apply', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'order_apply' ), 'permission_callback' => array( __CLASS__, 'require_admin_session' ) ) );
	}

	public static function create_grant( $request ) {
		global $wpdb;
		$body = $request->get_json_params();
		$secret = (string) $request->get_header( 'x-epic-admin-client' );
		$state = isset( $body['state'] ) ? (string) $body['state'] : '';
		$challenge = isset( $body['code_challenge'] ) ? (string) $body['code_challenge'] : '';
		$callback = isset( $body['callback_url'] ) ? (string) $body['callback_url'] : '';
		if ( ! self::secret() || ! hash_equals( self::secret(), $secret ) ) { return self::error( 'invalid_client', 'Dashboard authentication is not configured.', 401 ); }
		$limit = Epic_Admin_Security::rate_limit( $request, 'authorize' );
		if ( is_wp_error( $limit ) ) { return $limit; }
		if ( ! preg_match( '/^[A-Za-z0-9_-]{40,100}$/', $state ) || ! preg_match( '/^[A-Za-z0-9_-]{43}$/', $challenge ) || ! $callback || ! hash_equals( self::callback_url(), $callback ) || 'https' !== wp_parse_url( $callback, PHP_URL_SCHEME ) ) { return self::error( 'invalid_request', 'Invalid dashboard login request.', 400 ); }
		$tables = self::tables();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$tables['grants']} WHERE expires_at < %s", gmdate( 'Y-m-d H:i:s' ) ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$tables['sessions']} WHERE expires_at < %s", gmdate( 'Y-m-d H:i:s' ) ) );
		$wpdb->delete( $tables['grants'], array( 'state_hash' => self::hash( $state ) ), array( '%s' ) );
		$inserted = $wpdb->insert( $tables['grants'], array( 'state_hash' => self::hash( $state ), 'code_challenge' => $challenge, 'callback_url' => $callback, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 600 ) ), array( '%s', '%s', '%s', '%s' ) );
		if ( false === $inserted ) { return self::error( 'auth_store_unavailable', 'Dashboard sign-in could not be prepared.', 503 ); }
		$target = add_query_arg( array( 'action' => 'epic_admin_authorize', 'state' => rawurlencode( $state ) ), admin_url( 'admin-post.php' ) );
		return rest_ensure_response( array( 'login_url' => wp_login_url( $target ) ) );
	}

	public static function authorize() {
		global $wpdb;
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		if ( ! preg_match( '/^[A-Za-z0-9_-]{40,100}$/', $state ) || ! is_user_logged_in() ) { auth_redirect(); }
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'An administrator account is required.', 'epic-admin-dashboard' ), '', array( 'response' => 403 ) ); }
		$tables = self::tables();
		$grant = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['grants']} WHERE state_hash = %s AND status = 'pending' AND expires_at > %s", self::hash( $state ), gmdate( 'Y-m-d H:i:s' ) ) );
		if ( ! $grant ) { wp_die( esc_html__( 'Dashboard sign-in expired. Return to the dashboard and try again.', 'epic-admin-dashboard' ), '', array( 'response' => 400 ) ); }
		$code = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		$updated = $wpdb->update( $tables['grants'], array( 'status' => 'authorized', 'code_hash' => self::hash( $code ), 'auth_version' => Epic_Admin_Security::session_version( wp_get_current_user() ), 'user_id' => get_current_user_id(), 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 60 ) ), array( 'id' => (int) $grant->id, 'status' => 'pending' ), array( '%s', '%s', '%s', '%d', '%s' ), array( '%d', '%s' ) );
		if ( 1 !== $updated ) { wp_die( esc_html__( 'Dashboard sign-in could not be completed.', 'epic-admin-dashboard' ), '', array( 'response' => 409 ) ); }
		wp_redirect( add_query_arg( array( 'code' => $code, 'state' => $state ), $grant->callback_url ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- callback is the exact HTTPS URL registered by the dashboard client.
		exit;
	}

	public static function exchange( $request ) {
		global $wpdb;
		$body = $request->get_json_params();
		$secret = (string) $request->get_header( 'x-epic-admin-client' );
		$code = isset( $body['code'] ) ? (string) $body['code'] : '';
		$verifier = isset( $body['code_verifier'] ) ? (string) $body['code_verifier'] : '';
		if ( ! self::secret() || ! hash_equals( self::secret(), $secret ) ) { return self::error( 'invalid_client', 'Invalid dashboard client.', 401 ); }
		$limit = Epic_Admin_Security::rate_limit( $request, 'exchange' );
		if ( is_wp_error( $limit ) ) { return $limit; }
		if ( ! preg_match( '/^[A-Za-z0-9_-]{40,100}$/', $code ) || ! preg_match( '/^[A-Za-z0-9._~-]{43,128}$/', $verifier ) ) { return self::error( 'invalid_grant', 'Dashboard authorization code is invalid or expired.', 400 ); }
		$tables = self::tables();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['grants']} WHERE code_hash = %s AND status = 'authorized' AND expires_at > %s", self::hash( $code ), gmdate( 'Y-m-d H:i:s' ) ) );
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		if ( ! $row || ! hash_equals( (string) $row->code_challenge, $challenge ) ) { return self::error( 'invalid_grant', 'Dashboard authorization code is invalid or expired.', 400 ); }
		$user = get_user_by( 'id', (int) $row->user_id );
		if ( ! $user || ! user_can( $user, 'manage_options' ) ) { return self::error( 'forbidden', 'An administrator account is required.', 403 ); }
		if ( empty( $row->auth_version ) || ! hash_equals( Epic_Admin_Security::session_version( $user ), (string) $row->auth_version ) ) { return self::error( 'invalid_grant', 'Dashboard authorization was revoked. Start a new sign-in.', 401 ); }
		$used = $wpdb->update( $tables['grants'], array( 'status' => 'used' ), array( 'id' => (int) $row->id, 'status' => 'authorized' ), array( '%s' ), array( '%d', '%s' ) );
		if ( 1 !== $used ) { return self::error( 'invalid_grant', 'Dashboard authorization code has already been used.', 409 ); }
		$token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		$now = time();
		$inserted = $wpdb->insert( $tables['sessions'], array( 'token_hash' => self::hash( $token ), 'auth_version' => $row->auth_version, 'user_id' => (int) $user->ID, 'created_at' => gmdate( 'Y-m-d H:i:s', $now ), 'last_used_at' => gmdate( 'Y-m-d H:i:s', $now ), 'expires_at' => gmdate( 'Y-m-d H:i:s', $now + self::SESSION_TTL ) ), array( '%s', '%s', '%d', '%s', '%s', '%s' ) );
		if ( false === $inserted ) { return self::error( 'session_store_unavailable', 'Administrator sign-in could not be completed. Start a new sign-in.', 503 ); }
		return rest_ensure_response( array( 'session' => $token, 'expires_in' => self::SESSION_TTL, 'user' => array( 'id' => (int) $user->ID, 'name' => $user->display_name, 'email' => $user->user_email ) ) );
	}

	public static function require_admin_session( $request ) {
		global $wpdb;
		$token = (string) $request->get_header( 'x-epic-admin-session' );
		if ( ! preg_match( '/^[A-Za-z0-9_-]{40,100}$/', $token ) ) { return self::error( 'not_authenticated', 'Administrator sign-in required.', 401 ); }
		$tables = self::tables();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['sessions']} WHERE token_hash = %s AND expires_at > %s", self::hash( $token ), gmdate( 'Y-m-d H:i:s' ) ) );
		if ( ! $row || strtotime( $row->last_used_at . ' UTC' ) < time() - self::IDLE_TTL ) { if ( $row ) { $wpdb->delete( $tables['sessions'], array( 'id' => (int) $row->id ), array( '%d' ) ); } return self::error( 'session_expired', 'Administrator session expired. Sign in again.', 401 ); }
		$user = get_user_by( 'id', (int) $row->user_id );
		if ( ! $user || ! user_can( $user, 'manage_options' ) ) { $wpdb->delete( $tables['sessions'], array( 'id' => (int) $row->id ), array( '%d' ) ); return self::error( 'forbidden', 'Administrator access is required.', 403 ); }
		if ( empty( $row->auth_version ) || ! hash_equals( Epic_Admin_Security::session_version( $user ), (string) $row->auth_version ) ) {
			$wpdb->delete( $tables['sessions'], array( 'id' => (int) $row->id ), array( '%d' ) );
			return self::error( 'session_revoked', 'Administrator session revoked. Sign in again.', 401 );
		}
		wp_set_current_user( (int) $user->ID );
		$wpdb->update( $tables['sessions'], array( 'last_used_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => (int) $row->id ), array( '%s' ), array( '%d' ) );
		$GLOBALS['epic_admin_dashboard_session'] = array( 'id' => (int) $row->id, 'user_id' => (int) $user->ID, 'token_hash' => self::hash( $token ) );
		return true;
	}

	public static function revoke( $request ) {
		global $wpdb;
		$session = $GLOBALS['epic_admin_dashboard_session'];
		$deleted = $wpdb->delete( self::tables()['sessions'], array( 'id' => $session['id'] ), array( '%d' ) );
		if ( false === $deleted ) { Epic_Admin_Security::audit( $session['user_id'], 'auth.revoke', 'failed' ); return self::error( 'revoke_failed', 'Administrator session could not be revoked.', 503 ); }
		Epic_Admin_Security::audit( $session['user_id'], 'auth.revoke', 'success' );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function me() {
		$user = wp_get_current_user();
		return rest_ensure_response( array( 'id' => (int) $user->ID, 'name' => $user->display_name, 'email' => $user->user_email, 'role' => 'administrator' ) );
	}

	public static function overview( $request ) {
		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'Asia/Ho_Chi_Minh' );
		$today = new DateTimeImmutable( 'now', $timezone );
		$from = (string) $request->get_param( 'from' );
		$to = (string) $request->get_param( 'to' );
		$from = '' === $from ? $today->format( 'Y-m-d' ) : $from;
		$to = '' === $to ? $today->format( 'Y-m-d' ) : $to;
		$start = DateTimeImmutable::createFromFormat( '!Y-m-d', $from, $timezone );
		$end = DateTimeImmutable::createFromFormat( '!Y-m-d', $to, $timezone );
		$errors = DateTimeImmutable::getLastErrors();
		if ( ! $start || ! $end || ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) ) || $start->format( 'Y-m-d' ) !== $from || $end->format( 'Y-m-d' ) !== $to ) { return self::error( 'invalid_date_range', 'Enter valid dates for the reporting period.', 400 ); }
		$days = (int) $start->diff( $end )->format( '%a' ) + 1;
		if ( $start > $end || $days > 366 ) { return self::error( 'invalid_date_range', 'Choose a date range of at most 366 days with the start on or before the end.', 400 ); }
		$range = $start->format( 'Y-m-d H:i:s' ) . '...' . $end->setTime( 23, 59, 59 )->format( 'Y-m-d H:i:s' );
		$order_count = null; $revenue = null; $backlog = null; $low_stock = null;
		if ( class_exists( 'WooCommerce' ) && function_exists( 'wc_get_orders' ) ) {
			$count = wc_get_orders( array( 'limit' => 1, 'page' => 1, 'paginate' => true, 'date_created' => $range, 'return' => 'ids' ) );
			$order_count = is_object( $count ) && isset( $count->total ) ? (int) $count->total : null;
			$paid_count = wc_get_orders( array( 'limit' => 1, 'page' => 1, 'paginate' => true, 'date_created' => $range, 'status' => array( 'processing', 'completed' ), 'return' => 'ids' ) );
			if ( is_object( $paid_count ) && isset( $paid_count->total ) ) {
				if ( 0 === (int) $paid_count->total ) { $revenue = 0.0; }
				else {
					$revenue = 0.0; $page = 1;
					do {
						$batch = wc_get_orders( array( 'limit' => 100, 'page' => $page, 'paginate' => true, 'date_created' => $range, 'status' => array( 'processing', 'completed' ), 'return' => 'objects' ) );
						if ( ! is_object( $batch ) || empty( $batch->orders ) ) { $revenue = null; break; }
						foreach ( $batch->orders as $order ) { $revenue += (float) $order->get_total(); }
						$page++;
					} while ( $page <= (int) ceil( (int) $paid_count->total / 100 ) );
				}
			}
			$backlog_result = wc_get_orders( array( 'limit' => 1, 'page' => 1, 'paginate' => true, 'status' => array( 'processing', 'on-hold' ), 'return' => 'ids' ) );
			$backlog = is_object( $backlog_result ) && isset( $backlog_result->total ) ? (int) $backlog_result->total : null;
			if ( function_exists( 'wc_get_products' ) ) {
				$low_stock = 0; $page = 1;
				do {
					$batch = wc_get_products( array( 'limit' => 100, 'page' => $page, 'paginate' => true, 'status' => array( 'publish', 'private', 'draft' ), 'orderby' => 'ID', 'order' => 'ASC' ) );
					if ( ! is_object( $batch ) || ! isset( $batch->products ) ) { $low_stock = null; break; }
					foreach ( $batch->products as $product ) {
						if ( ! $product->managing_stock() || null === $product->get_stock_quantity() ) { continue; }
						$threshold = (int) $product->get_low_stock_amount();
						if ( $threshold <= 0 ) { $threshold = (int) get_option( 'woocommerce_notify_low_stock_amount', 2 ); }
						if ( $product->get_stock_quantity() <= $threshold ) { $low_stock++; }
					}
					$page++;
				} while ( $page <= (int) ceil( (int) ( $batch->total ?? 0 ) / 100 ) );
			}
		}
		$pending_leads = self::pending_lead_count();
		return rest_ensure_response( array(
			'from' => $from, 'to' => $to, 'orders' => $order_count, 'revenue' => $revenue,
			'fulfillment_backlog' => $backlog, 'low_stock' => $low_stock, 'pending_leads' => $pending_leads,
			'pending_reviews' => class_exists( 'Epic_Reviews_Store' ) ? (int) Epic_Reviews_Store::count( 'pending' ) : null,
			'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : null,
			'generated_at' => gmdate( 'c' ),
		) );
	}

	private static function pending_lead_count() {
		global $wpdb;
		$sources = array( 'epic_contact_requests', 'epic_sample_requests', 'epic_wholesale_inquiries' );
		$total = 0;
		foreach ( $sources as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) { return null; }
			if ( ! in_array( 'email_status', $wpdb->get_col( "SHOW COLUMNS FROM `$table`", 0 ), true ) ) { return null; }
			$total += (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table` WHERE email_status = 'pending'" );
		}
		return $total;
	}

	public static function wholesale_settings( $request ) {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! class_exists( 'Epic_Wholesale_Orders_Store' ) ) { return self::error( 'forbidden', 'Wholesale settings are unavailable or your account cannot manage them.', class_exists( 'Epic_Wholesale_Orders_Store' ) ? 403 : 503 ); }
		if ( 'GET' === $request->get_method() ) { return rest_ensure_response( self::wholesale_settings_data( $request ) ); }
		$body = $request->get_json_params();
		$current = self::wholesale_settings_data();
		if ( empty( $body['expected_revision'] ) || ! hash_equals( $current['revision'], (string) $body['expected_revision'] ) ) { return self::error( 'revision_conflict', 'Wholesale settings changed after you opened them. Reload before saving.', 409 ); }
		$customer_ids = isset( $body['customer_ids'] ) && is_array( $body['customer_ids'] ) ? array_values( array_unique( array_map( 'absint', $body['customer_ids'] ) ) ) : null;
		$levels = isset( $body['levels'] ) && is_array( $body['levels'] ) ? $body['levels'] : null;
		$customer_levels = isset( $body['customer_levels'] ) && is_array( $body['customer_levels'] ) ? $body['customer_levels'] : array();
		$vip_ids = isset( $body['vip_customer_ids'] ) && is_array( $body['vip_customer_ids'] ) ? array_values( array_unique( array_map( 'absint', $body['vip_customer_ids'] ) ) ) : array();
		if ( null === $customer_ids || count( $customer_ids ) > 1000 || null === $levels || empty( $levels ) || count( $levels ) > 25 ) { return self::error( 'invalid_wholesale_settings', 'Provide up to 1,000 wholesale customers and between 1 and 25 pricing levels.' ); }
		$clean_levels = array();
		foreach ( $levels as $key => $level ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || ! is_array( $level ) || ! isset( $level['name'] ) || ! is_string( $level['name'] ) || ! isset( $level['discount'] ) || ! is_numeric( $level['discount'] ) || (float) $level['discount'] < 0 || (float) $level['discount'] > 100 ) { return self::error( 'invalid_wholesale_level', 'Each pricing level needs a name and a discount between 0 and 100.' ); }
			$clean_levels[ $key ] = array( 'name' => sanitize_text_field( $level['name'] ), 'discount' => (float) $level['discount'] );
		}
		$default_level = sanitize_key( (string) ( $body['default_level'] ?? '' ) );
		if ( ! isset( $clean_levels[ $default_level ] ) ) { return self::error( 'invalid_default_level', 'Choose one of the configured pricing levels as the default.' ); }
		$customer_ids = array_values( array_filter( $customer_ids ) );
		foreach ( $customer_ids as $user_id ) {
			$user = get_user_by( 'id', $user_id );
			if ( ! $user || user_can( $user, 'manage_options' ) ) { return self::error( 'invalid_wholesale_customer', 'Only existing non-administrator WordPress accounts can be placed on the wholesale list.' ); }
			$level = sanitize_key( (string) ( $customer_levels[ (string) $user_id ] ?? $default_level ) );
			if ( ! isset( $clean_levels[ $level ] ) ) { return self::error( 'invalid_customer_level', 'Every wholesale customer must use a configured pricing level.' ); }
		}
		$previous = Epic_Wholesale_Orders_Store::get_customers();
		Epic_Wholesale_Orders_Store::save_levels( $clean_levels, $default_level );
		Epic_Wholesale_Orders_Store::set_customers( $customer_ids );
		foreach ( $previous as $user_id ) { if ( ! in_array( (int) $user_id, $customer_ids, true ) ) { delete_user_meta( (int) $user_id, Epic_Wholesale_Orders_Store::USER_META_LEVEL ); } }
		foreach ( $customer_ids as $user_id ) {
			Epic_Wholesale_Orders_Store::set_customer_level( $user_id, $customer_levels[ (string) $user_id ] ?? $default_level );
			Epic_Wholesale_Orders_Store::set_vip( $user_id, in_array( (int) $user_id, $vip_ids, true ) );
		}
		self::log( 'wholesale.settings.update', 'wholesale-settings', '', array( 'customer_ids', 'customer_levels', 'vip_customer_ids', 'levels', 'default_level' ), 'success' );
		return rest_ensure_response( self::wholesale_settings_data() );
	}

	private static function wholesale_settings_data( $request = null ) {
		$levels = Epic_Wholesale_Orders_Store::get_levels();
		$default = Epic_Wholesale_Orders_Store::get_default_level_key();
		$customers = array();
		foreach ( Epic_Wholesale_Orders_Store::get_customers() as $user_id ) {
			$user = get_user_by( 'id', $user_id );
			if ( ! $user ) { continue; }
			$customers[] = array( 'id' => (int) $user_id, 'name' => $user->display_name, 'email' => $user->user_email, 'level' => Epic_Wholesale_Orders_Store::get_customer_level( $user_id ), 'vip' => Epic_Wholesale_Orders_Store::is_vip( $user_id ) );
		}
		$candidates = array();
		$search = $request ? sanitize_text_field( (string) $request->get_param( 'search' ) ) : '';
		if ( strlen( $search ) >= 2 ) {
			$page = max( 1, absint( $request->get_param( 'page' ) ) );
			$query = new WP_User_Query( array( 'role__in' => array( 'customer', 'subscriber' ), 'search' => '*' . $search . '*', 'search_columns' => array( 'user_email', 'user_login', 'display_name' ), 'number' => 20, 'paged' => $page, 'orderby' => 'display_name', 'order' => 'ASC', 'fields' => array( 'ID', 'display_name', 'user_email' ) ) );
			foreach ( $query->get_results() as $user ) {
				if ( user_can( $user->ID, 'manage_options' ) ) { continue; }
				$candidates[] = array( 'id' => (int) $user->ID, 'name' => $user->display_name, 'email' => $user->user_email, 'selected' => Epic_Wholesale_Orders_Store::is_customer( $user->ID ) );
			}
		}
		$data = array( 'customers' => $customers, 'levels' => $levels, 'default_level' => $default, 'available_customers' => $candidates );
		$data['revision'] = hash( 'sha256', wp_json_encode( $data ) );
		return $data;
	}

	public static function newsletter_campaigns( $request ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return self::error( 'forbidden', 'You cannot manage newsletter campaigns.', 403 ); }
		if ( ! class_exists( 'Epic_Newsletter_Campaign_Store' ) || ! class_exists( 'Epic_Newsletter_Store' ) ) { return self::error( 'newsletter_unavailable', 'Activate the newsletter subscription plugin to manage campaigns.', 503 ); }
		if ( 'GET' === $request->get_method() ) {
			$page = max( 1, absint( $request->get_param( 'page' ) ) ); $per_page = min( 100, max( 1, absint( $request->get_param( 'per_page' ) ) ?: 20 ) );
			$items = Epic_Newsletter_Campaign_Store::get_page( $per_page, ( $page - 1 ) * $per_page );
			$items = array_map( static function ( $campaign ) { $campaign['revision'] = self::campaign_revision( $campaign ); return $campaign; }, $items );
			return rest_ensure_response( array( 'items' => $items, 'page' => $page, 'per_page' => $per_page, 'total' => Epic_Newsletter_Campaign_Store::count() ) );
		}
		$body = $request->get_json_params();
		$subject_vi = isset( $body['subject_vi'] ) ? sanitize_text_field( $body['subject_vi'] ) : '';
		$body_vi = isset( $body['body_vi'] ) ? wp_kses_post( $body['body_vi'] ) : '';
		$recipient_filter = isset( $body['recipient_filter'] ) ? sanitize_key( $body['recipient_filter'] ) : 'all';
		if ( '' === trim( $subject_vi ) || '' === trim( wp_strip_all_tags( $body_vi ) ) || ! in_array( $recipient_filter, array( 'all', 'vi', 'en' ), true ) ) { return self::error( 'invalid_campaign', 'A Vietnamese subject and body plus a supported recipient group are required.' ); }
		$reservation = self::reserve_mutation( $request, 'newsletter-create' );
		if ( is_wp_error( $reservation ) ) { return $reservation; }
		if ( isset( $reservation['replay'] ) ) { return rest_ensure_response( $reservation['replay'] ); }
		$data = array( 'subject_vi' => $subject_vi, 'subject_en' => isset( $body['subject_en'] ) ? sanitize_text_field( $body['subject_en'] ) : '', 'body_vi' => $body_vi, 'body_en' => isset( $body['body_en'] ) ? wp_kses_post( $body['body_en'] ) : '', 'recipient_filter' => $recipient_filter );
		$id = Epic_Newsletter_Campaign_Store::create_draft( $data );
		if ( ! $id ) { self::finish_mutation( $reservation['hash'], 'uncertain', null ); return self::error( 'campaign_save_failed', 'The campaign draft could not be saved. Check its status before retrying.', 503 ); }
		Epic_Newsletter_Campaign_Store::snapshot_recipients( $id, $recipient_filter );
		$campaign = Epic_Newsletter_Campaign_Store::get( $id ); $campaign['revision'] = self::campaign_revision( $campaign );
		$response = array( 'ok' => true, 'campaign' => $campaign );
		self::finish_mutation( $reservation['hash'], 'success', $response );
		self::log( 'newsletter.campaign.create', 'newsletter', (string) $id, array_keys( $data ), 'success' );
		return rest_ensure_response( $response );
	}

	public static function newsletter_campaign( $request ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return self::error( 'forbidden', 'You cannot manage newsletter campaigns.', 403 ); }
		if ( ! class_exists( 'Epic_Newsletter_Campaign_Store' ) ) { return self::error( 'newsletter_unavailable', 'Activate the newsletter subscription plugin to manage campaigns.', 503 ); }
		$id = absint( $request['id'] ); $campaign = Epic_Newsletter_Campaign_Store::get( $id );
		if ( ! $campaign ) { return self::error( 'not_found', 'Campaign not found.', 404 ); }
		if ( 'GET' === $request->get_method() ) { $campaign['revision'] = self::campaign_revision( $campaign ); return rest_ensure_response( $campaign ); }
		$body = $request->get_json_params();
		if ( 'draft' !== $campaign['status'] ) { return self::error( 'campaign_locked', 'A campaign cannot be changed after background delivery has started.', 409 ); }
		if ( empty( $body['expected_revision'] ) || ! hash_equals( self::campaign_revision( $campaign ), (string) $body['expected_revision'] ) ) { return self::error( 'revision_conflict', 'This campaign changed after you opened it. Reload before saving.', 409 ); }
		$subject_vi = isset( $body['subject_vi'] ) ? sanitize_text_field( $body['subject_vi'] ) : '';
		$body_vi = isset( $body['body_vi'] ) ? wp_kses_post( $body['body_vi'] ) : '';
		$recipient_filter = isset( $body['recipient_filter'] ) ? sanitize_key( $body['recipient_filter'] ) : $campaign['recipient_filter'];
		if ( '' === trim( $subject_vi ) || '' === trim( wp_strip_all_tags( $body_vi ) ) || ! in_array( $recipient_filter, array( 'all', 'vi', 'en' ), true ) ) { return self::error( 'invalid_campaign', 'A Vietnamese subject and body plus a supported recipient group are required.' ); }
		$data = array( 'subject_vi' => $subject_vi, 'subject_en' => isset( $body['subject_en'] ) ? sanitize_text_field( $body['subject_en'] ) : '', 'body_vi' => $body_vi, 'body_en' => isset( $body['body_en'] ) ? wp_kses_post( $body['body_en'] ) : '', 'recipient_filter' => $recipient_filter );
		if ( ! Epic_Newsletter_Campaign_Store::update_draft( $id, $data ) ) { return self::error( 'campaign_save_failed', 'The campaign draft could not be saved.', 503 ); }
		if ( $recipient_filter !== $campaign['recipient_filter'] ) {
			global $wpdb;
			$wpdb->delete( Epic_Newsletter_Campaign_Store::recipients_table(), array( 'campaign_id' => $id ), array( '%d' ) );
			Epic_Newsletter_Campaign_Store::snapshot_recipients( $id, $recipient_filter );
		}
		$updated = Epic_Newsletter_Campaign_Store::get( $id ); $updated['revision'] = self::campaign_revision( $updated );
		self::log( 'newsletter.campaign.update', 'newsletter', (string) $id, array_keys( $data ), 'success' );
		return rest_ensure_response( array( 'ok' => true, 'campaign' => $updated ) );
	}

	public static function newsletter_send( $request ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return self::error( 'forbidden', 'You cannot send newsletter campaigns.', 403 ); }
		if ( ! class_exists( 'Epic_Newsletter_Campaign_Store' ) || ! class_exists( 'Epic_Newsletter_Broadcast_Sender' ) ) { return self::error( 'newsletter_unavailable', 'Newsletter background delivery is unavailable.', 503 ); }
		$body = $request->get_json_params();
		if ( empty( $body['confirm_send'] ) ) { return self::error( 'campaign_confirmation_required', 'Review the campaign and explicitly confirm sending.', 400 ); }
		$id = absint( $request['id'] ); $campaign = Epic_Newsletter_Campaign_Store::get( $id );
		if ( ! $campaign ) { return self::error( 'not_found', 'Campaign not found.', 404 ); }
		$reservation = self::reserve_mutation( $request, 'newsletter-send:' . $id );
		if ( is_wp_error( $reservation ) ) { return $reservation; }
		if ( isset( $reservation['replay'] ) ) { return rest_ensure_response( $reservation['replay'] ); }
		if ( 'draft' !== $campaign['status'] ) { self::finish_mutation( $reservation['hash'], 'uncertain', null ); return self::error( 'campaign_locked', 'This campaign is already queued or has already been sent. Refresh its delivery status.', 409 ); }
		$started = Epic_Newsletter_Broadcast_Sender::start( $id );
		if ( ! $started ) {
			$latest = Epic_Newsletter_Campaign_Store::get( $id );
			if ( $latest && 'draft' !== $latest['status'] ) { self::finish_mutation( $reservation['hash'], 'success', array( 'ok' => true, 'queued' => true, 'status' => $latest['status'], 'campaign_id' => $id ) ); return self::error( 'campaign_already_queued', 'This campaign has already entered the delivery queue. Refresh its status.', 409 ); }
			self::finish_mutation( $reservation['hash'], 'failed', null );
			return self::error( 'campaign_queue_failed', 'The newsletter campaign could not be queued. Check integration status before retrying.', 503 );
		}
		$response = array( 'ok' => true, 'queued' => true, 'status' => 'sending', 'campaign_id' => $id );
		self::finish_mutation( $reservation['hash'], 'success', $response );
		self::log( 'newsletter.campaign.send', 'newsletter', (string) $id, array( 'status', 'background_queue' ), 'success' );
		return rest_ensure_response( $response );
	}

	private static function campaign_revision( $campaign ) {
		unset( $campaign['revision'] );
		return hash( 'sha256', wp_json_encode( $campaign ) );
	}

	private static function reserve_mutation( $request, $action ) {
		global $wpdb;
		$key = (string) $request->get_header( 'idempotency-key' );
		if ( ! preg_match( '/^[A-Za-z0-9_-]{16,128}$/', $key ) ) { return self::error( 'idempotency_required', 'A unique action key is required for this request.' ); }
		$hash = self::hash( $key . ':' . $action . ':' . get_current_user_id() );
		$table = self::tables()['receipts'];
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE receipt_key = %s", $hash ) );
		if ( $existing ) {
			if ( 'success' === $existing->outcome && $existing->response ) { return array( 'hash' => $hash, 'replay' => json_decode( $existing->response, true ) ); }
			return self::error( 'mutation_reconcile', 'This action already ran or has an uncertain outcome. Refresh the record before retrying.', 409 );
		}
		$inserted = $wpdb->insert( $table, array( 'receipt_key' => $hash, 'user_id' => get_current_user_id(), 'action' => sanitize_text_field( $action ), 'outcome' => 'pending', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ), array( '%s', '%d', '%s', '%s', '%s' ) );
		if ( false === $inserted ) { return self::error( 'mutation_reservation_failed', 'This action could not be safely reserved. No external action was started.', 503 ); }
		return array( 'hash' => $hash );
	}

	private static function finish_mutation( $hash, $outcome, $response ) {
		global $wpdb;
		$wpdb->update( self::tables()['receipts'], array( 'outcome' => sanitize_key( $outcome ), 'response' => null === $response ? null : wp_json_encode( $response ) ), array( 'receipt_key' => $hash ), array( '%s', '%s' ), array( '%s' ) );
	}

	public static function health() {
		return rest_ensure_response( array( 'wordpress' => true, 'woocommerce' => class_exists( 'WooCommerce' ), 'plugins' => array(
			'epic-viettelpost-shipping' => class_exists( 'Epic_VTP_Ajax' ), 'epic-product-reviews' => class_exists( 'Epic_Reviews_Store' ),
			'epic-product-cost' => class_exists( 'Epic_Product_Cost_Store' ), 'epic-distributor-profit' => class_exists( 'Epic_Distributor_Profit_Store' ),
			'epic-newsletter-subscription' => class_exists( 'Epic_Newsletter_Campaign_Store' ), 'epic-wholesale-orders' => class_exists( 'Epic_Wholesale_Orders_Store' ) && post_type_exists( 'epic_wholesale_order' ),
			'epic-contact-requests' => self::has_table( 'epic_contact_requests' ), 'epic-sample-requests' => self::has_table( 'epic_sample_requests' ),
			'epic-wholesale-inquiries' => self::has_table( 'epic_wholesale_inquiries' ), 'epic-advanced-coupons' => class_exists( 'Epic_Adv_Coupons_Meta' ),
			'epic-newsletter-campaigns' => class_exists( 'Epic_Newsletter_Campaign_Store' ) && self::has_table( 'epic_newsletter_campaigns' ),
			'epic-distributor-ledger' => class_exists( 'Epic_Distributor_Profit_Store' ) && self::has_table( 'epic_distributor_profit_entries' ),
		) ) );
	}

	public static function product_change( $request ) {
		$operation = (string) $request['operation'];
		$ability_name = 'epic-product-mcp/' . ( 'preview' === $operation ? 'preview-change' : 'apply-change' );
		if ( ! function_exists( 'wp_get_ability' ) ) { return self::error( 'product_mcp_unavailable', 'The reviewed product editing service is unavailable.', 503 ); }
		$ability = wp_get_ability( $ability_name );
		if ( ! $ability || ! is_callable( array( $ability, 'execute' ) ) ) { return self::error( 'product_mcp_unavailable', 'Activate EPIC Product MCP to edit products from this dashboard.', 503 ); }
		$input = $request->get_json_params();
		$result = $ability->execute( is_array( $input ) ? $input : array() );
		if ( is_wp_error( $result ) ) { return $result; }
		self::log( 'product.' . $operation, 'products', isset( $input['id'] ) ? (string) absint( $input['id'] ) : '', array_keys( $input ), 'success' );
		return rest_ensure_response( $result );
	}

	public static function product_variations( $request ) {
		return self::execute_product_ability( 'epic-product-mcp/list-product-variations', array( 'product_id' => absint( $request['product_id'] ), 'page' => max( 1, absint( $request->get_param( 'page' ) ) ), 'per_page' => min( 100, max( 1, absint( $request->get_param( 'per_page' ) ) ?: 100 ) ) ) );
	}

	public static function product_variation( $request ) {
		return self::execute_product_ability( 'epic-product-mcp/get-product-variation', array( 'product_id' => absint( $request['product_id'] ), 'variation_id' => absint( $request['variation_id'] ) ) );
	}

	private static function execute_product_ability( $name, $input ) {
		if ( ! current_user_can( 'edit_products' ) || ! function_exists( 'wp_get_ability' ) ) { return self::error( 'forbidden', 'You cannot manage WooCommerce products or variations.', 403 ); }
		$ability = wp_get_ability( $name );
		if ( ! $ability || ! is_callable( array( $ability, 'execute' ) ) ) { return self::error( 'product_mcp_unavailable', 'Activate EPIC Product MCP to inspect product variations.', 503 ); }
		$result = $ability->execute( $input );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function shipment_action( $request ) {
		$order_id = absint( $request['order_id'] );
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! $order ) { return self::error( 'not_found', 'Order not found.', 404 ); }
		if ( ! current_user_can( 'edit_shop_order', $order_id ) && ! current_user_can( 'manage_woocommerce' ) ) { return self::error( 'forbidden', 'You cannot manage shipments for this order.', 403 ); }
		if ( ! class_exists( 'Epic_VTP_Ajax' ) || ! class_exists( 'Epic_VTP_Order_Meta_Box' ) || ! class_exists( 'Epic_VTP_Client' ) ) { return self::error( 'shipping_unavailable', 'ViettelPost shipping plugin is unavailable.', 503 ); }
		$body = $request->get_json_params();
		$action = isset( $body['action'] ) ? sanitize_key( $body['action'] ) : '';
		if ( ! in_array( $action, array( 'book', 'cancel', 'label', 'status', 'reconcile' ), true ) ) { return self::error( 'invalid_action', 'Unsupported shipment action.' ); }
		if ( 'label' === $action ) {
			$tracking = (string) $order->get_meta( Epic_VTP_Order_Meta_Box::META_ORDER_NUMBER );
			if ( ! $tracking ) { return self::error( 'shipment_missing', 'This order has no shipment label.', 404 ); }
			$token = Epic_VTP_Client::gen_print_token( array( $tracking ) );
			if ( is_wp_error( $token ) ) { return $token; }
			$settings = Epic_VTP_Client::get_settings();
			return rest_ensure_response( array( 'url' => Epic_VTP_Client::print_url( $token, $settings['label_size'], 'yes' === $settings['label_show_postage'] ) ) );
		}
		if ( 'status' === $action ) {
			$status = isset( $body['status'] ) ? sanitize_text_field( $body['status'] ) : '';
			if ( ! $order->get_meta( Epic_VTP_Order_Meta_Box::META_ORDER_NUMBER ) || ! isset( Epic_VTP_Client::status_map()[ $status ] ) ) { return self::error( 'invalid_status', 'A valid status and existing shipment are required.' ); }
			$order->update_meta_data( Epic_VTP_Order_Meta_Box::META_STATUS, $status );
			$order->save();
			do_action( 'epic_vtp_status_changed', $order, $status, 'manual' );
			self::log( 'shipment.status', 'shipments', (string) $order_id, array( '_vtp_shipment_status' ), 'success' );
			return rest_ensure_response( array( 'ok' => true, 'status' => $status, 'label' => Epic_VTP_Client::status_label( $status ) ) );
		}
		global $wpdb;
		$tables = self::tables();
		$receipt = sanitize_text_field( (string) $request->get_header( 'idempotency-key' ) );
		if ( ! preg_match( '/^[A-Za-z0-9_-]{16,128}$/', $receipt ) ) { return self::error( 'idempotency_required', 'A unique action key is required before contacting ViettelPost.' ); }
		$receipt_hash = self::hash( $receipt . ':' . $action . ':' . $order_id . ':' . get_current_user_id() );
		$lock_name = 'epic-admin-shipment-' . $order_id;
		$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );
		if ( '1' !== (string) $locked ) { return self::error( 'shipment_busy', 'Another shipment action is being processed. Refresh the order and try again.', 409 ); }
		try {
			$prior = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['receipts']} WHERE receipt_key = %s", $receipt_hash ) );
			if ( $prior ) { if ( 'success' === $prior->outcome && $prior->response ) { return rest_ensure_response( json_decode( $prior->response, true ) ); } return self::error( 'shipment_reconcile', 'This courier action already ran or has an uncertain result. Reconcile it before trying again.', 409 ); }
			$pending_rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, created_at FROM {$tables['receipts']} WHERE action IN (%s, %s) AND outcome = 'pending'", 'shipment-book:' . $order_id, 'shipment-cancel:' . $order_id ) );
			foreach ( $pending_rows as $pending_row ) {
				if ( strtotime( $pending_row->created_at . ' UTC' ) <= time() - 120 ) { $wpdb->update( $tables['receipts'], array( 'outcome' => 'uncertain' ), array( 'id' => (int) $pending_row->id, 'outcome' => 'pending' ), array( '%s' ), array( '%d', '%s' ) ); }
				else { return self::error( 'shipment_busy', 'A courier request is still being processed. Wait briefly, then refresh the order.', 409 ); }
			}
			$uncertain_rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tables['receipts']} WHERE action IN (%s, %s) AND outcome = 'uncertain' ORDER BY id DESC", 'shipment-book:' . $order_id, 'shipment-cancel:' . $order_id ) );
			if ( count( $uncertain_rows ) > 1 ) { return self::error( 'shipment_reconcile', 'More than one courier action needs reconciliation. Resolve the order in wp-admin before continuing.', 409 ); }
			if ( $uncertain_rows && 'reconcile' !== $action ) { return self::error( 'shipment_reconcile', 'A previous courier action has an uncertain result. Reconcile it before another action.', 409 ); }
			if ( 'reconcile' === $action ) {
				if ( ! $uncertain_rows ) { return self::error( 'shipment_not_uncertain', 'There is no uncertain courier action to reconcile.', 409 ); }
				$uncertain_row = $uncertain_rows[0];
				$uncertain_action = ( 'shipment-book:' . $order_id === $uncertain_row->action ) ? 'book' : 'cancel';
				$outcome = isset( $body['outcome'] ) ? sanitize_key( $body['outcome'] ) : '';
				$has_waybill = (bool) $order->get_meta( Epic_VTP_Order_Meta_Box::META_ORDER_NUMBER );
				$receipt_outcome = 'success';
				$response = array( 'ok' => true, 'reconciled' => true );
				if ( 'book' === $uncertain_action ) {
					if ( $has_waybill && 'booked' === $outcome ) { $response['shipment_exists'] = true; $response['trackingCode'] = (string) $order->get_meta( Epic_VTP_Order_Meta_Box::META_ORDER_NUMBER ); }
					elseif ( ! $has_waybill && 'booked' === $outcome ) {
						$tracking = isset( $body['tracking'] ) ? strtoupper( sanitize_text_field( $body['tracking'] ) ) : '';
						if ( ! preg_match( '/^[A-Z0-9-]{4,50}$/', $tracking ) ) { return self::error( 'tracking_required', 'Enter the tracking code shown in ViettelPost before confirming this booking.', 400 ); }
						$duplicates = wc_get_orders( array( 'limit' => 1, 'return' => 'ids', 'meta_query' => array( array( 'key' => Epic_VTP_Order_Meta_Box::META_ORDER_NUMBER, 'value' => $tracking ) ) ) );
						if ( $duplicates ) { return self::error( 'tracking_conflict', 'This tracking code is already linked to an order.', 409 ); }
						$order->update_meta_data( Epic_VTP_Order_Meta_Box::META_ORDER_NUMBER, $tracking );
						$order->update_meta_data( Epic_VTP_Order_Meta_Box::META_STATUS, '102' );
						$order->update_meta_data( Epic_VTP_Order_Meta_Box::META_LAST_SYNCED, current_time( 'mysql' ) );
						$order->add_order_note( sprintf( 'ViettelPost shipment %s confirmed in the courier portal and linked after an uncertain dashboard booking.', $tracking ) );
						$order->save();
						do_action( 'epic_vtp_shipment_booked', $order, $tracking, 'manual-reconciliation' );
						$response['shipment_exists'] = true; $response['trackingCode'] = $tracking;
					} elseif ( ! $has_waybill && 'not_booked' === $outcome ) {
						$receipt_outcome = 'failed'; $response['ok'] = false; $response['shipment_exists'] = false; $response['ready_to_retry'] = true;
					} else { return self::error( 'confirmation_required', 'Confirm the booking result in ViettelPost before resolving this action.', 400 ); }
				} else {
				$tracking = (string) $order->get_meta( Epic_VTP_Order_Meta_Box::META_ORDER_NUMBER );
				if ( ! $has_waybill ) { return self::error( 'shipment_missing', 'The order has no linked shipment to reconcile. Review it in wp-admin.', 409 ); }
				if ( 'cancelled' === $outcome ) {
					$order->add_order_note( sprintf( 'ViettelPost shipment %s confirmed cancelled in the courier portal after an uncertain dashboard cancellation.', $tracking ) );
					foreach ( array( Epic_VTP_Order_Meta_Box::META_ORDER_NUMBER, Epic_VTP_Order_Meta_Box::META_EXPECTED, Epic_VTP_Order_Meta_Box::META_STATUS, Epic_VTP_Order_Meta_Box::META_LAST_SYNCED, Epic_VTP_Order_Meta_Box::META_FEE ) as $key ) { $order->delete_meta_data( $key ); }
					$order->save(); $response['shipment_exists'] = false;
				} elseif ( 'not_cancelled' === $outcome ) { $receipt_outcome = 'failed'; $response['ok'] = false; $response['shipment_exists'] = true; $response['ready_to_retry'] = true; }
				else { return self::error( 'confirmation_required', 'Confirm the cancellation result in ViettelPost before resolving this action.', 400 ); }
			}
			$wpdb->update( $tables['receipts'], array( 'outcome' => $receipt_outcome, 'response' => wp_json_encode( $response ) ), array( 'action' => $uncertain_row->action, 'outcome' => 'uncertain' ), array( '%s', '%s' ), array( '%s', '%s' ) );
			self::log( 'shipment.reconcile', 'shipments', (string) $order_id, array( 'action', 'outcome' ), 'success' );
			return rest_ensure_response( $response );
			}
			if ( 'book' === $action && $order->get_meta( Epic_VTP_Order_Meta_Box::META_ORDER_NUMBER ) ) { return self::error( 'shipment_exists', 'This order already has a ViettelPost shipment.', 409 ); }
			if ( 'cancel' === $action && ! $order->get_meta( Epic_VTP_Order_Meta_Box::META_ORDER_NUMBER ) ) { return self::error( 'shipment_missing', 'This order has no shipment to cancel.', 404 ); }
			$action_name = 'shipment-' . $action . ':' . $order_id;
			$in_flight = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tables['receipts']} WHERE action IN (%s, %s) AND outcome IN ('pending', 'uncertain') LIMIT 1", 'shipment-book:' . $order_id, 'shipment-cancel:' . $order_id ) );
			if ( $in_flight ) { return self::error( 'shipment_reconcile', 'A previous courier action is still pending or uncertain. Reconcile it before another action.', 409 ); }
			$inserted = $wpdb->insert( $tables['receipts'], array( 'receipt_key' => $receipt_hash, 'user_id' => get_current_user_id(), 'action' => $action_name, 'outcome' => 'pending', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ), array( '%s', '%d', '%s', '%s', '%s' ) );
			if ( false === $inserted ) { return self::error( 'shipment_receipt_failed', 'The shipment action could not be reserved safely. No courier request was sent.', 503 ); }
			if ( 'book' === $action ) { $result = Epic_VTP_Ajax::book_single_order( $order ); }
			else {
				$tracking = (string) $order->get_meta( Epic_VTP_Order_Meta_Box::META_ORDER_NUMBER );
				if ( ! $tracking ) { $result = self::error( 'shipment_missing', 'This order has no shipment to cancel.', 404 ); }
				else {
					$result = Epic_VTP_Client::cancel_order( $tracking, 'Cancelled from EPIC Admin dashboard' );
					if ( ! is_wp_error( $result ) ) { $order->add_order_note( sprintf( 'ViettelPost shipment %s cancelled from EPIC Admin dashboard.', $tracking ) ); foreach ( array( Epic_VTP_Order_Meta_Box::META_ORDER_NUMBER, Epic_VTP_Order_Meta_Box::META_EXPECTED, Epic_VTP_Order_Meta_Box::META_STATUS, Epic_VTP_Order_Meta_Box::META_LAST_SYNCED, Epic_VTP_Order_Meta_Box::META_FEE ) as $key ) { $order->delete_meta_data( $key ); } $order->save(); }
				}
			}
			if ( is_wp_error( $result ) ) {
				$wpdb->update( $tables['receipts'], array( 'outcome' => 'uncertain' ), array( 'receipt_key' => $receipt_hash ), array( '%s' ), array( '%s' ) );
				self::log( 'shipment.' . $action, 'shipments', (string) $order_id, array( $action ), 'uncertain' );
				return self::error( $result->get_error_code(), $result->get_error_message() . ' Check shipment state before retrying.', 409 );
			}
			$response = array( 'ok' => true );
			if ( is_array( $result ) ) { $response = array_merge( $response, $result ); }
			$wpdb->update( $tables['receipts'], array( 'outcome' => 'success', 'response' => wp_json_encode( $response ) ), array( 'receipt_key' => $receipt_hash ), array( '%s', '%s' ), array( '%s' ) );
			self::log( 'shipment.' . $action, 'shipments', (string) $order_id, array( $action ), 'success' );
			return rest_ensure_response( $response );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	public static function audit( $request ) {
		global $wpdb;
		$page = max( 1, absint( $request->get_param( 'page' ) ) );
		$limit = min( 100, max( 1, absint( $request->get_param( 'per_page' ) ) ?: 25 ) );
		$table = self::tables()['audit'];
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table ORDER BY id DESC LIMIT %d OFFSET %d", $limit, ( $page - 1 ) * $limit ), ARRAY_A );
		return rest_ensure_response( array( 'items' => $rows, 'page' => $page, 'per_page' => $limit, 'total' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ) ) );
	}

	public static function finance_export( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) { return self::error( 'forbidden', 'You cannot export finance records.', 403 ); }
		if ( ! class_exists( 'Epic_Distributor_Profit_Store' ) ) { return self::error( 'finance_unavailable', 'The distributor finance plugin is unavailable.', 503 ); }
		$args = self::finance_filters( $request );
		if ( is_wp_error( $args ) ) { return $args; }
		$total = Epic_Distributor_Profit_Store::count_entries( $args );
		if ( $total > 10000 ) { return self::error( 'export_too_large', 'Narrow the finance filters to 10,000 rows or fewer before exporting.', 413 ); }
		$rows = Epic_Distributor_Profit_Store::query_entries( $args );
		$columns = array( 'entry_date' => 'Ngày', 'order_code' => 'Mã đơn', 'channel' => 'Kênh', 'distributor_name' => 'Nhà phân phối', 'product_name' => 'Sản phẩm', 'quantity' => 'Số lượng', 'revenue' => 'Doanh thu', 'cost' => 'Giá vốn', 'shipping_cost' => 'Phí vận chuyển', 'other_cost_label' => 'Chi phí khác', 'other_cost_amount' => 'Giá trị chi phí khác', 'gross_profit' => 'Lợi nhuận gộp', 'commission_percent_snapshot' => 'Tỷ lệ hoa hồng', 'commission_amount' => 'Hoa hồng', 'net_profit' => 'Lợi nhuận ròng', 'source' => 'Nguồn' );
		$stream = fopen( 'php://temp', 'r+' );
		fwrite( $stream, "\xEF\xBB\xBF" );
		fputcsv( $stream, array_values( $columns ) );
		foreach ( $rows as $row ) {
			$values = array();
			foreach ( array_keys( $columns ) as $key ) { $value = isset( $row->{$key} ) ? (string) $row->{$key} : ''; if ( ! is_numeric( $value ) && preg_match( '/^[=+\-@\t\r]/', $value ) ) { $value = "'" . $value; } $values[] = $value; }
			fputcsv( $stream, $values );
		}
		rewind( $stream ); $csv = stream_get_contents( $stream ); fclose( $stream );
		return rest_ensure_response( array( 'filename' => 'epic-ledger-' . gmdate( 'Y-m-d' ) . '.csv', 'data_base64' => base64_encode( $csv ), 'rows' => count( $rows ) ) );
	}

	public static function coupon_redemptions( $request ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return self::error( 'forbidden', 'You cannot view coupon redemptions.', 403 ); }
		if ( ! class_exists( 'Epic_Adv_Coupons_Redemption_Log' ) ) { return self::error( 'coupon_reports_unavailable', 'Activate EPIC Advanced Coupons to view redemption reports.', 503 ); }
		$from = sanitize_text_field( (string) $request->get_param( 'from' ) );
		$to = sanitize_text_field( (string) $request->get_param( 'to' ) );
		foreach ( array( $from, $to ) as $date ) {
			if ( '' === $date ) { continue; }
			if ( ! preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $date ) ) { return self::error( 'invalid_date', 'Enter valid redemption dates in YYYY-MM-DD format.' ); }
			if ( ! checkdate( (int) substr( $date, 5, 2 ), (int) substr( $date, 8, 2 ), (int) substr( $date, 0, 4 ) ) ) { return self::error( 'invalid_date', 'Enter valid redemption dates in YYYY-MM-DD format.' ); }
		}
		if ( '' !== $from && '' !== $to ) { $days = (int) ( new DateTimeImmutable( $from ) )->diff( new DateTimeImmutable( $to ) )->format( '%r%a' ); if ( $days < 0 || $days > 365 ) { return self::error( 'invalid_date_range', 'Choose a date range of at most 366 days.' ); } }
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( '' !== $status && ! in_array( $status, array( 'all', 'active', 'removed' ), true ) ) { return self::error( 'invalid_status', 'Choose an active, removed, or all redemptions filter.' ); }
		$args = array(
			'search' => sanitize_text_field( (string) $request->get_param( 'search' ) ),
			'email' => sanitize_email( (string) $request->get_param( 'email' ) ),
			'generated_from' => absint( $request->get_param( 'generated_from' ) ),
			'status' => 'all' === $status ? '' : $status,
			'date_from' => $from,
			'date_to' => $to,
			'orderby' => sanitize_key( (string) $request->get_param( 'sort' ) ),
			'order' => 'asc' === strtolower( (string) $request->get_param( 'order' ) ) ? 'ASC' : 'DESC',
			'per_page' => min( 100, max( 1, absint( $request->get_param( 'per_page' ) ) ?: 20 ) ),
			'page' => max( 1, absint( $request->get_param( 'page' ) ) ),
		);
		if ( ! in_array( $args['orderby'], array( 'redeemed_at', 'updated_at', 'coupon_code', 'discount_amount', 'order_id' ), true ) ) { $args['orderby'] = 'redeemed_at'; }
		$items = Epic_Adv_Coupons_Redemption_Log::query( $args );
		return rest_ensure_response( array( 'items' => $items, 'page' => $args['page'], 'per_page' => $args['per_page'], 'total' => Epic_Adv_Coupons_Redemption_Log::count( $args ) ) );
	}

	public static function create_coupon( $request ) {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Coupon' ) ) { return self::error( 'forbidden', 'Coupon management is unavailable for this account.', 403 ); }
		$body = $request->get_json_params();
		$input = self::validate_coupon_input( $body, true );
		if ( is_wp_error( $input ) ) { return $input; }
		$reservation = self::reserve_mutation( $request, 'coupon-create' );
		if ( is_wp_error( $reservation ) ) { return $reservation; }
		if ( isset( $reservation['replay'] ) ) { return rest_ensure_response( $reservation['replay'] ); }
		if ( wc_get_coupon_id_by_code( wc_format_coupon_code( $input['code'] ) ) ) { self::finish_mutation( $reservation['hash'], 'failed', null ); return self::error( 'coupon_code_exists', 'That coupon code already exists. Choose a different code.', 409 ); }
		$coupon = new WC_Coupon();
		$result = self::apply_coupon_fields( $coupon, $input );
		if ( is_wp_error( $result ) ) { self::finish_mutation( $reservation['hash'], 'success', array( 'ok' => false, 'error' => $result->get_error_code() ) ); return $result; }
		try { $id = $coupon->save(); } catch ( Throwable $error ) { self::finish_mutation( $reservation['hash'], 'uncertain', null ); return self::error( 'coupon_save_uncertain', 'Coupon save did not return a confirmed result. Check the coupon list before retrying.', 503 ); }
		if ( ! $id ) { self::finish_mutation( $reservation['hash'], 'uncertain', null ); return self::error( 'coupon_save_uncertain', 'Coupon save did not return a confirmed result. Check the coupon list before retrying.', 503 ); }
		if ( isset( $input['advanced_rules'] ) ) { $rules = self::save_coupon_advanced_rules( $id, $input['advanced_rules'] ); if ( is_wp_error( $rules ) ) { self::finish_mutation( $reservation['hash'], 'uncertain', null ); return $rules; } }
		$data = self::coupon_data( new WC_Coupon( $id ) );
		$response = array( 'ok' => true, 'coupon' => $data );
		self::finish_mutation( $reservation['hash'], 'success', $response );
		self::log( 'coupon.create', 'coupons', (string) $id, array_keys( $input ), 'success' );
		return rest_ensure_response( $response );
	}

	private static function validate_coupon_input( $body, $creating = false ) {
		if ( ! is_array( $body ) ) { return self::error( 'invalid_coupon', 'Provide coupon details as a JSON object.' ); }
		$allowed = array( 'code', 'description', 'discount_type', 'amount', 'status', 'individual_use', 'usage_limit', 'usage_limit_per_user', 'limit_usage_to_x_items', 'free_shipping', 'minimum_amount', 'maximum_amount', 'email_restrictions', 'product_ids', 'excluded_product_ids', 'date_expires', 'advanced_rules', 'confirm_publish', 'expected_revision' );
		if ( array_diff( array_keys( $body ), $allowed ) ) { return self::error( 'unsupported_coupon_field', 'One or more coupon fields are not supported by the dashboard.' ); }
		$code = isset( $body['code'] ) ? trim( sanitize_text_field( $body['code'] ) ) : '';
		$type = isset( $body['discount_type'] ) ? sanitize_key( $body['discount_type'] ) : '';
		$amount = isset( $body['amount'] ) && is_numeric( $body['amount'] ) ? (float) $body['amount'] : -1;
		$status = $creating ? 'draft' : sanitize_key( (string) ( $body['status'] ?? 'draft' ) );
		if ( $creating && isset( $body['status'] ) && 'draft' !== sanitize_key( (string) $body['status'] ) ) { return self::error( 'coupon_create_draft_only', 'New coupons must start as drafts and be reviewed before publishing.' ); }
		if ( '' === $code || strlen( $code ) > 64 || preg_match( '/[\\x00-\\x1f]/', $code ) ) { return self::error( 'invalid_coupon_code', 'Enter a coupon code up to 64 characters long.' ); }
		if ( ! in_array( $type, array( 'percent', 'fixed_cart', 'fixed_product' ), true ) ) { return self::error( 'invalid_discount_type', 'Choose a supported coupon discount type.' ); }
		if ( $amount < 0 || ( 'percent' === $type && $amount > 100 ) ) { return self::error( 'invalid_coupon_amount', 'Discount amount must be non-negative and a percentage cannot exceed 100.' ); }
		if ( ! in_array( $status, array( 'draft', 'publish' ), true ) ) { return self::error( 'invalid_coupon_status', 'Coupons can only be saved as drafts or published.' ); }
		if ( 'publish' === $status && ( empty( $body['confirm_publish'] ) || $creating ) ) { return self::error( 'coupon_publish_confirmation_required', 'Save the draft, review it, and explicitly confirm before publishing.', 403 ); }
		$input = array( 'code' => $code, 'discount_type' => $type, 'amount' => $amount, 'status' => $status );
		if ( isset( $body['description'] ) ) { if ( ! is_string( $body['description'] ) || strlen( $body['description'] ) > 5000 ) { return self::error( 'invalid_coupon_description', 'Coupon description must be text under 5,000 characters.' ); } $input['description'] = sanitize_textarea_field( $body['description'] ); }
		foreach ( array( 'individual_use', 'free_shipping' ) as $key ) { if ( array_key_exists( $key, $body ) ) { if ( ! is_bool( $body[ $key ] ) ) { return self::error( 'invalid_coupon_field', 'Coupon switches must be true or false.' ); } $input[ $key ] = $body[ $key ]; } }
		foreach ( array( 'usage_limit', 'usage_limit_per_user', 'limit_usage_to_x_items' ) as $key ) { if ( array_key_exists( $key, $body ) ) { if ( '' !== (string) $body[ $key ] && ( ! is_numeric( $body[ $key ] ) || (int) $body[ $key ] < 0 ) ) { return self::error( 'invalid_coupon_limit', 'Coupon usage limits must be zero or a positive whole number.' ); } $input[ $key ] = '' === (string) $body[ $key ] ? 0 : absint( $body[ $key ] ); } }
		foreach ( array( 'minimum_amount', 'maximum_amount' ) as $key ) { if ( array_key_exists( $key, $body ) ) { if ( '' !== (string) $body[ $key ] && ( ! is_numeric( $body[ $key ] ) || (float) $body[ $key ] < 0 ) ) { return self::error( 'invalid_coupon_threshold', 'Coupon minimum and maximum spend must be non-negative amounts.' ); } $input[ $key ] = '' === (string) $body[ $key ] ? '' : wc_format_decimal( $body[ $key ] ); } }
		foreach ( array( 'product_ids', 'excluded_product_ids' ) as $key ) { if ( array_key_exists( $key, $body ) ) { $input[ $key ] = self::coupon_product_ids( $body[ $key ] ); if ( is_wp_error( $input[ $key ] ) ) { return $input[ $key ]; } } }
		if ( array_key_exists( 'email_restrictions', $body ) ) { $emails = is_array( $body['email_restrictions'] ) ? $body['email_restrictions'] : preg_split( '/[,\\n]+/', (string) $body['email_restrictions'] ); if ( count( $emails ) > 500 ) { return self::error( 'too_many_email_restrictions', 'A coupon can have at most 500 email restrictions.' ); } $input['email_restrictions'] = array_values( array_filter( array_map( static function ( $email ) { return sanitize_text_field( (string) $email ); }, $emails ) ) ); }
		if ( array_key_exists( 'date_expires', $body ) ) { $date = sanitize_text_field( (string) $body['date_expires'] ); if ( '' !== $date && ( ! preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $date ) || ! checkdate( (int) substr( $date, 5, 2 ), (int) substr( $date, 8, 2 ), (int) substr( $date, 0, 4 ) ) ) ) { return self::error( 'invalid_coupon_expiry', 'Enter a valid coupon expiration date.' ); } $input['date_expires'] = $date; }
		if ( array_key_exists( 'advanced_rules', $body ) ) { $rules = self::validate_coupon_advanced_rules( $body['advanced_rules'] ); if ( is_wp_error( $rules ) ) { return $rules; } $input['advanced_rules'] = $rules; }
		return $input;
	}

	private static function coupon_product_ids( $value ) {
		$ids = is_array( $value ) ? $value : preg_split( '/[,\\s]+/', (string) $value );
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( count( $ids ) > 500 ) { return self::error( 'too_many_coupon_products', 'A coupon can include at most 500 product restrictions.' ); }
		foreach ( $ids as $id ) { if ( ! wc_get_product( $id ) ) { return self::error( 'invalid_coupon_product', 'One or more coupon product restrictions do not match an existing product.' ); } }
		return $ids;
	}

	private static function validate_coupon_advanced_rules( $rules ) {
		if ( ! class_exists( 'Epic_Adv_Coupons_Meta' ) ) { return self::error( 'advanced_coupon_unavailable', 'Activate EPIC Advanced Coupons before editing advanced coupon rules.', 503 ); }
		if ( ! is_array( $rules ) ) { return self::error( 'invalid_advanced_coupon', 'Advanced coupon rules must be an object.' ); }
		$allowed_days = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );
		$days = isset( $rules['schedule_days'] ) && is_array( $rules['schedule_days'] ) ? array_values( array_unique( array_map( 'sanitize_key', $rules['schedule_days'] ) ) ) : array();
		if ( array_diff( $days, $allowed_days ) ) { return self::error( 'invalid_coupon_schedule', 'Choose valid days of the week.' ); }
		$start = sanitize_text_field( (string) ( $rules['schedule_start'] ?? '' ) ); $end = sanitize_text_field( (string) ( $rules['schedule_end'] ?? '' ) );
		foreach ( array( $start, $end ) as $time ) { if ( '' !== $time && ! preg_match( '/^(?:[01]?\\d|2[0-3]):[0-5]\\d$/', $time ) ) { return self::error( 'invalid_coupon_schedule', 'Coupon schedule times must use 24-hour HH:MM format.' ); } }
		$bxgy = isset( $rules['bxgy'] ) && is_array( $rules['bxgy'] ) ? $rules['bxgy'] : array();
		$trigger_type = sanitize_key( (string) ( $bxgy['trigger_type'] ?? 'product' ) ); $reward_type = sanitize_key( (string) ( $bxgy['reward_type'] ?? 'product' ) );
		if ( ! in_array( $trigger_type, array( 'product', 'category' ), true ) || ! in_array( $reward_type, array( 'product', 'category' ), true ) ) { return self::error( 'invalid_coupon_bundle', 'Buy X Get Y rules require a product or category trigger and reward.' ); }
		$trigger_id = absint( $bxgy['trigger_id'] ?? 0 ); $reward_id = absint( $bxgy['reward_id'] ?? 0 );
		foreach ( array( array( $trigger_type, $trigger_id ), array( $reward_type, $reward_id ) ) as $target ) { if ( ! empty( $bxgy['enabled'] ) && ( ! $target[1] || ( 'product' === $target[0] && ! wc_get_product( $target[1] ) ) || ( 'category' === $target[0] && ! term_exists( $target[1], 'product_cat' ) ) ) ) { return self::error( 'invalid_coupon_bundle_target', 'Choose existing products or categories for the Buy X Get Y rule.' ); } }
		$discount_type = sanitize_key( (string) ( $bxgy['discount_type'] ?? 'free' ) ); $discount_value = is_numeric( $bxgy['discount_value'] ?? 0 ) ? (float) ( $bxgy['discount_value'] ?? 0 ) : -1;
		if ( ! in_array( $discount_type, array( 'free', 'percent', 'fixed' ), true ) || $discount_value < 0 || ( 'percent' === $discount_type && $discount_value > 100 ) ) { return self::error( 'invalid_coupon_bundle_discount', 'Choose a valid Buy X Get Y discount.' ); }
		$quantities = array( absint( $bxgy['trigger_qty'] ?? 1 ), absint( $bxgy['reward_qty'] ?? 1 ) );
		if ( min( $quantities ) < 1 || max( $quantities ) > 10000 || absint( $bxgy['max_repeats'] ?? 1 ) > 10000 ) { return self::error( 'invalid_coupon_bundle_quantity', 'Coupon bundle quantities must be between 1 and 10,000; repeats may be zero for unlimited.' ); }
		$auto_category = absint( $rules['auto_apply_category'] ?? 0 );
		if ( $auto_category && ! term_exists( $auto_category, 'product_cat' ) ) { return self::error( 'invalid_auto_apply_category', 'Choose an existing product category for auto-apply.' ); }
		return array(
			'first_order_only' => ! empty( $rules['first_order_only'] ), 'allowlist' => sanitize_textarea_field( (string) ( $rules['allowlist'] ?? '' ) ),
			'schedule_days' => $days, 'schedule_start' => $start, 'schedule_end' => $end,
			'bxgy' => array( 'enabled' => ! empty( $bxgy['enabled'] ), 'trigger_type' => $trigger_type, 'trigger_id' => $trigger_id, 'trigger_qty' => max( 1, $quantities[0] ), 'reward_type' => $reward_type, 'reward_id' => $reward_id, 'reward_qty' => max( 1, $quantities[1] ), 'discount_type' => $discount_type, 'discount_value' => $discount_value, 'max_repeats' => absint( $bxgy['max_repeats'] ?? 1 ) ),
			'auto_apply_enabled' => ! empty( $rules['auto_apply_enabled'] ), 'auto_apply_category' => $auto_category,
		);
	}

	private static function apply_coupon_fields( $coupon, $input ) {
		$setters = array( 'code' => 'set_code', 'description' => 'set_description', 'discount_type' => 'set_discount_type', 'amount' => 'set_amount', 'individual_use' => 'set_individual_use', 'usage_limit' => 'set_usage_limit', 'usage_limit_per_user' => 'set_usage_limit_per_user', 'limit_usage_to_x_items' => 'set_limit_usage_to_x_items', 'free_shipping' => 'set_free_shipping', 'minimum_amount' => 'set_minimum_amount', 'maximum_amount' => 'set_maximum_amount', 'email_restrictions' => 'set_email_restrictions', 'product_ids' => 'set_product_ids', 'excluded_product_ids' => 'set_excluded_product_ids', 'date_expires' => 'set_date_expires', 'status' => 'set_status' );
		foreach ( $setters as $field => $setter ) { if ( array_key_exists( $field, $input ) && is_callable( array( $coupon, $setter ) ) ) { $coupon->{$setter}( $input[ $field ] ); } }
		return true;
	}

	private static function save_coupon_advanced_rules( $id, $rules ) {
		$meta = 'Epic_Adv_Coupons_Meta'; $bxgy = $rules['bxgy'];
		if ( ! class_exists( 'Epic_Adv_Coupons_Admin_Tab' ) || ! is_callable( array( 'Epic_Adv_Coupons_Admin_Tab', 'save_rules' ) ) ) { return self::error( 'advanced_coupon_unavailable', 'The reusable advanced coupon editor service is unavailable.', 503 ); }
		$values = array(
			$meta::FIRST_ORDER_ONLY => $rules['first_order_only'] ? 'yes' : 'no', $meta::ALLOWLIST => $rules['allowlist'], $meta::SCHEDULE_DAYS => implode( ',', $rules['schedule_days'] ),
			$meta::SCHEDULE_START => $rules['schedule_start'], $meta::SCHEDULE_END => $rules['schedule_end'], $meta::BXGY_ENABLED => $bxgy['enabled'] ? 'yes' : 'no',
			$meta::BXGY_TRIGGER_TYPE => $bxgy['trigger_type'], $meta::BXGY_TRIGGER_ID => $bxgy['trigger_id'], $meta::BXGY_TRIGGER_QTY => $bxgy['trigger_qty'],
			$meta::BXGY_REWARD_TYPE => $bxgy['reward_type'], $meta::BXGY_REWARD_ID => $bxgy['reward_id'], $meta::BXGY_REWARD_QTY => $bxgy['reward_qty'],
			$meta::BXGY_DISCOUNT_TYPE => $bxgy['discount_type'], $meta::BXGY_DISCOUNT_VALUE => $bxgy['discount_value'], $meta::BXGY_MAX_REPEATS => $bxgy['max_repeats'],
			$meta::AUTO_APPLY_ENABLED => $rules['auto_apply_enabled'] ? 'yes' : 'no', $meta::AUTO_APPLY_CATEGORY => $rules['auto_apply_category'],
		);
		$result = Epic_Adv_Coupons_Admin_Tab::save_rules( $id, $rules );
		if ( is_wp_error( $result ) ) { return $result; }
		foreach ( $values as $key => $value ) { if ( (string) get_post_meta( $id, $key, true ) !== (string) $value ) { return self::error( 'advanced_coupon_save_failed', 'Advanced coupon rules could not be saved completely.', 503 ); } }
		return true;
	}

	private static function coupon_data( $coupon ) {
		if ( ! $coupon || ! $coupon->get_id() ) { return null; }
		$expires = $coupon->get_date_expires();
		$data = array(
			'id' => $coupon->get_id(), 'code' => $coupon->get_code(), 'description' => $coupon->get_description(), 'discount_type' => $coupon->get_discount_type(), 'amount' => $coupon->get_amount(),
			'status' => $coupon->get_status(), 'individual_use' => $coupon->get_individual_use(), 'usage_limit' => $coupon->get_usage_limit(), 'usage_limit_per_user' => $coupon->get_usage_limit_per_user(),
			'limit_usage_to_x_items' => $coupon->get_limit_usage_to_x_items(), 'free_shipping' => $coupon->get_free_shipping(), 'minimum_amount' => $coupon->get_minimum_amount(), 'maximum_amount' => $coupon->get_maximum_amount(),
			'email_restrictions' => $coupon->get_email_restrictions(), 'product_ids' => $coupon->get_product_ids(), 'excluded_product_ids' => $coupon->get_excluded_product_ids(), 'date_expires' => $expires ? $expires->date( 'Y-m-d' ) : '',
			'advanced_rules' => null,
		);
		if ( class_exists( 'Epic_Adv_Coupons_Meta' ) ) {
			$meta = 'Epic_Adv_Coupons_Meta'; $id = $coupon->get_id(); $days = (string) get_post_meta( $id, $meta::SCHEDULE_DAYS, true );
			$data['advanced_rules'] = array(
				'first_order_only' => 'yes' === get_post_meta( $id, $meta::FIRST_ORDER_ONLY, true ), 'allowlist' => (string) get_post_meta( $id, $meta::ALLOWLIST, true ),
				'schedule_days' => '' === $days ? array() : array_map( 'trim', explode( ',', $days ) ), 'schedule_start' => (string) get_post_meta( $id, $meta::SCHEDULE_START, true ), 'schedule_end' => (string) get_post_meta( $id, $meta::SCHEDULE_END, true ),
				'bxgy' => array( 'enabled' => 'yes' === get_post_meta( $id, $meta::BXGY_ENABLED, true ), 'trigger_type' => get_post_meta( $id, $meta::BXGY_TRIGGER_TYPE, true ) ?: 'product', 'trigger_id' => (int) get_post_meta( $id, $meta::BXGY_TRIGGER_ID, true ), 'trigger_qty' => max( 1, (int) get_post_meta( $id, $meta::BXGY_TRIGGER_QTY, true ) ), 'reward_type' => get_post_meta( $id, $meta::BXGY_REWARD_TYPE, true ) ?: 'product', 'reward_id' => (int) get_post_meta( $id, $meta::BXGY_REWARD_ID, true ), 'reward_qty' => max( 1, (int) get_post_meta( $id, $meta::BXGY_REWARD_QTY, true ) ), 'discount_type' => get_post_meta( $id, $meta::BXGY_DISCOUNT_TYPE, true ) ?: 'free', 'discount_value' => (float) get_post_meta( $id, $meta::BXGY_DISCOUNT_VALUE, true ), 'max_repeats' => (int) get_post_meta( $id, $meta::BXGY_MAX_REPEATS, true ) ),
				'auto_apply_enabled' => 'yes' === get_post_meta( $id, $meta::AUTO_APPLY_ENABLED, true ), 'auto_apply_category' => (int) get_post_meta( $id, $meta::AUTO_APPLY_CATEGORY, true ),
			);
		}
		$data['revision'] = hash( 'sha256', wp_json_encode( $data ) );
		return $data;
	}

	private static function coupon_record( $request, $id ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return self::error( 'forbidden', 'You cannot manage coupons.', 403 ); }
		if ( ! class_exists( 'WC_Coupon' ) ) { return self::error( 'woocommerce_unavailable', 'WooCommerce coupon management is unavailable.', 503 ); }
		$coupon = new WC_Coupon( $id );
		if ( 'shop_coupon' !== get_post_type( $id ) || ! $coupon->get_id() ) { return self::error( 'not_found', 'Coupon not found.', 404 ); }
		if ( 'GET' === $request->get_method() ) { return rest_ensure_response( self::coupon_data( $coupon ) ); }
		if ( 'PATCH' !== $request->get_method() ) { return self::error( 'unsupported_action', 'This coupon action is not available.', 501 ); }
		$body = $request->get_json_params();
		$current = self::coupon_data( $coupon );
		if ( empty( $body['expected_revision'] ) || ! hash_equals( $current['revision'], (string) $body['expected_revision'] ) ) { return self::error( 'revision_conflict', 'This coupon changed after you opened it. Reload before saving.', 409 ); }
		$input = self::validate_coupon_input( $body );
		if ( is_wp_error( $input ) ) { return $input; }
		$duplicate_id = wc_get_coupon_id_by_code( wc_format_coupon_code( $input['code'] ) );
		if ( $duplicate_id && (int) $duplicate_id !== (int) $id ) { return self::error( 'coupon_code_exists', 'That coupon code already exists. Choose a different code.', 409 ); }
		$result = self::apply_coupon_fields( $coupon, $input );
		try { $saved = $coupon->save(); } catch ( Throwable $error ) { return self::error( 'coupon_save_failed', 'WooCommerce could not save this coupon. Your unsaved changes are still in the form.', 503 ); }
		if ( ! $saved ) { return self::error( 'coupon_save_failed', 'WooCommerce could not confirm this coupon save.', 503 ); }
		if ( isset( $input['advanced_rules'] ) ) { $rules = self::save_coupon_advanced_rules( $id, $input['advanced_rules'] ); if ( is_wp_error( $rules ) ) { return $rules; } }
		self::log( 'coupon.update', 'coupons', (string) $id, array_values( array_diff( array_keys( $input ), array( 'confirm_publish' ) ) ), 'success' );
		return rest_ensure_response( array( 'ok' => true, 'coupon' => self::coupon_data( new WC_Coupon( $id ) ) ) );
	}

	public static function address_provinces( $request ) {
		if ( ! class_exists( 'Epic_VTP_Client' ) ) { return self::error( 'shipping_unavailable', 'ViettelPost shipping plugin is unavailable.', 503 ); }
		$provinces = Epic_VTP_Client::get_provinces_new();
		if ( is_wp_error( $provinces ) ) { return self::error( 'address_unavailable', 'ViettelPost province data is unavailable.', 503 ); }
		$items = array();
		foreach ( (array) $provinces as $province ) {
			if ( ! is_array( $province ) || ! isset( $province['PROVINCE_ID'], $province['PROVINCE_NAME'] ) ) { continue; }
			$items[] = array( 'id' => (string) $province['PROVINCE_ID'], 'name' => (string) $province['PROVINCE_NAME'] );
		}
		return rest_ensure_response( array( 'items' => $items ) );
	}

	public static function address_wards( $request ) {
		if ( ! class_exists( 'Epic_VTP_Client' ) ) { return self::error( 'shipping_unavailable', 'ViettelPost shipping plugin is unavailable.', 503 ); }
		$province_id = absint( $request->get_param( 'province_id' ) );
		if ( $province_id < 1 ) { return self::error( 'province_required', 'Choose a province before loading wards.' ); }
		$wards = Epic_VTP_Client::get_wards_new( $province_id );
		if ( is_wp_error( $wards ) ) { return self::error( 'address_unavailable', 'ViettelPost ward data is unavailable.', 503 ); }
		$items = array();
		foreach ( (array) $wards as $ward ) {
			if ( ! is_array( $ward ) || ! isset( $ward['WARDS_ID'], $ward['WARDS_NAME'] ) ) { continue; }
			$items[] = array( 'id' => (string) $ward['WARDS_ID'], 'name' => (string) $ward['WARDS_NAME'] );
		}
		return rest_ensure_response( array( 'items' => $items ) );
	}

	private static function can_manage_direct_orders() {
		return current_user_can( 'edit_shop_orders' ) || current_user_can( 'manage_woocommerce' );
	}

	private static function is_direct_order( $order ) {
		return self::SOURCE_DIRECT === (string) $order->get_meta( self::META_SOURCE );
	}

	public static function order_preview( $request ) {
		if ( ! self::can_manage_direct_orders() ) { return self::error( 'forbidden', 'You cannot create direct orders.', 403 ); }
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_product' ) ) { return self::error( 'woocommerce_unavailable', 'WooCommerce is unavailable.', 503 ); }
		$body = $request->get_json_params();
		$draft = self::normalize_order_draft( is_array( $body ) ? $body : array() );
		if ( is_wp_error( $draft ) ) { return $draft; }
		$computed = self::compute_direct_order( $draft );
		if ( is_wp_error( $computed ) ) { return $computed; }
		$token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		set_transient( 'epic_admin_order_draft_' . $token, array( 'user_id' => get_current_user_id(), 'draft' => $draft ), self::DIRECT_ORDER_PREVIEW_TTL );
		$response = array(
			'preview_token' => $token,
			'action' => $draft['action'],
			'items' => $computed['items'],
			'totals' => $computed['totals'],
			'warnings' => $computed['warnings'],
			'customer' => $draft['customer'],
			'fulfillment' => $draft['fulfillment'],
		);
		if ( 'update' === $draft['action'] ) { $response['order_id'] = (int) $draft['order_id']; }
		return rest_ensure_response( $response );
	}

	public static function order_apply( $request ) {
		if ( ! self::can_manage_direct_orders() ) { return self::error( 'forbidden', 'You cannot create direct orders.', 403 ); }
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_product' ) ) { return self::error( 'woocommerce_unavailable', 'WooCommerce is unavailable.', 503 ); }
		$body = $request->get_json_params();
		$token = isset( $body['preview_token'] ) ? (string) $body['preview_token'] : '';
		if ( ! preg_match( '/^[A-Za-z0-9_-]{40,100}$/', $token ) ) { return self::error( 'preview_required', 'Review the order before saving it.', 400 ); }
		$stored = get_transient( 'epic_admin_order_draft_' . $token );
		if ( ! is_array( $stored ) || (int) $stored['user_id'] !== get_current_user_id() || empty( $stored['draft'] ) || ! is_array( $stored['draft'] ) ) { return self::error( 'preview_expired', 'This order preview expired. Review the order again.', 409 ); }
		delete_transient( 'epic_admin_order_draft_' . $token );
		$draft = $stored['draft'];
		$computed = self::compute_direct_order( $draft );
		if ( is_wp_error( $computed ) ) { return $computed; }
		$reservation = self::reserve_mutation( $request, 'update' === $draft['action'] ? 'order-direct-update:' . absint( $draft['order_id'] ) : 'order-direct-create' );
		if ( is_wp_error( $reservation ) ) { return $reservation; }
		if ( isset( $reservation['replay'] ) ) { return rest_ensure_response( $reservation['replay'] ); }
		$result = 'update' === $draft['action'] ? self::update_direct_order_from_draft( $draft, $computed ) : self::create_direct_order_from_draft( $draft, $computed );
		if ( is_wp_error( $result ) ) { self::finish_mutation( $reservation['hash'], 'failed', null ); return $result; }
		self::finish_mutation( $reservation['hash'], 'success', $result );
		return rest_ensure_response( $result );
	}

	private static function normalize_order_draft( $body ) {
		$action = isset( $body['action'] ) && 'update' === $body['action'] ? 'update' : 'create';
		$order_id = 0; $expected_revision = '';
		if ( 'update' === $action ) {
			$order_id = absint( $body['order_id'] ?? 0 );
			$order = $order_id ? wc_get_order( $order_id ) : false;
			if ( ! $order ) { return self::error( 'not_found', 'Order not found.', 404 ); }
			if ( ! self::is_direct_order( $order ) ) { return self::error( 'not_direct_order', 'Only orders created from the direct-order form can be edited here.', 403 ); }
			$expected_revision = isset( $body['expected_revision'] ) ? (string) $body['expected_revision'] : '';
			if ( '' === $expected_revision || ! hash_equals( self::order_revision( $order ), $expected_revision ) ) { return self::error( 'revision_conflict', 'This order changed after you opened it. Reload before saving.', 409 ); }
		}
		$input = isset( $body['customer'] ) && is_array( $body['customer'] ) ? $body['customer'] : array();
		$first_name = sanitize_text_field( (string) ( $input['first_name'] ?? '' ) );
		$last_name = sanitize_text_field( (string) ( $input['last_name'] ?? '' ) );
		$phone = trim( sanitize_text_field( (string) ( $input['phone'] ?? '' ) ) );
		$email = isset( $input['email'] ) ? sanitize_email( (string) $input['email'] ) : '';
		$address_1 = sanitize_text_field( (string) ( $input['address_1'] ?? '' ) );
		$address_2 = sanitize_text_field( (string) ( $input['address_2'] ?? '' ) );
		$province_name = sanitize_text_field( (string) ( $input['province_name'] ?? $input['city'] ?? '' ) );
		$province_id = absint( $input['province_id'] ?? 0 );
		$ward_id = absint( $input['ward_id'] ?? 0 );
		$ward_name = sanitize_text_field( (string) ( $input['ward_name'] ?? '' ) );
		$postcode = sanitize_text_field( (string) ( $input['postcode'] ?? '' ) );
		if ( '' === trim( $first_name . ' ' . $last_name ) ) { return self::error( 'customer_name_required', 'Enter the customer name.' ); }
		if ( ! preg_match( '/^[0-9+().\-\s]{8,20}$/', $phone ) ) { return self::error( 'customer_phone_required', 'Enter a valid customer phone number.' ); }
		if ( '' !== $email && ! is_email( $email ) ) { return self::error( 'customer_email_invalid', 'Enter a valid email address or leave it blank.' ); }
		if ( '' === $address_1 ) { return self::error( 'address_required', 'Enter the street address.' ); }
		if ( '' === $province_name ) { return self::error( 'province_required', 'Choose the province/city.' ); }
		$fulfillment = isset( $body['fulfillment'] ) && in_array( $body['fulfillment'], self::DIRECT_ORDER_FULFILLMENTS, true ) ? (string) $body['fulfillment'] : 'courier';
		$shipping_fee = isset( $body['shipping_fee'] ) ? (float) $body['shipping_fee'] : 0.0;
		if ( $shipping_fee < 0 || $shipping_fee > 100000000 ) { return self::error( 'invalid_shipping_fee', 'Shipping fee must be between 0 and 100,000,000.' ); }
		if ( 'self' === $fulfillment ) { $shipping_fee = 0.0; }
		$raw_items = isset( $body['items'] ) && is_array( $body['items'] ) ? $body['items'] : array();
		if ( ! $raw_items || count( $raw_items ) > 50 ) { return self::error( 'items_required', 'Add between 1 and 50 products to the order.' ); }
		$items = array();
		foreach ( $raw_items as $raw ) {
			if ( ! is_array( $raw ) ) { continue; }
			$product_id = absint( $raw['product_id'] ?? 0 );
			$variation_id = absint( $raw['variation_id'] ?? 0 );
			$quantity = absint( $raw['quantity'] ?? 0 );
			if ( $product_id < 1 || $quantity < 1 || $quantity > 10000 ) { return self::error( 'invalid_item', 'Each line needs a product and a quantity between 1 and 10,000.' ); }
			$unit_price = isset( $raw['unit_price'] ) && '' !== $raw['unit_price'] ? (float) $raw['unit_price'] : null;
			if ( null !== $unit_price && ( $unit_price < 0 || $unit_price > 1000000000 ) ) { return self::error( 'invalid_price', 'Line prices must be between 0 and 1,000,000,000.' ); }
			$reason = isset( $raw['override_reason'] ) ? sanitize_text_field( (string) $raw['override_reason'] ) : '';
			if ( strlen( $reason ) > 500 ) { return self::error( 'invalid_reason', 'Price override reasons must be under 500 characters.' ); }
			$items[] = array( 'product_id' => $product_id, 'variation_id' => $variation_id, 'quantity' => $quantity, 'unit_price' => $unit_price, 'override_reason' => $reason );
		}
		if ( ! $items ) { return self::error( 'items_required', 'Add between 1 and 50 products to the order.' ); }
		$coupon_codes = array();
		if ( isset( $body['coupon_codes'] ) && is_array( $body['coupon_codes'] ) ) {
			foreach ( array_slice( array_values( $body['coupon_codes'] ), 0, 10 ) as $code ) { $code = function_exists( 'wc_format_coupon_code' ) ? wc_format_coupon_code( wc_clean( (string) $code ) ) : sanitize_text_field( (string) $code ); if ( '' !== $code ) { $coupon_codes[] = $code; } }
		}
		$manual_discount = null;
		if ( isset( $body['manual_discount'] ) && is_array( $body['manual_discount'] ) && ! empty( $body['manual_discount']['enabled'] ) ) {
			$type = isset( $body['manual_discount']['type'] ) && 'percent' === $body['manual_discount']['type'] ? 'percent' : 'fixed';
			$value = (float) ( $body['manual_discount']['value'] ?? 0 );
			$reason = sanitize_text_field( (string) ( $body['manual_discount']['reason'] ?? '' ) );
			if ( $value < 0 || ( 'percent' === $type && $value > 100 ) ) { return self::error( 'invalid_manual_discount', 'Enter a discount between 0 and 100 percent, or a non-negative amount.' ); }
			if ( '' === $reason ) { return self::error( 'manual_discount_reason_required', 'Provide a reason for the manual discount.' ); }
			if ( strlen( $reason ) > 500 ) { return self::error( 'invalid_reason', 'Discount reasons must be under 500 characters.' ); }
			$manual_discount = array( 'type' => $type, 'value' => $value, 'reason' => $reason );
		}
		$fee_lines = array();
		if ( isset( $body['fee_lines'] ) && is_array( $body['fee_lines'] ) ) {
			foreach ( array_slice( array_values( $body['fee_lines'] ), 0, 20 ) as $line ) {
				if ( ! is_array( $line ) ) { continue; }
				$name = sanitize_text_field( (string) ( $line['name'] ?? '' ) );
				$amount = (float) ( $line['amount'] ?? 0 );
				if ( '' === $name || $amount < 0 || $amount > 100000000 ) { return self::error( 'invalid_fee', 'Each fee needs a name and a non-negative amount.' ); }
				$fee_lines[] = array( 'name' => $name, 'amount' => $amount );
			}
		}
		$note = isset( $body['note'] ) ? sanitize_textarea_field( (string) $body['note'] ) : '';
		if ( strlen( $note ) > 4000 ) { return self::error( 'invalid_note', 'Order notes must be under 4,000 characters.' ); }
		return array(
			'action' => $action, 'order_id' => $order_id, 'expected_revision' => $expected_revision,
			'customer' => array( 'first_name' => $first_name, 'last_name' => $last_name, 'phone' => $phone, 'email' => $email, 'address_1' => $address_1, 'address_2' => $address_2, 'province_id' => $province_id, 'province_name' => $province_name, 'ward_id' => $ward_id, 'ward_name' => $ward_name, 'postcode' => $postcode ),
			'fulfillment' => $fulfillment, 'shipping_fee' => $shipping_fee, 'items' => $items, 'coupon_codes' => $coupon_codes,
			'manual_discount' => $manual_discount, 'fee_lines' => $fee_lines, 'note' => $note,
		);
	}

	private static function resolve_direct_item_product( $line, &$warnings ) {
		$product = wc_get_product( $line['variation_id'] ? $line['variation_id'] : $line['product_id'] );
		if ( ! $product ) { return self::error( 'product_not_found', 'One of the selected products no longer exists.' ); }
		if ( $line['variation_id'] ) {
			if ( ! $product->is_type( 'variation' ) || (int) $product->get_parent_id() !== (int) $line['product_id'] ) { return self::error( 'invalid_variation', 'A selected variation does not belong to its product.' ); }
		} elseif ( $product->is_type( 'variable' ) ) {
			return self::error( 'variation_required', sprintf( 'Choose a variation for "%s".', $product->get_name() ) );
		}
		if ( ! $product->is_purchasable() ) { $warnings[] = sprintf( 'Sản phẩm "%s" hiện không thể bán.', $product->get_name() ); }
		return $product;
	}

	private static function compute_direct_order( $draft ) {
		$order = new WC_Order();
		$order->set_currency( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'VND' );
		$customer = $draft['customer'];
		$order->set_billing_email( $customer['email'] );
		$order->set_billing_phone( $customer['phone'] );
		$order->set_billing_first_name( $customer['first_name'] );
		$order->set_billing_last_name( $customer['last_name'] );
		$resolved = array(); $subtotal = 0.0; $warnings = array();
		foreach ( $draft['items'] as $line ) {
			$product = self::resolve_direct_item_product( $line, $warnings );
			if ( is_wp_error( $product ) ) { return $product; }
			$catalog = (float) $product->get_price();
			$unit_price = null === $line['unit_price'] ? $catalog : (float) $line['unit_price'];
			$line_total = $unit_price * $line['quantity'];
			$subtotal += $line_total;
			$item = new WC_Order_Item_Product();
			$item->set_product( $product );
			$item->set_quantity( $line['quantity'] );
			$item->set_subtotal( $line_total );
			$item->set_total( $line_total );
			$order->add_item( $item );
			$resolved[] = array( 'product_id' => $line['product_id'], 'variation_id' => $line['variation_id'], 'name' => $product->get_name(), 'sku' => $product->get_sku(), 'quantity' => $line['quantity'], 'unit_price' => $unit_price, 'catalog_price' => $catalog, 'line_total' => $line_total, 'override_reason' => $line['override_reason'] );
			if ( null !== $line['unit_price'] && abs( $line['unit_price'] - $catalog ) > 0.0001 ) { $warnings[] = sprintf( 'Giá dòng "%s" đã được sửa so với giá niêm yết %s.', $product->get_name(), wp_strip_all_tags( wc_price( $catalog ) ) ); }
			if ( ! $product->is_in_stock() && ! $product->backorders_allowed() ) { $warnings[] = sprintf( 'Sản phẩm "%s" đang hết hàng.', $product->get_name() ); }
		}
		$coupon_total = 0.0;
		if ( $draft['coupon_codes'] && class_exists( 'WC_Discounts' ) ) {
			$discounts = new WC_Discounts( $order );
			foreach ( $draft['coupon_codes'] as $code ) {
				$coupon = new WC_Coupon( $code );
				if ( ! $coupon->get_id() ) { return self::error( 'invalid_coupon', sprintf( 'Coupon "%s" does not exist.', $code ) ); }
				$valid = $discounts->is_coupon_valid( $coupon );
				if ( is_wp_error( $valid ) ) { return self::error( 'invalid_coupon', sprintf( 'Coupon "%s": %s', $code, $valid->get_error_message() ) ); }
				$discounts->apply_coupon( $coupon );
				if ( $coupon->get_free_shipping() ) { $warnings[] = sprintf( 'Coupon "%s" miễn phí vận chuyển không được áp dụng cho đơn trực tiếp.', $code ); }
			}
			$coupon_total = (float) $discounts->get_discount_total();
		}
		$base = max( 0.0, $subtotal - $coupon_total );
		$manual_total = 0.0;
		if ( is_array( $draft['manual_discount'] ) ) {
			$manual = $draft['manual_discount'];
			$manual_total = 'percent' === $manual['type'] ? ( $base * $manual['value'] / 100 ) : (float) $manual['value'];
			if ( $manual_total > $base ) { $manual_total = $base; $warnings[] = 'Giảm giá thủ công đã được giới hạn theo giá trị còn lại của đơn.'; }
			$manual_total = max( 0.0, $manual_total );
		}
		$fees_total = 0.0;
		foreach ( $draft['fee_lines'] as $fee ) { $fees_total += (float) $fee['amount']; }
		$shipping_fee = 'self' === $draft['fulfillment'] ? 0.0 : (float) $draft['shipping_fee'];
		$total = max( 0.0, $subtotal - $coupon_total - $manual_total + $shipping_fee + $fees_total );
		return array(
			'items' => $resolved,
			'warnings' => $warnings,
			'totals' => array( 'subtotal' => $subtotal, 'coupon_discount' => $coupon_total, 'manual_discount' => $manual_total, 'shipping' => $shipping_fee, 'fees' => $fees_total, 'total' => $total, 'currency' => $order->get_currency() ),
		);
	}

	private static function fill_direct_order( $order, $draft, $computed ) {
		$customer = $draft['customer'];
		$ward_name = isset( $customer['ward_name'] ) ? (string) $customer['ward_name'] : '';
		$extra = (string) $customer['address_2'];
		$street = (string) $customer['address_1'];
		// The structured picker puts the ward in address_2 (the WooCommerce field
		// staff and the courier plugin read). A separately entered supplementary
		// address is folded into the street line so nothing is lost.
		$address_2 = '' !== $ward_name ? $ward_name : $extra;
		if ( '' !== $ward_name && '' !== $extra ) { $street = rtrim( $street ) . ', ' . $extra; }
		$address = array(
			'first_name' => $customer['first_name'], 'last_name' => $customer['last_name'], 'company' => '',
			'address_1' => $street, 'address_2' => $address_2,
			'city' => $customer['province_name'], 'state' => $customer['province_name'],
			'postcode' => $customer['postcode'], 'country' => 'VN',
			'phone' => $customer['phone'], 'email' => $customer['email'],
		);
		// Use the CRUD address setters (HPOS-safe). The legacy set_address()
		// writes raw postmeta and is the source of the empty-address/empty-order
		// failure on custom order tables.
		if ( is_callable( array( $order, 'set_billing_address' ) ) ) { $order->set_billing_address( $address ); }
		else { $order->set_address( $address, 'billing' ); }
		if ( is_callable( array( $order, 'set_shipping_address' ) ) ) { $order->set_shipping_address( $address ); }
		else { $order->set_address( $address, 'shipping' ); }
		if ( is_callable( array( $order, 'set_shipping_phone' ) ) ) { $order->set_shipping_phone( $customer['phone'] ); }
		if ( '' !== $customer['email'] && function_exists( 'email_exists' ) ) {
			$user_id = email_exists( $customer['email'] );
			if ( ! $user_id && function_exists( 'wc_create_new_customer' ) ) {
				$suppress = static function () { return false; };
				add_filter( 'woocommerce_email_enabled_customer_new_account', $suppress );
				$user_id = wc_create_new_customer( $customer['email'], '', '', array( 'first_name' => $customer['first_name'], 'last_name' => $customer['last_name'] ) );
				remove_filter( 'woocommerce_email_enabled_customer_new_account', $suppress );
				if ( is_wp_error( $user_id ) ) { $user_id = 0; }
			}
			if ( $user_id ) { $order->set_customer_id( (int) $user_id ); }
		}
		foreach ( $order->get_items( array( 'line_item', 'fee', 'shipping', 'coupon' ) ) as $item_id => $item ) { $order->remove_item( $item_id ); }
		$discard = array();
		foreach ( $computed['items'] as $line ) {
			$product = self::resolve_direct_item_product( array( 'product_id' => $line['product_id'], 'variation_id' => $line['variation_id'], 'unit_price' => $line['unit_price'] ), $discard );
			if ( is_wp_error( $product ) ) { return $product; }
			$item = new WC_Order_Item_Product();
			$item->set_product( $product );
			$item->set_quantity( $line['quantity'] );
			$item->set_subtotal( $line['line_total'] );
			$item->set_total( $line['line_total'] );
			if ( '' !== $line['override_reason'] ) { $item->add_meta_data( '_epic_price_override_reason', $line['override_reason'] ); }
			$order->add_item( $item );
		}
		foreach ( $draft['coupon_codes'] as $code ) {
			$applied = $order->apply_coupon( $code );
			if ( is_wp_error( $applied ) ) { return self::error( 'invalid_coupon', sprintf( 'Coupon "%s": %s', $code, $applied->get_error_message() ) ); }
		}
		if ( $computed['totals']['manual_discount'] > 0 && is_array( $draft['manual_discount'] ) ) {
			$amount = -1 * (float) $computed['totals']['manual_discount'];
			$fee = new WC_Order_Item_Fee();
			$fee->set_name( 'Giảm giá: ' . $draft['manual_discount']['reason'] );
			$fee->set_amount( $amount );
			$fee->set_total( $amount );
			$order->add_item( $fee );
		}
		foreach ( $draft['fee_lines'] as $line ) {
			$fee = new WC_Order_Item_Fee();
			$fee->set_name( $line['name'] );
			$fee->set_amount( (float) $line['amount'] );
			$fee->set_total( (float) $line['amount'] );
			$order->add_item( $fee );
		}
		$shipping = new WC_Order_Item_Shipping();
		$shipping->set_method_title( 'self' === $draft['fulfillment'] ? 'Nhân viên giao trực tiếp' : 'Giao hàng (ViettelPost)' );
		$shipping->set_method_id( 'self' === $draft['fulfillment'] ? 'epic_self_delivery' : 'epic_courier' );
		$shipping->set_total( (float) $computed['totals']['shipping'] );
		$order->add_item( $shipping );
		$order->set_payment_method( 'cod' );
		$order->set_payment_method_title( 'Thanh toán khi nhận hàng (COD)' );
		$order->set_paid( false );
		$order->update_meta_data( self::META_SOURCE, self::SOURCE_DIRECT );
		$order->update_meta_data( self::META_FULFILLMENT, $draft['fulfillment'] );
		$order->update_meta_data( self::META_CREATED_BY, get_current_user_id() );
		if ( $customer['province_id'] ) { $order->update_meta_data( '_epic_vtp_province_id', $customer['province_id'] ); }
		if ( $customer['ward_id'] ) { $order->update_meta_data( '_epic_ward_id', $customer['ward_id'] ); }
		if ( '' !== $ward_name ) { $order->update_meta_data( '_epic_ward_name', $ward_name ); }
		$order->calculate_totals();
		$order->save();
		$notes = array( 'Tạo từ EPIC Admin — đơn trực tiếp (điện thoại/Zalo).' );
		if ( is_array( $draft['manual_discount'] ) ) { $notes[] = 'Giảm giá thủ công: ' . $draft['manual_discount']['reason']; }
		foreach ( $computed['items'] as $line ) { if ( '' !== $line['override_reason'] ) { $notes[] = sprintf( 'Sửa giá "%s": %s', $line['name'], $line['override_reason'] ); } }
		if ( '' !== $draft['note'] ) { $notes[] = $draft['note']; }
		$order->add_order_note( implode( "\n", $notes ) );
		return true;
	}

	private static function direct_order_is_persisted( $order_id ) {
		$order_id = absint( $order_id );
		if ( $order_id < 1 || ! function_exists( 'wc_get_order' ) ) { return false; }
		$order = wc_get_order( $order_id );
		if ( ! $order ) { return false; }
		if ( (int) $order->get_item_count( 'line_item' ) < 1 ) { return false; }
		if ( '' === trim( (string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name() ) ) { return false; }
		if ( '' === (string) $order->get_billing_phone() ) { return false; }
		if ( '' === (string) $order->get_billing_address_1() ) { return false; }
		return true;
	}

	private static function create_direct_order_from_draft( $draft, $computed ) {
		$order = wc_create_order( array( 'created_via' => 'epic-admin' ) );
		if ( is_wp_error( $order ) ) { return self::error( 'order_create_failed', 'The order could not be created.', 500 ); }
		$order_id = (int) $order->get_id();
		try {
			$result = self::fill_direct_order( $order, $draft, $computed );
			if ( is_wp_error( $result ) ) { $order->delete( true ); return $result; }
			$order->update_status( 'on-hold', 'Tạo đơn trực tiếp (điện thoại/Zalo) từ EPIC Admin.' );
		} catch ( \Throwable $e ) {
			// WC_Order::save() catches Exceptions raised by save hooks; a Throwable
			// escaping here means the write genuinely failed mid-way.
			if ( function_exists( 'wc_get_logger' ) ) { wc_get_logger()->error( sprintf( 'Direct order %d failed: %s', $order_id, $e->getMessage() ), array( 'source' => 'epic-admin-dashboard' ) ); }
			$order->delete( true );
			self::log( 'order.create', 'orders', (string) $order_id, array( 'source', 'items', 'customer' ), 'failed' );
			return self::error( 'order_save_failed', 'Không lưu được đơn hàng. Vui lòng thử lại.', 500 );
		}
		// WC_Order::save() can silently swallow an Exception from a save hook and
		// leave an empty order behind (this was the direct-order "empty order"
		// bug). Re-read the persisted order and roll it back instead of reporting
		// a false success.
		if ( ! self::direct_order_is_persisted( $order_id ) ) {
			if ( function_exists( 'wc_get_logger' ) ) { wc_get_logger()->error( sprintf( 'Direct order %d was created but did not persist (no items/address); rolled back.', $order_id ), array( 'source' => 'epic-admin-dashboard' ) ); }
			$order->delete( true );
			self::log( 'order.create', 'orders', (string) $order_id, array( 'source', 'items', 'customer' ), 'failed' );
			return self::error( 'order_save_failed', 'Đơn hàng không lưu được sản phẩm hoặc địa chỉ. Vui lòng kiểm tra Nhật ký WooCommerce (WooCommerce → Trạng thái → Nhật ký) rồi thử lại.', 500 );
		}
		if ( function_exists( 'wc_reduce_stock_levels' ) ) { wc_reduce_stock_levels( $order_id ); }
		self::log( 'order.create', 'orders', (string) $order_id, array( 'source', 'fulfillment', 'items', 'shipping', 'coupons', 'manual_discount', 'fees', 'customer' ), 'success' );
		return array( 'ok' => true, 'id' => $order_id, 'number' => $order->get_order_number(), 'status' => $order->get_status(), 'total' => $order->get_total(), 'currency' => $order->get_currency() );
	}

	private static function update_direct_order_from_draft( $draft, $computed ) {
		$order = wc_get_order( $draft['order_id'] );
		if ( ! $order ) { return self::error( 'not_found', 'Order not found.', 404 ); }
		if ( ! self::is_direct_order( $order ) ) { return self::error( 'not_direct_order', 'Only direct orders can be edited here.', 403 ); }
		if ( ! hash_equals( self::order_revision( $order ), (string) $draft['expected_revision'] ) ) { return self::error( 'revision_conflict', 'This order changed after you opened it. Reload before saving.', 409 ); }
		$order_id = (int) $order->get_id();
		try {
			$result = self::fill_direct_order( $order, $draft, $computed );
			if ( is_wp_error( $result ) ) { return $result; }
		} catch ( \Throwable $e ) {
			if ( function_exists( 'wc_get_logger' ) ) { wc_get_logger()->error( sprintf( 'Direct order %d update failed: %s', $order_id, $e->getMessage() ), array( 'source' => 'epic-admin-dashboard' ) ); }
			return self::error( 'order_save_failed', 'Không lưu được thay đổi của đơn hàng. Vui lòng thử lại.', 500 );
		}
		if ( ! self::direct_order_is_persisted( $order_id ) ) {
			if ( function_exists( 'wc_get_logger' ) ) { wc_get_logger()->error( sprintf( 'Direct order %d update did not persist.', $order_id ), array( 'source' => 'epic-admin-dashboard' ) ); }
			return self::error( 'order_save_failed', 'Thay đổi chưa được lưu (thiếu sản phẩm hoặc địa chỉ). Vui lòng kiểm tra Nhật ký WooCommerce rồi thử lại.', 500 );
		}
		self::log( 'order.update', 'orders', (string) $order_id, array( 'source', 'fulfillment', 'items', 'shipping', 'coupons', 'manual_discount', 'fees', 'customer' ), 'success' );
		return array( 'ok' => true, 'id' => $order_id, 'number' => $order->get_order_number(), 'status' => $order->get_status(), 'total' => $order->get_total(), 'currency' => $order->get_currency() );
	}

	private static function finance_filters( $request ) {
		$from = sanitize_text_field( (string) $request->get_param( 'from' ) );
		$to = sanitize_text_field( (string) $request->get_param( 'to' ) );
		foreach ( array( $from, $to ) as $date ) { if ( '' !== $date && ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || gmdate( 'Y-m-d', strtotime( $date . ' 00:00:00 UTC' ) ) !== $date ) ) { return self::error( 'invalid_date', 'Finance filters require valid YYYY-MM-DD dates.' ); } }
		if ( '' !== $from && '' !== $to && ( $from > $to || ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS > 365 ) ) { return self::error( 'invalid_date_range', 'Choose a date range of at most 366 days.' ); }
		return array( 'date_from' => $from, 'date_to' => $to, 'distributor_id' => absint( $request->get_param( 'distributor_id' ) ), 'channel' => sanitize_text_field( (string) $request->get_param( 'channel' ) ), 'orderby' => sanitize_key( (string) $request->get_param( 'sort' ) ), 'order' => 'asc' === strtolower( (string) $request->get_param( 'order' ) ) ? 'ASC' : 'DESC' );
	}

	public static function records( $request ) {
		$resource = (string) $request['resource'];
		if ( in_array( $resource, array( 'orders', 'shipments', 'products', 'customers' ), true ) && ! class_exists( 'WooCommerce' ) ) { return self::error( 'woocommerce_unavailable', 'WooCommerce is unavailable. Business data was not loaded.', 503 ); }
		if ( 'shipments' === $resource && ! class_exists( 'Epic_VTP_Order_Meta_Box' ) ) { return self::error( 'shipping_unavailable', 'ViettelPost shipment records are unavailable.', 503 ); }
		if ( 'reviews' === $resource && ! class_exists( 'Epic_Reviews_Store' ) ) { return self::error( 'reviews_unavailable', 'The product reviews plugin is unavailable.', 503 ); }
		$read_caps = array(
			'orders' => 'edit_shop_orders', 'shipments' => 'edit_shop_orders', 'products' => 'edit_products', 'customers' => 'manage_woocommerce',
			'content' => 'edit_posts', 'leads' => 'manage_options', 'reviews' => 'manage_options', 'wholesale-orders' => 'manage_woocommerce',
			'costs' => 'manage_options', 'distributors' => 'manage_options', 'coupons' => 'manage_woocommerce', 'newsletter' => 'manage_options', 'ledger' => 'manage_options',
		);
		if ( ! isset( $read_caps[ $resource ] ) || ! current_user_can( $read_caps[ $resource ] ) ) { return self::error( 'forbidden', 'Your WordPress account cannot view this business data.', 403 ); }
		$page = max( 1, absint( $request->get_param( 'page' ) ) );
		$per_page = min( 100, max( 1, absint( $request->get_param( 'per_page' ) ) ?: 20 ) );
		$search = sanitize_text_field( (string) $request->get_param( 'search' ) );
		if ( 'orders' === $resource && function_exists( 'wc_get_orders' ) ) {
			$status = sanitize_key( (string) $request->get_param( 'status' ) );
			if ( '' !== $status && ! in_array( $status, array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' ), true ) ) { return self::error( 'invalid_status', 'Choose a supported order status.' ); }
			$source = sanitize_key( (string) $request->get_param( 'source' ) );
			if ( '' !== $source && ! in_array( $source, array( self::SOURCE_DIRECT ), true ) ) { return self::error( 'invalid_source', 'Choose a supported order source.' ); }
			if ( '' !== $search && class_exists( 'Epic_Order_Code' ) && preg_match( '/^EPIC-[A-Z0-9]+$/i', trim( $search ) ) ) {
				$code_id = Epic_Order_Code::decode( $search );
				$code_order = $code_id ? wc_get_order( $code_id ) : false;
				if ( $code_order && ( '' === $status || $code_order->get_status() === $status ) && ( '' === $source || $code_order->get_meta( self::META_SOURCE ) === $source ) ) {
					return rest_ensure_response( array( 'items' => array( self::order_list_item( $code_order ) ), 'page' => 1, 'per_page' => $per_page, 'total' => 1 ) );
				}
			}
			$args = array( 'limit' => $per_page, 'page' => $page, 'paginate' => true, 'orderby' => 'date', 'order' => 'DESC', 'search' => $search ? '*' . $search . '*' : '' );
			if ( '' !== $status ) { $args['status'] = array( $status ); }
			if ( '' !== $source ) { $args['meta_query'] = array( array( 'key' => self::META_SOURCE, 'value' => $source ) ); }
			$result = wc_get_orders( $args );
			$items = array_map( static function ( $order ) { return self::order_list_item( $order ); }, $result->orders );
			return rest_ensure_response( array( 'items' => $items, 'page' => $page, 'per_page' => $per_page, 'total' => (int) $result->total ) );
		}
		if ( 'shipments' === $resource && function_exists( 'wc_get_orders' ) ) {
			if ( ! class_exists( 'Epic_VTP_Order_Meta_Box' ) ) { return self::error( 'shipping_unavailable', 'ViettelPost shipping plugin is unavailable.', 503 ); }
			$tracking_lookup = strtoupper( trim( $search ) );
			if ( '' !== $tracking_lookup && preg_match( '/^[A-Z0-9-]{4,50}$/', $tracking_lookup ) ) {
				$matched = wc_get_orders( array( 'limit' => 5, 'orderby' => 'date', 'order' => 'DESC', 'meta_query' => array( array( 'key' => Epic_VTP_Order_Meta_Box::META_ORDER_NUMBER, 'value' => $tracking_lookup ) ) ) );
				if ( $matched ) {
					$items = array_map( static function ( $order ) { return self::shipment_list_item( $order ); }, $matched );
					return rest_ensure_response( array( 'items' => $items, 'page' => 1, 'per_page' => $per_page, 'total' => count( $items ) ) );
				}
			}
			$result = wc_get_orders( array( 'limit' => $per_page, 'page' => $page, 'paginate' => true, 'orderby' => 'date', 'order' => 'DESC', 'meta_query' => array( array( 'key' => Epic_VTP_Order_Meta_Box::META_ORDER_NUMBER, 'compare' => 'EXISTS' ) ), 'search' => $search ? '*' . $search . '*' : '' ) );
			$items = array_map( static function ( $order ) { return self::shipment_list_item( $order ); }, $result->orders );
			return rest_ensure_response( array( 'items' => $items, 'page' => $page, 'per_page' => $per_page, 'total' => (int) $result->total ) );
		}
		if ( 'products' === $resource && function_exists( 'wc_get_products' ) ) {
			$product_statuses = array( 'publish', 'draft', 'pending', 'private' );
			if ( '' !== $search ) {
				$by_name = wc_get_products( array( 'limit' => -1, 'return' => 'ids', 'status' => $product_statuses, 's' => $search ) );
				$by_sku = get_posts( array( 'post_type' => 'product', 'post_status' => $product_statuses, 'posts_per_page' => -1, 'fields' => 'ids', 'meta_query' => array( array( 'key' => '_sku', 'value' => $search, 'compare' => 'LIKE' ) ) ) );
				$ids = array_values( array_unique( array_merge( array_map( 'intval', (array) $by_name ), array_map( 'intval', (array) $by_sku ) ) ) );
				$items = array();
				foreach ( array_slice( $ids, ( $page - 1 ) * $per_page, $per_page ) as $product_id ) { $product = wc_get_product( $product_id ); if ( $product ) { $items[] = self::product_list_item( $product ); } }
				return rest_ensure_response( array( 'items' => $items, 'page' => $page, 'per_page' => $per_page, 'total' => count( $ids ) ) );
			}
			$result = wc_get_products( array( 'limit' => $per_page, 'page' => $page, 'paginate' => true, 'orderby' => 'title', 'order' => 'ASC', 'status' => $product_statuses ) );
			$items = array_map( static function ( $product ) { return self::product_list_item( $product ); }, $result->products );
			return rest_ensure_response( array( 'items' => $items, 'page' => $page, 'per_page' => $per_page, 'total' => (int) $result->total ) );
		}
		if ( 'customers' === $resource && function_exists( 'wc_get_customers' ) ) {
			$result = wc_get_customers( array( 'limit' => $per_page, 'page' => $page, 'paginate' => true, 'search' => $search ? '*' . $search . '*' : '' ) );
			$customers = is_object( $result ) && isset( $result->customers ) ? $result->customers : (array) $result;
			$total = is_object( $result ) && isset( $result->total ) ? (int) $result->total : null;
			return rest_ensure_response( array( 'items' => array_map( static function ( $customer ) { return array( 'id' => $customer->get_id(), 'name' => $customer->get_display_name(), 'email' => $customer->get_email(), 'orders' => (int) $customer->get_order_count(), 'spent' => $customer->get_total_spent() ); }, $customers ), 'page' => $page, 'per_page' => $per_page, 'total' => $total ) );
		}
		if ( 'content' === $resource ) {
			$post_status = array( 'publish', 'draft', 'pending', 'private' );
			$status = sanitize_key( (string) $request->get_param( 'status' ) );
			if ( '' !== $status ) { if ( ! in_array( $status, $post_status, true ) ) { return self::error( 'invalid_status', 'Choose a supported content status.' ); } $post_status = array( $status ); }
			$query = new WP_Query( array( 'post_type' => array( 'post', 'page' ), 'post_status' => $post_status, 'posts_per_page' => $per_page, 'paged' => $page, 's' => $search, 'orderby' => 'modified', 'order' => 'DESC' ) );
			return rest_ensure_response( array( 'items' => array_map( static function ( $post ) { return array( 'id' => $post->ID, 'title' => get_the_title( $post ), 'type' => $post->post_type, 'status' => $post->post_status, 'modified' => get_post_modified_time( DATE_ATOM, true, $post ), 'slug' => $post->post_name ); }, $query->posts ), 'page' => $page, 'per_page' => $per_page, 'total' => (int) $query->found_posts ) );
		}
		if ( 'reviews' === $resource && class_exists( 'Epic_Reviews_Store' ) ) {
			$status = in_array( $request->get_param( 'status' ), array( 'pending', 'approved' ), true ) ? $request->get_param( 'status' ) : '';
			$items = Epic_Reviews_Store::get_page( $per_page, ( $page - 1 ) * $per_page, $status );
			foreach ( $items as &$review ) { $product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $review['product_id'] ) : false; $review['product_name'] = $product ? $product->get_name() : ''; }
			unset( $review );
			return rest_ensure_response( array( 'items' => $items, 'page' => $page, 'per_page' => $per_page, 'total' => (int) Epic_Reviews_Store::count( $status ) ) );
		}
		if ( 'ledger' === $resource ) {
			if ( ! class_exists( 'Epic_Distributor_Profit_Store' ) ) { return self::error( 'finance_unavailable', 'The distributor finance plugin is unavailable.', 503 ); }
			$args = self::finance_filters( $request );
			if ( is_wp_error( $args ) ) { return $args; }
			$args['per_page'] = $per_page; $args['page'] = $page;
			$rows = Epic_Distributor_Profit_Store::query_entries( $args );
			$items = array_map( static function ( $row ) { return get_object_vars( $row ); }, $rows );
			return rest_ensure_response( array( 'items' => $items, 'page' => $page, 'per_page' => $per_page, 'total' => Epic_Distributor_Profit_Store::count_entries( $args ), 'totals' => Epic_Distributor_Profit_Store::totals( $args ) ) );
		}
		return self::custom_records( $resource, $request, $page, $per_page, $search );
	}

	private static function order_list_item( $order ) {
		$item = array( 'id' => $order->get_id(), 'number' => $order->get_order_number(), 'date' => $order->get_date_created() ? $order->get_date_created()->date( DATE_ATOM ) : null, 'status' => $order->get_status(), 'total' => $order->get_total(), 'currency' => $order->get_currency(), 'customer' => trim( $order->get_formatted_billing_full_name() ), 'email' => $order->get_billing_email(), 'payment_method' => $order->get_payment_method_title(), 'shipping' => $order->get_shipping_method(), 'source' => (string) $order->get_meta( self::META_SOURCE ), 'shipment_status' => '', 'shipment_status_code' => '' );
		if ( class_exists( 'Epic_VTP_Client' ) && class_exists( 'Epic_VTP_Order_Meta_Box' ) ) {
			$code = (string) $order->get_meta( Epic_VTP_Order_Meta_Box::META_STATUS );
			if ( '' !== $code ) { $item['shipment_status_code'] = $code; $item['shipment_status'] = Epic_VTP_Client::status_label( $code ); }
		}
		return $item;
	}

	private static function product_list_item( $product ) {
		return array( 'id' => $product->get_id(), 'name' => $product->get_name(), 'type' => $product->get_type(), 'status' => $product->get_status(), 'sku' => $product->get_sku(), 'price' => $product->get_price(), 'stock' => $product->get_stock_status(), 'stock_quantity' => $product->get_stock_quantity() );
	}

	private static function shipment_list_item( $order ) {
		$tracking = (string) $order->get_meta( Epic_VTP_Order_Meta_Box::META_ORDER_NUMBER );
		return array( 'id' => $order->get_id(), 'number' => $order->get_order_number(), 'date' => $order->get_date_created() ? $order->get_date_created()->date( DATE_ATOM ) : null, 'status' => $order->get_status(), 'total' => $order->get_total(), 'currency' => $order->get_currency(), 'customer' => $order->get_formatted_billing_full_name(), 'tracking' => $tracking, 'tracking_url' => self::shipment_tracking_url( $tracking ), 'shipment_status' => Epic_VTP_Client::status_label( $order->get_meta( Epic_VTP_Order_Meta_Box::META_STATUS ) ), 'shipping' => $order->get_shipping_method() );
	}

	private static function custom_records( $resource, $request, $page, $per_page, $search ) {
		global $wpdb;
		$map = array(
			'leads' => array( 'epic_contact_requests', 'epic_sample_requests', 'epic_wholesale_inquiries' ),
			'wholesale-orders' => array( 'posts' ), 'costs' => array( 'epic_product_cost_history' ),
			'distributors' => array( 'epic_distributor_profit_distributors' ), 'ledger' => array( 'epic_distributor_profit_entries' ), 'coupons' => array( 'posts' ),
			'newsletter' => array( 'epic_newsletter_subscribers', 'epic_newsletter_campaigns' ),
		);
		if ( ! isset( $map[ $resource ] ) ) { return self::error( 'unknown_resource', 'This dashboard resource is unavailable.', 404 ); }
		$requested_source = sanitize_key( (string) $request->get_param( 'source' ) );
		if ( '' !== $requested_source ) {
			if ( ! in_array( $requested_source, $map[ $resource ], true ) ) { return self::error( 'invalid_source', 'Choose a supported data source for this collection.' ); }
			$map[ $resource ] = array( $requested_source );
		}
		$sources = array(); $total = 0;
		foreach ( $map[ $resource ] as $suffix ) {
				if ( 'posts' === $suffix ) {
					$type = 'wholesale-orders' === $resource ? 'epic_wholesale_order' : 'shop_coupon';
					if ( ! post_type_exists( $type ) ) { return self::error( 'source_unavailable', 'The WordPress plugin providing this collection is unavailable.', 503 ); }
					$args = array( 'post_type' => $type, 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 's' => $search, 'no_found_rows' => false );
					if ( 'coupons' === $resource ) { $status = sanitize_key( (string) $request->get_param( 'status' ) ); if ( '' !== $status ) { if ( ! in_array( $status, array( 'draft', 'publish' ), true ) ) { return self::error( 'invalid_status', 'Choose a draft or published coupon status.' ); } $args['post_status'] = array( $status ); } else { $args['post_status'] = array( 'publish', 'draft' ); } }
				if ( 'wholesale-orders' === $resource && class_exists( 'Epic_Wholesale_Orders_Store' ) ) {
					$status_map = array( 'pending' => Epic_Wholesale_Orders_Store::STATUS_PENDING, 'approved' => Epic_Wholesale_Orders_Store::STATUS_APPROVED, 'done' => Epic_Wholesale_Orders_Store::STATUS_DONE, 'unapproved' => Epic_Wholesale_Orders_Store::STATUS_UNAPPROVED );
					$status = sanitize_key( (string) $request->get_param( 'status' ) );
					if ( '' !== $status ) { if ( ! isset( $status_map[ $status ] ) ) { return self::error( 'invalid_status', 'Choose a supported wholesale order status.' ); } $args['post_status'] = array( $status_map[ $status ] ); }
				}
				$query = new WP_Query( $args );
				$count = (int) $query->found_posts;
				$sources[] = array( 'kind' => 'posts', 'suffix' => $suffix, 'type' => $type, 'count' => $count );
				$total += $count;
				continue;
			}
			$table = $wpdb->prefix . $suffix;
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists !== $table ) { return self::error( 'source_unavailable', 'One or more WordPress data sources for this collection are unavailable.', 503 ); }
			$cols = $wpdb->get_col( "SHOW COLUMNS FROM `$table`", 0 );
			$where = '';
			if ( $search ) {
				$searchable = array_values( array_intersect( $cols, array( 'name', 'email', 'phone', 'company', 'title', 'status', 'subject' ) ) );
				if ( $searchable ) { $parts = array(); foreach ( $searchable as $column ) { $parts[] = $wpdb->prepare( "`$column` LIKE %s", '%' . $wpdb->esc_like( $search ) . '%' ); } $where = ' WHERE ' . implode( ' OR ', $parts ); }
			}
			$status = sanitize_key( (string) $request->get_param( 'status' ) );
			if ( '' !== $status && in_array( 'email_status', $cols, true ) ) {
				if ( ! in_array( $status, array( 'pending', 'sent', 'failed', 'disabled', 'skipped', 'exists' ), true ) ) { return self::error( 'invalid_status', 'Choose a supported lead or newsletter status.' ); }
				$where .= '' === $where ? ' WHERE ' : ' AND ';
				$where .= $wpdb->prepare( '`email_status` = %s', $status );
			}
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table`$where" );
			if ( ! $cols ) { continue; }
			$order_col = in_array( 'created_at', $cols, true ) ? 'created_at' : ( in_array( 'id', $cols, true ) ? 'id' : $cols[0] );
			$sources[] = array( 'kind' => 'table', 'suffix' => $suffix, 'table' => $table, 'columns' => $cols, 'where' => $where, 'order_col' => $order_col, 'count' => $count );
			$total += $count;
		}
		$offset = ( $page - 1 ) * $per_page; $remaining = $per_page; $out = array();
		foreach ( $sources as $source ) {
			if ( $remaining <= 0 ) { break; }
			if ( $offset >= $source['count'] ) { $offset -= $source['count']; continue; }
			$take = min( $remaining, $source['count'] - $offset );
			if ( 'posts' === $source['kind'] ) {
				$post_args = array( 'post_type' => $source['type'], 'post_status' => 'any', 'posts_per_page' => $take, 'offset' => $offset, 's' => $search, 'orderby' => 'modified', 'order' => 'DESC' );
				if ( 'coupons' === $resource ) { $status = sanitize_key( (string) $request->get_param( 'status' ) ); $post_args['post_status'] = '' === $status ? array( 'publish', 'draft' ) : array( $status ); }
				if ( 'wholesale-orders' === $resource && class_exists( 'Epic_Wholesale_Orders_Store' ) ) {
					$status_map = array( 'pending' => Epic_Wholesale_Orders_Store::STATUS_PENDING, 'approved' => Epic_Wholesale_Orders_Store::STATUS_APPROVED, 'done' => Epic_Wholesale_Orders_Store::STATUS_DONE, 'unapproved' => Epic_Wholesale_Orders_Store::STATUS_UNAPPROVED );
					$status = sanitize_key( (string) $request->get_param( 'status' ) );
					if ( isset( $status_map[ $status ] ) ) { $post_args['post_status'] = array( $status_map[ $status ] ); }
				}
				$posts = get_posts( $post_args );
				foreach ( $posts as $post ) {
					if ( 'wholesale-orders' === $resource && class_exists( 'Epic_Wholesale_Orders_Store' ) ) {
						$order = Epic_Wholesale_Orders_Store::get_order( $post->ID );
						if ( ! $order ) { continue; }
						$out[] = array( 'id' => $post->ID, 'order_number' => $order['order_number'], 'customer_name' => $order['customer_name'], 'customer_email' => $order['customer_email'], 'order_status' => self::wholesale_status_key( $order['order_status'] ), 'payment_status' => $order['payment_status'], 'total' => $order['total'], 'date_created' => $order['date_created'], 'level_name' => $order['level_name'], 'has_invoice' => $order['has_invoice'], '_source' => 'posts' );
					} elseif ( 'coupons' === $resource && class_exists( 'WC_Coupon' ) ) { $coupon = new WC_Coupon( $post->ID ); $out[] = array( 'id' => $post->ID, 'code' => $coupon->get_code(), 'title' => $coupon->get_code(), 'discount_type' => $coupon->get_discount_type(), 'amount' => $coupon->get_amount(), 'status' => $coupon->get_status(), 'usage_count' => $coupon->get_usage_count(), 'modified' => get_post_modified_time( DATE_ATOM, true, $post ), '_source' => 'posts' ); }
					else { $out[] = array( 'id' => $post->ID, 'title' => get_the_title( $post ), 'status' => $post->post_status, 'modified' => get_post_modified_time( DATE_ATOM, true, $post ), '_source' => 'posts' ); }
				}
			} else {
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$source['table']}`{$source['where']} ORDER BY `{$source['order_col']}` DESC LIMIT %d OFFSET %d", $take, $offset ), ARRAY_A );
				foreach ( $rows as $row ) { unset( $row['password'], $row['token'], $row['secret'] ); $row['_source'] = $source['suffix']; $out[] = $row; }
			}
			$remaining -= $take; $offset = 0;
		}
		$result = array( 'items' => $out, 'page' => $page, 'per_page' => $per_page, 'total' => $total );
		if ( 'coupons' === $resource ) { $result['advanced_rules_enabled'] = class_exists( 'Epic_Adv_Coupons_Meta' ); }
		return rest_ensure_response( $result );
	}

	public static function record( $request ) {
		$resource = (string) $request['resource']; $id = absint( $request['id'] );
		if ( ! in_array( $resource, self::RESOURCES, true ) ) { return self::error( 'unknown_resource', 'This dashboard resource is unavailable.', 404 ); }
		if ( 'coupons' === $resource ) { return self::coupon_record( $request, $id ); }
		if ( in_array( $resource, array( 'orders', 'shipments', 'products', 'customers' ), true ) && ! class_exists( 'WooCommerce' ) ) { return self::error( 'woocommerce_unavailable', 'WooCommerce is unavailable. Business data was not loaded.', 503 ); }
		if ( 'shipments' === $resource && ! class_exists( 'Epic_VTP_Order_Meta_Box' ) ) { return self::error( 'shipping_unavailable', 'ViettelPost shipment records are unavailable.', 503 ); }
		if ( 'orders' === $resource && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $id ); if ( ! $order ) { return self::error( 'not_found', 'Order not found.', 404 ); }
			if ( 'GET' === $request->get_method() ) {
				if ( ! current_user_can( 'edit_shop_order', $id ) && ! current_user_can( 'manage_woocommerce' ) ) { return self::error( 'forbidden', 'You cannot view this order.', 403 ); }
				return rest_ensure_response( array(
					'id' => $id,
					'number' => $order->get_order_number(),
					'status' => $order->get_status(),
					'total' => $order->get_total(),
					'currency' => $order->get_currency(),
					'payment_method' => $order->get_payment_method_title(),
					'transaction_id' => $order->get_transaction_id(),
					'paid_at' => $order->get_date_paid() ? $order->get_date_paid()->date( DATE_ATOM ) : null,
					'billing' => $order->get_address( 'billing' ),
					'shipping' => $order->get_address( 'shipping' ),
					'source' => (string) $order->get_meta( self::META_SOURCE ),
					'fulfillment' => (string) $order->get_meta( self::META_FULFILLMENT ),
					'province_id' => (int) $order->get_meta( '_epic_vtp_province_id' ),
					'ward_id' => (int) $order->get_meta( '_epic_ward_id' ),
					'ward_name' => (string) $order->get_meta( '_epic_ward_name' ),
					'shipping_fee' => $order->get_shipping_total(),
					'items' => array_map( static function ( $item ) { $product = $item->get_product(); $quantity = max( 1, (int) $item->get_quantity() ); return array( 'item_id' => $item->get_id(), 'product_id' => $item->get_product_id(), 'variation_id' => $item->get_variation_id(), 'name' => $item->get_name(), 'sku' => $product ? $product->get_sku() : '', 'quantity' => $item->get_quantity(), 'unit_price' => (float) $item->get_total() / $quantity, 'override_reason' => (string) $item->get_meta( '_epic_price_override_reason' ), 'total' => $item->get_total() ); }, $order->get_items() ),
					'coupon_codes' => array_values( array_map( static function ( $coupon ) { return $coupon->get_code(); }, $order->get_coupons() ) ),
					'notes' => wc_get_order_notes( array( 'order_id' => $id ) ),
					'customer_note' => (string) $order->get_customer_note(),
					'shipment' => self::order_shipment_summary( $order ),
					'revision' => self::order_revision( $order ),
				) );
			}
			if ( 'PATCH' === $request->get_method() ) {
				if ( ! current_user_can( 'edit_shop_order', $id ) && ! current_user_can( 'manage_woocommerce' ) ) { return self::error( 'forbidden', 'You cannot edit this order.', 403 ); }
				$body = $request->get_json_params();
				if ( empty( $body['expected_revision'] ) || ! hash_equals( self::order_revision( $order ), (string) $body['expected_revision'] ) ) { return self::error( 'revision_conflict', 'This order changed after you opened it. Reload before saving.', 409 ); }
				$fields = array();
				if ( array_key_exists( 'status', $body ) ) {
					if ( ! in_array( $body['status'], array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' ), true ) ) { return self::error( 'invalid_status', 'Choose a supported order status.' ); }
					$order->update_status( $body['status'], 'Updated via EPIC Admin dashboard.' ); $fields[] = 'status';
				}
				if ( array_key_exists( 'note', $body ) ) {
					if ( ! is_string( $body['note'] ) || strlen( $body['note'] ) > 4000 ) { return self::error( 'invalid_note', 'Order notes must be text under 4,000 characters.' ); }
					if ( trim( $body['note'] ) ) { $order->add_order_note( sanitize_textarea_field( $body['note'] ) ); $fields[] = 'note'; }
				}
				if ( ! $fields ) { return self::error( 'empty_update', 'No order changes were provided.' ); }
				$order->save();
				self::log( 'order.update', 'orders', (string) $id, $fields, 'success' );
				return rest_ensure_response( array( 'ok' => true ) );
			}
		}
		if ( 'shipments' === $resource && 'GET' === $request->get_method() && function_exists( 'wc_get_order' ) && class_exists( 'Epic_VTP_Order_Meta_Box' ) ) {
			$order = wc_get_order( $id );
			if ( ! $order ) { return self::error( 'not_found', 'Order not found.', 404 ); }
			if ( ! current_user_can( 'edit_shop_order', $id ) && ! current_user_can( 'manage_woocommerce' ) ) { return self::error( 'forbidden', 'You cannot view this shipment.', 403 ); }
			$tracking        = (string) $order->get_meta( Epic_VTP_Order_Meta_Box::META_ORDER_NUMBER );
			$recipient_name  = $order->get_formatted_shipping_full_name() ? $order->get_formatted_shipping_full_name() : $order->get_formatted_billing_full_name();
			$recipient_phone = $order->get_shipping_phone() ? $order->get_shipping_phone() : $order->get_billing_phone();
			return rest_ensure_response( array(
				'id' => $id,
				'number' => $order->get_order_number(),
				'date' => $order->get_date_created() ? $order->get_date_created()->date( DATE_ATOM ) : null,
				'status' => $order->get_status(),
				'total' => $order->get_total(),
				'currency' => $order->get_currency(),
				'payment_method' => $order->get_payment_method_title(),
				'shipping_method' => $order->get_shipping_method(),
				'is_cod' => class_exists( 'Epic_VTP_Client' ) ? Epic_VTP_Client::is_cod_order( $order ) : false,
				'tracking' => $tracking,
				'tracking_url' => self::shipment_tracking_url( $tracking ),
				'tracking_history' => self::shipment_tracking_history( $order ),
				'shipment_status' => Epic_VTP_Client::status_label( $order->get_meta( Epic_VTP_Order_Meta_Box::META_STATUS ) ),
				'shipment_status_code' => (string) $order->get_meta( Epic_VTP_Order_Meta_Box::META_STATUS ),
				'status_options' => Epic_VTP_Client::status_map(),
				'status_date' => (string) $order->get_meta( Epic_VTP_Order_Meta_Box::META_STATUS_DATE ),
				'expected_delivery' => $order->get_meta( Epic_VTP_Order_Meta_Box::META_EXPECTED ),
				'last_synced' => (string) $order->get_meta( Epic_VTP_Order_Meta_Box::META_LAST_SYNCED ),
				'service' => (string) $order->get_meta( Epic_VTP_Order_Meta_Box::META_SERVICE ),
				'province' => (string) $order->get_meta( Epic_VTP_Order_Meta_Box::META_PROVINCE_NAME ),
				'cod_amount' => (string) $order->get_meta( Epic_VTP_Order_Meta_Box::META_COD_AMOUNT ),
				'courier_fee' => (string) $order->get_meta( Epic_VTP_Order_Meta_Box::META_FEE ),
				'quoted_shipping' => (float) $order->get_shipping_total() + (float) $order->get_shipping_tax(),
				'cost_breakdown' => self::shipment_cost_breakdown( $order ),
				'needs_action' => (string) $order->get_meta( Epic_VTP_Order_Meta_Box::META_NEEDS_ACTION ),
				'recipient_name' => $recipient_name,
				'recipient_phone' => $recipient_phone,
				'customer_note' => (string) $order->get_customer_note(),
				'billing' => $order->get_address( 'billing' ),
				'shipping' => $order->get_address( 'shipping' ),
				'reconciliation' => self::shipment_reconciliation_state( $id ),
			) );
		}
		if ( 'customers' === $resource && 'GET' === $request->get_method() && function_exists( 'wc_get_customer' ) ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) { return self::error( 'forbidden', 'You cannot view customer profiles.', 403 ); }
			$customer = wc_get_customer( $id );
			if ( ! $customer ) { return self::error( 'not_found', 'Customer not found.', 404 ); }
			$wholesale = class_exists( 'Epic_Wholesale_Orders_Store' ) && Epic_Wholesale_Orders_Store::is_customer( $id );
			$address = static function ( $type ) use ( $customer ) { $fields = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ); $result = array(); foreach ( $fields as $field ) { $method = 'get_' . $type . '_' . $field; $result[ $field ] = is_callable( array( $customer, $method ) ) ? $customer->{$method}() : ''; } return $result; };
			$orders = function_exists( 'wc_get_orders' ) ? wc_get_orders( array( 'customer_id' => $id, 'limit' => 20, 'orderby' => 'date', 'order' => 'DESC' ) ) : array();
			$history = array_map( static function ( $order ) { return array( 'id' => $order->get_id(), 'number' => $order->get_order_number(), 'date' => $order->get_date_created() ? $order->get_date_created()->date( DATE_ATOM ) : null, 'status' => $order->get_status(), 'total' => $order->get_total(), 'currency' => $order->get_currency() ); }, $orders );
			return rest_ensure_response( array( 'id' => $id, 'name' => $customer->get_display_name(), 'email' => $customer->get_email(), 'phone' => $customer->get_billing_phone(), 'billing' => $address( 'billing' ), 'shipping' => $address( 'shipping' ), 'orders' => (int) $customer->get_order_count(), 'recent_orders' => $history, 'spent' => $customer->get_total_spent(), 'linked_account' => (bool) get_user_meta( $id, 'epic_google_sub', true ), 'wholesale_eligible' => $wholesale ) );
		}
		if ( 'wholesale-orders' === $resource && class_exists( 'Epic_Wholesale_Orders_Store' ) ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) { return self::error( 'forbidden', 'You cannot manage wholesale orders.', 403 ); }
			$order = Epic_Wholesale_Orders_Store::get_order( $id );
			if ( ! $order ) { return self::error( 'not_found', 'Wholesale order not found.', 404 ); }
			if ( 'GET' === $request->get_method() ) {
				$order['order_status_key'] = self::wholesale_status_key( $order['order_status'] );
				$order['status_options'] = array( 'pending' => 'Chờ xử lý', 'approved' => 'Đã duyệt', 'done' => 'Hoàn tất', 'unapproved' => 'Không duyệt' );
				$order['invoice_edit_url'] = $order['has_invoice'] ? admin_url( 'post.php?post=' . $id . '&action=edit' ) : null;
				$order['revision'] = self::wholesale_order_revision( $order );
				return rest_ensure_response( $order );
			}
			if ( 'PATCH' === $request->get_method() ) {
				$body = $request->get_json_params();
				if ( array_key_exists( 'payment_status', $body ) ) { return self::error( 'payment_read_only', 'Payment status is visible for review but cannot be set from the dashboard.', 403 ); }
				if ( empty( $body['expected_revision'] ) || ! hash_equals( self::wholesale_order_revision( $order ), (string) $body['expected_revision'] ) ) { return self::error( 'revision_conflict', 'This wholesale order changed after you opened it. Reload before saving.', 409 ); }
				$status_map = array( 'pending' => Epic_Wholesale_Orders_Store::STATUS_PENDING, 'approved' => Epic_Wholesale_Orders_Store::STATUS_APPROVED, 'done' => Epic_Wholesale_Orders_Store::STATUS_DONE, 'unapproved' => Epic_Wholesale_Orders_Store::STATUS_UNAPPROVED );
				$status = isset( $body['order_status'] ) ? sanitize_key( $body['order_status'] ) : '';
				if ( ! isset( $status_map[ $status ] ) ) { return self::error( 'invalid_status', 'Choose a supported wholesale order status.' ); }
				$reason = isset( $body['cancel_reason'] ) ? sanitize_textarea_field( $body['cancel_reason'] ) : '';
				if ( 'unapproved' === $status && '' === trim( $reason ) ) { return self::error( 'reason_required', 'Provide a reason before unapproving this wholesale order.' ); }
				if ( 'approved' === $status && ! empty( $order['is_vip'] ) && empty( $order['priced'] ) ) { return self::error( 'vip_order_unpriced', 'Set prices for every VIP order item in the existing wholesale order screen before approving it.', 409 ); }
				if ( '' !== trim( $reason ) ) { update_post_meta( $id, Epic_Wholesale_Orders_Store::META_CANCEL_REASON, $reason ); }
				Epic_Wholesale_Orders_Store::apply_order_status( $id, $status_map[ $status ] );
				self::log( 'wholesale.order.update', 'wholesale-orders', (string) $id, array( 'order_status', 'cancel_reason' ), 'success' );
				return rest_ensure_response( array( 'ok' => true, 'order_status' => $status ) );
			}
		}
		if ( 'reviews' === $resource && 'GET' === $request->get_method() && class_exists( 'Epic_Reviews_Store' ) ) {
			$review = Epic_Reviews_Store::get( $id );
			if ( ! $review ) { return self::error( 'not_found', 'Review not found.', 404 ); }
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $review['product_id'] ) : false;
			$review['product_name'] = $product ? $product->get_name() : '';
			$review['revision'] = self::review_revision( $review );
			return rest_ensure_response( $review );
		}
		if ( 'products' === $resource && 'GET' === $request->get_method() ) {
			if ( ! function_exists( 'wp_get_ability' ) ) { return self::error( 'product_mcp_unavailable', 'Activate EPIC Product MCP to inspect full product copy.', 503 ); }
			$ability = wp_get_ability( 'epic-product-mcp/get-product' );
			if ( ! $ability || ! is_callable( array( $ability, 'execute' ) ) ) { return self::error( 'product_mcp_unavailable', 'Activate EPIC Product MCP to inspect full product copy.', 503 ); }
			return rest_ensure_response( $ability->execute( array( 'product_id' => $id ) ) );
		}
		if ( 'content' === $resource ) {
			$post = get_post( $id ); if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) { return self::error( 'not_found', 'Article not found.', 404 ); }
			if ( 'GET' === $request->get_method() ) { if ( ! current_user_can( 'edit_post', $id ) ) { return self::error( 'forbidden', 'You cannot view this article.', 403 ); } return rest_ensure_response( array( 'id' => $id, 'title' => $post->post_title, 'content' => $post->post_content, 'excerpt' => $post->post_excerpt, 'status' => $post->post_status, 'modified' => get_post_modified_time( DATE_ATOM, true, $post ), 'revision' => self::post_revision( $post ) ) ); }
			if ( 'PATCH' === $request->get_method() ) { if ( ! current_user_can( 'edit_post', $id ) ) { return self::error( 'forbidden', 'You cannot edit this article.', 403 ); } $body = $request->get_json_params(); if ( empty( $body['expected_revision'] ) || ! hash_equals( self::post_revision( $post ), (string) $body['expected_revision'] ) ) { return self::error( 'revision_conflict', 'This article changed after you opened it. Reload before saving.', 409 ); } $data = array( 'ID' => $id ); foreach ( array( 'post_title' => 'title', 'post_content' => 'content', 'post_excerpt' => 'excerpt' ) as $field => $key ) { if ( isset( $body[ $key ] ) ) { $data[ $field ] = 'post_content' === $field ? wp_kses_post( $body[ $key ] ) : sanitize_text_field( $body[ $key ] ); } } if ( isset( $body['status'] ) ) { if ( ! in_array( $body['status'], array( 'draft', 'pending', 'publish', 'private' ), true ) ) { return self::error( 'invalid_status', 'Choose a supported article status.' ); } if ( 'publish' === $body['status'] && ( empty( $body['confirm_publish'] ) || ! current_user_can( 'publish_posts' ) ) ) { return self::error( 'publish_confirmation_required', 'Publishing requires publication permission and explicit confirmation.', 403 ); } $data['post_status'] = $body['status']; } $updated = wp_update_post( wp_slash( $data ), true ); if ( is_wp_error( $updated ) ) { return $updated; } self::log( 'content.update', 'content', (string) $id, array_keys( $data ), 'success' ); return rest_ensure_response( array( 'ok' => true, 'id' => $id ) ); }
			if ( 'DELETE' === $request->get_method() ) { if ( ! current_user_can( 'delete_post', $id ) ) { return self::error( 'forbidden', 'You cannot move this article to Trash.', 403 ); } wp_trash_post( $id ); self::log( 'content.trash', 'content', (string) $id, array( 'post_status' ), 'success' ); return rest_ensure_response( array( 'ok' => true ) ); }
		}
		if ( 'reviews' === $resource && class_exists( 'Epic_Reviews_Store' ) ) {
			$review = Epic_Reviews_Store::get( $id );
			if ( ! $review ) { return self::error( 'not_found', 'Review not found.', 404 ); }
			if ( 'PATCH' === $request->get_method() ) {
				$body = $request->get_json_params(); $status = isset( $body['status'] ) ? sanitize_key( $body['status'] ) : '';
				if ( empty( $body['expected_revision'] ) || ! hash_equals( self::review_revision( $review ), (string) $body['expected_revision'] ) ) { return self::error( 'revision_conflict', 'This review changed after you opened it. Reload before saving.', 409 ); }
				if ( ! in_array( $status, array( 'pending', 'approved' ), true ) ) { return self::error( 'invalid_status', 'Review status must be pending or approved.' ); }
				if ( ! Epic_Reviews_Store::set_status( $id, $status ) ) { return self::error( 'save_failed', 'Could not save the review moderation change.', 500 ); }
				self::log( 'review.moderate', 'reviews', (string) $id, array( 'status' ), 'success' );
				return rest_ensure_response( array( 'ok' => true ) );
			}
			if ( 'DELETE' === $request->get_method() ) {
				if ( ! Epic_Reviews_Store::delete( $id ) ) { return self::error( 'delete_failed', 'Could not remove this review.', 500 ); }
				self::log( 'review.delete', 'reviews', (string) $id, array( 'deleted' ), 'success' );
				return rest_ensure_response( array( 'ok' => true ) );
			}
		}
		if ( 'leads' === $resource && in_array( $request->get_method(), array( 'GET', 'DELETE' ), true ) ) {
			global $wpdb;
			if ( ! current_user_can( 'manage_options' ) ) { return self::error( 'forbidden', 'You cannot inspect or remove lead records.', 403 ); }
			$source = sanitize_key( (string) $request->get_param( 'source' ) );
			$allowed = array( 'epic_contact_requests', 'epic_sample_requests', 'epic_wholesale_inquiries' );
			if ( ! in_array( $source, $allowed, true ) ) { return self::error( 'source_required', 'Choose the lead source from the reviewed list.', 400 ); }
			$table = $wpdb->prefix . $source;
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `$table` WHERE id = %d", $id ), ARRAY_A );
			if ( ! $row ) { return self::error( 'not_found', 'Lead not found.', 404 ); }
			if ( 'DELETE' === $request->get_method() ) {
				$body = $request->get_json_params();
				if ( true !== ( $body['confirm_delete'] ?? false ) || empty( $body['expected_revision'] ) ) { return self::error( 'lead_delete_confirmation_required', 'Review the lead and confirm its removal after reloading the latest revision.', 403 ); }
				if ( ! hash_equals( self::lead_revision( $row ), (string) $body['expected_revision'] ) ) { return self::error( 'revision_conflict', 'This lead changed after you opened it. Reload before removing it.', 409 ); }
				$stores = array( 'epic_contact_requests' => 'Epic_Contact_Store', 'epic_sample_requests' => 'Epic_Sample_Store', 'epic_wholesale_inquiries' => 'Epic_Wholesale_Store' );
				$store = $stores[ $source ];
				if ( ! class_exists( $store ) || ! is_callable( array( $store, 'delete' ) ) ) { return self::error( 'lead_source_unavailable', 'The lead source removal action is unavailable.', 503 ); }
				if ( ! $store::delete( $id ) ) { return self::error( 'lead_delete_failed', 'The lead could not be removed. Refresh its status before retrying.', 503 ); }
				self::log( 'lead.delete', 'leads', (string) $id, array( 'deleted' ), 'success' );
				return rest_ensure_response( array( 'ok' => true ) );
			}
			$revision = self::lead_revision( $row );
			unset( $row['password'], $row['token'], $row['secret'] );
			$row['_source'] = $source;
			$row['revision'] = $revision;
			return rest_ensure_response( $row );
		}
		if ( in_array( $resource, array( 'costs', 'distributors', 'coupons', 'newsletter', 'ledger' ), true ) && 'GET' === $request->get_method() ) {
			global $wpdb;
			$source = sanitize_key( (string) $request->get_param( 'source' ) );
			$allowed = array(
				'costs' => array( 'epic_product_cost_history' ), 'distributors' => array( 'epic_distributor_profit_distributors' ), 'ledger' => array( 'epic_distributor_profit_entries' ),
				'coupons' => array( 'posts' ), 'newsletter' => array( 'epic_newsletter_subscribers', 'epic_newsletter_campaigns' ),
			);
			if ( ! in_array( $source, $allowed[ $resource ], true ) ) { return self::error( 'source_required', 'Choose the record source from the reviewed list.', 400 ); }
			if ( 'posts' === $source ) { $type = 'shop_coupon'; $post = get_post( $id ); if ( ! $post || $type !== $post->post_type ) { return self::error( 'not_found', 'Coupon not found.', 404 ); } return rest_ensure_response( array( 'id' => $id, 'code' => $post->post_name, 'status' => $post->post_status, 'title' => $post->post_title, 'modified' => get_post_modified_time( DATE_ATOM, true, $post ) ) ); }
			$table = $wpdb->prefix . $source;
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `$table` WHERE id = %d", $id ), ARRAY_A );
			if ( ! $row ) { return self::error( 'not_found', 'Record not found.', 404 ); }
			unset( $row['password'], $row['token'], $row['secret'] );
			return rest_ensure_response( $row );
		}
		return self::error( 'unsupported_action', 'This record action is not available in the dashboard yet.', 501 );
	}

	private static function order_revision( $order ) {
		$items = array();
		foreach ( $order->get_items() as $item ) { $items[] = array( $item->get_id(), $item->get_name(), $item->get_quantity(), $item->get_total() ); }
		$notes = array_map( static function ( $note ) { return isset( $note->id ) ? (int) $note->id : 0; }, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		return hash( 'sha256', wp_json_encode( array( $order->get_data(), $items, $notes ) ) );
	}

	private static function wholesale_order_revision( $order ) {
		return hash( 'sha256', wp_json_encode( $order ) );
	}

	private static function wholesale_status_key( $status ) {
		if ( ! class_exists( 'Epic_Wholesale_Orders_Store' ) ) { return 'pending'; }
		if ( Epic_Wholesale_Orders_Store::STATUS_APPROVED === $status ) { return 'approved'; }
		if ( Epic_Wholesale_Orders_Store::STATUS_DONE === $status ) { return 'done'; }
		if ( in_array( $status, array( Epic_Wholesale_Orders_Store::STATUS_UNAPPROVED, Epic_Wholesale_Orders_Store::STATUS_CANCELLED ), true ) ) { return 'unapproved'; }
		return 'pending';
	}

	private static function post_revision( $post ) {
		return hash( 'sha256', wp_json_encode( array( $post->ID, $post->post_title, $post->post_content, $post->post_excerpt, $post->post_status, $post->post_modified_gmt ) ) );
	}

	private static function review_revision( $review ) {
		unset( $review['revision'], $review['product_name'] );
		return hash( 'sha256', wp_json_encode( array_values( $review ) ) );
	}

	private static function lead_revision( $lead ) {
		return hash( 'sha256', wp_json_encode( $lead ) );
	}

	private static function has_table( $suffix ) {
		global $wpdb;
		$table = $wpdb->prefix . sanitize_key( $suffix );
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Public ViettelPost tracking URL for a waybill. Reuses the canonical URL
	 * builder from epic-order-emails when present so the dashboard, the
	 * customer "shipped" email and wp-admin never drift; falls back to the same
	 * URL and stays filterable.
	 */
	private static function shipment_tracking_url( $tracking ) {
		$tracking = trim( (string) $tracking );
		if ( '' === $tracking ) { return ''; }
		if ( function_exists( 'epic_order_emails_viettelpost_tracking_url' ) ) {
			return (string) epic_order_emails_viettelpost_tracking_url( $tracking );
		}
		return (string) apply_filters( 'epic_admin_dashboard_viettelpost_tracking_url', 'https://viettelpost.com.vn/tra-cuu-hanh-trinh-don/?billcode=' . rawurlencode( $tracking ), $tracking );
	}

	/**
	 * Compact ViettelPost shipment summary for the order detail sidebar.
	 * Returns null when the order has no shipment (or the courier plugin is
	 * absent), so the order screen can degrade gracefully.
	 */
	private static function order_shipment_summary( $order ) {
		if ( ! class_exists( 'Epic_VTP_Client' ) || ! class_exists( 'Epic_VTP_Order_Meta_Box' ) ) { return null; }
		$tracking = (string) $order->get_meta( Epic_VTP_Order_Meta_Box::META_ORDER_NUMBER );
		$code     = (string) $order->get_meta( Epic_VTP_Order_Meta_Box::META_STATUS );
		if ( '' === $tracking && '' === $code ) { return null; }
		return array(
			'tracking' => $tracking,
			'tracking_url' => self::shipment_tracking_url( $tracking ),
			'status' => '' !== $code ? Epic_VTP_Client::status_label( $code ) : '',
			'status_code' => $code,
			'status_date' => (string) $order->get_meta( Epic_VTP_Order_Meta_Box::META_STATUS_DATE ),
			'expected_delivery' => (string) $order->get_meta( Epic_VTP_Order_Meta_Box::META_EXPECTED ),
			'last_synced' => (string) $order->get_meta( Epic_VTP_Order_Meta_Box::META_LAST_SYNCED ),
			'needs_action' => (string) $order->get_meta( Epic_VTP_Order_Meta_Box::META_NEEDS_ACTION ),
		);
	}

	/**
	 * The ViettelPost journey for a shipment, newest event first. ViettelPost
	 * exposes no tracking-query endpoint — the courier plugin records one
	 * WooCommerce order note per status change — so the journey is derived
	 * from those notes. Each entry is { status, date, note }.
	 */
	private static function shipment_tracking_history( $order ) {
		// Prefer structured events if a future courier version stores them.
		$stored = $order->get_meta( '_vtp_tracking_history' );
		if ( is_array( $stored ) && $stored ) {
			$events = array();
			foreach ( $stored as $event ) {
				if ( ! is_array( $event ) ) { continue; }
				$events[] = array(
					'status' => isset( $event['status'] ) ? (string) $event['status'] : '',
					'date' => isset( $event['date'] ) ? (string) $event['date'] : '',
					'note' => isset( $event['note'] ) ? (string) $event['note'] : '',
				);
			}
			if ( $events ) { return array_reverse( $events ); }
		}

		$codes  = '10[1-7]|20[0-2]|300|400|50[0-9]|515|550';
		$events = array();
		foreach ( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) as $note ) {
			$content = isset( $note->content ) ? wp_strip_all_tags( (string) $note->content ) : '';
			if ( ! preg_match( '/\((' . $codes . ')\)/', $content, $match ) ) { continue; }
			$date = '';
			if ( preg_match( '/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $content, $date_match ) ) {
				$date = $date_match[0];
			} elseif ( isset( $note->date_created ) && $note->date_created instanceof DateTimeInterface ) {
				$date = $note->date_created->format( 'Y-m-d H:i:s' );
			}
			$text = preg_replace( '/^.*?\(' . $codes . '\)/s', '', $content );
			$text = preg_replace( '/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', '', (string) $text );
			$text = trim( (string) preg_replace( '/^[\s\x{2013}\x{2014}-]+|[\s\x{2013}\x{2014}-]+$/u', '', (string) $text ) );
			$events[] = array( 'status' => $match[1], 'date' => $date, 'note' => $text );
		}
		return array_reverse( $events );
	}

	/**
	 * Itemized ViettelPost shipping-cost breakdown for the shipment detail.
	 *
	 * Reads `_vtp_cost_breakdown`, written by the courier plugin's webhook on
	 * every status event. For shipments booked before that meta existed,
	 * backfills once from ViettelPost's server-side push history (there is no
	 * order-detail API), caches the result and seeds the journey timeline.
	 * Read-only and best-effort: returns null on any failure so the detail
	 * never breaks.
	 */
	private static function shipment_cost_breakdown( $order ) {
		// Literal meta key (not the courier plugin's constant) so an older
		// epic-viettelpost-shipping that predates the constant can't fatal.
		$stored = $order->get_meta( '_vtp_cost_breakdown' );
		if ( is_array( $stored ) && $stored ) {
			return $stored;
		}
		return self::backfill_shipment_from_push_history( $order );
	}

	/**
	 * Fetches ViettelPost's push history for the order's waybill and caches the
	 * cost breakdown (+ journey events) into order meta. Returns the breakdown
	 * or null. Never throws.
	 */
	private static function backfill_shipment_from_push_history( $order ) {
		$tracking = (string) $order->get_meta( Epic_VTP_Order_Meta_Box::META_ORDER_NUMBER );
		if ( '' === $tracking || ! class_exists( 'Epic_VTP_Client' ) || ! method_exists( 'Epic_VTP_Client', 'get_push_history' ) ) {
			return null;
		}

		$records = Epic_VTP_Client::get_push_history( $tracking );
		if ( is_wp_error( $records ) || ! is_array( $records ) ) {
			return null;
		}

		$latest = null;
		$events = array();
		foreach ( $records as $record ) {
			$data = ( is_array( $record ) && isset( $record['body']['DATA'] ) && is_array( $record['body']['DATA'] ) ) ? $record['body']['DATA'] : null;
			if ( ! $data ) {
				continue;
			}
			if ( null === $latest ) {
				$latest = $data; // Records are newest-first.
			}
			$events[] = array(
				'status' => isset( $data['ORDER_STATUS'] ) ? (string) $data['ORDER_STATUS'] : '',
				'date'   => isset( $data['ORDER_STATUSDATE'] ) ? sanitize_text_field( (string) $data['ORDER_STATUSDATE'] ) : '',
				'note'   => isset( $data['NOTE'] ) ? sanitize_text_field( (string) $data['NOTE'] ) : '',
			);
		}

		if ( null === $latest || ! method_exists( 'Epic_VTP_Client', 'parse_cost_breakdown' ) ) {
			return null;
		}

		$breakdown = Epic_VTP_Client::parse_cost_breakdown( $latest );
		if ( ! is_array( $breakdown ) || ! $breakdown ) {
			return null;
		}

		$order->update_meta_data( '_vtp_cost_breakdown', $breakdown );

		// Seed the structured journey (stored oldest-first) only when absent so
		// richer webhook-collected history is never clobbered.
		$existing = $order->get_meta( '_vtp_tracking_history' );
		if ( ! is_array( $existing ) || ! $existing ) {
			$order->update_meta_data( '_vtp_tracking_history', array_reverse( $events ) );
		}

		$order->save();
		return $breakdown;
	}

	private static function shipment_reconciliation_state( $order_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT action, outcome, created_at FROM " . self::tables()['receipts'] . " WHERE action IN (%s, %s) AND outcome IN ('pending', 'uncertain') ORDER BY id DESC LIMIT 2", 'shipment-book:' . absint( $order_id ), 'shipment-cancel:' . absint( $order_id ) ) );
		if ( ! $rows ) { return null; }
		if ( count( $rows ) > 1 ) { return array( 'status' => 'multiple', 'action' => null ); }
		$row = $rows[0];
		$state = $row->outcome;
		if ( 'pending' === $state && strtotime( $row->created_at . ' UTC' ) <= time() - 120 ) { $state = 'uncertain'; }
		return array(
			'status' => $state,
			'action' => ( 'shipment-book:' . absint( $order_id ) ) === $row->action ? 'book' : 'cancel',
		);
	}

	private static function log( $action, $resource, $id, $fields, $outcome ) {
		global $wpdb;
		$session = isset( $GLOBALS['epic_admin_dashboard_session'] ) ? $GLOBALS['epic_admin_dashboard_session'] : array( 'user_id' => get_current_user_id() );
		$wpdb->insert( self::tables()['audit'], array( 'user_id' => (int) $session['user_id'], 'action' => sanitize_key( $action ), 'resource' => sanitize_key( $resource ), 'record_id' => sanitize_text_field( $id ), 'fields' => wp_json_encode( array_values( $fields ) ), 'outcome' => sanitize_key( $outcome ), 'created_at' => gmdate( 'Y-m-d H:i:s' ) ), array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' ) );
	}
}
