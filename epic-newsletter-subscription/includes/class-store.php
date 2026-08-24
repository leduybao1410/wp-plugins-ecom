<?php
/**
 * Persists every newsletter subscription to its own table — separate from
 * (and not a replacement for) the notification email in
 * class-email-newsletter-subscription.php. Added so a subscription whose
 * notification email fails to send, bounces, or lands in spam still leaves
 * a permanent, reviewable record in wp-admin (WooCommerce → Newsletter
 * Subscribers) instead of vanishing except for a log line.
 *
 * Deliberately a plain custom table via $wpdb, not a custom post type —
 * this is structured subscriber data (an email address plus a timestamp),
 * not editorial content, and there's no need for revisions, taxonomies, or
 * any other CPT machinery. Same reasoning epic-wholesale-inquiries and
 * epic-payment-store used for their own tables (see those plugins'
 * class-store.php files).
 *
 * The email column is UNIQUE so a repeated submission is idempotent: the
 * REST controller looks up the address first and, if it already exists,
 * returns success without re-firing the notification email.
 *
 * Note for whoever maintains this: this table stores subscriber PII (email
 * address) with no automatic expiry. There's no bulk export/anonymize
 * tooling here — review WooCommerce → Newsletter Subscribers periodically
 * and delete rows for subscribers you no longer need to retain.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Newsletter_Store {

	const DB_VERSION        = '1.1';
	const DB_VERSION_OPTION = 'epic_newsletter_db_version';

	/** Valid values for the `email_status` / `confirm_status` columns — kept here so callers don't hand-roll strings. */
	const STATUS_PENDING  = 'pending';
	const STATUS_SENT     = 'sent';
	const STATUS_FAILED   = 'failed';
	const STATUS_DISABLED = 'disabled';
	const STATUS_EXISTS   = 'exists';

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'epic_newsletter_subscribers';
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
			email VARCHAR(191) NOT NULL,
			locale VARCHAR(32) NOT NULL DEFAULT 'unknown',
			email_status VARCHAR(16) NOT NULL DEFAULT 'pending',
			confirm_status VARCHAR(16) NOT NULL DEFAULT 'pending',
			subscribed_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email),
			KEY subscribed_at (subscribed_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Looks a subscriber up by (lowercased) email. Returns the row as an
	 * associative array, or null when the address isn't subscribed yet.
	 */
	public static function find_by_email( $email ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE email = %s', strtolower( (string) $email ) ),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * @param array $data Same sanitized shape the REST controller assembles
	 *                     (see class-rest-api.php::subscribe) — keys:
	 *                     email, locale, subscribed_at.
	 * @return int Inserted row id, or 0 on failure (caller should treat 0 as
	 *             "couldn't persist this one" and not block the email on it).
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$inserted = $wpdb->insert(
			self::table_name(),
			array(
				'email'          => strtolower( (string) ( $data['email'] ?? '' ) ),
				'locale'         => (string) ( $data['locale'] ?? 'unknown' ),
				'email_status'   => self::STATUS_PENDING,
				'confirm_status' => self::STATUS_PENDING,
				'subscribed_at'  => (string) ( $data['subscribed_at'] ?? current_time( 'mysql' ) ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					'Failed to write newsletter subscription to the epic_newsletter_subscribers table — the notification email (if enabled) still fires, but this submission will not appear in the wp-admin list.',
					array( 'source' => 'epic-newsletter-subscription' )
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

	/** Updates a row's confirm_status after the customer confirmation email attempt (or non-attempt). No-op if $id is falsy. */
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
	 * @param string $orderby One of 'subscribed_at' or 'email' — anything else falls back to 'subscribed_at'. Whitelisted here so it's safe to interpolate into SQL (can't be parameterized with $wpdb->prepare()).
	 * @param string $order   'asc' or 'desc' (case-insensitive) — anything else falls back to 'desc'.
	 */
	public static function get_page( $per_page, $offset, $orderby = 'subscribed_at', $order = 'desc' ) {
		global $wpdb;

		$orderby = in_array( $orderby, array( 'subscribed_at', 'email' ), true ) ? $orderby : 'subscribed_at';
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

	/**
	 * Every subscriber row, unpaginated, oldest-sort-independent (always
	 * newest first) — backs the CSV/XLSX export in class-export.php, which
	 * downloads the full list rather than whatever page/sort the on-screen
	 * list table currently happens to be showing.
	 */
	public static function get_all_for_export() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . self::table_name() . ' ORDER BY subscribed_at DESC', ARRAY_A );
	}

	/**
	 * Feeds the recipient snapshot for a bulk broadcast campaign
	 * (class-campaign-store.php::snapshot_recipients()) — every subscriber
	 * matching a locale bucket, id+email+locale only (no need for the
	 * status columns here).
	 *
	 * 'vi' deliberately matches locale != 'en' rather than locale = 'vi' —
	 * an 'unknown' locale is treated as Vietnamese everywhere else in this
	 * plugin (see class-email-newsletter-confirmation.php's docblock), so
	 * "send the Vietnamese version" should reach those subscribers too,
	 * not silently skip them.
	 *
	 * @param string $locale_filter 'all' | 'vi' | 'en' — anything else falls back to 'all'.
	 */
	public static function get_subscribers_for_broadcast( $locale_filter ) {
		global $wpdb;
		$table = self::table_name();

		if ( 'en' === $locale_filter ) {
			return $wpdb->get_results( "SELECT id, email, locale FROM {$table} WHERE locale = 'en'", ARRAY_A );
		}
		if ( 'vi' === $locale_filter ) {
			return $wpdb->get_results( "SELECT id, email, locale FROM {$table} WHERE locale != 'en'", ARRAY_A );
		}
		return $wpdb->get_results( "SELECT id, email, locale FROM {$table}", ARRAY_A );
	}

	public static function count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table_name() );
	}

	public static function delete( $id ) {
		global $wpdb;
		return $wpdb->delete( self::table_name(), array( 'id' => (int) $id ), array( '%d' ) );
	}
}
