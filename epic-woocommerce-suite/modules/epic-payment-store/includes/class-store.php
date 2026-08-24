<?php
/**
 * DB access layer for the two handoff tables. Custom tables (not post meta,
 * not a CPT) because these rows are short-lived, high-churn, and never
 * meant to be browsed/edited as content — same reasoning epic-ghn-shipping
 * used for its bundle table.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Payment_Store {

	public static function pending_table() {
		global $wpdb;
		return $wpdb->prefix . 'epic_payment_pending';
	}

	public static function completed_table() {
		global $wpdb;
		return $wpdb->prefix . 'epic_payment_completed';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$pending_table    = self::pending_table();
		$completed_table  = self::completed_table();

		// dbDelta is picky about formatting: two spaces before KEY/PRIMARY KEY,
		// each column on its own line.
		$sql = "CREATE TABLE {$pending_table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id VARCHAR(32) NOT NULL,
  amount BIGINT NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'pending',
  payload LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  claimed_at DATETIME NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY order_id (order_id)
) {$charset_collate};
CREATE TABLE {$completed_table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id VARCHAR(32) NOT NULL,
  result LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY order_id (order_id)
) {$charset_collate};";

		dbDelta( $sql );
		update_option( 'epic_payment_store_db_version', EPIC_PAYMENT_STORE_VERSION );
	}

	/** Insert or replace the pending record for this order id. */
	public static function put_pending( $order_id, $amount, $ttl_seconds, array $payload ) {
		global $wpdb;
		$now     = current_time( 'mysql', true );
		$expires = gmdate( 'Y-m-d H:i:s', time() + max( (int) $ttl_seconds, 60 ) );

		// REPLACE INTO relies on the order_id UNIQUE KEY — a retried checkout
		// POST with the same (freshly generated) order_id just overwrites
		// cleanly rather than erroring.
		$wpdb->query(
			$wpdb->prepare(
				"REPLACE INTO " . self::pending_table() . "
				 (order_id, amount, status, payload, created_at, expires_at, claimed_at)
				 VALUES (%s, %d, 'pending', %s, %s, %s, NULL)",
				$order_id,
				(int) $amount,
				wp_json_encode( $payload ),
				$now,
				$expires
			)
		);
	}

	/**
	 * Read-only lookup — does not consume the record. For debugging/admin only.
	 *
	 * Returns the record whether it is still 'pending' OR already 'claimed',
	 * as long as it is within its TTL. The website's `/api/sepay/status` poll
	 * relies on this: after a webhook atomically claims a payment it spends a
	 * moment in 'claimed' while it calls the WooCommerce API to create the
	 * order *before* writing the 'completed' record. Hiding 'claimed' rows here
	 * would make that poll read "no pending record" for a split second and
	 * flash a false "QR expired without payment" on the checkout page even
	 * though the transfer later confirms. Claimed != expired.
	 */
	public static function peek_pending( $order_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT payload FROM " . self::pending_table() . "
				 WHERE order_id = %s AND expires_at > UTC_TIMESTAMP()",
				$order_id
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		return json_decode( $row['payload'], true );
	}

	/**
	 * Atomically claims the pending record: only succeeds while status is
	 * still 'pending' and it hasn't expired. This single UPDATE is the whole
	 * idempotency guard — a second call (webhook retry) affects 0 rows and
	 * returns null, so the caller can never double-create an order from the
	 * same payment notification.
	 */
	public static function claim_pending( $order_id ) {
		global $wpdb;
		$table = self::pending_table();

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET status = 'claimed', claimed_at = UTC_TIMESTAMP()
				 WHERE order_id = %s AND status = 'pending' AND expires_at > UTC_TIMESTAMP()",
				$order_id
			)
		);

		if ( ! $updated ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT payload FROM {$table} WHERE order_id = %s", $order_id ),
			ARRAY_A
		);
		return $row ? json_decode( $row['payload'], true ) : null;
	}

	public static function put_completed( $order_id, array $result ) {
		global $wpdb;
		$now     = current_time( 'mysql', true );
		$expires = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );

		$wpdb->query(
			$wpdb->prepare(
				"REPLACE INTO " . self::completed_table() . "
				 (order_id, result, created_at, expires_at)
				 VALUES (%s, %s, %s, %s)",
				$order_id,
				wp_json_encode( $result ),
				$now,
				$expires
			)
		);
	}

	public static function get_completed( $order_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT result FROM " . self::completed_table() . "
				 WHERE order_id = %s AND expires_at > UTC_TIMESTAMP()",
				$order_id
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		return json_decode( $row['result'], true );
	}

	/** Table hygiene — deletes rows past their expiry. Not load-bearing (reads already filter on expires_at). */
	public static function purge_expired() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . self::pending_table() . ' WHERE expires_at < UTC_TIMESTAMP()' );
		$wpdb->query( 'DELETE FROM ' . self::completed_table() . ' WHERE expires_at < UTC_TIMESTAMP()' );
	}
}
