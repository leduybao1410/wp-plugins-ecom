<?php
/**
 * The actual "log" a wp-admin user sees under WooCommerce → Sample
 * Requests — a standard WP_List_Table over the epic_sample_requests table
 * (class-store.php), so it looks and behaves like every other admin list
 * screen (sortable columns, pagination).
 *
 * Read-only except for a per-row Delete action — there's no "edit" concept
 * for a submitted request, and no bulk actions (kept deliberately simple).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Epic_Sample_List_Table extends WP_List_Table {

	const PER_PAGE = 20;

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'epic_sample_request',
				'plural'   => 'epic_sample_requests',
				'ajax'     => false,
			)
		);
	}

	/**
	 * `name` is listed FIRST deliberately — it must match
	 * get_primary_column_name() below, which is where the Delete row action
	 * lives. See epic-wholesale-inquiries' list table for the layout bug a
	 * mismatch causes.
	 */
	public function get_columns() {
		return array(
			'name'           => __( 'Name', 'epic-sample-requests' ),
			'submitted_at'   => __( 'Submitted', 'epic-sample-requests' ),
			'phone'          => __( 'Phone', 'epic-sample-requests' ),
			'email'          => __( 'Email', 'epic-sample-requests' ),
			'address'        => __( 'Address', 'epic-sample-requests' ),
			'taste_label_vi' => __( 'Taste', 'epic-sample-requests' ),
			'brew_label_vi'  => __( 'Brew', 'epic-sample-requests' ),
			'email_status'   => __( 'Admin email', 'epic-sample-requests' ),
			'confirm_status' => __( 'Thank-you', 'epic-sample-requests' ),
		);
	}

	/** Must agree with the Delete row action's placement in column_name(). */
	protected function get_primary_column_name() {
		return 'name';
	}

	protected function get_sortable_columns() {
		return array(
			'submitted_at' => array( 'submitted_at', true ), // true = already sorted desc by default.
			'name'         => array( 'name', false ),
		);
	}

	public function no_items() {
		esc_html_e( 'No sample requests yet — submissions from the /roastery page will show up here.', 'epic-sample-requests' );
	}

	public function prepare_items() {
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'submitted_at'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only sort/pagination, not a state-changing request.
		$order   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$current_page = $this->get_pagenum();
		$total_items  = Epic_Sample_Store::count();

		$this->items = Epic_Sample_Store::get_page(
			self::PER_PAGE,
			( $current_page - 1 ) * self::PER_PAGE,
			$orderby,
			$order
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) ceil( $total_items / self::PER_PAGE ),
			)
		);
	}

	/** First column gets the row actions (Delete) — WP_List_Table convention. */
	protected function column_name( $item ) {
		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'   => 'epic-sample-requests',
					'action' => 'delete',
					'id'     => $item['id'],
				),
				admin_url( 'admin.php' )
			),
			'epic_sample_delete_' . $item['id']
		);

		$actions = array(
			'delete' => sprintf(
				'<a href="%1$s" onclick="return confirm(%2$s);">%3$s</a>',
				esc_url( $delete_url ),
				wp_json_encode( __( 'Delete this sample request permanently? This cannot be undone.', 'epic-sample-requests' ) ),
				esc_html__( 'Delete', 'epic-sample-requests' )
			),
		);

		return '<strong>' . esc_html( $item['name'] ) . '</strong>' . $this->row_actions( $actions );
	}

	protected function column_address( $item ) {
		$text = trim( (string) $item['address'] );
		if ( '' === $text ) {
			return '<span style="color:#999;">&#8212;</span>';
		}
		return esc_html( $text );
	}

	protected function column_email( $item ) {
		$text = trim( (string) ( $item['email'] ?? '' ) );
		if ( '' === $text ) {
			return '<span style="color:#999;">&#8212;</span>';
		}
		return '<a href="mailto:' . esc_attr( $text ) . '">' . esc_html( $text ) . '</a>';
	}

	protected function column_email_status( $item ) {
		$status = isset( $item['email_status'] ) ? (string) $item['email_status'] : Epic_Sample_Store::STATUS_PENDING;
		return $this->status_badge( $status );
	}

	protected function column_confirm_status( $item ) {
		$status = isset( $item['confirm_status'] ) ? (string) $item['confirm_status'] : Epic_Sample_Store::STATUS_PENDING;
		return $this->status_badge( $status );
	}

	/** Shared colored badge for the two email-status columns. */
	private function status_badge( $status ) {
		$badges = array(
			Epic_Sample_Store::STATUS_SENT     => array( '#1a7f37', __( 'Sent', 'epic-sample-requests' ) ),
			Epic_Sample_Store::STATUS_FAILED   => array( '#c0392b', __( 'Failed', 'epic-sample-requests' ) ),
			Epic_Sample_Store::STATUS_DISABLED => array( '#8a6d3b', __( 'Email disabled', 'epic-sample-requests' ) ),
			Epic_Sample_Store::STATUS_PENDING  => array( '#666666', __( 'Pending', 'epic-sample-requests' ) ),
			Epic_Sample_Store::STATUS_SKIPPED  => array( '#666666', __( 'No email', 'epic-sample-requests' ) ),
		);
		list( $color, $label ) = $badges[ $status ] ?? array( '#666666', ucfirst( (string) $status ) );

		return '<span style="color:' . esc_attr( $color ) . '; font-weight:600;">' . esc_html( $label ) . '</span>';
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'submitted_at':
				return esc_html( mysql2date( 'Y-m-d H:i', $item['submitted_at'] ) );
			case 'phone':
			case 'taste_label_vi':
			case 'brew_label_vi':
				return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
			default:
				return '';
		}
	}
}
