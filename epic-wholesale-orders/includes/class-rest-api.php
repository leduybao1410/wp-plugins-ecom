<?php
/**
 * REST routes the Next.js website's wholesale page calls (the plan's §7).
 * Every route is gated behind a shared secret (see class-settings.php) sent as
 * an `X-Epic-Secret` header, exactly like epic-account-linking /
 * epic-wholesale-inquiries. The wholesale customer is identified by the
 * `google_sub` (and fallback `email`) that the website's verified session
 * carries — this plugin never trusts a client-supplied identity OR a
 * client-supplied price: every line total is recomputed from the stored
 * wholesale price before the order is recorded.
 *
 * Routes:
 *   GET  /products?google_sub=…&email=…   eligible products (+ wholesale prices) for a whitelisted user
 *   POST /orders                          place a wholesale order
 *   GET  /orders?google_sub=…&email=…     the user's own wholesale order history
 *
 * Guarantees (see PLAN.md §5.4): creating an order NEVER reduces stock and
 * NEVER creates a shop_order — wholesale orders live only in the
 * epic_wholesale_order CPT.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Wholesale_Orders_Rest_Api {

	const NAMESPACE = 'epic-wholesale-orders/v1';

	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/products',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_products' ),
				'permission_callback' => array( __CLASS__, 'check_secret' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/orders',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_order' ),
				'permission_callback' => array( __CLASS__, 'check_secret' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/orders',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_orders' ),
				'permission_callback' => array( __CLASS__, 'check_secret' ),
			)
		);
	}

	/** Constant-time comparison against the secret configured in WooCommerce → Wholesale Orders. */
	public static function check_secret( \WP_REST_Request $request ) {
		$configured = Epic_Wholesale_Settings::get_shared_secret();
		if ( empty( $configured ) ) {
			return new \WP_Error( 'epic_wholesale_orders_not_configured', 'Shared secret not configured.', array( 'status' => 500 ) );
		}
		$provided = $request->get_header( 'x-epic-secret' );
		if ( empty( $provided ) || ! hash_equals( $configured, $provided ) ) {
			return new \WP_Error( 'epic_wholesale_orders_forbidden', 'Invalid or missing X-Epic-Secret header.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Resolve the identity to a WP user. Primary path: the epic-account-linking
	 * accounts table (google_sub → wc_customer_id). Fallback: a WP user by
	 * email (covers accounts not created through Google sign-in).
	 *
	 * @param string $google_sub
	 * @param string $email
	 * @return \WP_User|null
	 */
	public static function resolve_customer( $google_sub, $email ) {
		global $wpdb;

		$user_id = 0;

		if ( '' !== (string) $google_sub ) {
			$accounts_table = $wpdb->prefix . 'epic_accounts';
			$table_exists   = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $accounts_table ) );
			if ( $table_exists ) {
				$row = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT wc_customer_id FROM {$accounts_table} WHERE google_sub = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is derived from $wpdb->prefix, not user input.
						(string) $google_sub
					),
					ARRAY_A
				);
				if ( $row && ! empty( $row['wc_customer_id'] ) ) {
					$user_id = (int) $row['wc_customer_id'];
				}
			}
		}

		if ( ! $user_id && '' !== (string) $email ) {
			$user = get_user_by( 'email', (string) $email );
			if ( $user ) {
				$user_id = (int) $user->ID;
			}
		}

		return $user_id ? get_userdata( $user_id ) : null;
	}

	// ------------------------------------------------------------------
	// GET /products
	// ------------------------------------------------------------------

	public static function list_products( \WP_REST_Request $request ) {
		$customer = self::resolve_customer(
			(string) $request->get_param( 'google_sub' ),
			(string) $request->get_param( 'email' )
		);
		if ( ! $customer || ! Epic_Wholesale_Orders_Store::is_customer( $customer->ID ) ) {
			return new \WP_Error( 'epic_wholesale_orders_not_whitelisted', 'This account is not a wholesale customer.', array( 'status' => 403 ) );
		}

		$products = array();

		// Simple products (+ any other non-variable product) marked wholesale.
		$simple = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => Epic_Wholesale_Product_Pricing::META_ENABLED,
						'value' => 'yes',
					),
				),
			)
		);
		foreach ( $simple->posts as $post ) {
			$product = wc_get_product( $post->ID );
			if ( ! $product ) {
				continue;
			}
			// Variable parents carry no wholesale price of their own — their
			// variations are collected separately below.
			if ( $product->is_type( 'variable' ) ) {
				continue;
			}
			$entry = self::product_entry( $product );
			if ( $entry ) {
				$products[] = $entry;
			}
		}

		// Variable products: include each wholesale-enabled variation.
		$variations = new WP_Query(
			array(
				'post_type'      => 'product_variation',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => Epic_Wholesale_Product_Pricing::META_ENABLED,
						'value' => 'yes',
					),
				),
			)
		);
		foreach ( $variations->posts as $post ) {
			$parent = get_post( $post->post_parent );
			if ( ! $parent || 'publish' !== $parent->post_status || 'product' !== $parent->post_type ) {
				continue;
			}
			$variation = wc_get_product( $post->ID );
			if ( ! $variation ) {
				continue;
			}
			$entry = self::variation_entry( $variation );
			if ( $entry ) {
				$products[] = $entry;
			}
		}

		return new \WP_REST_Response( array( 'products' => $products ), 200 );
	}

	/**
	 * @param \WC_Product $product
	 * @return array|null Null when the wholesale price is missing/zero.
	 */
	private static function product_entry( \WC_Product $product ) {
		$price = Epic_Wholesale_Product_Pricing::get_price( $product->get_id() );
		if ( '' === $price || (float) $price <= 0 ) {
			return null;
		}

		return array(
			'id'                 => $product->get_id(),
			'parent_id'          => 0,
			'name'               => $product->get_name(),
			'sku'                => (string) $product->get_sku(),
			'wholesale_price'    => (float) $price,
			'wholesale_price_html' => html_entity_decode( (string) wp_strip_all_tags( wc_price( $price ) ) ),
			'regular_price'      => $product->get_regular_price() !== '' ? (float) $product->get_regular_price() : null,
			'image_url'          => self::product_image_url( $product ),
			'is_variation'       => false,
			'stock_status'       => $product->get_stock_status(),
			'stock_quantity'     => $product->managing_stock() ? (int) $product->get_stock_quantity() : null,
		);
	}

	/**
	 * @param \WC_Product_Variation $variation
	 * @return array|null
	 */
	private static function variation_entry( \WC_Product_Variation $variation ) {
		$price = Epic_Wholesale_Product_Pricing::get_price( $variation->get_id() );
		if ( '' === $price || (float) $price <= 0 ) {
			return null;
		}

		$attributes = array();
		foreach ( $variation->get_attributes() as $taxonomy => $term_slug ) {
			$label = wc_attribute_label( $taxonomy, $variation );
			$value = is_taxonomy( $taxonomy ) ? wc_get_product_terms( $variation->get_parent_id(), $taxonomy, array( 'fields' => 'names' ) ) : '';
			if ( is_array( $value ) ) {
				$value = implode( ', ', $value );
			}
			if ( '' === $value ) {
				$value = strtolower( $term_slug );
			}
			$attributes[] = $label . ': ' . $value;
		}

		$parent = wc_get_product( $variation->get_parent_id() );

		return array(
			'id'                 => $variation->get_id(),
			'parent_id'          => $variation->get_parent_id(),
			'name'               => ( $parent ? $parent->get_name() : '' ) . ( $attributes ? ' — ' . implode( ', ', $attributes ) : '' ),
			'sku'                => (string) $variation->get_sku(),
			'wholesale_price'    => (float) $price,
			'wholesale_price_html' => html_entity_decode( (string) wp_strip_all_tags( wc_price( $price ) ) ),
			'regular_price'      => $variation->get_regular_price() !== '' ? (float) $variation->get_regular_price() : null,
			'image_url'          => self::product_image_url( $variation ),
			'is_variation'       => true,
			'stock_status'       => $variation->get_stock_status(),
			'stock_quantity'     => $variation->managing_stock() ? (int) $variation->get_stock_quantity() : null,
		);
	}

	private static function product_image_url( \WC_Product $product ) {
		$image_id = $product->get_image_id();
		if ( ! $image_id ) {
			return '';
		}
		$src = wp_get_attachment_image_src( $image_id, 'woocommerce_thumbnail' );
		return $src ? $src[0] : '';
	}

	// ------------------------------------------------------------------
	// POST /orders
	// ------------------------------------------------------------------

	public static function create_order( \WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			return new \WP_Error( 'epic_wholesale_orders_bad_request', 'A JSON body is required.', array( 'status' => 400 ) );
		}

		$customer = self::resolve_customer(
			isset( $params['google_sub'] ) ? sanitize_text_field( (string) $params['google_sub'] ) : '',
			isset( $params['email'] ) ? sanitize_email( (string) $params['email'] ) : ''
		);
		if ( ! $customer ) {
			return new \WP_Error( 'epic_wholesale_orders_no_account', 'No account found for this session.', array( 'status' => 403 ) );
		}
		if ( ! Epic_Wholesale_Orders_Store::is_customer( $customer->ID ) ) {
			return new \WP_Error( 'epic_wholesale_orders_not_whitelisted', 'This account is not a wholesale customer.', array( 'status' => 403 ) );
		}

		$raw_items = isset( $params['items'] ) && is_array( $params['items'] ) ? $params['items'] : array();
		if ( empty( $raw_items ) ) {
			return new \WP_Error( 'epic_wholesale_orders_bad_request', 'At least one item is required.', array( 'status' => 400 ) );
		}

		$note = isset( $params['note'] ) ? sanitize_textarea_field( (string) $params['note'] ) : '';

		// Recompute everything server-side — never trust client prices.
		$items = array();
		$total = 0.0;
		foreach ( $raw_items as $raw ) {
			$product_id = isset( $raw['product_id'] ) ? absint( $raw['product_id'] ) : 0;
			$quantity   = isset( $raw['quantity'] ) ? absint( $raw['quantity'] ) : 0;

			if ( ! $product_id || $quantity < 1 ) {
				return new \WP_Error( 'epic_wholesale_orders_bad_request', 'Each item needs a product_id and a positive quantity.', array( 'status' => 400 ) );
			}

			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				return new \WP_Error( 'epic_wholesale_orders_bad_request', sprintf( 'Unknown product %d.', $product_id ), array( 'status' => 400 ) );
			}

			// Variations live under a parent product — allow submitting either
			// the variation id (preferred) or a simple product id.
			$wholesale_price = Epic_Wholesale_Product_Pricing::get_price( $product_id );
			$enabled         = Epic_Wholesale_Product_Pricing::is_enabled( $product_id );

			if ( ! $enabled || '' === $wholesale_price || (float) $wholesale_price <= 0 ) {
				return new \WP_Error( 'epic_wholesale_orders_not_eligible', sprintf( 'Product %d is not available for wholesale ordering.', $product_id ), array( 'status' => 400 ) );
			}

			$unit_price = wc_format_decimal( $wholesale_price );
			$line_total = wc_format_decimal( (float) $unit_price * $quantity );

			$items[] = array(
				'product_id'  => $product_id,
				'name'        => $product->get_name(),
				'sku'         => (string) $product->get_sku(),
				'quantity'    => $quantity,
				'unit_price'  => (float) $unit_price,
				'line_total'  => (float) $line_total,
			);
			$total += (float) $line_total;
		}

		$order_id = Epic_Wholesale_Orders_Store::create_order(
			(int) $customer->ID,
			$items,
			$note,
			$customer->display_name,
			$customer->user_email
		);

		if ( ! $order_id ) {
			return new \WP_Error( 'epic_wholesale_orders_failed', 'Could not save the wholesale order.', array( 'status' => 500 ) );
		}

		$order = Epic_Wholesale_Orders_Store::get_order( $order_id );

		/**
		 * Fires after a wholesale order is recorded. Listened to by the two
		 * notification emails (admin + customer).
		 *
		 * @param array $data get_order() shape plus nothing else — the emails
		 *                    read everything they need from it.
		 */
		do_action( 'epic_wholesale_order_created', $order );

		return new \WP_REST_Response( self::serialize_order( $order ), 201 );
	}

	// ------------------------------------------------------------------
	// GET /orders
	// ------------------------------------------------------------------

	public static function list_orders( \WP_REST_Request $request ) {
		$customer = self::resolve_customer(
			(string) $request->get_param( 'google_sub' ),
			(string) $request->get_param( 'email' )
		);
		if ( ! $customer ) {
			return new \WP_Error( 'epic_wholesale_orders_no_account', 'No account found for this session.', array( 'status' => 403 ) );
		}

		$orders = array();
		foreach ( Epic_Wholesale_Orders_Store::get_customer_orders( $customer->ID ) as $order ) {
			$orders[] = self::serialize_order( $order );
		}

		return new \WP_REST_Response( array( 'orders' => $orders ), 200 );
	}

	// ------------------------------------------------------------------
	// Serialization
	// ------------------------------------------------------------------

	/** Map the internal prefixed post status to a short, stable key for the front-end. */
	private static function short_order_status( $post_status ) {
		switch ( $post_status ) {
			case Epic_Wholesale_Orders_Store::STATUS_DONE:
				return 'done';
			case Epic_Wholesale_Orders_Store::STATUS_CANCELLED:
				return 'cancelled';
			case Epic_Wholesale_Orders_Store::STATUS_PENDING:
			default:
				return 'pending';
		}
	}

	private static function serialize_order( array $order ) {
		return array(
			'id'             => $order['id'],
			'order_number'   => $order['order_number'],
			'date_created'   => $order['date_created'],
			'order_status'   => self::short_order_status( $order['order_status'] ),
			'payment_status' => $order['payment_status'],
			'items'          => $order['items'],
			'note'           => $order['note'],
			'total'          => $order['total'],
		);
	}
}
