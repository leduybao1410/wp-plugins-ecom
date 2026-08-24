<?php
/**
 * Bulk unique single-use code generator.
 *
 * Adds a "Generate Codes" tab to an already-saved coupon's edit screen.
 * The current coupon acts as the template: every setting (discount type/
 * amount, all native restrictions, and every advanced rule from this
 * plugin) is cloned onto N brand-new coupon posts, each with its own
 * random unique code and usage limit forced to 1 — so every generated
 * code works exactly once, store-wide, regardless of the template's own
 * usage-limit setting (this is what "single-use" was asked for).
 *
 * Codes are generated from a charset that excludes visually ambiguous
 * characters (0/O, 1/I/L) since these are meant to be typed in by hand
 * (giveaway winners, influencer audiences, etc.).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Adv_Coupons_Bulk_Generate {

	const CODE_CHARSET  = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
	const MAX_QUANTITY  = 500;
	const MAX_ATTEMPTS  = 20; // per code, before giving up on a collision.

	/**
	 * Native WooCommerce coupon meta keys cloned onto each generated code
	 * (everything set via the coupon's own Coupon data tabs).
	 *
	 * @var string[]
	 */
	const NATIVE_META_KEYS = array(
		'discount_type',
		'coupon_amount',
		'individual_use',
		'product_ids',
		'exclude_product_ids',
		'product_categories',
		'exclude_product_categories',
		'exclude_sale_items',
		'minimum_amount',
		'maximum_amount',
		'customer_email',
		'free_shipping',
		'limit_usage_to_x_items',
		'date_expires',
	);

	public static function init() {
		add_filter( 'woocommerce_coupon_data_tabs', array( __CLASS__, 'add_tab' ) );
		add_action( 'woocommerce_coupon_data_panels', array( __CLASS__, 'render_panel' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_ajax_epic_generate_coupons', array( __CLASS__, 'ajax_generate' ) );
	}

	public static function add_tab( $tabs ) {
		$tabs['epic_generate_codes'] = array(
			'label'  => __( 'Generate Codes', 'epic-advanced-coupons' ),
			'target' => 'epic_generate_codes_data',
			'class'  => array(),
		);
		return $tabs;
	}

	public static function enqueue( $hook ) {
		global $post;
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || ! isset( $post->post_type ) || 'shop_coupon' !== $post->post_type ) {
			return;
		}
		wp_enqueue_script( 'jquery' );
	}

	public static function render_panel() {
		global $post;
		$coupon_id = $post->ID;
		$is_new    = empty( $coupon_id ) || 'auto-draft' === get_post_status( $coupon_id );
		?>
		<div id="epic_generate_codes_data" class="panel woocommerce_options_panel">
			<div class="options_group">
				<p class="form-field"><strong><?php esc_html_e( 'Generate unique one-time codes', 'epic-advanced-coupons' ); ?></strong></p>

				<?php if ( $is_new ) : ?>
					<p class="form-field"><em><?php esc_html_e( 'Save (Publish) this coupon first — it will be used as the template for every field (discount, restrictions, advanced rules) that the generated codes copy.', 'epic-advanced-coupons' ); ?></em></p>
				<?php else : ?>
					<p class="form-field">
						<em><?php esc_html_e( 'This coupon is the template. Each generated code is a separate, independent coupon that copies every setting from it, but with its own unique code and a usage limit of 1 (works once, store-wide).', 'epic-advanced-coupons' ); ?></em>
					</p>
					<p class="form-field">
						<label for="epic_gen_quantity"><?php esc_html_e( 'Quantity', 'epic-advanced-coupons' ); ?></label>
						<input type="number" id="epic_gen_quantity" min="1" max="<?php echo esc_attr( self::MAX_QUANTITY ); ?>" value="50" style="width:100px;" />
						<span class="description"><?php echo esc_html( sprintf( __( 'Max %d per batch.', 'epic-advanced-coupons' ), self::MAX_QUANTITY ) ); ?></span>
					</p>
					<p class="form-field">
						<label for="epic_gen_prefix"><?php esc_html_e( 'Prefix', 'epic-advanced-coupons' ); ?></label>
						<input type="text" id="epic_gen_prefix" value="" placeholder="VIP-" style="width:150px;" maxlength="20" />
					</p>
					<p class="form-field">
						<label for="epic_gen_length"><?php esc_html_e( 'Random code length', 'epic-advanced-coupons' ); ?></label>
						<input type="number" id="epic_gen_length" min="4" max="20" value="8" style="width:100px;" />
					</p>
					<p class="form-field">
						<button type="button" class="button button-primary" id="epic_gen_button"><?php esc_html_e( 'Generate', 'epic-advanced-coupons' ); ?></button>
						<span id="epic_gen_status" style="margin-left:10px;"></span>
					</p>
					<p class="form-field">
						<textarea id="epic_gen_result" rows="8" style="width:70%;display:none;" readonly></textarea>
						<br />
						<a href="#" id="epic_gen_download" style="display:none;"><?php esc_html_e( 'Download CSV', 'epic-advanced-coupons' ); ?></a>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php if ( ! $is_new ) : ?>
		<script>
		jQuery( function ( $ ) {
			$( '#epic_gen_button' ).on( 'click', function ( e ) {
				e.preventDefault();
				var $btn = $( this ), $status = $( '#epic_gen_status' );
				$btn.prop( 'disabled', true );
				$status.text( <?php echo wp_json_encode( __( 'Generating…', 'epic-advanced-coupons' ) ); ?> );

				$.post( ajaxurl, {
					action: 'epic_generate_coupons',
					nonce: <?php echo wp_json_encode( wp_create_nonce( 'epic_generate_coupons_' . $coupon_id ) ); ?>,
					coupon_id: <?php echo (int) $coupon_id; ?>,
					quantity: $( '#epic_gen_quantity' ).val(),
					prefix: $( '#epic_gen_prefix' ).val(),
					length: $( '#epic_gen_length' ).val()
				} ).done( function ( response ) {
					$btn.prop( 'disabled', false );
					if ( ! response || ! response.success ) {
						$status.text( ( response && response.data && response.data.message ) || <?php echo wp_json_encode( __( 'Something went wrong.', 'epic-advanced-coupons' ) ); ?> );
						return;
					}
					var codes = response.data.codes || [];
					$status.text( codes.length + <?php echo wp_json_encode( ' ' . __( 'codes generated.', 'epic-advanced-coupons' ) ); ?> );
					$( '#epic_gen_result' ).show().val( codes.join( "\n" ) );

					var csv = 'code\n' + codes.join( '\n' );
					var blob = new Blob( [ csv ], { type: 'text/csv' } );
					var url = URL.createObjectURL( blob );
					$( '#epic_gen_download' ).show().attr( 'href', url ).attr( 'download', 'coupon-codes.csv' );
				} ).fail( function () {
					$btn.prop( 'disabled', false );
					$status.text( <?php echo wp_json_encode( __( 'Request failed.', 'epic-advanced-coupons' ) ); ?> );
				} );
			} );
		} );
		</script>
		<?php endif; ?>
		<?php
	}

	public static function ajax_generate() {
		$coupon_id = isset( $_POST['coupon_id'] ) ? (int) $_POST['coupon_id'] : 0;

		if ( ! $coupon_id || ! check_ajax_referer( 'epic_generate_coupons_' . $coupon_id, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed — please reload the page and try again.', 'epic-advanced-coupons' ) ) );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'epic-advanced-coupons' ) ) );
		}
		if ( 'shop_coupon' !== get_post_type( $coupon_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Template coupon not found.', 'epic-advanced-coupons' ) ) );
		}

		$quantity = isset( $_POST['quantity'] ) ? (int) $_POST['quantity'] : 0;
		$quantity = max( 1, min( self::MAX_QUANTITY, $quantity ) );

		$prefix = isset( $_POST['prefix'] ) ? strtoupper( preg_replace( '/[^A-Za-z0-9_-]/', '', wp_unslash( $_POST['prefix'] ) ) ) : '';
		$prefix = substr( $prefix, 0, 20 );

		$length = isset( $_POST['length'] ) ? (int) $_POST['length'] : 8;
		$length = max( 4, min( 20, $length ) );

		$codes = array();
		for ( $i = 0; $i < $quantity; $i++ ) {
			$code = self::generate_unique_code( $prefix, $length );
			if ( ! $code ) {
				continue; // Ran out of collision-free attempts for this slot — skip rather than fail the whole batch.
			}
			$new_id = self::create_coupon( $coupon_id, $code );
			if ( $new_id ) {
				$codes[] = $code;
			}
		}

		wp_send_json_success( array( 'codes' => $codes ) );
	}

	protected static function generate_unique_code( $prefix, $length ) {
		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			$random = '';
			for ( $i = 0; $i < $length; $i++ ) {
				$random .= self::CODE_CHARSET[ wp_rand( 0, strlen( self::CODE_CHARSET ) - 1 ) ];
			}
			$code = $prefix . $random;
			if ( ! wc_get_coupon_id_by_code( $code ) ) {
				return $code;
			}
		}
		return '';
	}

	protected static function create_coupon( $template_id, $code ) {
		$new_id = wp_insert_post(
			array(
				'post_type'   => 'shop_coupon',
				'post_status' => 'publish',
				'post_title'  => $code,
			),
			true
		);

		if ( is_wp_error( $new_id ) || ! $new_id ) {
			return 0;
		}

		foreach ( self::NATIVE_META_KEYS as $key ) {
			$value = get_post_meta( $template_id, $key, true );
			if ( '' !== $value ) {
				update_post_meta( $new_id, $key, $value );
			}
		}

		foreach ( Epic_Adv_Coupons_Meta::all_keys() as $key ) {
			$value = get_post_meta( $template_id, $key, true );
			if ( '' !== $value ) {
				update_post_meta( $new_id, $key, $value );
			}
		}

		// Single-use, store-wide — forced regardless of the template's own setting.
		update_post_meta( $new_id, 'usage_limit', 1 );
		update_post_meta( $new_id, 'usage_limit_per_user', 1 );

		update_post_meta( $new_id, Epic_Adv_Coupons_Meta::GENERATED_FROM, $template_id );

		return $new_id;
	}
}
