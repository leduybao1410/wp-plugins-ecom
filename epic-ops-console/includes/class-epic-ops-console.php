<?php
/** Routes and access gate for the /ops console. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Epic_Ops_Console {
	const QUERY_VAR = 'epic_ops';
	const PATH_VAR  = 'epic_ops_path';
	const BASE      = 'ops';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'routes' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_home' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'dispatch' ), 1 );
		add_filter( 'robots_txt', array( __CLASS__, 'robots' ), 10, 2 );
	}

	/**
	 * Makes /ops the landing page for the domain: a bare visit to `/` sends a
	 * signed-in administrator to the console and an anonymous visitor to the
	 * WordPress login form, which returns them to /ops after signing in.
	 *
	 * Only the exact site root is touched. Post/page permalinks, feeds, the REST
	 * API, wp-admin, wp-login, cron, sitemaps and any request carrying a query
	 * string keep their normal WordPress behaviour, so nothing else on the site
	 * moves.
	 */
	public static function redirect_home() {
		if ( ! self::is_root_request() ) { return; }
		if ( ! apply_filters( 'epic_ops_redirect_home', true ) ) { return; }
		if ( defined( 'EPIC_OPS_DISABLE_HOME_REDIRECT' ) && EPIC_OPS_DISABLE_HOME_REDIRECT ) { return; }

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( home_url( '/' . self::BASE ) ), 302 );
			exit;
		}

		// Other signed-in roles (shop managers, customers) keep the normal site.
		if ( ! current_user_can( 'manage_options' ) ) { return; }

		wp_safe_redirect( home_url( '/' . self::BASE ), 302 );
		exit;
	}

	private static function is_root_request() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) { return false; }
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) { return false; }
		if ( defined( 'WP_CLI' ) && WP_CLI ) { return false; }
		if ( ! empty( $_SERVER['QUERY_STRING'] ) ) { return false; }

		$path = wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
		$path = untrailingslashit( (string) $path );
		return '' === $path || '/index.php' === $path;
	}

	public static function routes() {
		add_rewrite_rule( '^' . self::BASE . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
		add_rewrite_rule( '^' . self::BASE . '/(.+?)/?$', 'index.php?' . self::QUERY_VAR . '=1&' . self::PATH_VAR . '=$matches[1]', 'top' );
	}

	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		$vars[] = self::PATH_VAR;
		return $vars;
	}

	public static function activate() {
		self::routes();
		flush_rewrite_rules( false );
	}

	public static function deactivate() {
		flush_rewrite_rules( false );
	}

	public static function dispatch() {
		if ( ! get_query_var( self::QUERY_VAR ) ) { return; }

		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		do_action( 'litespeed_control_set_nocache', 'epic ops console' );

		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			status_header( 403 );
			wp_die(
				esc_html__( 'Chỉ quản trị viên mới được truy cập bảng vận hành EPIC.', 'epic-ops-console' ),
				esc_html__( 'Không đủ quyền', 'epic-ops-console' ),
				array( 'response' => 403 )
			);
		}

		Epic_Ops_Template::render();
		exit;
	}

	public static function robots( $output, $public ) {
		if ( '0' === (string) $public ) { return $output; }
		$output .= "\nDisallow: /ops\n";
		return $output;
	}
}
