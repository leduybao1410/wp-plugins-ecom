<?php
/**
 * Registers post meta linking a journal/news post (post_type 'post') to
 * WooCommerce product data, and renders the "Linked Coffee" meta box on the
 * post editor where an admin sets it.
 *
 * Three independent, optional fields — an admin can set any combination:
 *  - epic_linked_product_id       (int)      A specific product this post is
 *                                              about (e.g. an origin
 *                                              spotlight article about one
 *                                              exact bean).
 *  - epic_linked_product_category (int)      A WooCommerce product category
 *                                              term ID this post relates to
 *                                              (e.g. "Cà Phê Blend").
 *  - epic_linked_product_tags     (string[]) WooCommerce product tag slugs
 *                                              this post relates to (e.g. a
 *                                              flavor note, or a tier tag
 *                                              like "signature"/"decaf").
 *
 * All three are registered with `show_in_rest => true` so they come back in
 * `meta` on every `GET /wp-json/wp/v2/posts` (and single-post) response —
 * the Next.js website's src/lib/news.ts reads them straight off that field
 * to build the article page's related-coffee sidebar (see
 * getRelatedProductsForPost() in src/lib/products.ts). REST *write* access
 * is intentionally left off (`auth_callback => '__return_false'`) — this
 * data is only ever set from the post editor's meta box below, through the
 * normal $_POST + nonce save flow, never over the API.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_News_Product_Link_Meta_Box {

	const META_PRODUCT_ID       = 'epic_linked_product_id';
	const META_PRODUCT_CATEGORY = 'epic_linked_product_category';
	const META_PRODUCT_TAGS     = 'epic_linked_product_tags';
	const NONCE_ACTION          = 'epic_news_product_link_save';
	const NONCE_NAME            = 'epic_news_product_link_nonce';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
		add_action( 'add_meta_boxes_post', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_post', array( __CLASS__, 'save' ) );
	}

	public static function register_meta() {
		register_post_meta(
			'post',
			self::META_PRODUCT_ID,
			array(
				'type'          => 'integer',
				'single'        => true,
				'default'       => 0,
				'show_in_rest'  => true,
				'auth_callback' => '__return_false',
			)
		);

		register_post_meta(
			'post',
			self::META_PRODUCT_CATEGORY,
			array(
				'type'          => 'integer',
				'single'        => true,
				'default'       => 0,
				'show_in_rest'  => true,
				'auth_callback' => '__return_false',
			)
		);

		register_post_meta(
			'post',
			self::META_PRODUCT_TAGS,
			array(
				'type'          => 'array',
				'single'        => true,
				'default'       => array(),
				'show_in_rest'  => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
				'auth_callback' => '__return_false',
			)
		);
	}

	public static function add_meta_box() {
		add_meta_box(
			'epic_news_product_link',
			__( 'Linked Coffee (EPIC)', 'epic-news-product-link' ),
			array( __CLASS__, 'render' ),
			'post',
			'side',
			'default'
		);
	}

	/**
	 * All three fields are optional and independent — see the class doc
	 * comment above. The Next.js side (getRelatedProductsForPost()) shows
	 * the specific linked product first when set, then fills in with
	 * products from the linked category and/or tags, then falls back to the
	 * site's featured picks if nothing was linked at all.
	 */
	public static function render( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		if ( ! post_type_exists( 'product' ) || ! taxonomy_exists( 'product_cat' ) || ! taxonomy_exists( 'product_tag' ) ) {
			echo '<p>' . esc_html__( "WooCommerce product data isn't available.", 'epic-news-product-link' ) . '</p>';
			return;
		}

		$linked_product_id = (int) get_post_meta( $post->ID, self::META_PRODUCT_ID, true );
		$linked_category   = (int) get_post_meta( $post->ID, self::META_PRODUCT_CATEGORY, true );
		$linked_tags       = (array) get_post_meta( $post->ID, self::META_PRODUCT_TAGS, true );

		$products = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$categories = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);

		$tags = get_terms(
			array(
				'taxonomy'   => 'product_tag',
				'hide_empty' => false,
			)
		);
		?>
		<p>
			<label for="epic_linked_product_id"><strong><?php esc_html_e( 'Product', 'epic-news-product-link' ); ?></strong></label><br />
			<select id="epic_linked_product_id" name="epic_linked_product_id" style="width:100%;">
				<option value="0"><?php esc_html_e( '— None —', 'epic-news-product-link' ); ?></option>
				<?php foreach ( $products as $product ) : ?>
					<option value="<?php echo esc_attr( $product->ID ); ?>" <?php selected( $linked_product_id, $product->ID ); ?>>
						<?php echo esc_html( $product->post_title ); ?>
					</option>
				<?php endforeach; ?>
			</select><br />
			<span class="description"><?php esc_html_e( 'The exact coffee this post is about (e.g. an origin spotlight).', 'epic-news-product-link' ); ?></span>
		</p>

		<p>
			<label for="epic_linked_product_category"><strong><?php esc_html_e( 'Product category', 'epic-news-product-link' ); ?></strong></label><br />
			<select id="epic_linked_product_category" name="epic_linked_product_category" style="width:100%;">
				<option value="0"><?php esc_html_e( '— None —', 'epic-news-product-link' ); ?></option>
				<?php if ( ! is_wp_error( $categories ) ) : ?>
					<?php foreach ( $categories as $term ) : ?>
						<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( $linked_category, $term->term_id ); ?>>
							<?php echo esc_html( $term->name ); ?>
						</option>
					<?php endforeach; ?>
				<?php endif; ?>
			</select><br />
			<span class="description"><?php esc_html_e( 'Show coffees from this category alongside/instead of the specific product above.', 'epic-news-product-link' ); ?></span>
		</p>

		<p>
			<label for="epic_linked_product_tags"><strong><?php esc_html_e( 'Product tags', 'epic-news-product-link' ); ?></strong></label><br />
			<select id="epic_linked_product_tags" name="epic_linked_product_tags[]" multiple="multiple" size="6" style="width:100%;">
				<?php if ( ! is_wp_error( $tags ) ) : ?>
					<?php foreach ( $tags as $term ) : ?>
						<option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( in_array( $term->slug, $linked_tags, true ), true ); ?>>
							<?php echo esc_html( $term->name ); ?>
						</option>
					<?php endforeach; ?>
				<?php endif; ?>
			</select><br />
			<span class="description"><?php esc_html_e( 'Ctrl/Cmd-click to select more than one. Coffees carrying any of these tags are also matched.', 'epic-news-product-link' ); ?></span>
		</p>
		<?php
	}

	public static function save( $post_id ) {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$product_id = isset( $_POST['epic_linked_product_id'] ) ? (int) $_POST['epic_linked_product_id'] : 0;
		update_post_meta( $post_id, self::META_PRODUCT_ID, $product_id );

		$category_id = isset( $_POST['epic_linked_product_category'] ) ? (int) $_POST['epic_linked_product_category'] : 0;
		update_post_meta( $post_id, self::META_PRODUCT_CATEGORY, $category_id );

		$tags = array();
		if ( isset( $_POST['epic_linked_product_tags'] ) && is_array( $_POST['epic_linked_product_tags'] ) ) {
			$tags = array_values( array_unique( array_map( 'sanitize_title', wp_unslash( $_POST['epic_linked_product_tags'] ) ) ) );
		}
		update_post_meta( $post_id, self::META_PRODUCT_TAGS, $tags );
	}
}
