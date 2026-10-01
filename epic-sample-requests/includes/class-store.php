<?php
/**
 * Persists every free-sample request to its own table — separate from (and
 * not a replacement for) the notification email in
 * class-email-sample-request.php. Added so a submission that fails to send,
 * bounces, or lands in spam still leaves a permanent, reviewable record in
 * wp-admin (WooCommerce → Sample Requests) instead of vanishing except for
 * a log line.
 *
 * Deliberately a plain custom table via $wpdb, not a custom post type —
 * this is structured lead data (name/phone/address/taste), not editorial
 * content. Same reasoning epic-wholesale-inquiries used for its own table.
 *
 * Note for whoever maintains this: this table stores lead PII (name, phone
 * number, delivery address) with no automatic expiry. There's no bulk
 * export/anonymize tooling here — review WooCommerce → Sample Requests
 * periodically and delete rows for leads you no longer need to retain.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Sample_Store {

	const DB_VERSION        = '1.2';
	const DB_VERSION_OPTION = 'epic_sample_db_version';

	/** Valid values for the `email_status` / `confirm_status` columns — kept here so callers don't hand-roll strings. */
	const STATUS_PENDING  = 'pending';
	const STATUS_SENT     = 'sent';
	const STATUS_FAILED   = 'failed';
	const STATUS_DISABLED = 'disabled';
	/** confirm_status only: the request had no email address, so no thank-you could be sent (email is optional). */
	const STATUS_SKIPPED  = 'skipped';

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'epic_sample_requests';
	}

	/**
	 * Creates (or upgrades, via dbDelta's own diffing) the table. Called on
	 * plugin activation and, defensively, once on `plugins_loaded` whenever
	 * the stored DB_VERSION option doesn't match the constant above.
	 */
	public static function install() {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL,
			email VARCHAR(191) NOT NULL DEFAULT '',
			phone VARCHAR(64) NOT NULL DEFAULT '',
			province VARCHAR(191) NOT NULL DEFAULT '',
			ward VARCHAR(191) NOT NULL DEFAULT '',
			street VARCHAR(255) NOT NULL DEFAULT '',
			address VARCHAR(512) NOT NULL DEFAULT '',
			taste VARCHAR(32) NOT NULL DEFAULT '',
			taste_label_vi VARCHAR(191) NOT NULL DEFAULT '',
			brew VARCHAR(32) NOT NULL DEFAULT '',
			brew_label_vi VARCHAR(191) NOT NULL DEFAULT '',
			locale VARCHAR(32) NOT NULL DEFAULT 'unknown',
			email_status VARCHAR(16) NOT NULL DEFAULT 'pending',
			confirm_status VARCHAR(16) NOT NULL DEFAULT 'pending',
			submitted_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY submitted_at (submitted_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * @param array $data Same sanitized shape the REST controller assembles
	 *                     (see class-rest-api.php::submit_request) — keys:
	 *                     name, email, phone, province, ward, street, address,
	 *                     taste, taste_label_vi, locale, submitted_at.
	 * @return int Inserted row id, or 0 on failure (caller should treat 0 as
	 *             "couldn't persist this one" and not block the email on it).
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$inserted = $wpdb->insert(
			self::table_name(),
			array(
				'name'           => (string) ( $data['name'] ?? '' ),
				'email'          => (string) ( $data['email'] ?? '' ),
				'phone'          => (string) ( $data['phone'] ?? '' ),
				'province'       => (string) ( $data['province'] ?? '' ),
				'ward'           => (string) ( $data['ward'] ?? '' ),
				'street'         => (string) ( $data['street'] ?? '' ),
				'address'        => (string) ( $data['address'] ?? '' ),
				'taste'          => (string) ( $data['taste'] ?? '' ),
				'taste_label_vi' => (string) ( $data['taste_label_vi'] ?? '' ),
				'brew'           => (string) ( $data['brew'] ?? '' ),
				'brew_label_vi'  => (string) ( $data['brew_label_vi'] ?? '' ),
				'locale'         => (string) ( $data['locale'] ?? 'unknown' ),
				'email_status'   => self::STATUS_PENDING,
				'confirm_status' => self::STATUS_PENDING,
				'submitted_at'   => (string) ( $data['submitted_at'] ?? current_time( 'mysql' ) ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					'Failed to write sample request to the epic_sample_requests table — the notification email (if enabled) still fires, but this submission will not appear in the wp-admin log.',
					array( 'source' => 'epic-sample-requests' )
				);
			}
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/** Updates a row's email_status after the admin notification email attempt (or non-attempt). No-op if $id is falsy. */
	public static function mark_email_status( $id, $status ) {
		if ( empty( $id ) ) {
			return;
		}
		global $wpdb;
		$wpdb->update(
			self::table_name(),
			array( 'email_status' => $status ),
			array( 'id' => (int) $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/** Updates a row's confirm_status after the customer thank-you email attempt (or non-attempt). No-op if $id is falsy. */
	public static function mark_confirm_status( $id, $status ) {
		if ( empty( $id ) ) {
			return;
		}
		global $wpdb;
		$wpdb->update(
			self::table_name(),
			array( 'confirm_status' => $status ),
			array( 'id' => (int) $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * @param int    $per_page
	 * @param int    $offset
	 * @param string $orderby One of 'submitted_at' or 'name' — anything else falls back to 'submitted_at'. Whitelisted here so it's safe to interpolate into SQL (can't be parameterized with $wpdb->prepare()).
	 * @param string $order   'asc' or 'desc' (case-insensitive) — anything else falls back to 'desc'.
	 */
	public static function get_page( $per_page, $offset, $orderby = 'submitted_at', $order = 'desc' ) {
		global $wpdb;

		$orderby = in_array( $orderby, array( 'submitted_at', 'name' ), true ) ? $orderby : 'submitted_at';
		$order   = 'asc' === strtolower( (string) $order ) ? 'ASC' : 'DESC';
		$table   = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $orderby/$order are whitelisted above (not raw user input), $per_page/$offset go through prepare().
		$sql = $wpdb->prepare(
			"SELECT * FROM {$table} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			(int) $per_page,
			(int) $offset
		);

		return $wpdb->get_results( $sql, ARRAY_A );
	}

	public static function count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table_name() );
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE id = %d', (int) $id ),
			ARRAY_A
		);
	}

	public static function delete( $id ) {
		global $wpdb;
		return $wpdb->delete( self::table_name(), array( 'id' => (int) $id ), array( '%d' ) );
	}
}
