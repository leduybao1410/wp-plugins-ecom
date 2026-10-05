<?php
/**
 * WooCommerce product MCP abilities with preview, revision checks and audit logging.
 *
 * Product and variation field validation is delegated to WooCommerce's v3 REST
 * controllers through WordPress's internal REST dispatcher. The plugin does
 * not register a public REST write route.
 *
 * @package Epic_Product_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Product_MCP_Abilities {
	const CATEGORY      = 'epic-product-mcp';
	const META_PREFIX   = '_epic_product_copy_';
	const TOKEN_PREFIX  = 'epic_product_mcp_preview_';
	const TOKEN_TTL     = 600;
	const LOCALES       = array( 'vi', 'en', 'ru', 'hi', 'zh', 'ko', 'ja' );
	const COPY_FIELDS   = array( 'name', 'notes', 'excerpt', 'description' );
	const WC_TEXT_FIELDS = array(
		'name'              => 'name',
		'short_description' => 'excerpt',
		'description'      => 'description',
	);

	public static function init() {
		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_register_ability_category' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'render_missing_api_notice' ) );
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	public static function render_missing_api_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>EPIC Product MCP:</strong> WordPress 6.9+ (or the Abilities API feature plugin), WooCommerce, and the MCP Adapter are required.</p></div>';
	}

	public static function register_category() {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => 'WooCommerce Products',
				'description' => 'Search, inspect, preview and apply reviewed WooCommerce product and variation changes.',
			)
		);
	}

	public static function register_abilities() {
		foreach ( self::definitions() as $name => $definition ) {
			wp_register_ability( self::CATEGORY . '/' . $name, $definition );
		}
	}

	private static function meta( $annotations = array() ) {
		return array(
			'public'       => true,
			'show_in_rest' => true,
			'mcp'          => array(
				'public' => true,
				'type'   => 'tool',
			),
			'annotations'  => array_merge( array( 'openWorldHint' => true ), $annotations ),
		);
	}

	private static function definitions() {
		return array(
			'search-products' => array(
				'label'               => 'Search WooCommerce products',
				'description'         => 'Search WooCommerce products. Each result includes the full WooCommerce record, seven-language EPIC copy and a revision token.',
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'search'    => array( 'type' => 'string', 'description' => 'WooCommerce name or content search.' ),
						'slug'      => array( 'type' => 'string', 'description' => 'Exact product slug.' ),
						'status'    => array( 'type' => 'string', 'description' => 'WooCommerce product status, such as publish or draft.' ),
						'page'      => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
						'per_page'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ),
					),
				),
				'execute_callback'    => static function ( $input ) {
					return self::search_products( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => array( __CLASS__, 'can_manage_products' ),
				'meta'                => self::meta( array( 'readonly' => true, 'idempotent' => true, 'title' => 'Search WooCommerce products' ) ),
			),
			'get-product' => array(
				'label'               => 'Read WooCommerce product',
				'description'         => 'Read the full WooCommerce product record, its seven-language EPIC copy and current revision token.',
				'category'            => self::CATEGORY,
				'input_schema'        => self::object_schema( array( 'product_id' => array( 'type' => 'integer', 'minimum' => 1 ) ), array( 'product_id' ) ),
				'execute_callback'    => static function ( $input ) {
					return self::get_product( absint( $input['product_id'] ?? 0 ) );
				},
				'permission_callback' => array( __CLASS__, 'can_manage_products' ),
				'meta'                => self::meta( array( 'readonly' => true, 'idempotent' => true, 'title' => 'Read WooCommerce product' ) ),
			),
			'list-product-variations' => array(
				'label'               => 'List product variations',
				'description'         => 'List full WooCommerce variation records for a product, with revision tokens.',
				'category'            => self::CATEGORY,
				'input_schema'        => self::object_schema(
					array(
						'product_id' => array( 'type' => 'integer', 'minimum' => 1 ),
						'page'       => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
						'per_page'   => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 100 ),
					),
					array( 'product_id' )
				),
				'execute_callback'    => static function ( $input ) {
					return self::list_variations( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => array( __CLASS__, 'can_manage_products' ),
				'meta'                => self::meta( array( 'readonly' => true, 'idempotent' => true, 'title' => 'List product variations' ) ),
			),
			'get-product-variation' => array(
				'label'               => 'Read product variation',
				'description'         => 'Read the full WooCommerce variation record and its current revision token.',
				'category'            => self::CATEGORY,
				'input_schema'        => self::object_schema(
					array(
						'product_id'   => array( 'type' => 'integer', 'minimum' => 1 ),
						'variation_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					),
					array( 'product_id', 'variation_id' )
				),
				'execute_callback'    => static function ( $input ) {
					return self::get_variation( absint( $input['product_id'] ?? 0 ), absint( $input['variation_id'] ?? 0 ) );
				},
				'permission_callback' => array( __CLASS__, 'can_manage_products' ),
				'meta'                => self::meta( array( 'readonly' => true, 'idempotent' => true, 'title' => 'Read product variation' ) ),
			),
			'preview-change' => array(
				'label'               => 'Preview WooCommerce product change',
				'description'         => 'Preview an exact product or variation change and issue a signed, ten-minute token bound to the current revision and user. Apply only by submitting this token.',
				'category'            => self::CATEGORY,
				'input_schema'        => self::change_schema( false ),
				'execute_callback'    => static function ( $input ) {
					return self::preview_change( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => array( __CLASS__, 'can_manage_products' ),
				'meta'                => self::meta( array( 'readonly' => true, 'idempotent' => false, 'title' => 'Preview product change' ) ),
			),
			'apply-change' => array(
				'label'               => 'Apply reviewed WooCommerce change',
				'description'         => 'Apply only a valid preview token. The token expires after ten minutes and the product revision is checked again before saving.',
				'category'            => self::CATEGORY,
				'input_schema'        => self::object_schema( array( 'preview_token' => array( 'type' => 'string' ) ), array( 'preview_token' ) ),
				'execute_callback'    => static function ( $input ) {
					return self::apply_change( isset( $input['preview_token'] ) ? (string) $input['preview_token'] : '', false );
				},
				'permission_callback' => array( __CLASS__, 'can_manage_products' ),
				'meta'                => self::meta( array( 'readonly' => false, 'destructive' => false, 'idempotent' => false, 'title' => 'Apply reviewed product change' ) ),
			),
			'permanently-delete-variation' => array(
				'label'               => 'Permanently delete product variation',
				'description'         => 'Permanently delete a variation after preview-change has issued a token for the permanent_delete_variation action. WooCommerce cannot trash variations.',
				'category'            => self::CATEGORY,
				'input_schema'        => self::object_schema( array( 'preview_token' => array( 'type' => 'string' ) ), array( 'preview_token' ) ),
				'execute_callback'    => static function ( $input ) {
					return self::apply_change( isset( $input['preview_token'] ) ? (string) $input['preview_token'] : '', true );
				},
				'permission_callback' => array( __CLASS__, 'can_manage_products' ),
				'meta'                => self::meta( array( 'readonly' => false, 'destructive' => true, 'idempotent' => false, 'title' => 'Permanently delete product variation' ) ),
			),
		);
	}

	private static function object_schema( $properties, $required = array() ) {
		$schema = array( 'type' => 'object', 'properties' => $properties );
		if ( ! empty( $required ) ) {
			$schema['required'] = $required;
		}
		return $schema;
	}

	private static function change_schema( $apply ) {
		return self::object_schema(
			array(
				'action'        => array( 'type' => 'string', 'enum' => array( 'create', 'update', 'trash', 'disable_variation', 'permanent_delete_variation' ) ),
				'resource_type' => array( 'type' => 'string', 'enum' => array( 'product', 'variation' ) ),
				'product_id'    => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'Required for variation operations.' ),
				'id'            => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'Existing product or variation ID for update/trash/disable/delete.' ),
				'changes'       => array( 'type' => 'object', 'description' => 'WooCommerce REST API v3 fields, including meta_data. WooCommerce validates fields when saving.' ),
				'translations'  => array( 'type' => 'object', 'description' => 'Per-locale product copy object. For each changed text field include all locales vi, en, ru, hi, zh, ko, ja.' ),
			),
			array( 'action', 'resource_type' )
		);
	}

	public static function can_manage_products() {
		return current_user_can( 'edit_products' ) && class_exists( 'WooCommerce' );
	}

	private static function error( $code, $message, $status = 400 ) {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}

	private static function wc_request( $method, $route, $params = array() ) {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'rest_do_request' ) ) {
			return self::error( 'epic_product_mcp_woocommerce_unavailable', 'WooCommerce REST controllers are unavailable.', 503 );
		}
		$request = new WP_REST_Request( $method, $route );
		if ( 'GET' === strtoupper( $method ) ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}
		return rest_do_request( $request );
	}

	private static function response_data( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! $response instanceof WP_REST_Response ) {
			return self::error( 'epic_product_mcp_invalid_response', 'WooCommerce returned an unexpected REST response.', 500 );
		}
		if ( $response->get_status() >= 400 ) {
			$data = $response->get_data();
			return self::error(
				isset( $data['code'] ) ? sanitize_key( $data['code'] ) : 'epic_product_mcp_woocommerce_error',
				isset( $data['message'] ) ? wp_strip_all_tags( $data['message'] ) : 'WooCommerce rejected the request.',
				$response->get_status()
			);
		}
		return $response->get_data();
	}

	private static function product_route( $product_id = 0 ) {
		return $product_id ? '/wc/v3/products/' . absint( $product_id ) : '/wc/v3/products';
	}

	private static function variation_route( $product_id, $variation_id = 0 ) {
		$route = '/wc/v3/products/' . absint( $product_id ) . '/variations';
		return $variation_id ? $route . '/' . absint( $variation_id ) : $route;
	}

	private static function copy_for_product( $product_id ) {
		$copy = array();
		foreach ( self::LOCALES as $locale ) {
			$value = get_post_meta( absint( $product_id ), self::META_PREFIX . $locale, true );
			if ( is_array( $value ) ) {
				$copy[ $locale ] = $value;
			} else {
				$copy[ $locale ] = array();
			}
		}
		return $copy;
	}

	private static function canonicalize( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_values( $value ) !== $value ) {
			ksort( $value );
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::canonicalize( $item );
		}
		return $value;
	}

	private static function revision( $record, $translations = array() ) {
		// WooCommerce computes related_ids with a randomized product query. It
		// can change between identical reads, so it is not part of the saved
		// product revision used to protect a reviewed change.
		if ( is_array( $record ) ) {
			unset( $record['related_ids'] );
		}
		$encoded = wp_json_encode( self::canonicalize( array( 'record' => $record, 'translations' => $translations ) ) );
		return hash( 'sha256', (string) $encoded );
	}

	private static function with_product_context( $record ) {
		if ( ! is_array( $record ) || empty( $record['id'] ) ) {
			return self::error( 'epic_product_mcp_record_not_found', 'WooCommerce did not return the requested product.', 404 );
		}
		$translations = self::copy_for_product( $record['id'] );
		$record['translations'] = $translations;
		$record['revision'] = self::revision( $record, $translations );
		return $record;
	}

	private static function with_variation_context( $record, $product_id ) {
		if ( ! is_array( $record ) || empty( $record['id'] ) ) {
			return self::error( 'epic_product_mcp_variation_not_found', 'WooCommerce did not return the requested variation.', 404 );
		}
		$record['product_id'] = absint( $product_id );
		$record['translations'] = self::copy_for_product( $product_id );
		$record['revision'] = self::revision( $record, $record['translations'] );
		return $record;
	}

	private static function search_products( $input ) {
		$params = array(
			'page'     => max( 1, absint( $input['page'] ?? 1 ) ),
			'per_page' => min( 100, max( 1, absint( $input['per_page'] ?? 20 ) ) ),
		);
		foreach ( array( 'search', 'slug', 'status' ) as $key ) {
			if ( isset( $input[ $key ] ) && '' !== (string) $input[ $key ] ) {
				$params[ $key ] = sanitize_text_field( (string) $input[ $key ] );
			}
		}
		$response = self::response_data( self::wc_request( 'GET', self::product_route(), $params ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$items = array();
		foreach ( (array) $response as $record ) {
			$context = self::with_product_context( (array) $record );
			if ( is_wp_error( $context ) ) {
				return $context;
			}
			$items[] = $context;
		}
		return array( 'items' => $items, 'page' => $params['page'], 'per_page' => $params['per_page'] );
	}

	private static function get_product( $product_id ) {
		if ( ! $product_id ) {
			return self::error( 'epic_product_mcp_invalid_id', 'product_id must be a positive integer.' );
		}
		$record = self::response_data( self::wc_request( 'GET', self::product_route( $product_id ) ) );
		return is_wp_error( $record ) ? $record : self::with_product_context( $record );
	}

	private static function list_variations( $input ) {
		$product_id = absint( $input['product_id'] ?? 0 );
		if ( ! $product_id ) {
			return self::error( 'epic_product_mcp_invalid_id', 'product_id must be a positive integer.' );
		}
		$params = array(
			'page'     => max( 1, absint( $input['page'] ?? 1 ) ),
			'per_page' => min( 100, max( 1, absint( $input['per_page'] ?? 100 ) ) ),
		);
		$response = self::response_data( self::wc_request( 'GET', self::variation_route( $product_id ), $params ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$items = array();
		foreach ( (array) $response as $record ) {
			$context = self::with_variation_context( (array) $record, $product_id );
			if ( is_wp_error( $context ) ) {
				return $context;
			}
			$items[] = $context;
		}
		return array( 'items' => $items, 'product_id' => $product_id, 'page' => $params['page'], 'per_page' => $params['per_page'] );
	}

	private static function get_variation( $product_id, $variation_id ) {
		if ( ! $product_id || ! $variation_id ) {
			return self::error( 'epic_product_mcp_invalid_id', 'product_id and variation_id must be positive integers.' );
		}
		$record = self::response_data( self::wc_request( 'GET', self::variation_route( $product_id, $variation_id ) ) );
		return is_wp_error( $record ) ? $record : self::with_variation_context( $record, $product_id );
	}

	private static function normalize_change( $input ) {
		$action        = sanitize_key( $input['action'] ?? '' );
		$resource_type = sanitize_key( $input['resource_type'] ?? '' );
		$allowed       = array( 'create', 'update', 'trash', 'disable_variation', 'permanent_delete_variation' );
		if ( ! in_array( $action, $allowed, true ) || ! in_array( $resource_type, array( 'product', 'variation' ), true ) ) {
			return self::error( 'epic_product_mcp_invalid_operation', 'Choose a supported action and resource_type.' );
		}
		if ( 'trash' === $action && 'product' !== $resource_type ) {
			return self::error( 'epic_product_mcp_invalid_operation', 'Only products can be moved to Trash.' );
		}
		if ( in_array( $action, array( 'disable_variation', 'permanent_delete_variation' ), true ) && 'variation' !== $resource_type ) {
			return self::error( 'epic_product_mcp_invalid_operation', 'This action requires resource_type=variation.' );
		}
		if ( 'create' === $action && 'variation' === $resource_type && empty( $input['product_id'] ) ) {
			return self::error( 'epic_product_mcp_parent_required', 'Creating a variation requires its parent product_id.' );
		}
		if ( 'create' !== $action && empty( $input['id'] ) ) {
			return self::error( 'epic_product_mcp_id_required', 'Existing product or variation operations require id.' );
		}
		if ( 'variation' === $resource_type && empty( $input['product_id'] ) ) {
			return self::error( 'epic_product_mcp_parent_required', 'Variation operations require the parent product_id.' );
		}
		if ( isset( $input['changes'] ) && ! is_array( $input['changes'] ) ) {
			return self::error( 'epic_product_mcp_invalid_changes', 'changes must be an object of WooCommerce product fields.' );
		}
		if ( isset( $input['translations'] ) && ! is_array( $input['translations'] ) ) {
			return self::error( 'epic_product_mcp_invalid_copy', 'translations must be an object keyed by locale.' );
		}
		$changes = isset( $input['changes'] ) ? $input['changes'] : array();
		$translations = isset( $input['translations'] ) ? $input['translations'] : array();
		$validated_translations = self::validate_translations( $translations );
		if ( is_wp_error( $validated_translations ) ) {
			return $validated_translations;
		}
		if ( 'create' === $action && ! array_key_exists( 'status', $changes ) ) {
			$changes['status'] = 'draft';
		}
		if ( array_key_exists( 'excerpt', $changes ) ) {
			if ( array_key_exists( 'short_description', $changes ) ) {
				return self::error( 'epic_product_mcp_duplicate_excerpt', 'Use excerpt or short_description, not both.' );
			}
			$changes['short_description'] = $changes['excerpt'];
			unset( $changes['excerpt'] );
		}
		if ( array_key_exists( 'notes', $changes ) ) {
			if ( ! is_string( $changes['notes'] ) ) {
				return self::error( 'epic_product_mcp_invalid_copy', 'notes must be a string.' );
			}
			foreach ( self::LOCALES as $locale ) {
				if ( ! isset( $validated_translations[ $locale ] ) || ! array_key_exists( 'notes', $validated_translations[ $locale ] ) ) {
					return self::error( 'epic_product_mcp_translations_required', 'A changed text field requires a string translation in all seven locales.' );
				}
			}
			unset( $changes['notes'] );
		}
		if ( array_key_exists( 'id', $changes ) ) {
			return self::error( 'epic_product_mcp_immutable_id', 'The WooCommerce record ID is selected by the ability input and cannot be changed.' );
		}
		if ( isset( $changes['meta_data'] ) && is_array( $changes['meta_data'] ) ) {
			foreach ( $changes['meta_data'] as $meta ) {
				if ( isset( $meta['key'] ) && 0 === strpos( (string) $meta['key'], self::META_PREFIX ) ) {
					return self::error( 'epic_product_mcp_reserved_meta', 'Translation metadata is reserved; provide it through translations.' );
				}
			}
		}
		$required_fields = array();
		foreach ( self::WC_TEXT_FIELDS as $wc_field => $copy_field ) {
			if ( array_key_exists( $wc_field, $changes ) ) {
				$required_fields[ $copy_field ] = true;
			}
		}
		foreach ( $validated_translations as $locale => $fields ) {
			foreach ( $fields as $field => $value ) {
				$required_fields[ $field ] = true;
			}
		}
		foreach ( array_keys( $required_fields ) as $field ) {
			foreach ( self::LOCALES as $locale ) {
				if ( ! isset( $validated_translations[ $locale ] ) || ! array_key_exists( $field, $validated_translations[ $locale ] ) ) {
					return self::error( 'epic_product_mcp_translations_required', 'A changed text field requires a value in all seven locales: vi, en, ru, hi, zh, ko, ja.' );
				}
			}
		}
		if ( 'variation' === $resource_type && ! empty( $validated_translations ) && empty( $input['product_id'] ) ) {
			return self::error( 'epic_product_mcp_parent_required', 'Variation translations are stored on the parent product.' );
		}
		if ( in_array( $action, array( 'trash', 'disable_variation', 'permanent_delete_variation' ), true ) && ( ! empty( $changes ) || ! empty( $validated_translations ) ) ) {
			return self::error( 'epic_product_mcp_unexpected_change_fields', 'Deletion and disable actions do not accept changes or translations.' );
		}
		return array(
			'action'        => $action,
			'resource_type' => $resource_type,
			'id'            => absint( $input['id'] ?? 0 ),
			'product_id'    => absint( $input['product_id'] ?? 0 ),
			'changes'       => $changes,
			'translations'  => $validated_translations,
		);
	}

	private static function validate_translations( $translations ) {
		$clean = array();
		foreach ( $translations as $locale => $fields ) {
			if ( ! in_array( $locale, self::LOCALES, true ) || ! is_array( $fields ) ) {
				return self::error( 'epic_product_mcp_invalid_copy', 'translations must use the seven supported locale keys and object values.' );
			}
			foreach ( $fields as $field => $value ) {
				if ( ! in_array( $field, self::COPY_FIELDS, true ) || ! is_string( $value ) ) {
					return self::error( 'epic_product_mcp_invalid_copy', 'translations accepts only string name, notes, excerpt and description fields.' );
				}
				$clean[ $locale ][ $field ] = $value;
			}
		}
		return $clean;
	}

	private static function get_revision_context( $change ) {
		if ( 'create' === $change['action'] && 'product' === $change['resource_type'] ) {
			return array( 'revision' => null, 'record' => null, 'translations' => array() );
		}
		if ( 'create' === $change['action'] && 'variation' === $change['resource_type'] ) {
			$parent = self::get_product( $change['product_id'] );
			if ( is_wp_error( $parent ) ) {
				return $parent;
			}
			return array( 'revision' => $parent['revision'], 'record' => $parent, 'translations' => $parent['translations'] );
		}
		if ( 'product' === $change['resource_type'] ) {
			$record = self::get_product( $change['id'] );
			if ( is_wp_error( $record ) ) {
				return $record;
			}
			return array( 'revision' => $record['revision'], 'record' => $record, 'translations' => $record['translations'] );
		}
		$record = self::get_variation( $change['product_id'], $change['id'] );
		if ( is_wp_error( $record ) ) {
			return $record;
		}
		return array( 'revision' => $record['revision'], 'record' => $record, 'translations' => $record['translations'] ?? array() );
	}

	private static function check_record_capability( $change ) {
		if ( 'create' === $change['action'] ) {
			return current_user_can( 'edit_products' );
		}
		if ( 'product' === $change['resource_type'] ) {
			$capability = 'trash' === $change['action'] ? 'delete_post' : 'edit_post';
			return current_user_can( $capability, $change['id'] );
		}
		$capability = 'permanent_delete_variation' === $change['action'] ? 'delete_post' : 'edit_post';
		return current_user_can( $capability, $change['id'] ) && current_user_can( 'edit_post', $change['product_id'] );
	}

	private static function make_diff( $change, $context ) {
		$before = $context['record'];
		$diff   = array();
		foreach ( $change['changes'] as $field => $value ) {
			$before_value = is_array( $before ) && array_key_exists( $field, $before ) ? $before[ $field ] : null;
			if ( 'meta_data' === $field ) {
				$previous = isset( $before['meta_data'] ) && is_array( $before['meta_data'] ) ? $before['meta_data'] : array();
				foreach ( (array) $value as $entry ) {
					if ( ! is_array( $entry ) ) {
						continue;
					}
					$key = isset( $entry['key'] ) ? (string) $entry['key'] : '';
					$old = null;
					foreach ( $previous as $old_entry ) {
						if ( ( isset( $entry['id'], $old_entry['id'] ) && (int) $entry['id'] === (int) $old_entry['id'] ) || ( '' !== $key && isset( $old_entry['key'] ) && $key === $old_entry['key'] ) ) {
							$old = $old_entry['value'] ?? null;
							break;
						}
					}
					$new = $entry['value'] ?? null;
					if ( $old !== $new ) {
						$diff[] = array( 'field' => 'meta_data.' . $key, 'before' => $old, 'after' => $new );
					}
				}
			} elseif ( $before_value !== $value ) {
				$diff[] = array( 'field' => $field, 'before' => $before_value, 'after' => $value );
			}
		}
		foreach ( $change['translations'] as $locale => $fields ) {
			foreach ( $fields as $field => $value ) {
				$before_value = $context['translations'][ $locale ][ $field ] ?? null;
				if ( $before_value !== $value ) {
					$diff[] = array( 'field' => 'translations.' . $locale . '.' . $field, 'before' => $before_value, 'after' => $value );
				}
			}
		}
		if ( 'disable_variation' === $change['action'] ) {
			$diff[] = array( 'field' => 'status', 'before' => $before['status'] ?? null, 'after' => 'draft' );
		}
		if ( 'trash' === $change['action'] ) {
			$diff[] = array( 'field' => 'status', 'before' => $before['status'] ?? null, 'after' => 'trash' );
		}
		if ( 'permanent_delete_variation' === $change['action'] ) {
			$diff[] = array( 'field' => 'record', 'before' => 'variation exists', 'after' => 'permanently deleted' );
		}
		return $diff;
	}

	private static function normalize_json( $value ) {
		$encoded = wp_json_encode( self::canonicalize( $value ) );
		return hash( 'sha256', (string) $encoded );
	}

	private static function create_token( $change, $context ) {
		$nonce = strtolower( wp_generate_password( 40, false, false ) );
		$expires = time() + self::TOKEN_TTL;
		$payload = array( 'change' => $change );
		set_transient( self::TOKEN_PREFIX . $nonce, $payload, self::TOKEN_TTL );
		$claims = array(
			'nonce'      => $nonce,
			'user_id'    => get_current_user_id(),
			'revision'   => $context['revision'],
			'payload'    => self::normalize_json( $payload ),
			'expires_at' => $expires,
		);
		$encoded = rtrim( strtr( base64_encode( wp_json_encode( $claims ) ), '+/', '-_' ), '=' );
		$signature = hash_hmac( 'sha256', $encoded, wp_salt( 'auth' ) );
		return $encoded . '.' . $signature;
	}

	private static function decode_token( $token ) {
		$parts = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) ) {
			return self::error( 'epic_product_mcp_invalid_token', 'The preview token is invalid.' );
		}
		$expected = hash_hmac( 'sha256', $parts[0], wp_salt( 'auth' ) );
		if ( ! hash_equals( $expected, $parts[1] ) ) {
			return self::error( 'epic_product_mcp_invalid_token', 'The preview token signature is invalid.' );
		}
		$encoded = strtr( $parts[0], '-_', '+/' );
		$encoded .= str_repeat( '=', ( 4 - strlen( $encoded ) % 4 ) % 4 );
		$json = base64_decode( $encoded );
		$claims = json_decode( (string) $json, true );
		if ( ! is_array( $claims ) || empty( $claims['nonce'] ) || empty( $claims['payload'] ) || empty( $claims['expires_at'] ) ) {
			return self::error( 'epic_product_mcp_invalid_token', 'The preview token claims are invalid.' );
		}
		if ( absint( $claims['user_id'] ?? 0 ) !== get_current_user_id() ) {
			return self::error( 'epic_product_mcp_wrong_user', 'This preview token belongs to a different WordPress user.', 403 );
		}
		if ( time() > absint( $claims['expires_at'] ) ) {
			return self::error( 'epic_product_mcp_expired_token', 'The preview token has expired; preview the change again.', 410 );
		}
		$payload = get_transient( self::TOKEN_PREFIX . sanitize_key( $claims['nonce'] ) );
		if ( ! is_array( $payload ) || ! hash_equals( (string) $claims['payload'], self::normalize_json( $payload ) ) ) {
			return self::error( 'epic_product_mcp_missing_preview', 'The preview payload is unavailable or has already been used; preview the change again.', 410 );
		}
		return array( 'claims' => $claims, 'payload' => $payload );
	}

	public static function preview_change( $input ) {
		$change = self::normalize_change( $input );
		if ( is_wp_error( $change ) ) {
			return $change;
		}
		if ( ! self::check_record_capability( $change ) ) {
			return self::error( 'epic_product_mcp_forbidden', 'You do not have permission to edit this WooCommerce product or variation.', 403 );
		}
		$context = self::get_revision_context( $change );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$warnings = array();
		if ( 'product' === $change['resource_type'] && isset( $context['record']['status'] ) && 'publish' === $context['record']['status'] && isset( $change['changes']['slug'] ) && $context['record']['slug'] !== $change['changes']['slug'] ) {
			$warnings[] = 'This changes the URL slug of a published product. Preserve the current slug unless a URL change was explicitly requested.';
		}
		$token = self::create_token( $change, $context );
		return array(
			'action'         => $change['action'],
			'resource_type'  => $change['resource_type'],
			'product_id'     => 'product' === $change['resource_type'] ? $change['id'] : $change['product_id'],
			'id'             => $change['id'],
			'revision'       => $context['revision'],
			'diff'           => self::make_diff( $change, $context ),
			'warnings'       => $warnings,
			'expires_in'     => self::TOKEN_TTL,
			'preview_token'  => $token,
		);
	}

	private static function store_translations( $product_id, $translations ) {
		foreach ( $translations as $locale => $fields ) {
			if ( empty( $fields ) ) {
				continue;
			}
			$current = get_post_meta( $product_id, self::META_PREFIX . $locale, true );
			$current = is_array( $current ) ? $current : array();
			update_post_meta( $product_id, self::META_PREFIX . $locale, wp_slash( array_merge( $current, $fields ) ) );
		}
	}

	private static function request_change( $change ) {
		$action = $change['action'];
		$type = $change['resource_type'];
		$id = $change['id'];
		$product_id = $change['product_id'];
		if ( 'trash' === $action ) {
			return self::response_data( self::wc_request( 'DELETE', self::product_route( $id ), array( 'force' => false ) ) );
		}
		if ( 'permanent_delete_variation' === $action ) {
			return self::response_data( self::wc_request( 'DELETE', self::variation_route( $product_id, $id ), array( 'force' => true ) ) );
		}
		$changes = $change['changes'];
		if ( 'disable_variation' === $action ) {
			$changes = array( 'status' => 'draft' );
		}
		$method = 'create' === $action ? 'POST' : 'PUT';
		if ( 'variation' === $type ) {
			$route = self::variation_route( $product_id, 'create' === $action ? 0 : $id );
		} else {
			$route = self::product_route( 'create' === $action ? 0 : $id );
		}
		$response = 'update' === $action && empty( $changes )
			? array( 'id' => $id )
			: self::response_data( self::wc_request( $method, $route, $changes ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$record_id = absint( $response['id'] ?? ( 'product' === $type ? $id : 0 ) );
		if ( ! empty( $change['translations'] ) ) {
			$translation_product_id = 'product' === $type ? $record_id : $product_id;
			if ( $translation_product_id ) {
				self::store_translations( $translation_product_id, $change['translations'] );
			}
		}
		return $response;
	}

	private static function field_names( $change ) {
		$fields = array_keys( $change['changes'] );
		foreach ( $change['translations'] as $locale => $values ) {
			foreach ( array_keys( $values ) as $field ) {
				$fields[] = 'translations.' . $locale . '.' . $field;
			}
		}
		if ( 'disable_variation' === $change['action'] ) {
			$fields[] = 'status';
		}
		if ( 'trash' === $change['action'] || 'permanent_delete_variation' === $change['action'] ) {
			$fields[] = 'status';
		}
		$fields = array_map(
			static function ( $field ) {
				return preg_replace( '/[^A-Za-z0-9_.-]/', '', (string) $field );
			},
			$fields
		);
		return array_values( array_unique( array_filter( $fields ) ) );
	}

	private static function audit( $product_id, $operation, $fields ) {
		$entry = array(
			'actor'      => get_current_user_id(),
			'product_id' => absint( $product_id ),
			'operation'  => sanitize_key( $operation ),
			'fields'     => array_values( $fields ),
		);
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->info( 'Reviewed product MCP change.', array_merge( array( 'source' => 'epic-product-mcp' ), $entry ) );
		} else {
			error_log( 'EPIC Product MCP audit: ' . wp_json_encode( $entry ) );
		}
	}

	private static function apply_change( $token, $permanent_delete_ability ) {
		$decoded = self::decode_token( $token );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}
		$change = $decoded['payload']['change'];
		if ( 'permanent_delete_variation' === $change['action'] && ! $permanent_delete_ability ) {
			return self::error( 'epic_product_mcp_use_permanent_delete_ability', 'Use permanently-delete-variation for this reviewed operation.' );
		}
		if ( 'permanent_delete_variation' !== $change['action'] && $permanent_delete_ability ) {
			return self::error( 'epic_product_mcp_wrong_delete_token', 'This token is not for a permanent variation deletion.' );
		}
		if ( ! self::check_record_capability( $change ) ) {
			return self::error( 'epic_product_mcp_forbidden', 'You do not have permission to edit this WooCommerce product or variation.', 403 );
		}
		$current = self::get_revision_context( $change );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( $current['revision'] !== $decoded['claims']['revision'] ) {
			delete_transient( self::TOKEN_PREFIX . sanitize_key( $decoded['claims']['nonce'] ) );
			return self::error( 'epic_product_mcp_revision_conflict', 'This product changed after preview. Read it again and create a new preview.', 409 );
		}
		delete_transient( self::TOKEN_PREFIX . sanitize_key( $decoded['claims']['nonce'] ) );
		$result = self::request_change( $change );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$product_id = 'product' === $change['resource_type']
			? absint( $result['id'] ?? $change['id'] )
			: $change['product_id'];
		self::audit( $product_id, $change['action'], self::field_names( $change ) );
		if ( 'trash' === $change['action'] ) {
			return array( 'id' => $change['id'], 'product_id' => $change['id'], 'status' => 'trash', 'operation' => 'trash' );
		}
		if ( 'permanent_delete_variation' === $change['action'] ) {
			return array( 'id' => $change['id'], 'product_id' => $change['product_id'], 'deleted' => true, 'operation' => 'permanent_delete_variation' );
		}
		if ( 'variation' === $change['resource_type'] ) {
			$variation_id = absint( $result['id'] ?? $change['id'] );
			return self::get_variation( $change['product_id'], $variation_id );
		}
		return self::get_product( absint( $result['id'] ?? $change['id'] ) );
	}
}
