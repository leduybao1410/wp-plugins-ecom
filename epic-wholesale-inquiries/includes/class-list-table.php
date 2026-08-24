<?php
/**
 * The actual "log" a wp-admin user sees under WooCommerce → Wholesale
 * Inquiries — a standard WP_List_Table over the epic_wholesale_inquiries
 * table (class-store.php), so it looks and behaves like every other admin
 * list screen (sortable columns, pagination) rather than a bespoke table.
 *
 * Read-only except for a per-row Delete action — there's no "edit" concept
 * for a submitted inquiry, and no bulk actions (kept deliberately simple;
 * add a checkbox column + bulk handler here later if that's ever needed).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Epic_Wholesale_List_Table extends WP_List_Table {

	const PER_PAGE = 20;

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'epic_wholesale_inquiry',
				'plural'   => 'epic_wholesale_inquiries',
				'ajax'     => false,
			)
		);
	}

	/**
	 * `business_name` is listed FIRST deliberately, not just for readability
	 * — it must match get_primary_column_name() below. WP_List_Table's own
	 * mobile/responsive JS+CSS (the "toggle-row" expand button, and the
	 * hover-reveal behavior for row_actions()) targets whichever column it
	 * thinks is primary, which defaults to the first non-checkbox column
	 * here if get_primary_column_name() didn't exist. Since the Delete row
	 * action lives in column_business_name() below, primary MUST be
	 * 'business_name' — a mismatch there is what caused the previous
	 * layout (columns overlapping) at narrow/mobile widths.
	 */
	public function get_columns() {
		return array(
			'business_name'  => __( 'Business', 'epic-wholesale-inquiries' ),
			'submitted_at'   => __( 'Submitted', 'epic-wholesale-inquiries' ),
			'phone'          => __( 'Phone', 'epic-wholesale-inquiries' ),
			'contact'        => __( 'Contact', 'epic-wholesale-inquiries' ),
			'topic_label_vi' => __( 'Topic', 'epic-wholesale-inquiries' ),
			'details'        => __( 'Message', 'epic-wholesale-inquiries' ),
			'email_status'   => __( 'Email', 'epic-wholesale-inquiries' ),
		);
	}

	/** Must agree with the Delete row action's placement in column_business_name() — see get_columns()'s docblock. */
	protected function get_primary_column_name() {
		return 'business_name';
	}

	protected function get_sortable_columns() {
		return array(
			'submitted_at'  => array( 'submitted_at', true ), // true = already sorted desc by default.
			'business_name' => array( 'business_name', false ),
		);
	}

	public function no_items() {
		esc_html_e( 'No wholesale inquiries yet — submissions from the /wholesale page will show up here.', 'epic-wholesale-inquiries' );
	}

	public function prepare_items() {
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'submitted_at'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only sort/pagination, not a state-changing request.
		$order   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$current_page = $this->get_pagenum();
		$total_items  = Epic_Wholesale_Store::count();

		$this->items = Epic_Wholesale_Store::get_page(
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

	/** First column gets the row actions (Delete) — WP_List_Table convention, same as Posts/Users. */
	protected function column_business_name( $item ) {
		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'   => 'epic-wholesale-inquiries',
					'action' => 'delete',
					'id'     => $item['id'],
				),
				admin_url( 'admin.php' )
			),
			'epic_wholesale_delete_' . $item['id']
		);

		$actions = array(
			'delete' => sprintf(
				'<a href="%1$s" onclick="return confirm(%2$s);">%3$s</a>',
				esc_url( $delete_url ),
				wp_json_encode( __( 'Delete this inquiry permanently? This cannot be undone.', 'epic-wholesale-inquiries' ) ),
				esc_html__( 'Delete', 'epic-wholesale-inquiries' )
			),
		);

		return '<strong>' . esc_html( $item['business_name'] ) . '</strong>' . $this->row_actions( $actions );
	}

	protected function column_details( $item ) {
		$text = trim( (string) $item['details'] );
		if ( '' === $text ) {
			return '<span style="color:#999;">&#8212;</span>';
		}
		return nl2br( esc_html( $text ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html() already ran; nl2br() only adds <br> tags around it.
	}

	protected function column_email_status( $item ) {
		$badges = array(
			Epic_Wholesale_Store::STATUS_SENT     => array( '#1a7f37', __( 'Sent', 'epic-wholesale-inquiries' ) ),
			Epic_Wholesale_Store::STATUS_FAILED   => array( '#c0392b', __( 'Failed', 'epic-wholesale-inquiries' ) ),
			Epic_Wholesale_Store::STATUS_DISABLED => array( '#8a6d3b', __( 'Email disabled', 'epic-wholesale-inquiries' ) ),
			Epic_Wholesale_Store::STATUS_PENDING  => array( '#666666', __( 'Pending', 'epic-wholesale-inquiries' ) ),
		);
		$status = isset( $item['email_status'] ) ? (string) $item['email_status'] : Epic_Wholesale_Store::STATUS_PENDING;
		list( $color, $label ) = $badges[ $status ] ?? array( '#666666', ucfirst( $status ) );

		return '<span style="color:' . esc_attr( $color ) . '; font-weight:600;">' . esc_html( $label ) . '</span>';
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'submitted_at':
				return esc_html( mysql2date( 'Y-m-d H:i', $item['submitted_at'] ) );
			case 'phone':
			case 'contact':
			case 'topic_label_vi':
				return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
			default:
				return '';
		}
	}
}
