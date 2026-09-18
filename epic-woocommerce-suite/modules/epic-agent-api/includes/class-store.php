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

	const DB_VERSION        = '1.0';
	const DB_VERSION_OPTION = 'epic_agent_db_version';
	const RETENTION_DAYS    = 30;

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'epic_agent_events';
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

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
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
