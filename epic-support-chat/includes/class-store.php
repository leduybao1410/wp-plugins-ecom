<?php
/** Persistent store for anonymous, redacted support conversations. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Epic_Support_Chat_Store {
	const DB_VERSION = '1.0';
	const DB_VERSION_OPTION = 'epic_support_chat_db_version';
	const RETENTION_DAYS = 30;
	const PURGE_BATCH = 200;

	public static function tables() {
		global $wpdb;
		return array(
			'conversations' => $wpdb->prefix . 'epic_support_conversations',
			'turns'         => $wpdb->prefix . 'epic_support_turns',
			'notes'         => $wpdb->prefix . 'epic_support_notes',
			'reads'         => $wpdb->prefix . 'epic_support_reads',
		);
	}

	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) { self::install(); }
		if ( ! wp_next_scheduled( 'epic_support_chat_purge' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'epic_support_chat_purge' );
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$tables = self::tables();
		$collate = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$tables['conversations']} (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			session_hash char(64) NOT NULL,
			locale varchar(8) NOT NULL,
			status varchar(16) NOT NULL DEFAULT 'open',
			activity_version bigint unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			last_activity_at datetime NOT NULL,
			expires_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY session_locale (session_hash, locale),
			KEY status_activity (status, last_activity_at),
			KEY expires_at (expires_at)
		) $collate;" );
		dbDelta( "CREATE TABLE {$tables['turns']} (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			conversation_id bigint unsigned NOT NULL,
			request_hash char(64) NOT NULL,
			user_text text NOT NULL,
			response_text text NULL,
			response_kind varchar(24) NOT NULL DEFAULT 'pending',
			outcome varchar(24) NOT NULL DEFAULT 'pending',
			cards longtext NULL,
			started_at datetime NOT NULL,
			completed_at datetime NULL,
			duration_ms int unsigned NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY request_hash (request_hash),
			KEY conversation_turn (conversation_id, id),
			KEY pending_started (outcome, started_at)
		) $collate;" );
		dbDelta( "CREATE TABLE {$tables['notes']} (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			conversation_id bigint unsigned NOT NULL,
			user_id bigint unsigned NOT NULL,
			idempotency_key char(64) NOT NULL,
			note_text text NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY conversation_note (conversation_id, id)
		) $collate;" );
		dbDelta( "CREATE TABLE {$tables['reads']} (
			conversation_id bigint unsigned NOT NULL,
			user_id bigint unsigned NOT NULL,
			read_version bigint unsigned NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (conversation_id, user_id),
			KEY user_updated (user_id, updated_at)
		) $collate;" );
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
		if ( ! wp_next_scheduled( 'epic_support_chat_purge' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'epic_support_chat_purge' );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'epic_support_chat_purge' );
	}

	public static function redact( $value, $limit = 4000 ) {
		$text = sanitize_textarea_field( (string) $value );
		$text = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, max( 0, (int) $limit ), 'UTF-8' ) : substr( $text, 0, max( 0, (int) $limit ) );
		$text = preg_replace( '/(?<![\p{L}\p{N}_])(?:password|passcode|verification\s+code|one[- ]time\s+code|otp|api\s+key|secret|код\s+подтверждения|验证码|確認コード|인증\s*코드)\s*[:=：]?\s*\S+/iu', '[redacted]', $text );
		$text = preg_replace( '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/iu', '[redacted]', $text );
		$text = preg_replace( '/\b#?EPIC-[A-HJ-NP-Z2-9]{6}\b/iu', '[redacted]', $text );
		$text = preg_replace( '~https?://[^\s<>]+~iu', '[redacted]', $text );
		$text = preg_replace( '/(?<!\w)\+?\d(?:[\s().-]*\d){8,}(?!\w)/u', '[redacted]', $text );
		$text = preg_replace( '/(?<!\w)(?:\d[ -]*?){13,19}(?!\w)/u', '[redacted]', $text );
		return function_exists( 'mb_substr' ) ? mb_substr( (string) $text, 0, max( 0, (int) $limit ), 'UTF-8' ) : substr( (string) $text, 0, max( 0, (int) $limit ) );
	}

	public static function normalize_cards( $cards ) {
		if ( ! is_array( $cards ) ) { return null; }
		$out = array();
		if ( isset( $cards['products'] ) && is_array( $cards['products'] ) ) {
			$out['products'] = array();
			foreach ( array_slice( $cards['products'], 0, 3 ) as $product ) {
				if ( ! is_array( $product ) || ! isset( $product['name'] ) ) { continue; }
				$p = array( 'name' => self::redact( $product['name'], 160 ), 'inStock' => ! empty( $product['inStock'] ), 'packs' => array() );
				foreach ( array( 'origin' => 180, 'notes' => 240 ) as $key => $limit ) {
					if ( isset( $product[ $key ] ) && is_string( $product[ $key ] ) ) { $p[ $key ] = self::redact( $product[ $key ], $limit ); }
				}
				if ( isset( $product['packs'] ) && is_array( $product['packs'] ) ) {
					foreach ( array_slice( $product['packs'], 0, 8 ) as $pack ) {
						if ( ! is_array( $pack ) || ! isset( $pack['weightLabel'], $pack['priceVnd'] ) || ! is_numeric( $pack['priceVnd'] ) ) { continue; }
						$p['packs'][] = array( 'weightLabel' => self::redact( $pack['weightLabel'], 24 ), 'priceVnd' => max( 0, (int) $pack['priceVnd'] ), 'inStock' => ! empty( $pack['inStock'] ) );
					}
				}
				$out['products'][] = $p;
			}
		}
		if ( isset( $cards['orderStatus'] ) && is_array( $cards['orderStatus'] ) ) {
			$status = isset( $cards['orderStatus']['status'] ) ? sanitize_key( $cards['orderStatus']['status'] ) : 'unknown';
			$out['orderStatus'] = array( 'status' => substr( $status, 0, 24 ), 'label' => self::redact( $cards['orderStatus']['label'] ?? 'Order status', 80 ), 'description' => self::redact( $cards['orderStatus']['description'] ?? 'Order status was checked.', 300 ) );
		}
		return empty( $out ) ? null : $out;
	}

	public static function purge_expired() {
		global $wpdb;
		$tables = self::tables();
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$tables['conversations']} WHERE expires_at <= %s ORDER BY expires_at ASC LIMIT %d", gmdate( 'Y-m-d H:i:s' ), self::PURGE_BATCH ) );
		if ( empty( $ids ) ) { update_option( 'epic_support_chat_last_purge', gmdate( 'Y-m-d H:i:s' ), false ); return 0; }
		$ids = array_map( 'absint', $ids );
		$in = implode( ',', $ids );
		$wpdb->query( "DELETE FROM {$tables['turns']} WHERE conversation_id IN ($in)" );
		$wpdb->query( "DELETE FROM {$tables['notes']} WHERE conversation_id IN ($in)" );
		$wpdb->query( "DELETE FROM {$tables['reads']} WHERE conversation_id IN ($in)" );
		$deleted = $wpdb->query( "DELETE FROM {$tables['conversations']} WHERE id IN ($in)" );
		update_option( 'epic_support_chat_last_purge', gmdate( 'Y-m-d H:i:s' ), false );
		return max( 0, (int) $deleted );
	}
}
