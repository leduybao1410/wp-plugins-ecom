<?php
/**
 * WooCommerce → Distributors list table.
 *
 * @package Epic_Distributor_Profit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Epic_Distributor_Profit_Distributors_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'distributor',
				'plural'   => 'distributors',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'name'               => __( 'Name', 'epic-distributor-profit' ),
			'contact'            => __( 'Contact', 'epic-distributor-profit' ),
			'commission_percent' => __( 'Commission %', 'epic-distributor-profit' ),
			'active'             => __( 'Active', 'epic-distributor-profit' ),
			'entries'            => __( 'Entries', 'epic-distributor-profit' ),
		);
	}

	public function get_primary_column_name() {
		return 'name';
	}

	protected function get_row_actions( $item ) {
		$edit_url = add_query_arg(
			array(
				'page' => 'epic-distributor-profit-distributors',
				'edit' => $item->id,
			),
			admin_url( 'admin.php' )
		);

		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'   => 'epic-distributor-profit-distributors',
					'action' => 'delete',
					'id'     => $item->id,
				),
				admin_url( 'admin.php' )
			),
			'epic_dp_delete_distributor_' . $item->id
		);

		$actions = array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'epic-distributor-profit' ) ),
			'delete' => sprintf(
				'<a href="%s" onclick="return confirm(\'%s\');">%s</a>',
				esc_url( $delete_url ),
				esc_js( __( 'Delete this distributor? Existing ledger entries keep their history but will show a blank distributor.', 'epic-distributor-profit' ) ),
				esc_html__( 'Delete', 'epic-distributor-profit' )
			),
		);

		return $this->row_actions( $actions );
	}

	public function column_name( $item ) {
		return '<strong>' . esc_html( $item->name ) . '</strong>' . $this->get_row_actions( $item );
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'contact':
				return esc_html( $item->contact );
			case 'commission_percent':
				return esc_html( number_format( (float) $item->commission_percent, 2 ) . '%' );
			case 'active':
				return $item->active
					? '<span style="color:#1a7f37;">' . esc_html__( 'Active', 'epic-distributor-profit' ) . '</span>'
					: '<span style="color:#8a8a8a;">' . esc_html__( 'Inactive', 'epic-distributor-profit' ) . '</span>';
			case 'entries':
				return esc_html( (string) $item->entry_count );
			default:
				return '';
		}
	}

	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		global $wpdb;
		$distributors_table = Epic_Distributor_Profit_Store::distributors_table();
		$entries_table       = Epic_Distributor_Profit_Store::entries_table();

		$this->items = $wpdb->get_results(
			"SELECT d.*, COUNT(e.id) AS entry_count
			FROM {$distributors_table} d
			LEFT JOIN {$entries_table} e ON e.distributor_id = d.id
			GROUP BY d.id
			ORDER BY d.name ASC"
		); // phpcs:ignore
	}
}
