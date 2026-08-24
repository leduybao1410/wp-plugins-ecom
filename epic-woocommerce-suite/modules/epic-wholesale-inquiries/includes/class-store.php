<?php
/**
 * Persists every wholesale inquiry to its own table — separate from (and
 * not a replacement for) the notification email in
 * class-email-wholesale-inquiry.php. Added so a submission that fails to
 * send, bounces, or lands in spam still leaves a permanent, reviewable
 * record in wp-admin (WooCommerce → Wholesale Inquiries) instead of
 * vanishing except for a log line.
 *
 * Deliberately a plain custom table via $wpdb, not a custom post type —
 * this is structured lead data (business_name/phone/contact/topic/etc.),
 * not editorial content, and there's no need for revisions, taxonomies, or
 * any other CPT machinery. Same reasoning epic-payment-store used for its
 * own table (see that plugin's class-store.php), except this data is kept
 * indefinitely rather than expiring — a wholesale lead has ongoing value,
 * a pending-checkout handoff record doesn't.
 *
 * Note for whoever maintains this: this table stores lead PII (phone
 * number, email/Zalo contact) with no automatic expiry. There's no bulk
 * export/anonymize tooling here — review WooCommerce → Wholesale Inquiries
 * periodically and delete rows for leads you no longer need to retain.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Wholesale_Store {

	const DB_VERSION        = '1.0';
	const DB_VERSION_OPTION = 'epic_wholesale_db_version';

	/** Valid values for the `email_status` column — kept here so callers don't hand-roll strings. */
	const STATUS_PENDING  = 'pending';
	const STATUS_SENT     = 'sent';
	const STATUS_FAILED   = 'failed';
	const STATUS_DISABLED = 'disabled';

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'epic_wholesale_inquiries';
	}

	/**
	 * Creates (or upgrades, via dbDelta's own diffing) the table. Called on
	 * plugin activation and, defensively, once on `plugins_loaded` whenever
	 * the stored DB_VERSION option doesn't match the constant above — so a
	 * future schema change picked up by a plugin update still applies even
	 * if WordPress doesn't re-fire the activation hook (e.g. the plugin was
	 * already active when the files were replaced).
	 */
	public static function install() {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			business_name VARCHAR(255) NOT NULL,
			phone VARCHAR(64) NOT NULL DEFAULT '',
			contact VARCHAR(255) NOT NULL DEFAULT '',
			topic VARCHAR(32) NOT NULL DEFAULT '',
			topic_label_vi VARCHAR(191) NOT NULL DEFAULT '',
			details LONGTEXT NULL,
			locale VARCHAR(32) NOT NULL DEFAULT 'unknown',
			email_status VARCHAR(16) NOT NULL DEFAULT 'pending',
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
	 *                     (see class-rest-api.php::submit_inquiry) — keys:
	 *                     business_name, phone, contact, topic,
	 *                     topic_label_vi, details, locale, submitted_at.
	 * @return int Inserted row id, or 0 on failure (caller should treat 0 as
	 *             "couldn't persist this one" and not block the email on it).
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$inserted = $wpdb->insert(
			self::table_name(),
			array(
				'business_name'  => (string) ( $data['business_name'] ?? '' ),
				'phone'          => (string) ( $data['phone'] ?? '' ),
				'contact'        => (string) ( $data['contact'] ?? '' ),
				'topic'          => (string) ( $data['topic'] ?? '' ),
				'topic_label_vi' => (string) ( $data['topic_label_vi'] ?? '' ),
				'details'        => (string) ( $data['details'] ?? '' ),
				'locale'         => (string) ( $data['locale'] ?? 'unknown' ),
				'email_status'   => self::STATUS_PENDING,
				'submitted_at'   => (string) ( $data['submitted_at'] ?? current_time( 'mysql' ) ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					'Failed to write wholesale inquiry to the epic_wholesale_inquiries table — the notification email (if enabled) still fires, but this submission will not appear in the wp-admin log.',
					array( 'source' => 'epic-wholesale-inquiries' )
				);
			}
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/** Updates a row's email_status after the notification email attempt (or non-attempt). No-op if $id is falsy. */
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

	/**
	 * @param int    $per_page
	 * @param int    $offset
	 * @param string $orderby One of 'submitted_at' or 'business_name' — anything else falls back to 'submitted_at'. Whitelisted here so it's safe to interpolate into SQL (can't be parameterized with $wpdb->prepare()).
	 * @param string $order   'asc' or 'desc' (case-insensitive) — anything else falls back to 'desc'.
	 */
	public static function get_page( $per_page, $offset, $orderby = 'submitted_at', $order = 'desc' ) {
		global $wpdb;

		$orderby = in_array( $orderby, array( 'submitted_at', 'business_name' ), true ) ? $orderby : 'submitted_at';
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
