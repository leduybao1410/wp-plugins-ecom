<?php
/**
 * Persists a lightweight audit row for every public-API guard decision, so
 * the shop can see how much agent traffic is hitting the open API, from
 * which routes, and how much is being rate-limited. Deliberately does NOT
 * store lead content (name/phone/address) — the actual leads already live in
 * their own module tables; this is only traffic metadata.
 *
 * IP addresses are stored as a salted hash, never in the clear.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Agent_Store {

	const DB_VERSION        = '1.2';
	const DB_VERSION_OPTION = 'epic_agent_db_version';
	const RETENTION_DAYS    = 30;

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'epic_agent_events';
	}

	public static function counters_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'epic_agent_counters';
	}

	public static function idempotency_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'epic_agent_idempotency';
	}

	public static function install() {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			route VARCHAR(191) NOT NULL DEFAULT '',
			bucket VARCHAR(16) NOT NULL DEFAULT 'write',
			ip_hash CHAR(64) NOT NULL DEFAULT '',
			user_agent VARCHAR(300) NOT NULL DEFAULT '',
			allowed TINYINT(1) NOT NULL DEFAULT 1,
			silent TINYINT(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY route (route)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		$counters = self::counters_table_name();
		$counter_sql = "CREATE TABLE {$counters} (
			counter_key CHAR(64) NOT NULL,
			counter_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
			expires_at DATETIME NOT NULL,
			PRIMARY KEY  (counter_key),
			KEY expires_at (expires_at)
		) {$charset_collate};";
		dbDelta( $counter_sql );

		$idempotency = self::idempotency_table_name();
		$idempotency_sql = "CREATE TABLE {$idempotency} (
			fingerprint CHAR(64) NOT NULL,
			operation VARCHAR(24) NOT NULL,
			reference VARCHAR(80) NOT NULL,
			completed TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			expires_at DATETIME NOT NULL,
			PRIMARY KEY  (fingerprint),
			KEY expires_at (expires_at)
		) {$charset_collate};";
		dbDelta( $idempotency_sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/** Atomically increments and returns a durable fixed-window counter. */
	public static function increment_counter( $key, $expires_at ) {
		global $wpdb;
		$table = self::counters_table_name();
		// The increment is performed by MySQL in one statement, so concurrent PHP/Vercel workers cannot lose updates.
		$updated = $wpdb->query( $wpdb->prepare(
			"INSERT INTO {$table} (counter_key, counter_value, expires_at) VALUES (%s, LAST_INSERT_ID(1), %s) ON DUPLICATE KEY UPDATE counter_value = LAST_INSERT_ID(counter_value + 1), expires_at = VALUES(expires_at)",
			$key,
			$expires_at
		) );
		if ( false === $updated ) return false;
		// LAST_INSERT_ID is connection-local, so a separate concurrent request
		// cannot overwrite the value returned for this atomic increment.
		$count = $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
		return null === $count ? false : (int) $count;
	}

	/** Atomic 24-hour reservation shared by every transport, then mark success. */
	public static function idempotency( $fingerprint, $operation, $reference, $complete, $release = false ) {
		global $wpdb;
		$table = self::idempotency_table_name();
		if ( $release ) {
			$deleted = $wpdb->delete( $table, array( 'fingerprint' => $fingerprint, 'operation' => $operation, 'completed' => 0 ), array( '%s', '%s', '%d' ) );
			if ( false === $deleted ) return false;
			return array( 'created' => false, 'completed' => false, 'reference' => $reference );
		}
		if ( $complete ) {
			$updated = $wpdb->update(
				$table,
				array( 'completed' => 1 ),
				array( 'fingerprint' => $fingerprint, 'operation' => $operation ),
				array( '%d' ),
				array( '%s', '%s' )
			);
			if ( false === $updated ) return false;
			return array( 'created' => false, 'completed' => true, 'reference' => $reference );
		}
		$expires = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		$now = current_time( 'mysql', true );
		$inserted = $wpdb->query( $wpdb->prepare(
			"INSERT INTO {$table} (fingerprint, operation, reference, completed, created_at, expires_at) VALUES (%s, %s, %s, 0, %s, %s) ON DUPLICATE KEY UPDATE operation = IF(expires_at <= %s, VALUES(operation), operation), reference = IF(expires_at <= %s, VALUES(reference), reference), completed = IF(expires_at <= %s, 0, completed), created_at = IF(expires_at <= %s, VALUES(created_at), created_at), expires_at = IF(expires_at <= %s, VALUES(expires_at), expires_at)",
			$fingerprint, $operation, $reference, $now, $expires, $now, $now, $now, $now, $now
		) );
		if ( false === $inserted ) return false;
		if ( (int) $inserted > 0 ) return array( 'created' => true, 'completed' => false, 'reference' => $reference );
		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT operation, reference, completed FROM {$table} WHERE fingerprint = %s AND expires_at > %s",
			$fingerprint, $now
		), ARRAY_A );
		if ( ! $existing || $operation !== $existing['operation'] ) return false;
		return array( 'created' => false, 'completed' => (bool) $existing['completed'], 'reference' => (string) $existing['reference'] );
	}

	/**
	 * @param array $data route, bucket, ip_hash, user_agent, allowed, silent.
	 * @return int Inserted row id, or 0 on failure.
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$inserted = $wpdb->insert(
			self::table_name(),
			array(
				'created_at' => current_time( 'mysql' ),
				'route'      => substr( (string) ( $data['route'] ?? '' ), 0, 191 ),
				'bucket'     => substr( (string) ( $data['bucket'] ?? 'write' ), 0, 16 ),
				'ip_hash'    => substr( (string) ( $data['ip_hash'] ?? '' ), 0, 64 ),
				'user_agent' => substr( (string) ( $data['user_agent'] ?? '' ), 0, 300 ),
				'allowed'    => ! empty( $data['allowed'] ) ? 1 : 0,
				'silent'     => ! empty( $data['silent'] ) ? 1 : 0,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%d' )
		);

		return false === $inserted ? 0 : (int) $wpdb->insert_id;
	}

	public static function purge_old() {
		global $wpdb;
		$table  = self::table_name();
		$cutoff = current_datetime()
			->modify( '-' . ( self::RETENTION_DAYS * DAY_IN_SECONDS ) . ' seconds' )
			->format( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is built from $wpdb->prefix, not user input.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
		$counters = self::counters_table_name();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$counters} WHERE expires_at < %s", current_time( 'mysql', true ) ) );
		$idempotency = self::idempotency_table_name();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$idempotency} WHERE expires_at < %s", current_time( 'mysql', true ) ) );
	}

	/** Most recent events, newest first. */
	public static function recent( $limit = 50 ) {
		global $wpdb;
		$table = self::table_name();
		$limit = max( 1, min( (int) $limit, 200 ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is not user input; $limit is cast + clamped.
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
	}

	public static function count_since( $seconds ) {
		global $wpdb;
		$table  = self::table_name();
		$cutoff = current_datetime()
			->modify( '-' . (int) $seconds . ' seconds' )
			->format( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is not user input.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", $cutoff ) );
	}
}
