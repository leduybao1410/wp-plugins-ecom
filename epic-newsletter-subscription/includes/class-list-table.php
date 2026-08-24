<?php
/**
 * The actual "list" a wp-admin user sees under WooCommerce → Newsletter
 * Subscribers — a standard WP_List_Table over the
 * epic_newsletter_subscribers table (class-store.php), so it looks and
 * behaves like every other admin list screen (sortable columns,
 * pagination) rather than a bespoke table.
 *
 * Read-only except for a per-row Delete action — there's no "edit" concept
 * for a submitted subscription, and no bulk actions (kept deliberately
 * simple; add a checkbox column + bulk handler here later if that's ever
 * needed). To unsubscribe somebody from future mailings, delete their row.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Epic_Newsletter_List_Table extends WP_List_Table {

	const PER_PAGE = 20;

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'epic_newsletter_subscriber',
				'plural'   => 'epic_newsletter_subscribers',
				'ajax'     => false,
			)
		);
	}

	/**
	 * `email` is listed FIRST deliberately, not just for readability — it
	 * must match get_primary_column_name() below. WP_List_Table's own
	 * mobile/responsive JS+CSS (the "toggle-row" expand button, and the
	 * hover-reveal behavior for row_actions()) targets whichever column it
	 * thinks is primary, which defaults to the first non-checkbox column
	 * here if get_primary_column_name() didn't exist. Since the Delete row
	 * action lives in column_email() below, primary MUST be 'email' — a
	 * mismatch there is what caused the previous layout (columns
	 * overlapping) in the wholesale plugin at narrow/mobile widths.
	 */
	public function get_columns() {
		return array(
			'email'          => __( 'Email', 'epic-newsletter-subscription' ),
			'subscribed_at'  => __( 'Subscribed', 'epic-newsletter-subscription' ),
			'locale'         => __( 'Locale', 'epic-newsletter-subscription' ),
			'email_status'   => __( 'Team email', 'epic-newsletter-subscription' ),
			'confirm_status' => __( 'Confirmation', 'epic-newsletter-subscription' ),
		);
	}

	/** Must agree with the Delete row action's placement in column_email() — see get_columns()'s docblock. */
	protected function get_primary_column_name() {
		return 'email';
	}

	protected function get_sortable_columns() {
		return array(
			'subscribed_at' => array( 'subscribed_at', true ), // true = already sorted desc by default.
			'email'         => array( 'email', false ),
		);
	}

	public function no_items() {
		esc_html_e( 'No newsletter subscribers yet — signups from the website footer\'s subscription box will show up here.', 'epic-newsletter-subscription' );
	}

	public function prepare_items() {
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'subscribed_at'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only sort/pagination, not a state-changing request.
		$order   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$current_page = $this->get_pagenum();
		$total_items  = Epic_Newsletter_Store::count();

		$this->items = Epic_Newsletter_Store::get_page(
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
	protected function column_email( $item ) {
		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'   => 'epic-newsletter-subscription',
					'action' => 'delete',
					'id'     => $item['id'],
				),
				admin_url( 'admin.php' )
			),
			'epic_newsletter_delete_' . $item['id']
		);

		$actions = array(
			'delete' => sprintf(
				'<a href="%1$s" onclick="return confirm(%2$s);">%3$s</a>',
				esc_url( $delete_url ),
				wp_json_encode( __( 'Delete this subscriber permanently? They will no longer receive marketing emails. This cannot be undone.', 'epic-newsletter-subscription' ) ),
				esc_html__( 'Delete', 'epic-newsletter-subscription' )
			),
		);

		return '<strong>' . esc_html( $item['email'] ) . '</strong>' . $this->row_actions( $actions );
	}

	protected function column_email_status( $item ) {
		return self::render_status_badge( isset( $item['email_status'] ) ? (string) $item['email_status'] : Epic_Newsletter_Store::STATUS_PENDING );
	}

	protected function column_confirm_status( $item ) {
		return self::render_status_badge( isset( $item['confirm_status'] ) ? (string) $item['confirm_status'] : Epic_Newsletter_Store::STATUS_PENDING );
	}

	/** Shared color/label badge renderer for the two delivery-status columns. */
	private static function render_status_badge( $status ) {
		$badges = array(
			Epic_Newsletter_Store::STATUS_SENT     => array( '#1a7f37', __( 'Sent', 'epic-newsletter-subscription' ) ),
			Epic_Newsletter_Store::STATUS_FAILED   => array( '#c0392b', __( 'Failed', 'epic-newsletter-subscription' ) ),
			Epic_Newsletter_Store::STATUS_DISABLED => array( '#8a6d3b', __( 'Email disabled', 'epic-newsletter-subscription' ) ),
			Epic_Newsletter_Store::STATUS_PENDING  => array( '#666666', __( 'Pending', 'epic-newsletter-subscription' ) ),
			Epic_Newsletter_Store::STATUS_EXISTS   => array( '#666666', __( 'Already subscribed', 'epic-newsletter-subscription' ) ),
		);
		list( $color, $label ) = $badges[ $status ] ?? array( '#666666', ucfirst( $status ) );

		return '<span style="color:' . esc_attr( $color ) . '; font-weight:600;">' . esc_html( $label ) . '</span>';
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'subscribed_at':
				return esc_html( mysql2date( 'Y-m-d H:i', $item['subscribed_at'] ) );
			case 'locale':
				return isset( $item['locale'] ) ? esc_html( $item['locale'] ) : '';
			default:
				return '';
		}
	}
}
