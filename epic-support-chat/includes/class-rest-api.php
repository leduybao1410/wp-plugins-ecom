<?php
/** Server-to-server conversation ingestion. Admin reads are exposed by EPIC Admin Dashboard. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Epic_Support_Chat_Rest_Api {
	const NS = 'epic-support/v1';
	const LOCALES = array( 'vi', 'en', 'ru', 'hi', 'zh', 'ko', 'ja' );

	public static function register_routes() {
		register_rest_route( self::NS, '/turns/start', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'start_turn' ), 'permission_callback' => array( __CLASS__, 'check_secret' ) ) );
		register_rest_route( self::NS, '/turns/(?P<id>\d+)/finish', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'finish_turn' ), 'permission_callback' => array( __CLASS__, 'check_secret' ) ) );
	}

	public static function check_secret( $request ) {
		if ( ! class_exists( 'Epic_Agent_Settings' ) ) { return new WP_Error( 'support_storage_unavailable', 'Support chat storage is unavailable.', array( 'status' => 503 ) ); }
		$expected = (string) Epic_Agent_Settings::get_shared_secret();
		$provided = (string) $request->get_header( 'x-epic-secret' );
		if ( '' === $expected ) { return new WP_Error( 'support_storage_unavailable', 'Support chat storage is not configured.', array( 'status' => 503 ) ); }
		if ( '' === $provided || ! hash_equals( $expected, $provided ) ) { return new WP_Error( 'forbidden', 'Invalid or missing X-Epic-Secret header.', array( 'status' => 403 ) ); }
		return true;
	}

	public static function start_turn( $request ) {
		global $wpdb;
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || ! isset( $body['sessionId'], $body['requestId'], $body['locale'], $body['message'] )
			|| ! is_string( $body['sessionId'] ) || ! preg_match( '/^[A-Za-z0-9_-]{16,80}$/', $body['sessionId'] )
			|| ! is_string( $body['requestId'] ) || ! preg_match( '/^[A-Za-z0-9_-]{16,80}$/', $body['requestId'] )
			|| ! is_string( $body['locale'] ) || ! in_array( $body['locale'], self::LOCALES, true )
			|| ! is_string( $body['message'] ) || '' === trim( $body['message'] ) || strlen( $body['message'] ) > 4000 ) {
			return new WP_Error( 'invalid_support_turn', 'Invalid support conversation record.', array( 'status' => 400 ) );
		}
		$secret = (string) Epic_Agent_Settings::get_shared_secret();
		$session_hash = hash_hmac( 'sha256', $body['sessionId'], $secret );
		$request_hash = hash_hmac( 'sha256', $body['sessionId'] . ':' . $body['requestId'], $secret );
		$tables = Epic_Support_Chat_Store::tables();
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, conversation_id FROM {$tables['turns']} WHERE request_hash = %s", $request_hash ) );
		if ( $existing ) { return rest_ensure_response( array( 'conversationId' => (int) $existing->conversation_id, 'turnId' => (int) $existing->id, 'duplicate' => true ) ); }
		$now = gmdate( 'Y-m-d H:i:s' );
		$expires = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS * Epic_Support_Chat_Store::RETENTION_DAYS );
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$tables['conversations']} (session_hash, locale, status, activity_version, created_at, last_activity_at, expires_at) VALUES (%s, %s, 'open', 0, %s, %s, %s)", $session_hash, $body['locale'], $now, $now, $expires ) );
		$conversation_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tables['conversations']} WHERE session_hash = %s AND locale = %s", $session_hash, $body['locale'] ) );
		if ( ! $conversation_id ) { return new WP_Error( 'support_store_unavailable', 'Support conversation could not be recorded.', array( 'status' => 503 ) ); }
		$inserted = $wpdb->insert( $tables['turns'], array( 'conversation_id' => $conversation_id, 'request_hash' => $request_hash, 'user_text' => Epic_Support_Chat_Store::redact( $body['message'], 4000 ), 'response_kind' => 'pending', 'outcome' => 'pending', 'started_at' => $now ), array( '%d', '%s', '%s', '%s', '%s', '%s' ) );
		if ( false === $inserted ) {
			$duplicate = $wpdb->get_row( $wpdb->prepare( "SELECT id, conversation_id FROM {$tables['turns']} WHERE request_hash = %s", $request_hash ) );
			if ( $duplicate ) { return rest_ensure_response( array( 'conversationId' => (int) $duplicate->conversation_id, 'turnId' => (int) $duplicate->id, 'duplicate' => true ) ); }
			return new WP_Error( 'support_store_unavailable', 'Support conversation could not be recorded.', array( 'status' => 503 ) );
		}
		$turn_id = (int) $wpdb->insert_id;
		$wpdb->update( $tables['conversations'], array( 'status' => 'open', 'last_activity_at' => $now, 'expires_at' => $expires ), array( 'id' => $conversation_id ), array( '%s', '%s', '%s' ), array( '%d' ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$tables['conversations']} SET activity_version = activity_version + 1 WHERE id = %d", $conversation_id ) );
		return rest_ensure_response( array( 'conversationId' => $conversation_id, 'turnId' => $turn_id, 'duplicate' => false ) );
	}

	public static function finish_turn( $request ) {
		global $wpdb;
		$id = absint( $request['id'] );
		$body = $request->get_json_params();
		$kinds = array( 'faq', 'order', 'products', 'fallback', 'budget', 'error' );
		$outcomes = array( 'answered', 'fallback', 'error' );
		if ( ! is_array( $body ) || ! isset( $body['response'], $body['kind'], $body['outcome'] )
			|| ! is_string( $body['response'] ) || strlen( $body['response'] ) > 10000
			|| ! in_array( $body['kind'], $kinds, true ) || ! in_array( $body['outcome'], $outcomes, true ) ) {
			return new WP_Error( 'invalid_support_turn', 'Invalid support conversation result.', array( 'status' => 400 ) );
		}
		$cards = Epic_Support_Chat_Store::normalize_cards( $body['cards'] ?? null );
		$tables = Epic_Support_Chat_Store::tables();
		$now = gmdate( 'Y-m-d H:i:s' );
		$duration = isset( $body['durationMs'] ) && is_numeric( $body['durationMs'] ) ? min( 120000, max( 0, (int) $body['durationMs'] ) ) : null;
		$updated = $wpdb->update( $tables['turns'], array( 'response_text' => Epic_Support_Chat_Store::redact( $body['response'], 4000 ), 'response_kind' => sanitize_key( $body['kind'] ), 'outcome' => sanitize_key( $body['outcome'] ), 'cards' => $cards ? wp_json_encode( $cards ) : null, 'completed_at' => $now, 'duration_ms' => $duration ), array( 'id' => $id, 'outcome' => 'pending' ), array( '%s', '%s', '%s', '%s', '%s', '%d' ), array( '%d', '%s' ) );
		if ( false === $updated ) { return new WP_Error( 'support_store_unavailable', 'Support conversation result could not be recorded.', array( 'status' => 503 ) ); }
		if ( 1 !== $updated ) { return new WP_Error( 'support_turn_already_finished', 'Support conversation result was already recorded.', array( 'status' => 409 ) ); }
		$conversation_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT conversation_id FROM {$tables['turns']} WHERE id = %d", $id ) );
		if ( ! $conversation_id ) { return new WP_Error( 'support_turn_not_found', 'Support conversation was not found.', array( 'status' => 404 ) ); }
		$wpdb->query( $wpdb->prepare( "UPDATE {$tables['conversations']} SET activity_version = activity_version + 1, last_activity_at = %s WHERE id = %d", $now, $conversation_id ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}
}
