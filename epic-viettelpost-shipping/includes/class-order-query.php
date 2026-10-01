<?php
/**
 * Storage-aware order queries shared by the Shipments dashboard and the
 * stale-shipment cron.
 *
 * `meta_query` is only supported by the HPOS (custom order tables) datastore;
 * on the legacy wp_posts datastore WooCommerce 9.2+ logs it as unsupported.
 * This helper picks the right query path per storage so callers don't repeat
 * the check (mirrors Epic_VTP_Webhook::find_order_by_waybill()).
 *
 * @package Epic_ViettelPost_Shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_VTP_Order_Query {

	/** Statuses that mean a shipment is finished — no longer "in flight". */
	const TERMINAL_STATUSES = array( '501', '504', '101', '107', '201', '503' );

	public static function is_hpos() {
		$order_util = '\Automattic\WooCommerce\Utilities\OrderUtil';
		return class_exists( $order_util )
			&& method_exists( $order_util, 'custom_orders_table_usage_is_enabled' )
			&& $order_util::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Orders that have a booked ViettelPost shipment, newest first, with
	 * optional filters.
	 *
	 * @param array $filters {
	 *   @type string[] $status_codes  Raw VTP statuses to include (empty = any).
	 *   @type string   $search        Waybill substring.
	 *   @type bool     $needs_action  Only return/issue-flagged shipments.
	 * }
	 * @param int   $per_page
	 * @param int   $page
	 * @return array { orders: WC_Order[], total: int }
	 */
	public static function booked( array $filters, $per_page = 20, $page = 1 ) {
		$meta_query = array(
			array(
				'key'     => Epic_VTP_Order_Meta_Box::META_ORDER_NUMBER,
				'compare' => 'EXISTS',
			),
		);

		if ( ! empty( $filters['status_codes'] ) ) {
			$meta_query[] = array(
				'key'     => Epic_VTP_Order_Meta_Box::META_STATUS,
				'value'   => array_map( 'strval', (array) $filters['status_codes'] ),
				'compare' => 'IN',
			);
		}

		if ( ! empty( $filters['search'] ) ) {
			$meta_query[] = array(
				'key'     => Epic_VTP_Order_Meta_Box::META_ORDER_NUMBER,
				'value'   => $filters['search'],
				'compare' => 'LIKE',
			);
		}

		if ( ! empty( $filters['needs_action'] ) ) {
			$meta_query[] = array(
				'key'     => Epic_VTP_Order_Meta_Box::META_NEEDS_ACTION,
				'compare' => 'EXISTS',
			);
		}

		$per_page = max( 1, (int) $per_page );
		$offset   = max( 0, ( (int) $page - 1 ) * $per_page );

		if ( self::is_hpos() ) {
			$result = wc_get_orders(
				array(
					'type'       => 'shop_order',
					'status'     => array_keys( wc_get_order_statuses() ),
					'limit'      => $per_page,
					'offset'     => $offset,
					'orderby'    => 'date',
					'order'      => 'DESC',
					'paginate'   => true,
					'meta_query' => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- HPOS-only.
				)
			);
			return array(
				'orders' => $result->orders,
				'total'  => (int) $result->total,
			);
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'shop_order',
				'post_status'    => array_keys( wc_get_order_statuses() ),
				'posts_per_page' => $per_page,
				'offset'         => $offset,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- legacy path only.
			)
		);

		$orders = array();
		foreach ( $query->posts as $post_id ) {
			$order = wc_get_order( $post_id );
			if ( $order instanceof WC_Order ) {
				$orders[] = $order;
			}
		}

		return array(
			'orders' => $orders,
			'total'  => (int) $query->found_posts,
		);
	}
}
