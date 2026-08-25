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
	const OPTION_LEVELS       = 'epic_wholesale_orders_levels';
	const OPTION_DEFAULT_LEVEL = 'epic_wholesale_orders_default_level';
	const USER_META_LEVEL     = 'epic_wholesale_level';

	// Order statuses (custom post statuses).
	const STATUS_PENDING    = 'epic_wo_pending';
	const STATUS_APPROVED   = 'epic_wo_approved';
	const STATUS_DONE       = 'epic_wo_done';
	const STATUS_UNAPPROVED = 'epic_wo_unapproved';
	const STATUS_CANCELLED  = 'epic_wo_cancelled'; // Legacy — superseded by unapproved; kept for old orders.

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
	const META_LEVEL_KEY      = '_level_key';
	const META_LEVEL_NAME     = '_level_name';
	const META_LEVEL_DISCOUNT = '_level_discount';
	const META_INVOICE_ATTACHMENT = '_invoice_attachment_id';

	/** Valid payment statuses — kept here so no caller hand-rolls strings. */
	const PAYMENT_STATUSES = array(
		self::PAYMENT_WAITING_FOR_PAYMENT,
		self::PAYMENT_PAID,
		self::PAYMENT_PENDING,
		self::PAYMENT_CANCELED,
	);

	public static function order_statuses() {
		return array(
			self::STATUS_PENDING    => __( 'Pending', 'epic-wholesale-orders' ),
			self::STATUS_APPROVED   => __( 'Approved', 'epic-wholesale-orders' ),
			self::STATUS_DONE       => __( 'Done', 'epic-wholesale-orders' ),
			self::STATUS_UNAPPROVED => __( 'Unapproved', 'epic-wholesale-orders' ),
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
			self::STATUS_APPROVED,
			array(
				'label'                     => __( 'Approved', 'epic-wholesale-orders' ),
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
			self::STATUS_UNAPPROVED,
			array(
				'label'                     => __( 'Unapproved', 'epic-wholesale-orders' ),
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
				// Standard 'post' caps — NOT aliased to `manage_woocommerce`.
				// Registering this CPT with `map_meta_cap => true` and all
				// capabilities pointed at `manage_woocommerce` writes
				// `$post_type_meta_caps['manage_woocommerce'] = 'delete_post'`
				// (see _post_type_meta_capabilities()), which makes WP translate
				// EVERY bare `current_user_can('manage_woocommerce')` into
				// `delete_post` (→ do_not_allow without a post arg). That
				// silently revoked the cap for all administrators site-wide —
				// it even locked WooCommerce's own Settings screen. Access is
				// still fully gated: every entry point (menu page, list-table
				// delete, order metabox save, product pricing save) checks
				// `current_user_can('manage_woocommerce')` itself.
				'capability_type' => 'post',
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
	// Price levels
	// ------------------------------------------------------------------

	/**
	 * Default level set, used when the option has never been saved (fresh
	 * install / migration from the single-price version).
	 */
	public static function default_levels() {
		return array(
			'level_1' => array(
				'key'      => 'level_1',
				'name'     => __( 'Level 1', 'epic-wholesale-orders' ),
				'discount' => 0,
			),
		);
	}

	/**
	 * Creates the levels options on first load (no DB migration needed for
	 * existing installs): existing per-product prices stay as the base
	 * wholesale price, and existing whitelisted customers fall back to the
	 * default level via get_customer_level().
	 */
	public static function ensure_levels() {
		if ( false === get_option( self::OPTION_LEVELS ) ) {
			update_option( self::OPTION_LEVELS, self::default_levels() );
			update_option( self::OPTION_DEFAULT_LEVEL, 'level_1' );
		}
	}

	/**
	 * @return array Levels keyed by level key: array( 'key', 'name', 'discount' ).
	 */
	public static function get_levels() {
		$levels = get_option( self::OPTION_LEVELS, self::default_levels() );
		if ( ! is_array( $levels ) || empty( $levels ) ) {
			return self::default_levels();
		}

		$clean = array();
		foreach ( $levels as $key => $level ) {
			if ( ! is_array( $level ) ) {
				continue;
			}
			$key       = (string) $key;
			$name      = isset( $level['name'] ) ? (string) $level['name'] : '';
			$discount  = isset( $level['discount'] ) ? (float) $level['discount'] : 0;
			$discount  = max( 0, min( 100, $discount ) );
			$clean[ $key ] = array(
				'key'      => $key,
				'name'     => '' !== $name ? $name : $key,
				'discount' => $discount,
			);
		}

		return $clean;
	}

	/** @return array|null One level def, or null when the key is unknown. */
	public static function get_level( $key ) {
		$levels = self::get_levels();
		return isset( $levels[ $key ] ) ? $levels[ $key ] : null;
	}

	public static function get_default_level_key() {
		$default = (string) get_option( self::OPTION_DEFAULT_LEVEL, 'level_1' );
		$levels  = self::get_levels();
		return isset( $levels[ $default ] ) ? $default : (string) array_key_first( $levels );
	}

	/**
	 * Replaces the whole level set (name + discount per level). Admin UI only.
	 *
	 * @param array $levels Levels keyed by key, each array( 'name', 'discount' ).
	 * @param string $default_key Which level is the default for new customers.
	 */
	public static function save_levels( array $levels, $default_key ) {
		$clean = array();
		foreach ( $levels as $key => $level ) {
			$key      = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			$name     = isset( $level['name'] ) ? sanitize_text_field( (string) $level['name'] ) : '';
			$discount = isset( $level['discount'] ) ? (float) $level['discount'] : 0;
			$discount = max( 0, min( 100, $discount ) );
			$clean[ $key ] = array(
				'key'      => $key,
				'name'     => '' !== $name ? $name : $key,
				'discount' => $discount,
			);
		}

		if ( empty( $clean ) ) {
			$clean = self::default_levels();
		}

		$default_key = sanitize_key( (string) $default_key );
		if ( ! isset( $clean[ $default_key ] ) ) {
			$default_key = (string) array_key_first( $clean );
		}

		update_option( self::OPTION_LEVELS, $clean );
		update_option( self::OPTION_DEFAULT_LEVEL, $default_key );
		return true;
	}

	/** @return string Level key for a user, falling back to the default level. */
	public static function get_customer_level( $user_id ) {
		$level = (string) get_user_meta( (int) $user_id, self::USER_META_LEVEL, true );
		if ( '' === $level || null === self::get_level( $level ) ) {
			return self::get_default_level_key();
		}
		return $level;
	}

	public static function set_customer_level( $user_id, $level_key ) {
		$level_key = sanitize_key( (string) $level_key );
		if ( null === self::get_level( $level_key ) ) {
			$level_key = self::get_default_level_key();
		}
		return update_user_meta( (int) $user_id, self::USER_META_LEVEL, $level_key );
	}

	/** @return float Percent discount for a level key (0–100). */
	public static function level_discount( $key ) {
		$level = self::get_level( $key );
		return $level ? (float) $level['discount'] : 0;
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
	 * @param string $level_key        Level applied when the prices were computed (snapshot).
	 * @param string $level_name
	 * @param float  $level_discount
	 * @return int Post id, or 0 on failure.
	 */
	public static function create_order( $customer_user_id, array $items, $note = '', $customer_name = '', $customer_email = '', $level_key = '', $level_name = '', $level_discount = 0 ) {
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
		update_post_meta( $post_id, self::META_LEVEL_KEY, (string) $level_key );
		update_post_meta( $post_id, self::META_LEVEL_NAME, (string) $level_name );
		update_post_meta( $post_id, self::META_LEVEL_DISCOUNT, (float) $level_discount );
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

		if ( self::STATUS_UNAPPROVED === $new_status ) {
			// Unapproved (with a required reason) ⇒ payment CANCELED.
			$payment = self::PAYMENT_CANCELED;
		} elseif ( self::STATUS_APPROVED === $new_status && self::PAYMENT_PAID !== $payment ) {
			// Approved ⇒ waiting for payment, unless the admin already marked
			// it PAID — a manual PAID is never overwritten.
			$payment = self::PAYMENT_WAITING_FOR_PAYMENT;
		}
		// `done` is the final state and does not touch payment — the admin
		// marks PAID while the order is approved.

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
			'level_key'      => (string) get_post_meta( $post_id, self::META_LEVEL_KEY, true ),
			'level_name'     => (string) get_post_meta( $post_id, self::META_LEVEL_NAME, true ),
			'level_discount' => (float) get_post_meta( $post_id, self::META_LEVEL_DISCOUNT, true ),
			'invoice_attachment_id' => (int) get_post_meta( $post_id, self::META_INVOICE_ATTACHMENT, true ),
			'has_invoice'    => (int) get_post_meta( $post_id, self::META_INVOICE_ATTACHMENT, true ) > 0,
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
				'post_status'    => array( self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_DONE, self::STATUS_UNAPPROVED, self::STATUS_CANCELLED ),
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
			'post_status'    => array( self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_DONE, self::STATUS_UNAPPROVED, self::STATUS_CANCELLED ),
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
