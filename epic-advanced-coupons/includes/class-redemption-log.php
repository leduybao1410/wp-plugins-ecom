<?php
/**
 * Custom redemption log: which coupon (including bulk-generated single-use
 * codes) was used on which order. Not WooCommerce's native usage tracking
 * (postmeta on the coupon + a coupon line item on the order) — this is a
 * purpose-built, denormalized table so the admin "Redemptions" screen can
 * answer every report ("who used this code", "how many of batch X got
 * redeemed", "redemptions this month") with one indexed query against this
 * table alone, no joins back to posts/postmeta/order-items.
 *
 * Kept in sync via woocommerce_new_order / woocommerce_update_order, which
 * fire for every order create/save regardless of storage mode (legacy post
 * storage or HPOS) and regardless of *how* a coupon got onto the order
 * (native checkout, admin edit, or a future REST coupon_lines integration).
 * Each sync reconciles this table to the order's current coupon line items
 * — an idempotent upsert keyed on (order_id, coupon_id), so re-saving the
 * same order never creates duplicates and write cost never grows with the
 * table's size.
 *
 * Rows are never deleted when a coupon is removed from an order later —
 * they're marked status = 'removed' instead. This is business record-
 * keeping, like the order itself, not ephemeral state (unlike
 * epic-payment-store's short-lived handoff tables) — no purge job here.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Adv_Coupons_Redemption_Log {

	const DB_VERSION_OPTION = 'epic_coupon_redemptions_db_version';
	const DB_VERSION        = '1.0.0';

	public static function init() {
		add_action( 'woocommerce_new_order', array( __CLASS__, 'sync' ) );
		add_action( 'woocommerce_update_order', array( __CLASS__, 'sync' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'epic_coupon_redemptions';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta is picky about formatting: two spaces before KEY/PRIMARY KEY,
		// each column on its own line (matches epic-payment-store's convention).
		$sql = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  coupon_id BIGINT UNSIGNED NOT NULL,
  coupon_code VARCHAR(64) NOT NULL,
  generated_from BIGINT UNSIGNED NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  order_number VARCHAR(32) NULL,
  discount_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
  order_total DECIMAL(15,4) NULL,
  billing_email VARCHAR(190) NULL,
  billing_phone VARCHAR(32) NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'active',
  redeemed_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY order_coupon (order_id, coupon_id),
  KEY coupon_id (coupon_id),
  KEY coupon_code (coupon_code),
  KEY generated_from (generated_from),
  KEY redeemed_at (redeemed_at)
) {$charset_collate};";

		dbDelta( $sql );
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Reconciles the log for one order to match its current coupon line
	 * items. Safe to call any number of times for the same order — every
	 * write is an upsert keyed on (order_id, coupon_id).
	 *
	 * @param int $order_id
	 */
	public static function sync( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		global $wpdb;
		$table = self::table();
		$now   = current_time( 'mysql', true );

		$current_coupon_ids = array();

		foreach ( $order->get_items( 'coupon' ) as $item ) {
			/** @var WC_Order_Item_Coupon $item */
			$code      = $item->get_code();
			$coupon_id = (int) wc_get_coupon_id_by_code( $code );
			$current_coupon_ids[] = $coupon_id;

			$generated_from_raw = $coupon_id ? get_post_meta( $coupon_id, Epic_Adv_Coupons_Meta::GENERATED_FROM, true ) : '';
			$generated_from     = $generated_from_raw ? (int) $generated_from_raw : null;
			$discount           = (float) $item->get_discount() + (float) $item->get_discount_tax();

			// $wpdb->prepare() has no clean way to bind a PHP null through a
			// placeholder (it coerces to '' or 0 instead of SQL NULL), so the
			// generated_from column gets a literal NULL spliced into the SQL
			// when there's no batch, and a normal %d placeholder otherwise.
			$generated_from_sql = null === $generated_from ? 'NULL' : '%d';

			$params = array( $coupon_id, wc_format_coupon_code( $code ) );
			if ( null !== $generated_from ) {
				$params[] = $generated_from;
			}
			$params = array_merge(
				$params,
				array(
					$order->get_id(),
					$order->get_order_number(),
					$discount,
					(float) $order->get_total(),
					$order->get_billing_email(),
					$order->get_billing_phone(),
					$now,
					$now,
				)
			);

			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table}
					 (coupon_id, coupon_code, generated_from, order_id, order_number, discount_amount, order_total, billing_email, billing_phone, status, redeemed_at, updated_at)
					 VALUES (%d, %s, {$generated_from_sql}, %d, %s, %f, %f, %s, %s, 'active', %s, %s)
					 ON DUPLICATE KEY UPDATE
					   coupon_code = VALUES(coupon_code),
					   generated_from = VALUES(generated_from),
					   order_number = VALUES(order_number),
					   discount_amount = VALUES(discount_amount),
					   order_total = VALUES(order_total),
					   billing_email = VALUES(billing_email),
					   billing_phone = VALUES(billing_phone),
					   status = 'active',
					   updated_at = VALUES(updated_at)",
					$params
				)
			);
		}

		// Anything previously logged as 'active' for this order that isn't in
		// the current coupon list anymore was removed from the order — mark
		// it, don't delete it.
		if ( $current_coupon_ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $current_coupon_ids ), '%d' ) );
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = 'removed', updated_at = %s
					 WHERE order_id = %d AND status = 'active' AND coupon_id NOT IN ({$placeholders})",
					array_merge( array( $now, $order->get_id() ), $current_coupon_ids )
				)
			);
		} else {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = 'removed', updated_at = %s
					 WHERE order_id = %d AND status = 'active'",
					$now,
					$order->get_id()
				)
			);
		}
	}

	/**
	 * @param array $args {
	 *   @type string $search        Matches coupon_code (LIKE, case-insensitive).
	 *   @type string $email         Matches billing_email (LIKE, case-insensitive).
	 *   @type int    $generated_from Batch/template coupon ID.
	 *   @type string $status        'active'|'removed'|'' (all).
	 *   @type string $date_from     Y-m-d, inclusive, matched against redeemed_at.
	 *   @type string $date_to       Y-m-d, inclusive, matched against redeemed_at.
	 *   @type string $orderby       Column name, whitelisted below.
	 *   @type string $order         'ASC'|'DESC'.
	 *   @type int    $per_page
	 *   @type int    $page          1-indexed.
	 * }
	 * @return array
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$table = self::table();

		list( $where, $params ) = self::build_where( $args );

		$allowed_orderby = array( 'redeemed_at', 'updated_at', 'coupon_code', 'discount_amount', 'order_id' );
		$orderby          = in_array( $args['orderby'] ?? '', $allowed_orderby, true ) ? $args['orderby'] : 'redeemed_at';
		$order            = strtoupper( $args['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';

		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$sql = "SELECT * FROM {$table} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$params[] = $per_page;
		$params[] = $offset;

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
	}

	public static function count( array $args = array() ) {
		global $wpdb;
		$table = self::table();

		list( $where, $params ) = self::build_where( $args );
		$sql = "SELECT COUNT(*) FROM {$table} {$where}";

		if ( $params ) {
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
		}
		return (int) $wpdb->get_var( $sql );
	}

	protected static function build_where( array $args ) {
		global $wpdb;
		$clauses = array( '1=1' );
		$params  = array();

		if ( ! empty( $args['search'] ) ) {
			$clauses[] = 'coupon_code LIKE %s';
			$params[]  = '%' . $wpdb->esc_like( wc_format_coupon_code( $args['search'] ) ) . '%';
		}

		if ( ! empty( $args['email'] ) ) {
			$clauses[] = 'billing_email LIKE %s';
			$params[]  = '%' . $wpdb->esc_like( sanitize_email( $args['email'] ) ) . '%';
		}

		if ( ! empty( $args['generated_from'] ) ) {
			$clauses[] = 'generated_from = %d';
			$params[]  = (int) $args['generated_from'];
		}

		if ( ! empty( $args['status'] ) && in_array( $args['status'], array( 'active', 'removed' ), true ) ) {
			$clauses[] = 'status = %s';
			$params[]  = $args['status'];
		}

		if ( ! empty( $args['date_from'] ) ) {
			$clauses[] = 'redeemed_at >= %s';
			$params[]  = $args['date_from'] . ' 00:00:00';
		}

		if ( ! empty( $args['date_to'] ) ) {
			$clauses[] = 'redeemed_at <= %s';
			$params[]  = $args['date_to'] . ' 23:59:59';
		}

		return array( 'WHERE ' . implode( ' AND ', $clauses ), $params );
	}

	/**
	 * Distinct batches (bulk-generate template coupons) that actually have
	 * redemptions logged, for the admin screen's batch filter dropdown.
	 *
	 * @return array<int,string> template coupon ID => "code (used N times)"
	 */
	public static function get_batches() {
		global $wpdb;
		$table = self::table();

		$rows = $wpdb->get_results(
			"SELECT r.generated_from AS template_id, p.post_title AS template_code, COUNT(*) AS redemption_count
			 FROM {$table} r
			 LEFT JOIN {$wpdb->posts} p ON p.ID = r.generated_from
			 WHERE r.generated_from IS NOT NULL
			 GROUP BY r.generated_from, p.post_title
			 ORDER BY p.post_title ASC",
			ARRAY_A
		);

		$batches = array();
		foreach ( $rows as $row ) {
			$label = $row['template_code'] ? $row['template_code'] : sprintf( '#%d', $row['template_id'] );
			$batches[ (int) $row['template_id'] ] = sprintf( '%s (%d)', $label, $row['redemption_count'] );
		}
		return $batches;
	}
}
