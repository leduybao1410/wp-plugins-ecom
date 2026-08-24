<?php
/**
 * The moderation log a wp-admin user sees under WooCommerce → Product
 * Reviews — a standard WP_List_Table over the epic_product_reviews table
 * (class-store.php), so it looks and behaves like every other admin list
 * screen. Reviews arrive `pending`; the Approve row action is the gate that
 * publishes them to the site (and its Product JSON-LD). Unapprove pulls one
 * back off the site; Delete removes it permanently.
 *
 * Bulk moderation: the checkbox column + Approve / Unapprove / Delete
 * bulk dropdown apply the same operations to every checked review in one
 * submit (nonce-checked in process_bulk_action()), and the per-page
 * selector lets staff choose 10 / 25 / 50 / 100 rows — persisted per user.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Epic_Reviews_List_Table extends WP_List_Table {

	const PER_PAGE_DEFAULT  = 20;
	const PER_PAGE_OPTIONS  = array( 10, 25, 50, 100 );
	const PER_PAGE_META_KEY = 'epic_product_reviews_per_page';

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'epic_product_review',
				'plural'   => 'epic_product_reviews',
				'ajax'     => false,
			)
		);
	}

	/**
	 * `author` is listed FIRST deliberately, not just for readability — it
	 * must match get_primary_column_name() below (WP_List_Table's mobile
	 * responsive JS targets the primary column, and the row actions live in
	 * column_author()). Same reasoning as epic-wholesale-inquiries.
	 */
	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox" />',
			'author'     => __( 'Author', 'epic-product-reviews' ),
			'product'    => __( 'Product', 'epic-product-reviews' ),
			'rating'     => __( 'Rating', 'epic-product-reviews' ),
			'title'      => __( 'Title', 'epic-product-reviews' ),
			'content'    => __( 'Review', 'epic-product-reviews' ),
			'status'     => __( 'Status', 'epic-product-reviews' ),
			'created_at' => __( 'Submitted', 'epic-product-reviews' ),
		);
	}

	/** Must agree with the row actions' placement in column_author() — see get_columns()'s docblock. */
	protected function get_primary_column_name() {
		return 'author';
	}

	protected function get_sortable_columns() {
		return array(
			'created_at' => array( 'created_at', true ), // true = already sorted desc by default.
			'product'    => array( 'product_id', false ),
		);
	}

	/** The bulk dropdown rendered above and below the table (top name="action", bottom name="action2"). */
	public function get_bulk_actions() {
		return array(
			'approve'   => __( 'Approve', 'epic-product-reviews' ),
			'unapprove' => __( 'Unapprove', 'epic-product-reviews' ),
			'delete'    => __( 'Delete', 'epic-product-reviews' ),
		);
	}

	/** Checkbox column — feeds `review_ids[]` into the bulk form. */
	protected function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="review_ids[]" value="%d" />',
			(int) $item['id']
		);
	}

	public function get_views() {
		$all      = Epic_Reviews_Store::count();
		$pending  = Epic_Reviews_Store::count( Epic_Reviews_Store::STATUS_PENDING );
		$approved = Epic_Reviews_Store::count( Epic_Reviews_Store::STATUS_APPROVED );

		$current = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter view, no state change.

		$views = array(
			'all' => array( '', __( 'All', 'epic-product-reviews' ), $all ),
			'pending' => array( Epic_Reviews_Store::STATUS_PENDING, __( 'Pending', 'epic-product-reviews' ), $pending ),
			'approved' => array( Epic_Reviews_Store::STATUS_APPROVED, __( 'Approved', 'epic-product-reviews' ), $approved ),
		);

		$out = array();
		foreach ( $views as $key => $parts ) {
			list( $value, $label, $count ) = $parts;
			$url = add_query_arg(
				array(
					'page'   => 'epic-product-reviews',
					'status' => $value,
				),
				admin_url( 'admin.php' )
			);
			$class = ( ( '' === $current && '' === $value ) || $current === $value ) ? ' class="current"' : '';
			$out[ $key ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$d)</span></a>',
				esc_url( $url ),
				$class,
				esc_html( $label ),
				(int) $count
			);
		}

		return $out;
	}

	public function no_items() {
		esc_html_e( 'No reviews yet — submissions from the product detail pages will show up here for moderation.', 'epic-product-reviews' );
	}

	public function prepare_items() {
		$this->process_bulk_action();
		self::save_per_page_preference();

		$status_filter = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only sort/filter/pagination, not a state-changing request.
		$orderby       = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'created_at'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order         = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$per_page     = self::get_per_page_option();
		$current_page = $this->get_pagenum();
		$total_items  = Epic_Reviews_Store::count( $status_filter );

		$this->items = Epic_Reviews_Store::get_page(
			$per_page,
			( $current_page - 1 ) * $per_page,
			$status_filter,
			$orderby,
			$order
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / $per_page ),
			)
		);
	}

	/**
	 * Applies the bulk action picked from the top/bottom dropdown — Approve,
	 * Unapprove or Delete — to every checked review. Runs at the top of
	 * prepare_items(), before any output, so it can redirect afterward (same
	 * "a state change must not re-fire on refresh" reasoning as the per-row
	 * actions in class-settings.php::maybe_handle_action()).
	 */
	protected function process_bulk_action() {
		$action = $this->current_action();
		if ( ! in_array( $action, array( 'approve', 'unapprove', 'delete' ), true ) ) {
			return;
		}

		$ids = isset( $_GET['review_ids'] ) ? (array) wp_unslash( $_GET['review_ids'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce is checked via check_admin_referer() immediately below.
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( empty( $ids ) ) {
			return;
		}

		check_admin_referer( 'bulk-' . $this->_args['plural'] );

		if ( 'delete' === $action ) {
			foreach ( $ids as $id ) {
				Epic_Reviews_Store::delete( $id );
			}
		} else {
			$status = 'approve' === $action ? Epic_Reviews_Store::STATUS_APPROVED : Epic_Reviews_Store::STATUS_PENDING;
			foreach ( $ids as $id ) {
				Epic_Reviews_Store::set_status( $id, $status );
			}
		}

		$redirect = array(
			'page'    => 'epic-product-reviews',
			'updated' => '1',
			'status'  => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter value echoed back onto the redirect.
		);
		if ( isset( $_GET['paged'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination echoed back onto the redirect.
			$redirect['paged'] = max( 1, absint( wp_unslash( $_GET['paged'] ) ) );
		}

		wp_safe_redirect( add_query_arg( $redirect, admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Per-page selector (10/25/50/100) at the top of the table — submits with the same GET form. */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$current = self::get_per_page_option();
		?>
		<div class="alignleft actions">
			<label for="epic-reviews-per-page" class="screen-reader-text"><?php esc_html_e( 'Reviews per page', 'epic-product-reviews' ); ?></label>
			<select id="epic-reviews-per-page" name="per_page">
				<?php foreach ( self::PER_PAGE_OPTIONS as $option ) : ?>
					<option value="<?php echo esc_attr( (string) $option ); ?>" <?php selected( $current, $option ); ?>>
						<?php echo esc_html( (string) $option ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Apply', 'epic-product-reviews' ), 'small', 'apply_per_page', false ); ?>
		</div>
		<?php
	}

	/**
	 * The user's chosen per-page size, persisted per user so the preference
	 * sticks across visits. Falls back to PER_PAGE_DEFAULT until they pick one.
	 */
	public static function get_per_page_option() {
		$saved = (int) get_user_meta( get_current_user_id(), self::PER_PAGE_META_KEY, true );
		return in_array( $saved, self::PER_PAGE_OPTIONS, true ) ? $saved : self::PER_PAGE_DEFAULT;
	}

	/** Persists a per-page choice submitted with the list form — whitelisted values only. */
	public static function save_per_page_preference() {
		if ( ! isset( $_GET['per_page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only UI preference, no data change.
			return;
		}
		$per_page = absint( wp_unslash( $_GET['per_page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $per_page, self::PER_PAGE_OPTIONS, true ) ) {
			return;
		}
		update_user_meta( get_current_user_id(), self::PER_PAGE_META_KEY, $per_page );
	}

	/**
	 * First column: the author name plus explicit, always-visible action
	 * buttons (Approve / Unapprove / Delete). We deliberately do NOT use
	 * WP_List_Table::row_actions() here — across some WP versions/admin
	 * themes it collapses the pending-state actions to just Delete (likely
	 * via the row-actions CSS/JS or an event-scoping mismatch), so a
	 * pending review could never be approved from the list. Rendering the
	 * actions as plain links out of the box means moderation is always
	 * reachable, on every WP version, whether or not the row is hovered.
	 */
	protected function column_author( $item ) {
		$is_approved = Epic_Reviews_Store::STATUS_APPROVED === (string) $item['status'];

		$base = array(
			'page' => 'epic-product-reviews',
			'id'   => $item['id'],
		);

		$actions = array();
		if ( $is_approved ) {
			$actions[] = sprintf(
				'<a class="button button-small" href="%1$s">%2$s</a>',
				esc_url( wp_nonce_url( add_query_arg( $base + array( 'action' => 'unapprove' ), admin_url( 'admin.php' ) ), 'epic_reviews_unapprove_' . $item['id'] ) ),
				esc_html__( 'Unapprove', 'epic-product-reviews' )
			);
		} else {
			$actions[] = sprintf(
				'<a class="button button-small" href="%1$s">%2$s</a>',
				esc_url( wp_nonce_url( add_query_arg( $base + array( 'action' => 'approve' ), admin_url( 'admin.php' ) ), 'epic_reviews_approve_' . $item['id'] ) ),
				esc_html__( 'Approve', 'epic-product-reviews' )
			);
		}
		$actions[] = sprintf(
			'<a class="button button-small" href="%1$s" onclick="return confirm(%2$s);">%3$s</a>',
			esc_url( wp_nonce_url( add_query_arg( $base + array( 'action' => 'delete' ), admin_url( 'admin.php' ) ), 'epic_reviews_delete_' . $item['id'] ) ),
			wp_json_encode( __( 'Delete this review permanently? This cannot be undone.', 'epic-product-reviews' ) ),
			esc_html__( 'Delete', 'epic-product-reviews' )
		);

		return '<strong>' . esc_html( $item['author'] ) . '</strong><div style="margin-top:4px;">' . implode( ' ', $actions ) . '</div>';
	}

	/** Product name resolved from the WooCommerce product id (wc_get_product). */
	protected function column_product( $item ) {
		$product_id = (int) $item['product_id'];
		$product    = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
		$name       = $product ? $product->get_name() : '#' . $product_id;

		return '<a href="' . esc_url( get_edit_post_link( $product_id ) ) . '">' . esc_html( $name ) . '</a>';
	}

	/** 1–5 star glyphs, colored to match the site's orange star treatment. */
	protected function column_rating( $item ) {
		$rating = (int) $item['rating'];
		$stars  = str_repeat( '★', $rating ) . str_repeat( '☆', max( 0, 5 - $rating ) );
		return '<span style="color:#c2410c; letter-spacing:1px;">' . esc_html( $stars ) . '</span> <span style="color:#666;">' . esc_html( (string) $rating ) . '/5</span>';
	}

	protected function column_status( $item ) {
		$is_approved = Epic_Reviews_Store::STATUS_APPROVED === (string) $item['status'];
		$color       = $is_approved ? '#1a7f37' : '#8a6d3b';
		$label       = $is_approved ? __( 'Approved', 'epic-product-reviews' ) : __( 'Pending', 'epic-product-reviews' );

		return '<span style="color:' . esc_attr( $color ) . '; font-weight:600;">' . esc_html( $label ) . '</span>';
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'created_at':
				return esc_html( mysql2date( 'Y-m-d H:i', $item['created_at'] ) );
			case 'title':
				return '' !== (string) $item['title'] ? esc_html( $item['title'] ) : '<span style="color:#999;">&#8212;</span>';
			case 'content':
				$text = trim( (string) $item['content'] );
				if ( '' === $text ) {
					return '<span style="color:#999;">&#8212;</span>';
				}
				return nl2br( esc_html( $text ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html() already ran; nl2br() only adds <br> tags around it.
			default:
				return '';
		}
	}
}
