<?php
/**
 * DB access layer for the two custom tables: accounts (one per Google sign-in,
 * keyed by the stable Google `sub`) and order links (which orders belong to
 * which account, and how they got linked). Custom tables, not user/order meta,
 * because an account's order history is a query pattern (list all links for an
 * account), not a per-record annotation — same reasoning epic-payment-store
 * used for its handoff tables.
 *
 * The link table is the single source of truth for "this order belongs to
 * this account" — even though new orders placed while signed in also get a
 * WooCommerce `customer_id`, historical guest orders (the whole point of
 * email auto-linking) have no customer, so a separate link table is what
 * unifies both cases.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Account_Store {

	public static function accounts_table() {
		global $wpdb;
		return $wpdb->prefix . 'epic_accounts';
	}

	public static function links_table() {
		global $wpdb;
		return $wpdb->prefix . 'epic_order_links';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$accounts        = self::accounts_table();
		$links           = self::links_table();

		// dbDelta is picky about formatting: two spaces before KEY/PRIMARY KEY,
		// each column on its own line.
		$sql = "CREATE TABLE {$accounts} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  google_sub VARCHAR(64) NOT NULL,
  email VARCHAR(190) NOT NULL DEFAULT '',
  display_name VARCHAR(190) NOT NULL DEFAULT '',
  picture_url VARCHAR(500) NOT NULL DEFAULT '',
  wc_customer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  last_login_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY google_sub (google_sub),
  KEY email (email)
) {$charset_collate};
CREATE TABLE {$links} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  link_source VARCHAR(10) NOT NULL DEFAULT 'email',
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY account_order (account_id, order_id),
  KEY order_id (order_id)
) {$charset_collate};";

		dbDelta( $sql );
		update_option( 'epic_account_linking_db_version', EPIC_ACCOUNT_LINKING_VERSION );
	}

	/** @return array|null Account row (ARRAY_A) or null. */
	public static function get_account_by_sub( $google_sub ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . self::accounts_table() . " WHERE google_sub = %s",
				(string) $google_sub
			),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * Create or refresh an account for a Google sign-in. Returns
	 * array( 'account_id' => int, 'is_new' => bool ). Idempotent — a repeat
	 * sign-in just updates profile fields and the login timestamp.
	 */
	public static function upsert_account( $google_sub, array $profile ) {
		global $wpdb;
		$table = self::accounts_table();
		$now   = current_time( 'mysql', true );

		$existing = self::get_account_by_sub( $google_sub );
		if ( $existing ) {
			$wpdb->update(
				$table,
				array(
					'email'          => (string) $profile['email'],
					'display_name'   => (string) $profile['display_name'],
					'picture_url'    => (string) $profile['picture_url'],
					'last_login_at'  => $now,
				),
				array( 'id' => (int) $existing['id'] )
			);
			return array( 'account_id' => (int) $existing['id'], 'is_new' => false );
		}

		$wpdb->insert(
			$table,
			array(
				'google_sub'    => (string) $google_sub,
				'email'         => (string) $profile['email'],
				'display_name'  => (string) $profile['display_name'],
				'picture_url'   => (string) $profile['picture_url'],
				'wc_customer_id' => 0,
				'created_at'    => $now,
				'last_login_at' => $now,
			)
		);
		return array( 'account_id' => (int) $wpdb->insert_id, 'is_new' => true );
	}

	public static function set_wc_customer_id( $account_id, $customer_id ) {
		global $wpdb;
		$wpdb->update(
			self::accounts_table(),
			array( 'wc_customer_id' => (int) $customer_id ),
			array( 'id' => (int) $account_id )
		);
	}

	/**
	 * Record an order↔account link, ignoring duplicates (the UNIQUE
	 * account_order key makes a second attempt a no-op).
	 *
	 * @return bool True if a new link was inserted, false if it already existed.
	 */
	public static function add_order_link( $account_id, $order_id, $link_source ) {
		global $wpdb;
		$source = in_array( $link_source, array( 'email', 'claim' ), true ) ? $link_source : 'email';
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO " . self::links_table() . "
				 (account_id, order_id, link_source, created_at)
				 VALUES (%d, %d, %s, %s)",
				(int) $account_id,
				(int) $order_id,
				$source,
				current_time( 'mysql', true )
			)
		);
		return (int) $wpdb->rows_affected > 0;
	}

	public static function has_order_link( $account_id, $order_id ) {
		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM " . self::links_table() . "
				 WHERE account_id = %d AND order_id = %d",
				(int) $account_id,
				(int) $order_id
			)
		);
		return $count > 0;
	}

	/** All order IDs linked to the account, newest link first. */
	public static function get_linked_order_ids( $account_id ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT order_id FROM " . self::links_table() . "
				 WHERE account_id = %d
				 ORDER BY id DESC",
				(int) $account_id
			),
			ARRAY_A
		);
		if ( ! $rows ) {
			return array();
		}
		return array_map( 'intval', wp_list_pluck( $rows, 'order_id' ) );
	}
}
