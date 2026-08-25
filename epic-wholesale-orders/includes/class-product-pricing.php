<?php
/**
 * Per-product wholesale pricing: admin sets a wholesale price per product (or
 * per variation for variable products) that whitelisted wholesale customers
 * see — and pay — on their dedicated ordering page. Nothing here touches the
 * regular storefront price or cart; wholesale prices are only ever surfaced
 * by this plugin's own REST layer (see class-rest-api.php).
 *
 * Simple products get a "Wholesale" metabox on the product editor. Variable
 * products get the same two fields per variation (inside WooCommerce's own
 * variation pricing panel) — a variable product has no single price of its
 * own, so the metabox isn't shown for it.
 *
 * Meta keys:
 *   _epic_wholesale_enabled  yes/no
 *   _epic_wholesale_price    float (empty = not set)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Wholesale_Product_Pricing {

	const META_ENABLED = '_epic_wholesale_enabled';
	const META_PRICE   = '_epic_wholesale_price';

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_metabox' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_metabox' ), 10, 2 );

		// Variable products: per-variation fields + save.
		add_action( 'woocommerce_variation_options_pricing', array( __CLASS__, 'render_variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation_fields' ), 10, 2 );
	}

	// ------------------------------------------------------------------
	// Simple-product metabox
	// ------------------------------------------------------------------

	public static function add_metabox() {
		add_meta_box(
			'epic_wholesale_pricing',
			__( 'Wholesale', 'epic-wholesale-orders' ),
			array( __CLASS__, 'render_metabox' ),
			'product',
			'side',
			'default'
		);
	}

	public static function render_metabox( $post ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$product = wc_get_product( $post );
		if ( ! $product || 'simple' !== $product->get_type() ) {
			// Variable products carry wholesale fields per-variation instead.
			echo '<p>' . esc_html__( 'Variable products: set a wholesale price per variation below.', 'epic-wholesale-orders' ) . '</p>';
			return;
		}

		$enabled = 'yes' === get_post_meta( $post->ID, self::META_ENABLED, true );
		$price   = (string) get_post_meta( $post->ID, self::META_PRICE, true );

		wp_nonce_field( 'epic_wholesale_pricing_' . $post->ID, 'epic_wholesale_pricing_nonce' );
		?>
		<p>
			<label style="display:block; margin-bottom:6px;">
				<input
					type="checkbox"
					name="epic_wholesale_enabled"
					value="yes"
					<?php checked( $enabled ); ?>
				/>
				<?php esc_html_e( 'Enable wholesale ordering', 'epic-wholesale-orders' ); ?>
			</label>
		</p>
		<p>
			<label for="epic_wholesale_price" style="display:block; margin-bottom:4px;">
				<?php esc_html_e( 'Wholesale price', 'epic-wholesale-orders' ); ?>
			</label>
			<input
				type="number"
				id="epic_wholesale_price"
				name="epic_wholesale_price"
				value="<?php echo esc_attr( $price ); ?>"
				min="0"
				step="any"
				class="wc_input_price"
				style="width:100%;"
			/>
			<span class="description"><?php esc_html_e( 'Shown only to wholesale customers.', 'epic-wholesale-orders' ); ?></span>
		</p>
		<?php
	}

	/**
	 * @param int $post_id
	 * @param \WP_Post $post
	 */
	public static function save_metabox( $post_id, $post ) {
		// The metabox only renders on simple products; a variable product's
		// own save path (woocommerce_save_product_variation) handles its data.
		$product = wc_get_product( $post_id );
		if ( ! $product || 'simple' !== $product->get_type() ) {
			return;
		}

		if ( ! isset( $_POST['epic_wholesale_pricing_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['epic_wholesale_pricing_nonce'] ) ), 'epic_wholesale_pricing_' . $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$enabled = isset( $_POST['epic_wholesale_enabled'] ) && 'yes' === sanitize_text_field( wp_unslash( $_POST['epic_wholesale_enabled'] ) );
		update_post_meta( $post_id, self::META_ENABLED, $enabled ? 'yes' : 'no' );

		$price = isset( $_POST['epic_wholesale_price'] ) ? wc_clean( wp_unslash( $_POST['epic_wholesale_price'] ) ) : '';
		if ( '' !== $price && is_numeric( $price ) ) {
			$price = wc_format_decimal( $price );
			update_post_meta( $post_id, self::META_PRICE, $price );
		} else {
			delete_post_meta( $post_id, self::META_PRICE );
		}
	}

	// ------------------------------------------------------------------
	// Variable-product variation fields
	// ------------------------------------------------------------------

	/**
	 * @param int    $loop           Variation index in the editor.
	 * @param array  $variation_data
	 * @param \WP_Post $variation
	 */
	public static function render_variation_fields( $loop, $variation_data, $variation ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$enabled = 'yes' === get_post_meta( $variation->ID, self::META_ENABLED, true );
		$price   = (string) get_post_meta( $variation->ID, self::META_PRICE, true );
		?>
		<p class="form-row form-row-full">
			<label>
				<input
					type="checkbox"
					name="epic_wholesale_enabled_variation[<?php echo esc_attr( $loop ); ?>]"
					value="yes"
					<?php checked( $enabled ); ?>
				/>
				<?php esc_html_e( 'Enable wholesale ordering', 'epic-wholesale-orders' ); ?>
			</label>
		</p>
		<p class="form-row form-row-first">
			<label for="epic_wholesale_price_variation_<?php echo esc_attr( $loop ); ?>">
				<?php esc_html_e( 'Wholesale price', 'epic-wholesale-orders' ); ?>
			</label>
			<input
				type="number"
				id="epic_wholesale_price_variation_<?php echo esc_attr( $loop ); ?>"
				name="epic_wholesale_price_variation[<?php echo esc_attr( $loop ); ?>]"
				value="<?php echo esc_attr( $price ); ?>"
				min="0"
				step="any"
				class="wc_input_price"
			/>
		</p>
		<?php
	}

	/**
	 * @param int $variation_id
	 * @param int $loop
	 */
	public static function save_variation_fields( $variation_id, $loop ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$enabled_key = 'epic_wholesale_enabled_variation';
		$price_key   = 'epic_wholesale_price_variation';

		$enabled = isset( $_POST[ $enabled_key ][ $loop ] ) && 'yes' === sanitize_text_field( wp_unslash( $_POST[ $enabled_key ][ $loop ] ) );
		update_post_meta( $variation_id, self::META_ENABLED, $enabled ? 'yes' : 'no' );

		if ( isset( $_POST[ $price_key ][ $loop ] ) ) {
			$price = wc_clean( wp_unslash( $_POST[ $price_key ][ $loop ] ) );
			if ( is_numeric( $price ) && '' !== $price ) {
				update_post_meta( $variation_id, self::META_PRICE, wc_format_decimal( $price ) );
			} else {
				delete_post_meta( $variation_id, self::META_PRICE );
			}
		} else {
			delete_post_meta( $variation_id, self::META_PRICE );
		}
	}

	// ------------------------------------------------------------------
	// Read helpers (used by the REST layer)
	// ------------------------------------------------------------------

	public static function is_enabled( $product_id ) {
		return 'yes' === get_post_meta( (int) $product_id, self::META_ENABLED, true );
	}

	/** @return string Wholesale price as a decimal string, or '' when unset. */
	public static function get_price( $product_id ) {
		$price = (string) get_post_meta( (int) $product_id, self::META_PRICE, true );
		return '' !== $price && is_numeric( $price ) ? (string) $price : '';
	}

	/**
	 * The effective wholesale price for a given level: the base wholesale
	 * price minus the level's discount %. A level with a 0% discount returns
	 * the base price, so a product with a base price is always orderable at
	 * every level.
	 *
	 * @param int|string $product_id
	 * @param string     $level_key
	 * @return string Decimal string, or '' when the base price is unset.
	 */
	public static function price_for_level( $product_id, $level_key ) {
		$base = self::get_price( $product_id );
		if ( '' === $base ) {
			return '';
		}
		$discount = Epic_Wholesale_Orders_Store::level_discount( $level_key );
		$price    = (float) $base * ( 1 - ( (float) $discount / 100 ) );
		return wc_format_decimal( $price );
	}
}
