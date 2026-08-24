<?php
/**
 * Persists product reviews in their own table — one row per review, linked
 * to a WooCommerce product by numeric id. Deliberately a plain custom table
 * via $wpdb, not WooCommerce's native product-review comments: the site
 * owns the whole review lifecycle here (submission → moderation → display →
 * structured data), and a custom table keeps the shape stable and the read
 * path (approved-only, aggregated) a single indexed query instead of a
 * comment_meta query. Same reasoning epic-wholesale-inquiries used for its
 * own table.
 *
 * Reviews are stored `pending` and only become `approved` when a staff
 * member approves them under WooCommerce → Product Reviews. Only approved
 * reviews are ever served to the website (see
 * Epic_Reviews_Rest_Api::get_reviews), so a submission can never appear on
 * the site — or in the Product JSON-LD the site emits — before a human has
 * looked at it. This moderation gate is what keeps the site's
 * aggregateRating/review structured data honest (Google requires review
 * markup to reflect genuine, visible reviews).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Reviews_Store {

	const DB_VERSION        = '1.0';
	const DB_VERSION_OPTION = 'epic_product_reviews_db_version';

	/** Valid values for the `status` column — kept here so callers don't hand-roll strings. */
	const STATUS_PENDING  = 'pending';
	const STATUS_APPROVED = 'approved';

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'epic_product_reviews';
	}

	/**
	 * Creates (or upgrades, via dbDelta's own diffing) the table. Called on
	 * plugin activation and, defensively, once on `plugins_loaded` whenever
	 * the stored DB_VERSION option doesn't match the constant above — same
	 * convention as every other EPIC plugin.
	 */
	public static function install() {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id BIGINT UNSIGNED NOT NULL,
			author VARCHAR(255) NOT NULL,
			rating TINYINT UNSIGNED NOT NULL,
			title VARCHAR(255) NOT NULL DEFAULT '',
			content TEXT NOT NULL,
			locale VARCHAR(32) NOT NULL DEFAULT 'unknown',
			status VARCHAR(16) NOT NULL DEFAULT 'pending',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY product_status (product_id, status),
			KEY status (status)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * @param array $data Same sanitized shape the REST controller assembles
	 *                     (see class-rest-api.php::submit_review) — keys:
	 *                     product_id, author, rating, title, content,
	 *                     locale, status, created_at.
	 * @return int Inserted row id, or 0 on failure (caller should treat 0 as
	 *             "couldn't persist this one").
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$inserted = $wpdb->insert(
			self::table_name(),
			array(
				'product_id' => (int) ( $data['product_id'] ?? 0 ),
				'author'     => (string) ( $data['author'] ?? '' ),
				'rating'     => (int) ( $data['rating'] ?? 5 ),
				'title'      => (string) ( $data['title'] ?? '' ),
				'content'    => (string) ( $data['content'] ?? '' ),
				'locale'     => (string) ( $data['locale'] ?? 'unknown' ),
				'status'     => (string) ( $data['status'] ?? self::STATUS_PENDING ),
				'created_at' => (string) ( $data['created_at'] ?? current_time( 'mysql' ) ),
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					'Failed to write product review to the epic_product_reviews table.',
					array( 'source' => 'epic-product-reviews' )
				);
			}
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Approved reviews for one product, newest first — this is the exact set
	 * the website shows on the page and feeds into its Product JSON-LD, so
	 * the two can never drift apart.
	 *
	 * @param int $product_id WooCommerce product id.
	 */
	public static function get_approved( $product_id ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . ' WHERE product_id = %d AND status = %s ORDER BY created_at DESC, id DESC',
				(int) $product_id,
				self::STATUS_APPROVED
			),
			ARRAY_A
		);
	}

	/**
	 * Aggregate rating for one product's APPROVED reviews only — Google
	 * requires the reviewCount/ratingValue in structured data to reflect
	 * exactly the reviews shown on the page, so pending reviews are never
	 * counted here.
	 *
	 * @return array{rating_value:float, review_count:int}|null Null when the
	 *         product has no approved reviews yet (callers then omit the
	 *         aggregateRating/review fields rather than emitting a 0-rating).
	 */
	public static function aggregate( $product_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(*) AS review_count, AVG(rating) AS avg_rating FROM ' . self::table_name() . ' WHERE product_id = %d AND status = %s',
				(int) $product_id,
				self::STATUS_APPROVED
			),
			ARRAY_A
		);

		if ( ! $row || empty( $row['review_count'] ) ) {
			return null;
		}

		// Round to one decimal — matches what Google's rich-results guidance
		// expects for ratingValue and keeps it stable across re-aggregations.
		$rating = round( (float) $row['avg_rating'], 1 );

		return array(
			'rating_value' => $rating,
			'review_count' => (int) $row['review_count'],
		);
	}

	/**
	 * @param int    $per_page
	 * @param int    $offset
	 * @param string $status_filter '' (all) | 'pending' | 'approved' — anything else is treated as all.
	 * @param string $orderby      One of 'created_at' or 'product_id' — anything else falls back to 'created_at'. Whitelisted here so it's safe to interpolate into SQL.
	 * @param string $order        'asc' or 'desc' (case-insensitive) — anything else falls back to 'desc'.
	 */
	public static function get_page( $per_page, $offset, $status_filter = '', $orderby = 'created_at', $order = 'desc' ) {
		global $wpdb;

		$orderby = in_array( $orderby, array( 'created_at', 'product_id' ), true ) ? $orderby : 'created_at';
		$order   = 'asc' === strtolower( (string) $order ) ? 'ASC' : 'DESC';
		$table   = self::table_name();

		$where = '';
		if ( in_array( $status_filter, array( self::STATUS_PENDING, self::STATUS_APPROVED ), true ) ) {
			$where = ' WHERE status = ' . $wpdb->prepare( '%s', $status_filter );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is fully constructed from whitelisted constants above; $orderby/$order are whitelisted (not raw user input), $per_page/$offset go through prepare().
		$sql = $wpdb->prepare(
			"SELECT * FROM {$table}{$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			(int) $per_page,
			(int) $offset
		);

		return $wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * @param string $status_filter '' (all) | 'pending' | 'approved' — anything else is treated as all.
	 */
	public static function count( $status_filter = '' ) {
		global $wpdb;

		$where = '';
		if ( in_array( $status_filter, array( self::STATUS_PENDING, self::STATUS_APPROVED ), true ) ) {
			$where = ' WHERE status = ' . $wpdb->prepare( '%s', $status_filter );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is fully constructed from whitelisted constants above.
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table_name() . $where );
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE id = %d', (int) $id ),
			ARRAY_A
		);
	}

	/** Flips a review's status (e.g. pending → approved). Returns true on success. */
	public static function set_status( $id, $status ) {
		global $wpdb;
		return false !== $wpdb->update(
			self::table_name(),
			array( 'status' => $status ),
			array( 'id' => (int) $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	public static function delete( $id ) {
		global $wpdb;
		return $wpdb->delete( self::table_name(), array( 'id' => (int) $id ), array( '%d' ) );
	}
}
