<?php
/**
 * WooCommerce → Distributor Profit screen: filters + ledger + totals + add/edit entry form.
 *
 * @package Epic_Distributor_Profit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Distributor_Profit_Entries_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function register_menu() {
		add_menu_page(
			__( 'Distributor Profit', 'epic-distributor-profit' ),
			__( 'Distributor Profit', 'epic-distributor-profit' ),
			EPIC_DISTRIBUTOR_PROFIT_CAP,
			'epic-distributor-profit',
			array( __CLASS__, 'render_page' ),
			'dashicons-chart-line',
			56
		);
	}

	public static function enqueue( $hook ) {
		if ( false === strpos( $hook, 'epic-distributor-profit' ) ) {
			return;
		}
		wp_enqueue_style(
			'epic-distributor-profit-admin',
			EPIC_DISTRIBUTOR_PROFIT_URL . 'assets/admin.css',
			array(),
			EPIC_DISTRIBUTOR_PROFIT_VERSION
		);
		wp_enqueue_script(
			'epic-distributor-profit-admin',
			EPIC_DISTRIBUTOR_PROFIT_URL . 'assets/admin.js',
			array( 'jquery' ),
			EPIC_DISTRIBUTOR_PROFIT_VERSION,
			true
		);
		wp_localize_script(
			'epic-distributor-profit-admin',
			'EpicDistributorProfit',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'searchNonce'  => wp_create_nonce( 'epic_dp_search_orders' ),
				'i18n'         => array(
					'searching'    => __( 'Searching…', 'epic-distributor-profit' ),
					'noResults'    => __( 'No matching orders found.', 'epic-distributor-profit' ),
					'searchError'  => __( 'Search failed — try again.', 'epic-distributor-profit' ),
					'orderUnlinked' => __( 'Order unlinked — will be saved as a manual entry.', 'epic-distributor-profit' ),
				),
			)
		);
	}

	private static function base_url() {
		return admin_url( 'admin.php?page=epic-distributor-profit' );
	}

	/**
	 * Handle add/edit/delete of entries before any output.
	 */
	public static function handle_actions() {
		if ( ! isset( $_GET['page'] ) || 'epic-distributor-profit' !== $_GET['page'] ) {
			return;
		}
		if ( ! current_user_can( EPIC_DISTRIBUTOR_PROFIT_CAP ) ) {
			return;
		}

		// Delete.
		if ( isset( $_GET['action'], $_GET['id'] ) && 'delete' === $_GET['action'] ) {
			$id = (int) $_GET['id'];
			check_admin_referer( 'epic_dp_delete_entry_' . $id );
			Epic_Distributor_Profit_Store::delete_entry( $id );
			wp_safe_redirect( add_query_arg( 'deleted', 1, self::base_url() ) );
			exit;
		}

		// Save (add or edit).
		if ( isset( $_POST['epic_dp_entry_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['epic_dp_entry_nonce'] ) ), 'epic_dp_save_entry' ) ) {
			$id = isset( $_POST['entry_id'] ) ? (int) $_POST['entry_id'] : 0;

			if ( empty( $_POST['distributor_id'] ) ) {
				add_settings_error( 'epic_dp_entries', 'distributor_required', __( 'Choose a distributor.', 'epic-distributor-profit' ) );
				return;
			}
			if ( empty( $_POST['product_name'] ) ) {
				add_settings_error( 'epic_dp_entries', 'product_required', __( 'Product is required.', 'epic-distributor-profit' ) );
				return;
			}

			$data = array(
				'entry_date'         => isset( $_POST['entry_date'] ) ? sanitize_text_field( wp_unslash( $_POST['entry_date'] ) ) : gmdate( 'Y-m-d' ),
				'wc_order_id'        => isset( $_POST['wc_order_id'] ) ? (int) $_POST['wc_order_id'] : 0,
				'order_code'         => isset( $_POST['order_code'] ) ? sanitize_text_field( wp_unslash( $_POST['order_code'] ) ) : '',
				'channel'            => isset( $_POST['channel'] ) ? sanitize_text_field( wp_unslash( $_POST['channel'] ) ) : '',
				'distributor_id'     => (int) $_POST['distributor_id'],
				'product_name'       => sanitize_text_field( wp_unslash( $_POST['product_name'] ) ),
				'quantity'           => isset( $_POST['quantity'] ) ? sanitize_text_field( wp_unslash( $_POST['quantity'] ) ) : '',
				'cost'               => isset( $_POST['cost'] ) ? (float) $_POST['cost'] : 0,
				'revenue'            => isset( $_POST['revenue'] ) ? (float) $_POST['revenue'] : 0,
				'shipping_cost'      => isset( $_POST['shipping_cost'] ) ? (float) $_POST['shipping_cost'] : 0,
				'other_cost_label'   => isset( $_POST['other_cost_label'] ) ? sanitize_text_field( wp_unslash( $_POST['other_cost_label'] ) ) : '',
				'other_cost_amount'  => isset( $_POST['other_cost_amount'] ) ? (float) $_POST['other_cost_amount'] : 0,
				'source'             => ! empty( $_POST['wc_order_id'] ) ? 'order_import' : 'manual',
			);

			Epic_Distributor_Profit_Store::save_entry( $data, $id );
			wp_safe_redirect( add_query_arg( 'saved', 1, self::base_url() ) );
			exit;
		}
	}

	private static function resolve_month_filter() {
		// A convenience GET param from the month <input>: "2026-09" -> date_from/date_to for that month.
		if ( ! empty( $_GET['month'] ) && preg_match( '/^\d{4}-\d{2}$/', sanitize_text_field( wp_unslash( $_GET['month'] ) ) ) ) {
			$month     = sanitize_text_field( wp_unslash( $_GET['month'] ) );
			$date_from = $month . '-01';
			$date_to   = gmdate( 'Y-m-t', strtotime( $date_from ) );
			return array( $date_from, $date_to );
		}
		return array( '', '' );
	}

	public static function render_page() {
		if ( ! current_user_can( EPIC_DISTRIBUTOR_PROFIT_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'epic-distributor-profit' ) );
		}

		list( $month_date_from, $month_date_to ) = self::resolve_month_filter();

		$date_from      = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : $month_date_from;
		$date_to        = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : $month_date_to;
		$distributor_id = isset( $_GET['distributor_id'] ) ? (int) $_GET['distributor_id'] : 0;
		$channel        = isset( $_GET['channel'] ) ? sanitize_text_field( wp_unslash( $_GET['channel'] ) ) : '';
		$month          = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : '';

		$filter_args = array(
			'date_from'      => $date_from,
			'date_to'        => $date_to,
			'distributor_id' => $distributor_id,
			'channel'        => $channel,
		);

		$totals       = Epic_Distributor_Profit_Store::totals( $filter_args );
		$distributors = Epic_Distributor_Profit_Store::list_distributors();
		$channels     = Epic_Distributor_Profit_Store::distinct_channels();

		$editing = isset( $_GET['edit'] ) ? Epic_Distributor_Profit_Store::get_entry( (int) $_GET['edit'] ) : null;

		$export_url_base = wp_nonce_url(
			add_query_arg(
				array_merge( array( 'action' => 'epic_dp_export' ), $filter_args ),
				admin_url( 'admin-post.php' )
			),
			'epic_dp_export'
		);

		?>
		<div class="wrap epic-distributor-profit">
			<h1>
				<?php esc_html_e( 'Distributor Profit', 'epic-distributor-profit' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=epic-distributor-profit-distributors' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Manage Distributors', 'epic-distributor-profit' ); ?></a>
			</h1>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Entry saved.', 'epic-distributor-profit' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['deleted'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Entry deleted.', 'epic-distributor-profit' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['imported'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf(
					/* translators: %d: number of imported entries */
					__( 'Historical import complete: %d entr(y/ies) created.', 'epic-distributor-profit' ),
					(int) $_GET['imported']
				) ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['import_error'] ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['import_error'] ) ) ); ?></p></div>
			<?php endif; ?>
			<?php settings_errors( 'epic_dp_entries' ); ?>

			<form method="get" class="epic-dp-filters">
				<input type="hidden" name="page" value="epic-distributor-profit" />
				<label>
					<?php esc_html_e( 'Month', 'epic-distributor-profit' ); ?>
					<input type="month" name="month" value="<?php echo esc_attr( $month ); ?>" />
				</label>
				<label>
					<?php esc_html_e( 'From', 'epic-distributor-profit' ); ?>
					<input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" />
				</label>
				<label>
					<?php esc_html_e( 'To', 'epic-distributor-profit' ); ?>
					<input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" />
				</label>
				<label>
					<?php esc_html_e( 'Distributor', 'epic-distributor-profit' ); ?>
					<select name="distributor_id">
						<option value="0"><?php esc_html_e( 'All', 'epic-distributor-profit' ); ?></option>
						<?php foreach ( $distributors as $d ) : ?>
							<option value="<?php echo esc_attr( $d->id ); ?>" <?php selected( $distributor_id, $d->id ); ?>><?php echo esc_html( $d->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<?php esc_html_e( 'Channel', 'epic-distributor-profit' ); ?>
					<input type="text" name="channel" list="epic-dp-channel-list" value="<?php echo esc_attr( $channel ); ?>" placeholder="<?php esc_attr_e( 'Any', 'epic-distributor-profit' ); ?>" />
					<datalist id="epic-dp-channel-list">
						<?php foreach ( $channels as $c ) : ?>
							<option value="<?php echo esc_attr( $c ); ?>"></option>
						<?php endforeach; ?>
					</datalist>
				</label>
				<?php submit_button( __( 'Filter', 'epic-distributor-profit' ), 'secondary', '', false ); ?>
				<a href="<?php echo esc_url( self::base_url() ); ?>" class="button"><?php esc_html_e( 'Reset', 'epic-distributor-profit' ); ?></a>
				<a href="<?php echo esc_url( add_query_arg( 'format', 'csv', $export_url_base ) ); ?>" class="button"><?php esc_html_e( 'Export CSV', 'epic-distributor-profit' ); ?></a>
				<a href="<?php echo esc_url( add_query_arg( 'format', 'xlsx', $export_url_base ) ); ?>" class="button"><?php esc_html_e( 'Export XLSX', 'epic-distributor-profit' ); ?></a>
			</form>

			<div class="epic-dp-totals">
				<div><span><?php esc_html_e( 'Entries', 'epic-distributor-profit' ); ?></span><strong><?php echo esc_html( $totals['entry_count'] ); ?></strong></div>
				<div><span><?php esc_html_e( 'Revenue', 'epic-distributor-profit' ); ?></span><strong><?php echo esc_html( number_format( (float) $totals['revenue'], 0, ',', '.' ) ); ?></strong></div>
				<div><span><?php esc_html_e( 'Cost', 'epic-distributor-profit' ); ?></span><strong><?php echo esc_html( number_format( (float) $totals['cost'], 0, ',', '.' ) ); ?></strong></div>
				<div><span><?php esc_html_e( 'Shipping', 'epic-distributor-profit' ); ?></span><strong><?php echo esc_html( number_format( (float) $totals['shipping_cost'], 0, ',', '.' ) ); ?></strong></div>
				<div><span><?php esc_html_e( 'Gross Profit', 'epic-distributor-profit' ); ?></span><strong><?php echo esc_html( number_format( (float) $totals['gross_profit'], 0, ',', '.' ) ); ?></strong></div>
				<div><span><?php esc_html_e( 'Commission', 'epic-distributor-profit' ); ?></span><strong><?php echo esc_html( number_format( (float) $totals['commission_amount'], 0, ',', '.' ) ); ?></strong></div>
				<div><span><?php esc_html_e( 'Net Profit', 'epic-distributor-profit' ); ?></span><strong><?php echo esc_html( number_format( (float) $totals['net_profit'], 0, ',', '.' ) ); ?></strong></div>
			</div>

			<?php
			$table = new Epic_Distributor_Profit_Entries_List_Table();
			$table->prepare_items();
			$table->display();
			?>

			<hr />

			<h2><?php echo $editing ? esc_html__( 'Edit Entry', 'epic-distributor-profit' ) : esc_html__( 'Add Entry', 'epic-distributor-profit' ); ?></h2>

			<div class="epic-dp-order-picker">
				<?php if ( $editing && $editing->wc_order_id ) : ?>
					<?php
					$linked_url = admin_url( 'post.php?post=' . (int) $editing->wc_order_id . '&action=edit' );
					if ( function_exists( 'wc_get_order' ) ) {
						$linked_order = wc_get_order( $editing->wc_order_id );
						if ( $linked_order && is_callable( array( $linked_order, 'get_edit_order_url' ) ) ) {
							$linked_url = $linked_order->get_edit_order_url();
						}
					}
					?>
					<p>
						<?php esc_html_e( 'Currently linked to:', 'epic-distributor-profit' ); ?>
						<a href="<?php echo esc_url( $linked_url ); ?>" class="button button-small" target="_blank" rel="noopener">#<?php echo esc_html( $editing->wc_order_id ); ?> &#8599;</a>
						<button type="button" class="button" id="epic-dp-order-clear-btn"><?php esc_html_e( 'Unlink order', 'epic-distributor-profit' ); ?></button>
					</p>
				<?php endif; ?>
				<label for="epic-dp-order-search"><?php echo $editing ? esc_html__( 'Search a different WooCommerce order to link instead', 'epic-distributor-profit' ) : esc_html__( 'Import from a real order (optional): search by order number', 'epic-distributor-profit' ); ?></label>
				<input type="text" id="epic-dp-order-search" placeholder="<?php esc_attr_e( 'e.g. 1234', 'epic-distributor-profit' ); ?>" />
				<button type="button" class="button" id="epic-dp-order-search-btn"><?php esc_html_e( 'Search', 'epic-distributor-profit' ); ?></button>
				<div id="epic-dp-order-results"></div>
				<p class="description"><?php esc_html_e( 'Picking an order fills in the fields below (product, quantity, revenue, shipping) — you can still edit anything before saving. Leave this blank for a manual/off-platform sale.', 'epic-distributor-profit' ); ?></p>
			</div>

			<form method="post" id="epic-dp-entry-form">
				<?php wp_nonce_field( 'epic_dp_save_entry', 'epic_dp_entry_nonce' ); ?>
				<input type="hidden" name="entry_id" value="<?php echo esc_attr( $editing ? $editing->id : 0 ); ?>" />
				<input type="hidden" name="wc_order_id" id="epic-dp-wc-order-id" value="<?php echo esc_attr( $editing ? $editing->wc_order_id : '' ); ?>" />

				<table class="form-table">
					<tr>
						<th><label for="entry_date"><?php esc_html_e( 'Date', 'epic-distributor-profit' ); ?></label></th>
						<td><input type="date" id="entry_date" name="entry_date" required
							value="<?php echo esc_attr( $editing ? $editing->entry_date : gmdate( 'Y-m-d' ) ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="order_code"><?php esc_html_e( 'Order code', 'epic-distributor-profit' ); ?></label></th>
						<td><input type="text" id="order_code" name="order_code" class="regular-text"
							value="<?php echo esc_attr( $editing ? $editing->order_code : '' ); ?>"
							placeholder="<?php esc_attr_e( 'Shopee code, WC order number, or any reference', 'epic-distributor-profit' ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="channel"><?php esc_html_e( 'Channel', 'epic-distributor-profit' ); ?></label></th>
						<td><input type="text" id="channel" name="channel" list="epic-dp-channel-list-form"
							value="<?php echo esc_attr( $editing ? $editing->channel : '' ); ?>"
							placeholder="<?php esc_attr_e( 'Shopee / Web / Wholesale / Offline', 'epic-distributor-profit' ); ?>" />
							<datalist id="epic-dp-channel-list-form">
								<?php foreach ( $channels as $c ) : ?>
									<option value="<?php echo esc_attr( $c ); ?>"></option>
								<?php endforeach; ?>
							</datalist>
						</td>
					</tr>
					<tr>
						<th><label for="distributor_id_field"><?php esc_html_e( 'Distributor', 'epic-distributor-profit' ); ?></label></th>
						<td>
							<select id="distributor_id_field" name="distributor_id" required>
								<option value=""><?php esc_html_e( '— choose —', 'epic-distributor-profit' ); ?></option>
								<?php foreach ( $distributors as $d ) : ?>
									<option value="<?php echo esc_attr( $d->id ); ?>" data-commission="<?php echo esc_attr( $d->commission_percent ); ?>" <?php selected( $editing ? $editing->distributor_id : '', $d->id ); ?>>
										<?php echo esc_html( $d->name . ' (' . number_format( (float) $d->commission_percent, 1 ) . '%)' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<?php if ( ! $distributors ) : ?>
								<p class="description"><?php esc_html_e( 'No distributors yet — add one first.', 'epic-distributor-profit' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><label for="product_name"><?php esc_html_e( 'Product', 'epic-distributor-profit' ); ?></label></th>
						<td><input type="text" id="product_name" name="product_name" class="regular-text" required
							value="<?php echo esc_attr( $editing ? $editing->product_name : '' ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="quantity"><?php esc_html_e( 'Quantity', 'epic-distributor-profit' ); ?></label></th>
						<td><input type="number" id="quantity" name="quantity" class="small-text" min="0"
							value="<?php echo esc_attr( $editing && null !== $editing->quantity ? $editing->quantity : '' ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="cost"><?php esc_html_e( 'Cost', 'epic-distributor-profit' ); ?></label></th>
						<td><input type="number" step="0.01" id="cost" name="cost" class="epic-dp-calc" required
							value="<?php echo esc_attr( $editing ? $editing->cost : '' ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="revenue"><?php esc_html_e( 'Revenue', 'epic-distributor-profit' ); ?></label></th>
						<td><input type="number" step="0.01" id="revenue" name="revenue" class="epic-dp-calc" required
							value="<?php echo esc_attr( $editing ? $editing->revenue : '' ); ?>" />
							<p class="description"><?php esc_html_e( 'Amount actually received for this sale (net of platform fees, if any).', 'epic-distributor-profit' ); ?></p></td>
					</tr>
					<tr>
						<th><label for="shipping_cost"><?php esc_html_e( 'Shipping cost', 'epic-distributor-profit' ); ?></label></th>
						<td><input type="number" step="0.01" id="shipping_cost" name="shipping_cost" class="epic-dp-calc"
							value="<?php echo esc_attr( $editing ? $editing->shipping_cost : '0' ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="other_cost_label"><?php esc_html_e( 'Other cost', 'epic-distributor-profit' ); ?></label></th>
						<td>
							<input type="text" id="other_cost_label" name="other_cost_label"
								value="<?php echo esc_attr( $editing ? $editing->other_cost_label : '' ); ?>"
								placeholder="<?php esc_attr_e( 'e.g. Shopee wallet top-up', 'epic-distributor-profit' ); ?>" />
							<input type="number" step="0.01" id="other_cost_amount" name="other_cost_amount" class="small-text epic-dp-calc"
								value="<?php echo esc_attr( $editing ? $editing->other_cost_amount : '0' ); ?>" />
						</td>
					</tr>
				</table>

				<div class="epic-dp-preview">
					<strong><?php esc_html_e( 'Preview (recalculated on save):', 'epic-distributor-profit' ); ?></strong>
					<span><?php esc_html_e( 'Gross profit:', 'epic-distributor-profit' ); ?> <span id="epic-dp-preview-gross">0</span></span>
					<span><?php esc_html_e( 'Commission:', 'epic-distributor-profit' ); ?> <span id="epic-dp-preview-commission">0</span></span>
					<span><?php esc_html_e( 'Net profit:', 'epic-distributor-profit' ); ?> <span id="epic-dp-preview-net">0</span></span>
				</div>

				<?php submit_button( $editing ? __( 'Update Entry', 'epic-distributor-profit' ) : __( 'Add Entry', 'epic-distributor-profit' ) ); ?>
				<?php if ( $editing ) : ?>
					<p><a href="<?php echo esc_url( self::base_url() ); ?>"><?php esc_html_e( 'Cancel edit', 'epic-distributor-profit' ); ?></a></p>
				<?php endif; ?>
			</form>

			<hr />
			<?php Epic_Distributor_Profit_Import_Legacy::render_import_box(); ?>
		</div>
		<?php
	}
}
