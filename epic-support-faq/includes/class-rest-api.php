<?php
/** Admin CRUD for FAQ records plus the server-to-server context bundle the website reads. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Epic_Support_Faq_Rest_Api {
	const ADMIN_NS = 'epic-admin/v1';
	const SUPPORT_NS = 'epic-support/v1';

	public static function register_routes() {
		register_rest_route( self::ADMIN_NS, '/faqs', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'list_faqs' ), 'permission_callback' => array( __CLASS__, 'require_admin' ) ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_faq' ), 'permission_callback' => array( __CLASS__, 'require_admin' ) ),
		) );
		register_rest_route( self::ADMIN_NS, '/faqs/bundle', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'bundle' ), 'permission_callback' => array( __CLASS__, 'require_admin' ) ) );
		register_rest_route( self::ADMIN_NS, '/faqs/rebuild', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rebuild' ), 'permission_callback' => array( __CLASS__, 'require_admin' ) ) );
		register_rest_route( self::ADMIN_NS, '/faqs/import-preview', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'import_preview' ), 'permission_callback' => array( __CLASS__, 'require_admin' ) ) );
		register_rest_route( self::ADMIN_NS, '/faqs/translate', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'translate_faq' ), 'permission_callback' => array( __CLASS__, 'require_admin' ) ) );
		register_rest_route( self::ADMIN_NS, '/faqs/import', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'import_seed' ), 'permission_callback' => array( __CLASS__, 'require_admin' ) ) );
		register_rest_route( self::ADMIN_NS, '/faqs/reorder', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'reorder' ), 'permission_callback' => array( __CLASS__, 'require_admin' ) ) );
		register_rest_route( self::ADMIN_NS, '/faqs/(?P<id>\d+)', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_faq' ), 'permission_callback' => array( __CLASS__, 'require_admin' ) ),
			array( 'methods' => 'PATCH', 'callback' => array( __CLASS__, 'update_faq' ), 'permission_callback' => array( __CLASS__, 'require_admin' ) ),
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete_faq' ), 'permission_callback' => array( __CLASS__, 'require_admin' ) ),
		) );
		register_rest_route( self::ADMIN_NS, '/faqs/(?P<id>\d+)/publish', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'publish_faq' ), 'permission_callback' => array( __CLASS__, 'require_admin' ) ) );
		register_rest_route( self::ADMIN_NS, '/faqs/(?P<id>\d+)/unpublish', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'unpublish_faq' ), 'permission_callback' => array( __CLASS__, 'require_admin' ) ) );
		register_rest_route( self::SUPPORT_NS, '/faq-context', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'faq_context' ), 'permission_callback' => array( __CLASS__, 'check_secret' ) ) );
	}

	public static function require_admin( $request ) {
		if ( ! class_exists( 'Epic_Admin_Dashboard' ) ) {
			return new WP_Error( 'faq_admin_unavailable', 'FAQ administration requires the EPIC Admin Dashboard plugin.', array( 'status' => 503 ) );
		}
		return Epic_Admin_Dashboard::require_admin_session( $request );
	}

	public static function check_secret( $request ) {
		if ( ! class_exists( 'Epic_Agent_Settings' ) ) {
			return new WP_Error( 'faq_unavailable', 'FAQ context is unavailable.', array( 'status' => 503 ) );
		}
		$expected = (string) Epic_Agent_Settings::get_shared_secret();
		$provided = (string) $request->get_header( 'x-epic-secret' );
		if ( '' === $expected ) { return new WP_Error( 'faq_unavailable', 'FAQ context is not configured.', array( 'status' => 503 ) ); }
		if ( '' === $provided || ! hash_equals( $expected, $provided ) ) { return new WP_Error( 'forbidden', 'Invalid or missing X-Epic-Secret header.', array( 'status' => 403 ) ); }
		return true;
	}

	private static function context( $lang ) {
		$lang = is_string( $lang ) && in_array( $lang, Epic_Support_Faq_Store::LOCALES, true ) ? $lang : 'en';
		$bundle = Epic_Support_Faq_Store::get_bundle( $lang );
		return array( 'bundle' => $bundle, 'settings' => Epic_Support_Faq_Store::settings() );
	}

	public static function list_faqs( $request ) {
		$result = Epic_Support_Faq_Store::list_faqs( array(
			'status'   => $request->get_param( 'status' ),
			'group'    => $request->get_param( 'group' ),
			'lang'     => $request->get_param( 'lang' ),
			'search'   => $request->get_param( 'search' ),
			'page'     => $request->get_param( 'page' ),
			'per_page' => $request->get_param( 'per_page' ),
		) );
		if ( is_wp_error( $result ) ) { return $result; }
		$result['settings'] = Epic_Support_Faq_Store::settings();
		return rest_ensure_response( $result );
	}

	public static function get_faq( $request ) {
		$faq = Epic_Support_Faq_Store::get_faq( absint( $request['id'] ) );
		if ( ! $faq ) { return new WP_Error( 'faq_not_found', 'FAQ was not found.', array( 'status' => 404 ) ); }
		return rest_ensure_response( array( 'faq' => $faq ) );
	}

	public static function create_faq( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) { return new WP_Error( 'invalid_faq', 'Send a JSON body.', array( 'status' => 400 ) ); }
		$faq = Epic_Support_Faq_Store::save_faq( $body, 0, 0, get_current_user_id() );
		if ( is_wp_error( $faq ) ) { return $faq; }
		$response = rest_ensure_response( array( 'faq' => $faq ) );
		$response->set_status( 201 );
		return $response;
	}

	public static function update_faq( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) { return new WP_Error( 'invalid_faq', 'Send a JSON body.', array( 'status' => 400 ) ); }
		$faq = Epic_Support_Faq_Store::save_faq( $body, absint( $request['id'] ), absint( $body['revision'] ?? 0 ), get_current_user_id() );
		if ( is_wp_error( $faq ) ) { return $faq; }
		return rest_ensure_response( array( 'faq' => $faq ) );
	}

	public static function delete_faq( $request ) {
		$rev = absint( $request->get_param( 'revision' ) );
		$result = Epic_Support_Faq_Store::delete_faq( absint( $request['id'] ), $rev );
		if ( is_wp_error( $result ) ) { return $result; }
		return rest_ensure_response( $result );
	}

	public static function publish_faq( $request ) {
		$rev = absint( $request->get_param( 'revision' ) );
		$result = Epic_Support_Faq_Store::publish_faq( absint( $request['id'] ), $rev );
		if ( is_wp_error( $result ) ) { return $result; }
		return rest_ensure_response( $result );
	}

	public static function unpublish_faq( $request ) {
		$rev = absint( $request->get_param( 'revision' ) );
		$result = Epic_Support_Faq_Store::unpublish_faq( absint( $request['id'] ), $rev );
		if ( is_wp_error( $result ) ) { return $result; }
		return rest_ensure_response( $result );
	}

	public static function reorder( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) { return new WP_Error( 'invalid_faq_order', 'Send a JSON body.', array( 'status' => 400 ) ); }
		$result = Epic_Support_Faq_Store::reorder( sanitize_key( (string) ( $body['group'] ?? '' ) ), $body['ids'] ?? array() );
		if ( is_wp_error( $result ) ) { return $result; }
		return rest_ensure_response( $result );
	}

	public static function bundle( $request ) {
		$context = self::context( $request->get_param( 'lang' ) );
		return rest_ensure_response( $context );
	}

	public static function rebuild( $request ) {
		$bundles = Epic_Support_Faq_Store::rebuild_bundles();
		return rest_ensure_response( array( 'ok' => true, 'bundles' => $bundles, 'settings' => Epic_Support_Faq_Store::settings() ) );
	}

	public static function import_preview( $request ) {
		$preview = Epic_Support_Faq_Store::seed_preview();
		if ( is_wp_error( $preview ) ) { return $preview; }
		return rest_ensure_response( $preview );
	}

	public static function translate_faq( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) { return new WP_Error( 'invalid_faq_translation', 'Send a JSON body.', array( 'status' => 400 ) ); }
		$result = Epic_Support_Faq_Store::translate( $body['question'] ?? '', $body['answer'] ?? '' );
		if ( is_wp_error( $result ) ) { return $result; }
		return rest_ensure_response( $result );
	}

	public static function import_seed( $request ) {
		$body = $request->get_json_params();
		$overwrite = is_array( $body ) && ! empty( $body['overwrite'] );
		$result = Epic_Support_Faq_Store::import_seed( $overwrite );
		if ( is_wp_error( $result ) ) { return $result; }
		return rest_ensure_response( $result );
	}

	/** Public-to-the-holding-secret bundle read used by the website at render time. */
	public static function faq_context( $request ) {
		$lang = $request->get_param( 'lang' );
		$lang = is_string( $lang ) && in_array( $lang, Epic_Support_Faq_Store::LOCALES, true ) ? $lang : 'en';
		$bundle = Epic_Support_Faq_Store::get_bundle( $lang );
		$etag = '"' . $bundle['version'] . '"';
		$if_none_match = (string) $request->get_header( 'if-none-match' );
		$response = new WP_REST_Response( $bundle, 200 );
		$response->header( 'ETag', $etag );
		$response->header( 'Cache-Control', 'private, max-age=60' );
		$response->header( 'X-Epic-Faq-Version', (string) $bundle['version'] );
		if ( '' !== $if_none_match && trim( $if_none_match ) === $etag ) {
			$response->set_status( 304 );
			$response->set_data( null );
		}
		return $response;
	}
}
