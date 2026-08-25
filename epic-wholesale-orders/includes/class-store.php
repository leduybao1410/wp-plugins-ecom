<?php
/**
 * Data layer for wholesale orders: the custom post type that holds each order,
 * its meta, and the helpers the admin screens + REST layer share (whitelist,
 * status workflow, order CRUD).
 *
 * Deliberately a CPT, not a custom table (unlike epic-wholesale-inquiries) —
 * an order has a wp-admin edit screen with a metabox, so it needs the post
 * machinery. It is deliberately NOT a shop_order: a wholesale order never
 * reduces stock, has no payment method, and has no shipping. See §5.4 of
 * PLAN.md for the explicit guarantees.
 *
 * Order statuses are custom post statuses (prefixed to avoid colliding with
 * WordPress core's reserved `pending`):
 *   epic_wo_pending    (default on submission)
 *   epic_wo_done
 *   epic_wo_cancelled
 *
 * Payment status is plain post meta (`_payment_status`), one of
 * WAITING_FOR_PAYMENT / PAID / PENDING / CANCELED, auto-set by the
 * order-status → payment-status workflow in apply_order_status():
 *   → done        ⇒ payment becomes WAITING_FOR_PAYMENT (unless already PAID)
 *   → cancelled   ⇒ payment becomes CANCELED
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Wholesale_Orders_Store {

	const POST_TYPE = 'epic_wholesale_order';

	const OPTION_WHITELIST = 'epic_wholesale_orders_customers';

	// Order statuses (custom post statuses).
	const STATUS_PENDING   = 'epic_wo_pending';
	const STATUS_DONE      = 'epic_wo_done';
	const STATUS_CANCELLED = 'epic_wo_cancelled';

	// Payment statuses (post meta `_payment_status`).
	const PAYMENT_WAITING_FOR_PAYMENT = 'WAITING_FOR_PAYMENT';
	const PAYMENT_PAID                = 'PAID';
	const PAYMENT_PENDING             = 'PENDING';
	const PAYMENT_CANCELED            = 'CANCELED';

	const META_CUSTOMER_USER_ID = '_customer_user_id';
	const META_CUSTOMER_NAME    = '_customer_name';
	const META_CUSTOMER_EMAIL   = '_customer_email';
	const META_ITEMS            = '_items';
	const META_NOTE             = '_note';
	const META_CANCEL_REASON    = '_cancel_reason';
	const META_TOTAL            = '_total';
	const META_PAYMENT_STATUS   = '_payment_status';
	const META_ADMIN_EMAIL_STATUS   = '_admin_email_status';
	const META_CUSTOMER_EMAIL_STATUS = '_customer_email_status';

	/** Valid payment statuses — kept here so no caller hand-rolls strings. */
	const PAYMENT_STATUSES = array(
		self::PAYMENT_WAITING_FOR_PAYMENT,
		self::PAYMENT_PAID,
		self::PAYMENT_PENDING,
		self::PAYMENT_CANCELED,
	);

	public static function order_statuses() {
		return array(
			self::STATUS_PENDING   => __( 'Pending', 'epic-wholesale-orders' ),
			self::STATUS_DONE      => __( 'Done', 'epic-wholesale-orders' ),
			self::STATUS_CANCELLED => __( 'Cancelled', 'epic-wholesale-orders' ),
		);
	}

	public static function payment_statuses() {
		return array(
			self::PAYMENT_WAITING_FOR_PAYMENT => __( 'Waiting for payment', 'epic-wholesale-orders' ),
			self::PAYMENT_PAID                => __( 'Paid', 'epic-wholesale-orders' ),
			self::PAYMENT_PENDING             => __( 'Pending', 'epic-wholesale-orders' ),
			self::PAYMENT_CANCELED            => __( 'Canceled', 'epic-wholesale-orders' ),
		);
	}

	// ------------------------------------------------------------------
	// Post type + statuses
	// ------------------------------------------------------------------

	public static function register_post_type() {
		register_post_status(
			self::STATUS_PENDING,
			array(
				'label'                     => __( 'Pending', 'epic-wholesale-orders' ),
				'public'                    => false,
				'internal'                  => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => false,
				'show_in_admin_status_list' => false,
			)
		);
		register_post_status(
			self::STATUS_DONE,
			array(
				'label'                     => __( 'Done', 'epic-wholesale-orders' ),
				'public'                    => false,
				'internal'                  => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => false,
				'show_in_admin_status_list' => false,
			)
		);
		register_post_status(
			self::STATUS_CANCELLED,
			array(
				'label'                     => __( 'Cancelled', 'epic-wholesale-orders' ),
				'public'                    => false,
				'internal'                  => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => false,
				'show_in_admin_status_list' => false,
			)
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Wholesale Orders', 'epic-wholesale-orders' ),
					'singular_name' => __( 'Wholesale Order', 'epic-wholesale-orders' ),
					'menu_name'     => __( 'Wholesale Orders', 'epic-wholesale-orders' ),
					'edit_item'     => __( 'Edit Wholesale Order', 'epic-wholesale-orders' ),
					'view_item'     => __( 'View Wholesale Order', 'epic-wholesale-orders' ),
					'not_found'     => __( 'No wholesale orders found.', 'epic-wholesale-orders' ),
				),
				'public'          => false,
				'publicly_queryable' => false,
				'exclude_from_search' => true,
				'show_ui'         => true,
				'show_in_menu'    => false,
				'show_in_rest'    => false,
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'capabilities'    => array(
					'create_posts'       => 'manage_woocommerce',
					'edit_post'          => 'manage_woocommerce',
					'read_post'          => 'manage_woocommerce',
					'delete_post'        => 'manage_woocommerce',
					'edit_posts'         => 'manage_woocommerce',
					'edit_others_posts'  => 'manage_woocommerce',
					'publish_posts'      => 'manage_woocommerce',
					'read_private_posts' => 'manage_woocommerce',
					'delete_posts'       => 'manage_woocommerce',
				),
			)
		);
	}

	// ------------------------------------------------------------------
	// Whitelist
	// ------------------------------------------------------------------

	/** @return int[] WP user IDs marked as wholesale customers. */
	public static function get_customers() {
		$ids = get_option( self::OPTION_WHITELIST, array() );
		if ( ! is_array( $ids ) ) {
			return array();
		}
		$clean = array();
		foreach ( $ids as $id ) {
			$id = absint( $id );
			if ( $id > 0 ) {
				$clean[ $id ] = $id;
			}
		}
		return array_values( $clean );
	}

	/**
	 * Replace the whole whitelist. Accepts ints or numeric strings; invalid
	 * entries are dropped.
	 *
	 * @param array $user_ids
	 * @return bool Whether the option changed.
	 */
	public static function set_customers( array $user_ids ) {
		$clean = array();
		foreach ( $user_ids as $id ) {
			$id = absint( $id );
			if ( $id > 0 ) {
				$clean[ $id ] = $id;
			}
		}
		return update_option( self::OPTION_WHITELIST, array_values( $clean ) );
	}

	/** @param int|string $user_id */
	public static function is_customer( $user_id ) {
		return in_array( (int) $user_id, self::get_customers(), true );
	}

	// ------------------------------------------------------------------
	// Order CRUD
	// ------------------------------------------------------------------

	/**
	 * Create a wholesale order. NEVER reduces stock and NEVER creates a
	 * shop_order — this only inserts an `epic_wholesale_order` post.
	 *
	 * @param int    $customer_user_id WP user id of the wholesale customer.
	 * @param array  $items            Normalized line items, each:
	 *                                 array( 'product_id', 'name', 'sku', 'quantity', 'unit_price', 'line_total' ).
	 * @param string $note             Customer/seller note (plain text).
	 * @param string $customer_name
	 * @param string $customer_email
	 * @return int Post id, or 0 on failure.
	 */
	public static function create_order( $customer_user_id, array $items, $note = '', $customer_name = '', $customer_email = '' ) {
		$total = 0.0;
		foreach ( $items as $item ) {
			$total += (float) $item['line_total'];
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => self::STATUS_PENDING,
				'post_title'  => 'Wholesale Order',
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					'Failed to create wholesale order.',
					array( 'source' => 'epic-wholesale-orders' )
				);
			}
			return 0;
		}

		$order_number = self::order_number_for( $post_id );

		// Title doubles as the human-readable reference (e.g. WO-42).
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => $order_number,
			)
		);

		update_post_meta( $post_id, self::META_CUSTOMER_USER_ID, (int) $customer_user_id );
		update_post_meta( $post_id, self::META_CUSTOMER_NAME, (string) $customer_name );
		update_post_meta( $post_id, self::META_CUSTOMER_EMAIL, (string) $customer_email );
		update_post_meta( $post_id, self::META_ITEMS, $items );
		update_post_meta( $post_id, self::META_NOTE, (string) $note );
		update_post_meta( $post_id, self::META_TOTAL, (string) $total );
		update_post_meta( $post_id, self::META_PAYMENT_STATUS, self::PAYMENT_PENDING );
		update_post_meta( $post_id, self::META_ADMIN_EMAIL_STATUS, 'pending' );
		update_post_meta( $post_id, self::META_CUSTOMER_EMAIL_STATUS, 'pending' );

		return (int) $post_id;
	}

	/**
	 * Applies the order-status → payment-status workflow. Called by the admin
	 * metabox save AND by anything else that transitions an order.
	 *
	 * @param int    $post_id
	 * @param string $new_status One of self::order_statuses() keys.
	 */
	public static function apply_order_status( $post_id, $new_status ) {
		$valid = array_keys( self::order_statuses() );
		if ( ! in_array( $new_status, $valid, true ) ) {
			return;
		}

		$current = get_post_status( $post_id );
		if ( $current === $new_status ) {
			// No actual transition — leave post status AND payment untouched.
			// Direct payment edits (e.g. WAITING_FOR_PAYMENT → PAID, or the
			// `done → pending` undo) are the metabox's own concern.
			return;
		}

		wp_update_post(
			array(
				'ID'          => (int) $post_id,
				'post_status' => $new_status,
			)
		);

		$payment = get_post_meta( $post_id, self::META_PAYMENT_STATUS, true );
		$payment = in_array( $payment, self::PAYMENT_STATUSES, true ) ? $payment : self::PAYMENT_PENDING;

		if ( self::STATUS_CANCELLED === $new_status ) {
			$payment = self::PAYMENT_CANCELED;
		} elseif ( self::STATUS_DONE === $new_status && self::PAYMENT_PAID !== $payment ) {
			// Done ⇒ waiting for payment, unless the admin already marked it
			// PAID — a manual PAID is never overwritten.
			$payment = self::PAYMENT_WAITING_FOR_PAYMENT;
		}

		update_post_meta( $post_id, self::META_PAYMENT_STATUS, $payment );
	}

	/**
	 * One order, hydrated for admin/customer use.
	 *
	 * @return array|null
	 */
	public static function get_order( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}

		$items = get_post_meta( $post_id, self::META_ITEMS, true );
		if ( ! is_array( $items ) ) {
			$items = array();
		}

		$payment = get_post_meta( $post_id, self::META_PAYMENT_STATUS, true );
		$payment = in_array( $payment, self::PAYMENT_STATUSES, true ) ? $payment : self::PAYMENT_PENDING;

		return array(
			'id'             => (int) $post_id,
			'order_number'   => self::order_number_for( $post_id ),
			// ISO 8601 UTC so the storefront can `new Date(...)` it directly.
			'date_created'   => mysql2date( 'c', $post->post_date_gmt, true ),
			'order_status'   => $post->post_status,
			'payment_status' => $payment,
			'customer_user_id' => (int) get_post_meta( $post_id, self::META_CUSTOMER_USER_ID, true ),
			'customer_name'  => (string) get_post_meta( $post_id, self::META_CUSTOMER_NAME, true ),
			'customer_email' => (string) get_post_meta( $post_id, self::META_CUSTOMER_EMAIL, true ),
			'items'          => $items,
			'note'           => (string) get_post_meta( $post_id, self::META_NOTE, true ),
			'cancel_reason'  => (string) get_post_meta( $post_id, self::META_CANCEL_REASON, true ),
			'total'          => (float) get_post_meta( $post_id, self::META_TOTAL, true ),
			'admin_email_status'    => (string) get_post_meta( $post_id, self::META_ADMIN_EMAIL_STATUS, true ),
			'customer_email_status' => (string) get_post_meta( $post_id, self::META_CUSTOMER_EMAIL_STATUS, true ),
		);
	}

	/**
	 * Orders belonging to one wholesale customer, newest first.
	 *
	 * @param int $user_id
	 * @return array[] Same shape as get_order().
	 */
	public static function get_customer_orders( $user_id ) {
		$query = new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( self::STATUS_PENDING, self::STATUS_DONE, self::STATUS_CANCELLED ),
				'meta_key'       => self::META_CUSTOMER_USER_ID,
				'meta_value'     => (int) $user_id,
				'meta_compare'   => '=',
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		$orders = array();
		foreach ( $query->posts as $post ) {
			$orders[] = self::get_order( $post->ID );
		}
		return $orders;
	}

	/**
	 * Meta-query-ready version of get_customer_orders() for the admin list
	 * table (supports paging + filtering). Returns [ 'items', 'total' ].
	 *
	 * @param array $args WP_Query args (already filtered).
	 * @return array
	 */
	public static function query_orders( array $args ) {
		$defaults = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => array( self::STATUS_PENDING, self::STATUS_DONE, self::STATUS_CANCELLED ),
			'posts_per_page' => 20,
			'paged'          => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		$query = new WP_Query( wp_parse_args( $args, $defaults ) );

		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = self::get_order( $post->ID );
		}
		return array(
			'items' => $items,
			'total' => (int) $query->found_posts,
		);
	}

	public static function delete( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return false;
		}
		return (bool) wp_delete_post( (int) $post_id, true );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/** Human reference like "WO-42". */
	public static function order_number_for( $post_id ) {
		return 'WO-' . (int) $post_id;
	}

	/** True if the order status allows it to still be acted on (pending or done). */
	public static function is_open_status( $status ) {
		return in_array( $status, array( self::STATUS_PENDING, self::STATUS_DONE ), true );
	}
}
