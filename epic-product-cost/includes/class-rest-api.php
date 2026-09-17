<?php
/**
 * REST API (namespace `epic-product-cost/v1`) so cost data can be read and edited from outside
 * wp-admin — built for the companion MCP server in `mcp-server/`, but usable by anything.
 *
 * Auth: WordPress Application Passwords (core WP feature since 5.6 — Users → Profile →
 * Application Passwords). No custom secret: the request authenticates as a real wp-admin user via
 * Basic Auth, and permission_callback below just checks that user's own capability, same as any
 * other authenticated REST request. Requires the site to be served over HTTPS (WordPress disables
 * Application Passwords over plain HTTP).
 *
 * @package Epic_Product_Cost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Product_Cost_Rest_Api {

	const NAMESPACE = 'epic-product-cost/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function check_permission() {
		return current_user_can( EPIC_PRODUCT_COST_CAP );
	}

	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/products',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'list_products' ),
					'permission_callback' => array( __CLASS__, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/products/search',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'search_products' ),
					'permission_callback' => array( __CLASS__, 'check_permission' ),
					'args'                => array(
						'q' => array(
							'required' => true,
							'type'     => 'string',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/costs/(?P<product_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_cost' ),
					'permission_callback' => array( __CLASS__, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'set_cost' ),
					'permission_callback' => array( __CLASS__, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/costs/(?P<product_id>\d+)/history/(?P<entry_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_history_entry' ),
					'permission_callback' => array( __CLASS__, 'check_permission' ),
				),
			)
		);
	}

	/**
	 * The same product set the admin screen shows — every Simple/Variable product, any status.
	 *
	 * @return WC_Product[]
	 */
	private static function get_products() {
		return wc_get_products(
			array(
				'limit'   => -1,
				'status'  => array( 'publish', 'draft', 'pending', 'private' ),
				'type'    => array( 'simple', 'variable' ),
				'orderby' => 'title',
				'order'   => 'ASC',
			)
		);
	}

	public static function list_products( $request ) {
		$today = current_time( 'Y-m-d' );
		$out   = array();

		foreach ( self::get_products() as $product ) {
			$current = Epic_Product_Cost_Store::get_cost_as_of( $product->get_id(), $today );
			$out[]   = array(
				'product_id'          => $product->get_id(),
				'name'                => $product->get_name(),
				'status'              => $product->get_status(),
				'type'                => $product->get_type(),
				'cost_250g'           => $current ? (float) $current->cost_250g : null,
				'cost_effective_date' => $current ? $current->effective_date : null,
				'price_250g'          => Epic_Product_Cost_Store::get_reference_price_250g( $product ),
			);
		}

		return rest_ensure_response( $out );
	}

	public static function search_products( $request ) {
		$q = (string) $request->get_param( 'q' );

		$products = wc_get_products(
			array(
				'limit'   => 20,
				's'       => $q,
				'status'  => array( 'publish', 'draft', 'pending', 'private' ),
				'type'    => array( 'simple', 'variable' ),
				'orderby' => 'title',
				'order'   => 'ASC',
			)
		);

		$out = array();
		foreach ( $products as $product ) {
			$out[] = array(
				'product_id' => $product->get_id(),
				'name'       => $product->get_name(),
			);
		}

		return rest_ensure_response( $out );
	}

	private static function get_product_or_404( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'not_found', __( 'Product not found.', 'epic-product-cost' ), array( 'status' => 404 ) );
		}
		return $product;
	}

	public static function get_cost( $request ) {
		$product_id = (int) $request->get_param( 'product_id' );
		$product    = self::get_product_or_404( $product_id );
		if ( is_wp_error( $product ) ) {
			return $product;
		}

		$date = $request->get_param( 'date' );
		$date = $date ? sanitize_text_field( $date ) : current_time( 'Y-m-d' );

		$current = Epic_Product_Cost_Store::get_cost_as_of( $product_id, $date );
		$history = Epic_Product_Cost_Store::get_history( $product_id );

		return rest_ensure_response(
			array(
				'product_id'     => $product_id,
				'name'           => $product->get_name(),
				'as_of'          => $date,
				'cost_250g'      => $current ? (float) $current->cost_250g : null,
				'effective_date' => $current ? $current->effective_date : null,
				'cost_500g'      => $current ? (float) $current->cost_250g * 2 : null,
				'cost_1kg'       => $current ? (float) $current->cost_250g * 4 : null,
				'history'        => array_map(
					function ( $row ) {
						return array(
							'id'             => (int) $row->id,
							'cost_250g'      => (float) $row->cost_250g,
							'effective_date' => $row->effective_date,
							'note'           => $row->note,
							'created_at'     => $row->created_at,
						);
					},
					$history
				),
			)
		);
	}

	public static function set_cost( $request ) {
		$product_id = (int) $request->get_param( 'product_id' );
		$product    = self::get_product_or_404( $product_id );
		if ( is_wp_error( $product ) ) {
			return $product;
		}

		$cost = $request->get_param( 'cost_250g' );
		if ( null === $cost || '' === $cost || ! is_numeric( $cost ) ) {
			return new WP_Error(
				'invalid_cost',
				__( 'cost_250g is required and must be numeric.', 'epic-product-cost' ),
				array( 'status' => 400 )
			);
		}
		if ( (float) $cost < 0 ) {
			return new WP_Error(
				'invalid_cost',
				__( 'cost_250g must not be negative.', 'epic-product-cost' ),
				array( 'status' => 400 )
			);
		}

		$effective_date = $request->get_param( 'effective_date' );
		$effective_date = $effective_date ? sanitize_text_field( $effective_date ) : current_time( 'Y-m-d' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $effective_date ) ) {
			return new WP_Error(
				'invalid_date',
				__( 'effective_date must be in YYYY-MM-DD format.', 'epic-product-cost' ),
				array( 'status' => 400 )
			);
		}

		$note = $request->get_param( 'note' );
		$note = $note ? sanitize_text_field( $note ) : '';

		$id = Epic_Product_Cost_Store::upsert_cost( $product_id, (float) $cost, $effective_date, $note );

		return rest_ensure_response(
			array(
				'ok'             => true,
				'id'             => $id,
				'product_id'     => $product_id,
				'cost_250g'      => (float) $cost,
				'effective_date' => $effective_date,
				'note'           => $note,
			)
		);
	}

	public static function delete_history_entry( $request ) {
		$product_id = (int) $request->get_param( 'product_id' );
		$entry_id   = (int) $request->get_param( 'entry_id' );

		global $wpdb;
		$table = Epic_Product_Cost_Store::table();
		$owner = $wpdb->get_var( $wpdb->prepare( "SELECT product_id FROM {$table} WHERE id = %d", $entry_id ) ); // phpcs:ignore

		if ( null === $owner || (int) $owner !== $product_id ) {
			return new WP_Error(
				'not_found',
				__( 'History entry not found for this product.', 'epic-product-cost' ),
				array( 'status' => 404 )
			);
		}

		Epic_Product_Cost_Store::delete_history_entry( $entry_id );

		return rest_ensure_response( array( 'ok' => true ) );
	}
}
