<?php
/**
 * The order log a wp-admin user sees under WooCommerce → Wholesale Orders — a
 * standard WP_List_Table over the epic_wholesale_order CPT (class-store.php),
 * so it behaves like every other admin list screen (pagination, sorting) with
 * filters for order status and payment status. Read-only except for a per-row
 * Delete action; editing happens on the CPT's own edit screen (class-meta-box.php).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Epic_Wholesale_Orders_List_Table extends WP_List_Table {

	const PER_PAGE = 20;

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'epic_wholesale_order',
				'plural'   => 'epic_wholesale_orders',
				'ajax'     => false,
			)
		);
	}

	/**
	 * `order_ref` is listed FIRST and must match get_primary_column_name() —
	 * WP_List_Table's responsive JS targets the primary column, and the
	 * per-row Edit/Delete actions live in column_order_ref(). See
	 * epic-wholesale-inquiries' class-list-table.php for the same reasoning.
	 */
	public function get_columns() {
		return array(
			'order_ref'  => __( 'Order', 'epic-wholesale-orders' ),
			'date'       => __( 'Date', 'epic-wholesale-orders' ),
			'customer'   => __( 'Customer', 'epic-wholesale-orders' ),
			'level'      => __( 'Level', 'epic-wholesale-orders' ),
			'items'      => __( 'Items', 'epic-wholesale-orders' ),
			'total'      => __( 'Total', 'epic-wholesale-orders' ),
			'payment'    => __( 'Payment', 'epic-wholesale-orders' ),
			'status'     => __( 'Status', 'epic-wholesale-orders' ),
			'emails'     => __( 'Emails', 'epic-wholesale-orders' ),
		);
	}

	protected function get_primary_column_name() {
		return 'order_ref';
	}

	protected function get_sortable_columns() {
		return array(
			'date' => array( 'date', true ), // true = already sorted desc by default.
		);
	}

	public function no_items() {
		esc_html_e( 'No wholesale orders yet.', 'epic-wholesale-orders' );
	}

	public function prepare_items() {
		$order_status  = isset( $_GET['epic_wo_status'] ) ? sanitize_key( wp_unslash( $_GET['epic_wo_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$payment_status = isset( $_GET['epic_wo_payment'] ) ? sanitize_key( wp_unslash( $_GET['epic_wo_payment'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$args = array(
			'post_type'      => Epic_Wholesale_Orders_Store::POST_TYPE,
			'post_status'    => array(
				Epic_Wholesale_Orders_Store::STATUS_PENDING,
				Epic_Wholesale_Orders_Store::STATUS_DONE,
				Epic_Wholesale_Orders_Store::STATUS_CANCELLED,
			),
			'posts_per_page' => self::PER_PAGE,
			'paged'          => $this->get_pagenum(),
		);

		if ( in_array( $order_status, array_keys( Epic_Wholesale_Orders_Store::order_statuses() ), true ) ) {
			$args['post_status'] = $order_status;
		}

		$valid_payments = Epic_Wholesale_Orders_Store::PAYMENT_STATUSES;
		if ( in_array( $payment_status, $valid_payments, true ) ) {
			$args['meta_query'][] = array(
				'key'   => Epic_Wholesale_Orders_Store::META_PAYMENT_STATUS,
				'value' => $payment_status,
			);
		}

		$result = Epic_Wholesale_Orders_Store::query_orders( $args );

		$this->items = $result['items'];

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) ceil( $result['total'] / self::PER_PAGE ),
			)
		);
	}

	/** First column holds the row actions (Edit / Delete) — WP_List_Table convention. */
	protected function column_order_ref( $item ) {
		$edit_url   = get_edit_post_link( $item['id'] );
		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'   => 'epic-wholesale-orders',
					'action' => 'delete',
					'id'     => $item['id'],
				),
				admin_url( 'admin.php' )
			),
			'epic_wholesale_order_delete_' . $item['id']
		);

		$actions = array(
			'edit' => sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $edit_url ),
				esc_html__( 'View / Edit', 'epic-wholesale-orders' )
			),
			'delete' => sprintf(
				'<a href="%1$s" onclick="return confirm(%2$s);">%3$s</a>',
				esc_url( $delete_url ),
				wp_json_encode( __( 'Delete this wholesale order permanently? This cannot be undone.', 'epic-wholesale-orders' ) ),
				esc_html__( 'Delete', 'epic-wholesale-orders' )
			),
		);

		return '<strong><a href="' . esc_url( $edit_url ) . '">' . esc_html( '#' . $item['order_number'] ) . '</a></strong>' . $this->row_actions( $actions );
	}

	protected function column_customer( $item ) {
		$name = $item['customer_name'] ? $item['customer_name'] : (string) $item['customer_user_id'];
		$html = '<strong>' . esc_html( $name ) . '</strong>';
		if ( $item['customer_email'] ) {
			$html .= '<br/><a href="mailto:' . esc_attr( $item['customer_email'] ) . '">' . esc_html( $item['customer_email'] ) . '</a>';
		}
		return $html;
	}

	protected function column_level( $item ) {
		$level = $item['level_name'];
		if ( ! $level && $item['customer_user_id'] ) {
			$level = Epic_Wholesale_Orders_Store::get_level( Epic_Wholesale_Orders_Store::get_customer_level( $item['customer_user_id'] ) )['name'] ?? '';
		}
		return $level ? esc_html( $level ) : '<span style="color:#999;">&#8212;</span>';
	}

	protected function column_items( $item ) {
		$count = count( $item['items'] );
		if ( 0 === $count ) {
			return '<span style="color:#999;">&#8212;</span>';
		}
		$qty = array_sum( wp_list_pluck( $item['items'], 'quantity' ) );
		/* translators: 1: line count, 2: total quantity */
		return esc_html( sprintf( _n( '%1$d line, %2$d pcs', '%1$d lines, %2$d pcs', $count, 'epic-wholesale-orders' ), $count, $qty ) );
	}

	protected function column_total( $item ) {
		return function_exists( 'wc_price' ) ? wp_kses_post( wc_price( $item['total'] ) ) : esc_html( number_format_i18n( $item['total'], 2 ) );
	}

	protected function column_payment( $item ) {
		return $this->badge( $item['payment_status'], Epic_Wholesale_Orders_Store::payment_statuses() );
	}

	protected function column_status( $item ) {
		return $this->badge( $item['order_status'], Epic_Wholesale_Orders_Store::order_statuses() );
	}

	protected function column_emails( $item ) {
		$labels = array(
			'pending'  => array( '#666666', __( 'Pending', 'epic-wholesale-orders' ) ),
			'sent'     => array( '#1a7f37', __( 'Sent', 'epic-wholesale-orders' ) ),
			'failed'   => array( '#c0392b', __( 'Failed', 'epic-wholesale-orders' ) ),
			'disabled' => array( '#8a6d3b', __( 'Disabled', 'epic-wholesale-orders' ) ),
		);
		$admin    = $labels[ $item['admin_email_status'] ] ?? $labels['pending'];
		$customer = $labels[ $item['customer_email_status'] ] ?? $labels['pending'];

		return '<span style="color:' . esc_attr( $admin[0] ) . '; font-weight:600;">' . esc_html__( 'Admin', 'epic-wholesale-orders' ) . ': ' . esc_html( $admin[1] ) . '</span><br/>'
			. '<span style="color:' . esc_attr( $customer[0] ) . '; font-weight:600;">' . esc_html__( 'Customer', 'epic-wholesale-orders' ) . ': ' . esc_html( $customer[1] ) . '</span>';
	}

	public function column_default( $item, $column_name ) {
		if ( 'date' === $column_name ) {
			return esc_html( mysql2date( 'Y-m-d H:i', $item['date_created'] ) );
		}
		return '';
	}

	/** The status filter dropdowns, rendered above the table. */
	public function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$selected_status  = isset( $_GET['epic_wo_status'] ) ? sanitize_key( wp_unslash( $_GET['epic_wo_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$selected_payment = isset( $_GET['epic_wo_payment'] ) ? sanitize_key( wp_unslash( $_GET['epic_wo_payment'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="alignleft actions">
			<select name="epic_wo_status">
				<option value=""><?php esc_html_e( 'All order statuses', 'epic-wholesale-orders' ); ?></option>
				<?php foreach ( Epic_Wholesale_Orders_Store::order_statuses() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $selected_status, $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="epic_wo_payment">
				<option value=""><?php esc_html_e( 'All payment statuses', 'epic-wholesale-orders' ); ?></option>
				<?php foreach ( Epic_Wholesale_Orders_Store::payment_statuses() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $selected_payment, $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'epic-wholesale-orders' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	private function badge( $value, array $map ) {
		$colors = array(
			Epic_Wholesale_Orders_Store::STATUS_PENDING => '#666666',
			Epic_Wholesale_Orders_Store::STATUS_DONE    => '#1a7f37',
			Epic_Wholesale_Orders_Store::STATUS_CANCELLED => '#c0392b',
			Epic_Wholesale_Orders_Store::PAYMENT_WAITING_FOR_PAYMENT => '#b45309',
			Epic_Wholesale_Orders_Store::PAYMENT_PAID                => '#1a7f37',
			Epic_Wholesale_Orders_Store::PAYMENT_PENDING             => '#666666',
			Epic_Wholesale_Orders_Store::PAYMENT_CANCELED            => '#c0392b',
		);
		$label = isset( $map[ $value ] ) ? $map[ $value ] : $value;
		$color = isset( $colors[ $value ] ) ? $colors[ $value ] : '#666666';
		return '<span style="color:' . esc_attr( $color ) . '; font-weight:600;">' . esc_html( $label ) . '</span>';
	}
}
