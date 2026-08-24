<?php
/**
 * WP_List_Table for the "Redemptions" admin screen. Only ever required
 * from Epic_Adv_Coupons_Redemption_Admin::render_page(), after that core
 * class is guaranteed loaded — see the require_once there.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Adv_Coupons_Redemption_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'redemption',
				'plural'   => 'redemptions',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'coupon_code'     => __( 'Coupon code', 'epic-advanced-coupons' ),
			'batch'           => __( 'Batch', 'epic-advanced-coupons' ),
			'order'           => __( 'Order', 'epic-advanced-coupons' ),
			'discount_amount' => __( 'Discount', 'epic-advanced-coupons' ),
			'order_total'     => __( 'Order total', 'epic-advanced-coupons' ),
			'billing_email'   => __( 'Email', 'epic-advanced-coupons' ),
			'billing_phone'   => __( 'Phone', 'epic-advanced-coupons' ),
			'status'          => __( 'Status', 'epic-advanced-coupons' ),
			'redeemed_at'     => __( 'Redeemed', 'epic-advanced-coupons' ),
		);
	}

	protected function get_sortable_columns() {
		return array(
			'coupon_code'     => array( 'coupon_code', false ),
			'discount_amount' => array( 'discount_amount', false ),
			'redeemed_at'     => array( 'redeemed_at', true ),
		);
	}

	protected function get_current_filters() {
		return array(
			'search'         => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filtering, no state change.
			'email'          => isset( $_REQUEST['epic_email'] ) ? sanitize_email( wp_unslash( $_REQUEST['epic_email'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'generated_from' => isset( $_REQUEST['epic_batch'] ) ? (int) $_REQUEST['epic_batch'] : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'status'         => isset( $_REQUEST['epic_status'] ) ? sanitize_key( $_REQUEST['epic_status'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'date_from'      => isset( $_REQUEST['epic_date_from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['epic_date_from'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'date_to'        => isset( $_REQUEST['epic_date_to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['epic_date_to'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'orderby'        => isset( $_REQUEST['orderby'] ) ? sanitize_key( $_REQUEST['orderby'] ) : 'redeemed_at', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'order'          => isset( $_REQUEST['order'] ) ? sanitize_key( $_REQUEST['order'] ) : 'desc', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);
	}

	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$filters  = $this->get_current_filters();
		$per_page = 20;
		$page     = $this->get_pagenum();

		$args = array_merge(
			$filters,
			array(
				'per_page' => $per_page,
				'page'     => $page,
			)
		);

		$this->items = Epic_Adv_Coupons_Redemption_Log::query( $args );
		$total_items = Epic_Adv_Coupons_Redemption_Log::count( $filters );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / $per_page ),
			)
		);
	}

	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$filters = $this->get_current_filters();
		$batches = Epic_Adv_Coupons_Redemption_Log::get_batches();
		?>
		<div class="alignleft actions">
			<select name="epic_batch">
				<option value=""><?php esc_html_e( 'All batches', 'epic-advanced-coupons' ); ?></option>
				<?php foreach ( $batches as $id => $label ) : ?>
					<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $filters['generated_from'], $id ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="epic_status">
				<option value=""><?php esc_html_e( 'All statuses', 'epic-advanced-coupons' ); ?></option>
				<option value="active" <?php selected( $filters['status'], 'active' ); ?>><?php esc_html_e( 'Active', 'epic-advanced-coupons' ); ?></option>
				<option value="removed" <?php selected( $filters['status'], 'removed' ); ?>><?php esc_html_e( 'Removed', 'epic-advanced-coupons' ); ?></option>
			</select>
			<input type="date" name="epic_date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>" placeholder="<?php esc_attr_e( 'From', 'epic-advanced-coupons' ); ?>" />
			<input type="date" name="epic_date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>" placeholder="<?php esc_attr_e( 'To', 'epic-advanced-coupons' ); ?>" />
			<input type="text" name="epic_email" value="<?php echo esc_attr( $filters['email'] ); ?>" placeholder="<?php esc_attr_e( 'Email', 'epic-advanced-coupons' ); ?>" />
			<?php submit_button( __( 'Filter', 'epic-advanced-coupons' ), '', 'filter_action', false ); ?>
			<?php
			$export_url = wp_nonce_url(
				add_query_arg( array_merge( $filters, array( 'action' => 'epic_export_redemptions' ) ), admin_url( 'admin-post.php' ) ),
				'epic_export_redemptions'
			);
			?>
			<a href="<?php echo esc_url( $export_url ); ?>" class="button"><?php esc_html_e( 'Export CSV', 'epic-advanced-coupons' ); ?></a>
		</div>
		<?php
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'coupon_code':
				return esc_html( $item['coupon_code'] );
			case 'batch':
				if ( empty( $item['generated_from'] ) ) {
					return '—';
				}
				$title = get_the_title( $item['generated_from'] );
				return $title ? esc_html( $title ) : sprintf( '#%d', (int) $item['generated_from'] );
			case 'order':
				$edit_link = get_edit_post_link( $item['order_id'] );
				$number    = $item['order_number'] ? $item['order_number'] : $item['order_id'];
				return $edit_link
					? sprintf( '<a href="%s">#%s</a>', esc_url( $edit_link ), esc_html( $number ) )
					: sprintf( '#%s', esc_html( $number ) );
			case 'discount_amount':
				return wc_price( $item['discount_amount'] );
			case 'order_total':
				return null === $item['order_total'] ? '—' : wc_price( $item['order_total'] );
			case 'billing_email':
				return esc_html( $item['billing_email'] );
			case 'billing_phone':
				return esc_html( $item['billing_phone'] );
			case 'status':
				return 'active' === $item['status']
					? '<span style="color:#2a7a2a;">' . esc_html__( 'Active', 'epic-advanced-coupons' ) . '</span>'
					: '<span style="color:#999;">' . esc_html__( 'Removed', 'epic-advanced-coupons' ) . '</span>';
			case 'redeemed_at':
				return esc_html( get_date_from_gmt( $item['redeemed_at'], 'Y-m-d H:i' ) );
			default:
				return '';
		}
	}
}
