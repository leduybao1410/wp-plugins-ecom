<?php
/**
 * The actual "log" a wp-admin user sees under WooCommerce → Contact
 * Requests — a standard WP_List_Table over the epic_contact_requests table
 * (class-store.php), so it looks and behaves like every other admin list
 * screen (sortable columns, pagination) rather than a bespoke table.
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

class Epic_Contact_List_Table extends WP_List_Table {

	const PER_PAGE = 20;

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'epic_contact_request',
				'plural'   => 'epic_contact_requests',
				'ajax'     => false,
			)
		);
	}

	/**
	 * `name` is listed FIRST deliberately, not just for readability — it must
	 * match get_primary_column_name() below. WP_List_Table's own mobile
	 * responsive behaviour targets whichever column it thinks is primary, and
	 * the Delete row action lives in column_name() below.
	 */
	public function get_columns() {
		return array(
			'name'           => __( 'Name', 'epic-contact-requests' ),
			'submitted_at'   => __( 'Submitted', 'epic-contact-requests' ),
			'phone'          => __( 'Phone', 'epic-contact-requests' ),
			'email'          => __( 'Email', 'epic-contact-requests' ),
			'direct_consult' => __( 'Direct consult', 'epic-contact-requests' ),
			'address'        => __( 'Address', 'epic-contact-requests' ),
			'message'        => __( 'Message', 'epic-contact-requests' ),
			'email_status'   => __( 'Email', 'epic-contact-requests' ),
		);
	}

	/** Must agree with the Delete row action's placement in column_name() — see get_columns()'s docblock. */
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
		esc_html_e( 'No contact requests yet — submissions from the /contact page will show up here.', 'epic-contact-requests' );
	}

	public function prepare_items() {
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'submitted_at'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only sort/pagination, not a state-changing request.
		$order   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$current_page = $this->get_pagenum();
		$total_items  = Epic_Contact_Store::count();

		$this->items = Epic_Contact_Store::get_page(
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
	protected function column_name( $item ) {
		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'   => 'epic-contact-requests',
					'action' => 'delete',
					'id'     => $item['id'],
				),
				admin_url( 'admin.php' )
			),
			'epic_contact_delete_' . $item['id']
		);

		$actions = array(
			'delete' => sprintf(
				'<a href="%1$s" onclick="return confirm(%2$s);">%3$s</a>',
				esc_url( $delete_url ),
				wp_json_encode( __( 'Delete this contact request permanently? This cannot be undone.', 'epic-contact-requests' ) ),
				esc_html__( 'Delete', 'epic-contact-requests' )
			),
		);

		return '<strong>' . esc_html( $item['name'] ) . '</strong>' . $this->row_actions( $actions );
	}

	protected function column_direct_consult( $item ) {
		return ! empty( $item['direct_consult'] )
			? '<span style="color:#1a7f37; font-weight:600;">' . esc_html__( 'Yes', 'epic-contact-requests' ) . '</span>'
			: '<span style="color:#999;">&#8212;</span>';
	}

	protected function column_address( $item ) {
		$parts = array_filter(
			array(
				(string) ( $item['street'] ?? '' ),
				(string) ( $item['ward'] ?? '' ),
				(string) ( $item['province'] ?? '' ),
			),
			static function ( $part ) {
				return '' !== trim( $part );
			}
		);
		if ( empty( $parts ) ) {
			return '<span style="color:#999;">&#8212;</span>';
		}
		return esc_html( implode( ', ', $parts ) );
	}

	protected function column_message( $item ) {
		$text = trim( (string) $item['message'] );
		if ( '' === $text ) {
			return '<span style="color:#999;">&#8212;</span>';
		}
		return nl2br( esc_html( $text ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html() already ran; nl2br() only adds <br> tags around it.
	}

	protected function column_email_status( $item ) {
		$badges = array(
			Epic_Contact_Store::STATUS_SENT     => array( '#1a7f37', __( 'Sent', 'epic-contact-requests' ) ),
			Epic_Contact_Store::STATUS_FAILED   => array( '#c0392b', __( 'Failed', 'epic-contact-requests' ) ),
			Epic_Contact_Store::STATUS_DISABLED => array( '#8a6d3b', __( 'Email disabled', 'epic-contact-requests' ) ),
			Epic_Contact_Store::STATUS_PENDING  => array( '#666666', __( 'Pending', 'epic-contact-requests' ) ),
		);
		$status = isset( $item['email_status'] ) ? (string) $item['email_status'] : Epic_Contact_Store::STATUS_PENDING;
		list( $color, $label ) = $badges[ $status ] ?? array( '#666666', ucfirst( $status ) );

		return '<span style="color:' . esc_attr( $color ) . '; font-weight:600;">' . esc_html( $label ) . '</span>';
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'submitted_at':
				return esc_html( mysql2date( 'Y-m-d H:i', $item['submitted_at'] ) );
			case 'phone':
			case 'email':
				return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
			default:
				return '';
		}
	}
}
