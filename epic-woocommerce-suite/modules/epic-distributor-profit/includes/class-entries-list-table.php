<?php
/**
 * WooCommerce → Distributor Profit list table (the ledger).
 *
 * @package Epic_Distributor_Profit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Epic_Distributor_Profit_Entries_List_Table extends WP_List_Table {

	/** @var array Filters resolved from the current request, shared with the exporter. */
	public $filter_args = array();

	private $per_page = 20;

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'entry',
				'plural'   => 'entries',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'entry_date'         => __( 'Date', 'epic-distributor-profit' ),
			'order_code'         => __( 'Order', 'epic-distributor-profit' ),
			'product_name'       => __( 'Product', 'epic-distributor-profit' ),
			'channel'            => __( 'Channel', 'epic-distributor-profit' ),
			'quantity'           => __( 'Qty', 'epic-distributor-profit' ),
			'revenue'            => __( 'Revenue', 'epic-distributor-profit' ),
			'cost'               => __( 'Cost', 'epic-distributor-profit' ),
			'shipping_cost'      => __( 'Shipping', 'epic-distributor-profit' ),
			'gross_profit'       => __( 'Gross Profit', 'epic-distributor-profit' ),
			'distributor_name'   => __( 'Distributor', 'epic-distributor-profit' ),
			'commission_amount'  => __( 'Commission', 'epic-distributor-profit' ),
			'net_profit'         => __( 'Net Profit', 'epic-distributor-profit' ),
		);
	}

	public function get_sortable_columns() {
		return array(
			'entry_date'        => array( 'entry_date', true ),
			'revenue'           => array( 'revenue', false ),
			'gross_profit'      => array( 'gross_profit', false ),
			'commission_amount' => array( 'commission_amount', false ),
			'net_profit'        => array( 'net_profit', false ),
		);
	}

	public function get_primary_column_name() {
		return 'entry_date';
	}

	private function money( $value ) {
		return number_format( (float) $value, 0, ',', '.' );
	}

	protected function get_row_actions( $item ) {
		$edit_url = add_query_arg(
			array_merge( $this->filter_args_for_url(), array( 'edit' => $item->id ) ),
			admin_url( 'admin.php' )
		);
		$delete_url = wp_nonce_url(
			add_query_arg(
				array_merge( $this->filter_args_for_url(), array( 'action' => 'delete', 'id' => $item->id ) ),
				admin_url( 'admin.php' )
			),
			'epic_dp_delete_entry_' . $item->id
		);

		$actions = array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'epic-distributor-profit' ) ),
			'delete' => sprintf(
				'<a href="%s" onclick="return confirm(\'%s\');">%s</a>',
				esc_url( $delete_url ),
				esc_js( __( 'Delete this entry?', 'epic-distributor-profit' ) ),
				esc_html__( 'Delete', 'epic-distributor-profit' )
			),
		);
		return $this->row_actions( $actions );
	}

	private function filter_args_for_url() {
		return array(
			'page'           => 'epic-distributor-profit',
			'date_from'      => $this->filter_args['date_from'] ?? '',
			'date_to'        => $this->filter_args['date_to'] ?? '',
			'distributor_id' => $this->filter_args['distributor_id'] ?? '',
			'channel'        => $this->filter_args['channel'] ?? '',
		);
	}

	public function column_entry_date( $item ) {
		return esc_html( $item->entry_date ) . $this->get_row_actions( $item );
	}

	public function column_order_code( $item ) {
		$label = $item->order_code ? $item->order_code : ( $item->wc_order_id ? '#' . $item->wc_order_id : '—' );
		$out   = esc_html( $label );

		if ( $item->wc_order_id ) {
			$order_edit_url = admin_url( 'post.php?post=' . (int) $item->wc_order_id . '&action=edit' );
			if ( function_exists( 'wc_get_order' ) ) {
				$order = wc_get_order( $item->wc_order_id );
				if ( $order && is_callable( array( $order, 'get_edit_order_url' ) ) ) {
					$order_edit_url = $order->get_edit_order_url();
				}
			}
			$out .= ' <a class="button button-small" href="' . esc_url( $order_edit_url ) . '" target="_blank" rel="noopener">' .
				esc_html__( 'View Order', 'epic-distributor-profit' ) . ' &#8599;</a>';
		}

		return $out;
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'product_name':
				return esc_html( $item->product_name );
			case 'channel':
				return esc_html( $item->channel );
			case 'quantity':
				return null === $item->quantity ? '—' : esc_html( (string) (int) $item->quantity );
			case 'revenue':
				return esc_html( $this->money( $item->revenue ) );
			case 'cost':
				return esc_html( $this->money( $item->cost ) );
			case 'shipping_cost':
				return esc_html( $this->money( $item->shipping_cost ) );
			case 'gross_profit':
				return esc_html( $this->money( $item->gross_profit ) );
			case 'distributor_name':
				return esc_html( $item->distributor_name ? $item->distributor_name : '—' );
			case 'commission_amount':
				return esc_html( $this->money( $item->commission_amount ) . ' (' . number_format( (float) $item->commission_percent_snapshot, 1 ) . '%)' );
			case 'net_profit':
				return esc_html( $this->money( $item->net_profit ) );
			default:
				return '';
		}
	}

	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$this->filter_args = array(
			'date_from'      => isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '',
			'date_to'        => isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '',
			'distributor_id' => isset( $_GET['distributor_id'] ) ? (int) $_GET['distributor_id'] : 0,
			'channel'        => isset( $_GET['channel'] ) ? sanitize_text_field( wp_unslash( $_GET['channel'] ) ) : '',
			'orderby'        => isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'entry_date',
			'order'          => isset( $_GET['order'] ) ? sanitize_key( $_GET['order'] ) : 'desc',
		);

		$current_page = $this->get_pagenum();
		$total_items  = Epic_Distributor_Profit_Store::count_entries( $this->filter_args );

		$query_args             = $this->filter_args;
		$query_args['per_page'] = $this->per_page;
		$query_args['page']     = $current_page;

		$this->items = Epic_Distributor_Profit_Store::query_entries( $query_args );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $this->per_page,
				'total_pages' => ceil( $total_items / $this->per_page ),
			)
		);
	}
}
