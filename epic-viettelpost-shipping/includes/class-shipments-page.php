<?php
/**
 * "ViettelPost Shipments" dashboard — a WooCommerce submenu listing every
 * booked shipment with status/date filters, fee reconciliation, bulk label
 * printing, and CSV export.
 *
 * @package Epic_ViettelPost_Shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_VTP_Shipments_Page {

	const SLUG       = 'epic-vtp-shipments';
	const PER_PAGE   = 20;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_export_csv' ) );
	}

	public static function register_page() {
		add_submenu_page(
			'woocommerce',
			__( 'ViettelPost Shipments', 'epic-viettelpost-shipping' ),
			__( 'ViettelPost Shipments', 'epic-viettelpost-shipping' ),
			'manage_woocommerce',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function maybe_enqueue( $hook ) {
		if ( false !== strpos( (string) $hook, self::SLUG ) ) {
			Epic_VTP_Assets::enqueue();
		}
	}

	/** Reads the current filter set from the query string. */
	private static function filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters.
		$bucket = isset( $_GET['vtp_status'] ) ? sanitize_key( wp_unslash( $_GET['vtp_status'] ) ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$action = ! empty( $_GET['vtp_action'] );
		$page   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		$status_codes = array();
		if ( '' !== $bucket && isset( Epic_VTP_Client::status_buckets()[ $bucket ] ) ) {
			$status_codes = Epic_VTP_Client::status_buckets()[ $bucket ];
		}

		return array(
			'bucket'       => $bucket,
			'search'       => $search,
			'needs_action' => $action,
			'status_codes' => $status_codes,
			'page'         => $page,
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$filters = self::filters();
		$query   = Epic_VTP_Order_Query::booked(
			array(
				'status_codes' => $filters['status_codes'],
				'search'       => $filters['search'],
				'needs_action' => $filters['needs_action'],
			),
			self::PER_PAGE,
			$filters['page']
		);

		$export_url = wp_nonce_url(
			add_query_arg(
				array_merge(
					array( 'epic_vtp_export' => 1 ),
					array_filter(
						array(
							'vtp_status' => $filters['bucket'],
							's'          => $filters['search'],
							'vtp_action' => $filters['needs_action'] ? 1 : '',
						)
					)
				),
				admin_url( 'admin.php?page=' . self::SLUG )
			),
			'epic_vtp_export'
		);
		?>
		<div class="wrap epic-vtp-dashboard">
			<h1><?php esc_html_e( 'ViettelPost Shipments', 'epic-viettelpost-shipping' ); ?></h1>

			<form method="get" class="epic-vtp-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<select name="vtp_status">
					<option value=""><?php esc_html_e( 'All statuses', 'epic-viettelpost-shipping' ); ?></option>
					<?php foreach ( Epic_VTP_Client::bucket_labels() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['bucket'], $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Waybill number…', 'epic-viettelpost-shipping' ); ?>" />
				<label><input type="checkbox" name="vtp_action" value="1" <?php checked( $filters['needs_action'] ); ?> /> <?php esc_html_e( 'Needs action only', 'epic-viettelpost-shipping' ); ?></label>
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'epic-viettelpost-shipping' ); ?></button>
				<a class="button" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export CSV', 'epic-viettelpost-shipping' ); ?></a>
			</form>

			<p class="epic-vtp-bulkbar">
				<button type="button" class="button button-primary epic-vtp-bulk-print" disabled="disabled">
					<?php esc_html_e( 'Print labels', 'epic-viettelpost-shipping' ); ?>
				</button>
				<span class="epic-vtp-feedback"></span>
			</p>

			<table class="wp-list-table widefat fixed striped epic-vtp-shipments">
				<thead>
					<tr>
						<td class="manage-column check-column"><input type="checkbox" class="epic-vtp-check-all" /></td>
						<th><?php esc_html_e( 'Order', 'epic-viettelpost-shipping' ); ?></th>
						<th><?php esc_html_e( 'Date', 'epic-viettelpost-shipping' ); ?></th>
						<th><?php esc_html_e( 'Recipient', 'epic-viettelpost-shipping' ); ?></th>
						<th><?php esc_html_e( 'Waybill', 'epic-viettelpost-shipping' ); ?></th>
						<th><?php esc_html_e( 'Status', 'epic-viettelpost-shipping' ); ?></th>
						<th><?php esc_html_e( 'COD', 'epic-viettelpost-shipping' ); ?></th>
						<th><?php esc_html_e( 'Courier fee', 'epic-viettelpost-shipping' ); ?></th>
						<th><?php esc_html_e( 'Δ vs charged', 'epic-viettelpost-shipping' ); ?></th>
						<th><?php esc_html_e( 'Action', 'epic-viettelpost-shipping' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $query['orders'] ) ) : ?>
					<tr><td colspan="10"><?php esc_html_e( 'No shipments match these filters.', 'epic-viettelpost-shipping' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $query['orders'] as $order ) : ?>
						<?php
						$waybill = (string) $order->get_meta( Epic_VTP_Order_Meta_Box::META_ORDER_NUMBER );
						$status  = Epic_VTP_Client::bucket_status( $order->get_meta( Epic_VTP_Order_Meta_Box::META_STATUS ) );
						$fee     = (float) $order->get_meta( Epic_VTP_Order_Meta_Box::META_FEE );
						$charged = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
						$delta   = $fee - $charged;
						$stale   = '1' === (string) $order->get_meta( Epic_VTP_Cron::STALE_META );
						$needs   = '' !== (string) $order->get_meta( Epic_VTP_Order_Meta_Box::META_NEEDS_ACTION );
						?>
						<tr>
							<th class="check-column"><input type="checkbox" class="epic-vtp-row" value="<?php echo esc_attr( $order->get_id() ); ?>" /></th>
							<td><a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a></td>
							<td><?php echo esc_html( $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d' ) : '' ); ?></td>
							<td><?php echo esc_html( $order->get_formatted_shipping_full_name() ? $order->get_formatted_shipping_full_name() : $order->get_formatted_billing_full_name() ); ?></td>
							<td><code><?php echo esc_html( $waybill ); ?></code></td>
							<td>
								<span class="epic-vtp-shipment-cell <?php echo esc_attr( $status['css_class'] ); ?>"><?php echo esc_html( $status['label'] ); ?></span>
								<?php if ( $stale ) : ?><span class="epic-vtp-badge epic-vtp-badge-stale" title="<?php esc_attr_e( 'No webhook update within the configured window — verify manually.', 'epic-viettelpost-shipping' ); ?>"><?php esc_html_e( 'Stale', 'epic-viettelpost-shipping' ); ?></span><?php endif; ?>
								<?php if ( $needs ) : ?><span class="epic-vtp-badge epic-vtp-badge-action"><?php esc_html_e( 'Action', 'epic-viettelpost-shipping' ); ?></span><?php endif; ?>
							</td>
							<td><?php echo esc_html( wp_strip_all_tags( wc_price( (float) $order->get_meta( Epic_VTP_Order_Meta_Box::META_COD_AMOUNT ) ) ) ); ?></td>
							<td><?php echo esc_html( wp_strip_all_tags( wc_price( $fee ) ) ); ?></td>
							<td>
								<?php if ( abs( $delta ) < 1 ) : ?>
									<span class="epic-vtp-delta-ok">—</span>
								<?php else : ?>
									<span class="<?php echo esc_attr( $delta > 0 ? 'epic-vtp-delta-loss' : 'epic-vtp-delta-gain' ); ?>">
										<?php echo esc_html( ( $delta > 0 ? '+' : '−' ) . wp_strip_all_tags( wc_price( abs( $delta ) ) ) ); ?>
									</span>
								<?php endif; ?>
							</td>
							<td>
								<button type="button" class="button button-small epic-vtp-list-print" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>"><?php esc_html_e( 'Print', 'epic-viettelpost-shipping' ); ?></button>
								<span class="epic-vtp-list-print-feedback epic-vtp-feedback"></span>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<?php
			$total_pages = (int) ceil( $query['total'] / self::PER_PAGE );
			if ( $total_pages > 1 ) {
				echo '<div class="epic-vtp-pagination">';
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'current'   => $filters['page'],
							'total'     => $total_pages,
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
						)
					)
				);
				echo '</div>';
			}
			?>
		</div>
		<?php
	}

	/** Streams a CSV of the current filter set. */
	public static function maybe_export_csv() {
		if ( empty( $_GET['epic_vtp_export'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		check_admin_referer( 'epic_vtp_export' );

		$filters = self::filters();
		$query   = Epic_VTP_Order_Query::booked(
			array(
				'status_codes' => $filters['status_codes'],
				'search'       => $filters['search'],
				'needs_action' => $filters['needs_action'],
			),
			1000,
			1
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=viettelpost-shipments.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'Order', 'Date', 'Recipient', 'Waybill', 'Status', 'COD', 'Courier fee', 'Charged shipping', 'Delta' ) );
		foreach ( $query['orders'] as $order ) {
			$fee     = (float) $order->get_meta( Epic_VTP_Order_Meta_Box::META_FEE );
			$charged = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
			fputcsv(
				$out,
				array(
					$order->get_order_number(),
					$order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d' ) : '',
					$order->get_formatted_shipping_full_name(),
					$order->get_meta( Epic_VTP_Order_Meta_Box::META_ORDER_NUMBER ),
					Epic_VTP_Client::status_label( $order->get_meta( Epic_VTP_Order_Meta_Box::META_STATUS ) ),
					$order->get_meta( Epic_VTP_Order_Meta_Box::META_COD_AMOUNT ),
					$fee,
					$charged,
					$fee - $charged,
				)
			);
		}
		fclose( $out );
		exit;
	}
}
