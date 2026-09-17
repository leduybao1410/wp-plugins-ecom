<?php
/**
 * The "N entries" drill-down from the main Product Cost screen: full history for one product,
 * with the ability to delete a mistaken entry. Rendered on the same admin.php?page=epic-product-cost
 * URL via a `view=history` query arg, so it shares the parent screen's menu highlighting.
 *
 * @package Epic_Product_Cost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Product_Cost_History_Admin {

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
	}

	private static function is_history_view() {
		return isset( $_GET['page'], $_GET['view'] ) && 'epic-product-cost' === $_GET['page'] && 'history' === $_GET['view'];
	}

	public static function handle_actions() {
		if ( ! self::is_history_view() || ! current_user_can( EPIC_PRODUCT_COST_CAP ) ) {
			return;
		}

		if ( isset( $_GET['action'], $_GET['id'] ) && 'delete' === $_GET['action'] ) {
			$id         = (int) $_GET['id'];
			$product_id = isset( $_GET['product_id'] ) ? (int) $_GET['product_id'] : 0;
			check_admin_referer( 'epic_pc_delete_history_' . $id );
			Epic_Product_Cost_Store::delete_history_entry( $id );
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'       => 'epic-product-cost',
						'view'       => 'history',
						'product_id' => $product_id,
						'deleted'    => 1,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}
	}

	/**
	 * Called directly by Epic_Product_Cost_Admin::render_page() when `view=history` is present —
	 * kept as an explicit method call (not a second hook on the same admin page action) so render
	 * order never depends on hook-priority ordering between the two classes.
	 */
	public static function render() {
		if ( ! current_user_can( EPIC_PRODUCT_COST_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'epic-product-cost' ) );
		}

		$product_id = isset( $_GET['product_id'] ) ? (int) $_GET['product_id'] : 0;
		$product    = wc_get_product( $product_id );
		$history    = Epic_Product_Cost_Store::get_history( $product_id );
		$back_url   = admin_url( 'admin.php?page=epic-product-cost' );

		?>
		<div class="wrap epic-product-cost">
			<h1>
				<?php
				printf(
					/* translators: %s: product name */
					esc_html__( 'Cost history — %s', 'epic-product-cost' ),
					esc_html( $product ? $product->get_name() : '#' . $product_id )
				);
				?>
			</h1>
			<p><a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Back to Product Cost', 'epic-product-cost' ); ?></a></p>

			<?php if ( isset( $_GET['deleted'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Entry deleted.', 'epic-product-cost' ); ?></p></div>
			<?php endif; ?>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Effective date', 'epic-product-cost' ); ?></th>
						<th><?php esc_html_e( 'Cost (250g)', 'epic-product-cost' ); ?></th>
						<th><?php esc_html_e( 'Note', 'epic-product-cost' ); ?></th>
						<th><?php esc_html_e( 'Recorded', 'epic-product-cost' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $history ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No cost history yet.', 'epic-product-cost' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $history as $row ) : ?>
						<?php
						$delete_url = wp_nonce_url(
							add_query_arg(
								array(
									'page'       => 'epic-product-cost',
									'view'       => 'history',
									'product_id' => $product_id,
									'action'     => 'delete',
									'id'         => $row->id,
								),
								admin_url( 'admin.php' )
							),
							'epic_pc_delete_history_' . $row->id
						);
						?>
						<tr>
							<td><?php echo esc_html( $row->effective_date ); ?></td>
							<td><?php echo esc_html( number_format( (float) $row->cost_250g, 0, ',', '.' ) ); ?></td>
							<td><?php echo esc_html( $row->note ); ?></td>
							<td><?php echo esc_html( mysql2date( 'Y-m-d H:i', $row->created_at ) ); ?></td>
							<td>
								<a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this cost entry? This cannot be undone.', 'epic-product-cost' ) ); ?>');">
									<?php esc_html_e( 'Delete', 'epic-product-cost' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
