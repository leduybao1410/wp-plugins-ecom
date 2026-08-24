<?php
/**
 * Storage for bulk newsletter campaigns — "one template, many recipients",
 * composed from WooCommerce → Send Newsletter (class-broadcast-admin.php)
 * and delivered in the background by class-broadcast-sender.php.
 *
 * Deliberately two tables, separate from `epic_newsletter_subscribers`
 * (class-store.php):
 *  - `epic_newsletter_campaigns` — one row per composed campaign (subject/
 *    body in both languages, which recipient bucket it targeted, and
 *    running sent/failed counters).
 *  - `epic_newsletter_campaign_recipients` — one row per (campaign,
 *    subscriber) pair, snapshotting the subscriber's email/locale *at the
 *    time the campaign was created* and tracking that one send's own
 *    pending/sent/failed status.
 *
 * The recipient snapshot is what makes sending resumable and safely
 * batchable: class-broadcast-sender.php pulls a page of 'pending' rows at a
 * time (via Action Scheduler, not a single long-running request), sends
 * each, and flips its status — so a page timeout, a host restart, or a
 * fatal error mid-campaign leaves a precise record of exactly who has and
 * hasn't been sent to yet, instead of an all-or-nothing loop that would have
 * to be re-run from the start (double-emailing anyone already sent to).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Newsletter_Campaign_Store {

	const DB_VERSION        = '1.0';
	const DB_VERSION_OPTION = 'epic_newsletter_campaign_db_version';

	const STATUS_DRAFT   = 'draft';
	const STATUS_SENDING = 'sending';
	const STATUS_DONE    = 'done';

	const RECIPIENT_PENDING = 'pending';
	const RECIPIENT_SENT    = 'sent';
	const RECIPIENT_FAILED  = 'failed';

	public static function campaigns_table() {
		global $wpdb;
		return $wpdb->prefix . 'epic_newsletter_campaigns';
	}

	public static function recipients_table() {
		global $wpdb;
		return $wpdb->prefix . 'epic_newsletter_campaign_recipients';
	}

	public static function install() {
		global $wpdb;

		$campaigns       = self::campaigns_table();
		$recipients      = self::recipients_table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$campaigns} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			subject_vi VARCHAR(255) NOT NULL DEFAULT '',
			subject_en VARCHAR(255) NOT NULL DEFAULT '',
			body_vi LONGTEXT NOT NULL,
			body_en LONGTEXT NOT NULL,
			recipient_filter VARCHAR(20) NOT NULL DEFAULT 'all',
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			total_recipients INT UNSIGNED NOT NULL DEFAULT 0,
			sent_count INT UNSIGNED NOT NULL DEFAULT 0,
			failed_count INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			started_at DATETIME NULL,
			finished_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY status (status)
		) {$charset_collate};

		CREATE TABLE {$recipients} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			campaign_id BIGINT UNSIGNED NOT NULL,
			subscriber_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			email VARCHAR(191) NOT NULL,
			locale VARCHAR(32) NOT NULL DEFAULT 'unknown',
			status VARCHAR(16) NOT NULL DEFAULT 'pending',
			sent_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY campaign_id (campaign_id),
			KEY campaign_status (campaign_id, status)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	// ------------------------------------------------------------------
	// Campaigns
	// ------------------------------------------------------------------

	/**
	 * @param array $data { subject_vi, subject_en, body_vi, body_en, recipient_filter } — caller (class-broadcast-admin.php) is responsible for sanitizing/kses-ing before this is called.
	 * @return int Newly inserted campaign id.
	 */
	public static function create_draft( array $data ) {
		global $wpdb;

		$wpdb->insert(
			self::campaigns_table(),
			array(
				'subject_vi'       => (string) ( $data['subject_vi'] ?? '' ),
				'subject_en'       => (string) ( $data['subject_en'] ?? '' ),
				'body_vi'          => (string) ( $data['body_vi'] ?? '' ),
				'body_en'          => (string) ( $data['body_en'] ?? '' ),
				'recipient_filter' => (string) ( $data['recipient_filter'] ?? 'all' ),
				'status'           => self::STATUS_DRAFT,
				'created_at'       => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	public static function get( $campaign_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::campaigns_table() . ' WHERE id = %d', (int) $campaign_id ),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	public static function list_all( $limit = 50 ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::campaigns_table() . ' ORDER BY created_at DESC LIMIT %d', (int) $limit ),
			ARRAY_A
		);
	}

	/** Only ever called on a `status = draft` row from the UI — a campaign that has started sending keeps its recipient log around as a permanent record. */
	public static function delete_draft( $campaign_id ) {
		global $wpdb;
		$campaign_id = (int) $campaign_id;
		$wpdb->delete( self::recipients_table(), array( 'campaign_id' => $campaign_id ), array( '%d' ) );
		return $wpdb->delete(
			self::campaigns_table(),
			array( 'id' => $campaign_id, 'status' => self::STATUS_DRAFT ),
			array( '%d', '%s' )
		);
	}

	/**
	 * Flips a draft to 'sending', but ONLY if it is currently 'draft' — the
	 * WHERE clause's own status check makes this atomic against a
	 * double-click or two overlapping requests both trying to start the same
	 * campaign: at most one of them gets back a truthy (1-row) result, so
	 * class-broadcast-sender.php's caller knows whether it actually won the
	 * right to schedule the first batch.
	 *
	 * @return bool
	 */
	public static function mark_sending( $campaign_id ) {
		global $wpdb;
		$updated = $wpdb->update(
			self::campaigns_table(),
			array(
				'status'     => self::STATUS_SENDING,
				'started_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $campaign_id, 'status' => self::STATUS_DRAFT ),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);
		return (bool) $updated;
	}

	public static function mark_finished( $campaign_id ) {
		global $wpdb;
		$wpdb->update(
			self::campaigns_table(),
			array(
				'status'      => self::STATUS_DONE,
				'finished_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $campaign_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	// ------------------------------------------------------------------
	// Recipients
	// ------------------------------------------------------------------

	/**
	 * Snapshots every subscriber matching $locale_filter into this
	 * campaign's recipient rows, all as 'pending'. Inserted in chunks of 200
	 * per statement rather than one row at a time (fine even for a list of a
	 * few thousand) or one giant statement (avoids hitting max_allowed_packet
	 * on a large list).
	 *
	 * @param string $locale_filter 'all' | 'vi' | 'en' — see Epic_Newsletter_Store::get_subscribers_for_broadcast().
	 * @return int Number of recipient rows created.
	 */
	public static function snapshot_recipients( $campaign_id, $locale_filter ) {
		global $wpdb;

		$subscribers = Epic_Newsletter_Store::get_subscribers_for_broadcast( $locale_filter );
		if ( empty( $subscribers ) ) {
			return 0;
		}

		$table      = self::recipients_table();
		$campaign_id = (int) $campaign_id;
		$inserted   = 0;

		foreach ( array_chunk( $subscribers, 200 ) as $chunk ) {
			$placeholders = array();
			$values       = array();
			foreach ( $chunk as $subscriber ) {
				$placeholders[] = '(%d, %d, %s, %s, %s)';
				array_push(
					$values,
					$campaign_id,
					(int) $subscriber['id'],
					(string) $subscriber['email'],
					(string) $subscriber['locale'],
					self::RECIPIENT_PENDING
				);
			}

			$sql = "INSERT INTO {$table} (campaign_id, subscriber_id, email, locale, status) VALUES " . implode( ', ', $placeholders );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is built entirely from the %d/%s placeholders above; $wpdb->prepare() below fills them from $values.
			$wpdb->query( $wpdb->prepare( $sql, $values ) );
			$inserted += count( $chunk );
		}

		$wpdb->update(
			self::campaigns_table(),
			array( 'total_recipients' => $inserted ),
			array( 'id' => $campaign_id ),
			array( '%d' ),
			array( '%d' )
		);

		return $inserted;
	}

	public static function get_pending_batch( $campaign_id, $limit ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::recipients_table() . ' WHERE campaign_id = %d AND status = %s ORDER BY id ASC LIMIT %d',
				(int) $campaign_id,
				self::RECIPIENT_PENDING,
				(int) $limit
			),
			ARRAY_A
		);
	}

	public static function count_pending( $campaign_id ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::recipients_table() . ' WHERE campaign_id = %d AND status = %s',
				(int) $campaign_id,
				self::RECIPIENT_PENDING
			)
		);
	}

	/** Flips one recipient row's status and bumps the parent campaign's running sent_count/failed_count counter — kept as a single-row update plus a `col = col + 1` update rather than recomputing COUNT(*) every time, since this runs once per recipient per batch. */
	public static function mark_recipient_result( $recipient_row_id, $campaign_id, $sent ) {
		global $wpdb;

		$wpdb->update(
			self::recipients_table(),
			array(
				'status'  => $sent ? self::RECIPIENT_SENT : self::RECIPIENT_FAILED,
				'sent_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $recipient_row_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$counter_column = $sent ? 'sent_count' : 'failed_count'; // one of exactly these two hardcoded strings — never user input — safe to interpolate below.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see comment above; %d below is still parameterized.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::campaigns_table() . " SET {$counter_column} = {$counter_column} + 1 WHERE id = %d",
				(int) $campaign_id
			)
		);
	}

	/** Backs the per-campaign delivery-log CSV/XLSX export (class-export.php). */
	public static function get_recipients_for_export( $campaign_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::recipients_table() . ' WHERE campaign_id = %d ORDER BY id ASC',
				(int) $campaign_id
			),
			ARRAY_A
		);
	}
}
