<?php
/**
 * WooCommerce → Product Cost screen: one row per coffee, current cost + a batch "apply as of this
 * date" form, matching how the user actually updates costs — pasting one price/cost sheet at a time.
 *
 * @package Epic_Product_Cost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Product_Cost_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Product Cost', 'epic-product-cost' ),
			__( 'Product Cost', 'epic-product-cost' ),
			EPIC_PRODUCT_COST_CAP,
			'epic-product-cost',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function enqueue( $hook ) {
		if ( false === strpos( $hook, 'epic-product-cost' ) ) {
			return;
		}
		wp_enqueue_style(
			'epic-product-cost-admin',
			EPIC_PRODUCT_COST_URL . 'assets/admin.css',
			array(),
			EPIC_PRODUCT_COST_VERSION
		);
	}

	/**
	 * All coffee products this screen tracks cost for — every Simple/Variable product, published or
	 * still a draft (so cost can be entered before a new coffee goes live).
	 *
	 * @return WC_Product[]
	 */
	private static function get_products() {
		return wc_get_products(
			array(
				'limit'   => -1,
				'status'  => array( 'publish', 'draft', 'pending', 'private' ),
				'type'    => array( 'simple', 'variable' ),
				'orderby' => 'title',
				'order'   => 'ASC',
			)
		);
	}

	/**
	 * Handle the batch save before any output, so we can redirect afterwards.
	 */
	public static function handle_actions() {
		if ( ! isset( $_GET['page'] ) || 'epic-product-cost' !== $_GET['page'] ) {
			return;
		}
		if ( isset( $_GET['view'] ) ) {
			return; // history subpage handles its own actions.
		}
		if ( ! current_user_can( EPIC_PRODUCT_COST_CAP ) ) {
			return;
		}

		// "Load starter costs" — a separate small form/nonce from the main batch-save form below.
		if ( isset( $_POST['epic_pc_seed_submit'], $_POST['epic_pc_seed_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['epic_pc_seed_nonce'] ) ), 'epic_pc_seed' ) ) {
			$result = Epic_Product_Cost_Seed::run();
			wp_safe_redirect( admin_url( 'admin.php?page=epic-product-cost&seeded=' . rawurlencode( $result ) ) );
			exit;
		}

		if ( ! isset( $_POST['epic_pc_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['epic_pc_nonce'] ) ), 'epic_pc_save_costs' ) ) {
			return;
		}

		$effective_date = isset( $_POST['effective_date'] ) ? sanitize_text_field( wp_unslash( $_POST['effective_date'] ) ) : '';
		if ( ! $effective_date || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $effective_date ) ) {
			add_settings_error( 'epic_pc', 'date_required', __( 'Please choose a valid effective date.', 'epic-product-cost' ) );
			return;
		}

		$note = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
		$raw_costs = isset( $_POST['cost'] ) && is_array( $_POST['cost'] ) ? wp_unslash( $_POST['cost'] ) : array();

		$saved = 0;
		foreach ( $raw_costs as $product_id => $value ) {
			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue; // blank = "no change" for this product.
			}
			if ( ! is_numeric( $value ) ) {
				continue;
			}
			Epic_Product_Cost_Store::upsert_cost( (int) $product_id, (float) $value, $effective_date, $note );
			++$saved;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=epic-product-cost&saved=' . $saved ) );
		exit;
	}

	public static function render_page() {
		if ( isset( $_GET['view'] ) && 'history' === $_GET['view'] ) {
			Epic_Product_Cost_History_Admin::render();
			return;
		}
		if ( ! current_user_can( EPIC_PRODUCT_COST_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'epic-product-cost' ) );
		}

		global $wpdb;
		if ( $wpdb->get_var( "SHOW TABLES LIKE '" . esc_sql( Epic_Product_Cost_Store::table() ) . "'" ) !== Epic_Product_Cost_Store::table() ) { // phpcs:ignore
			Epic_Product_Cost_Store::install();
		}

		$products = self::get_products();
		$today    = current_time( 'Y-m-d' );

		$unseeded_ids = Epic_Product_Cost_Seed::unseeded_product_ids();

		?>
		<div class="wrap epic-product-cost">
			<h1><?php esc_html_e( 'Product Cost', 'epic-product-cost' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Cost is entered per 250g. 500g and 1kg cost are calculated automatically as 2× and 4× the 250g cost — cost scales with the weight of beans in the bag, unlike retail price. Every change here is kept as history by effective date, so past sales keep the cost that was actually true at the time, even after the cost changes again later.', 'epic-product-cost' ); ?>
			</p>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						printf(
							/* translators: %d: number of products updated */
							esc_html( _n( '%d product cost saved.', '%d product costs saved.', (int) $_GET['saved'], 'epic-product-cost' ) ),
							(int) $_GET['saved']
						);
						?>
					</p>
				</div>
			<?php endif; ?>
			<?php if ( isset( $_GET['seeded'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html( wp_unslash( $_GET['seeded'] ) ); ?></p>
				</div>
			<?php endif; ?>
			<?php settings_errors( 'epic_pc' ); ?>

			<?php if ( ! empty( $unseeded_ids ) ) : ?>
				<div class="epic-pc-seed-box">
					<p>
						<?php esc_html_e( 'Starter costs from the 2026-09 cost sheet are available for the products below that don\'t have any cost recorded yet.', 'epic-product-cost' ); ?>
					</p>
					<form method="post">
						<?php wp_nonce_field( 'epic_pc_seed', 'epic_pc_seed_nonce' ); ?>
						<button type="submit" name="epic_pc_seed_submit" value="1" class="button">
							<?php esc_html_e( 'Load starter costs for unset products', 'epic-product-cost' ); ?>
						</button>
					</form>
				</div>
			<?php endif; ?>

			<form method="post" class="epic-pc-form">
				<?php wp_nonce_field( 'epic_pc_save_costs', 'epic_pc_nonce' ); ?>

				<div class="epic-pc-batch-fields">
					<label>
						<?php esc_html_e( 'Effective date for any costs entered below', 'epic-product-cost' ); ?>
						<input type="date" name="effective_date" value="<?php echo esc_attr( $today ); ?>" required />
					</label>
					<label>
						<?php esc_html_e( 'Note (optional)', 'epic-product-cost' ); ?>
						<input type="text" name="note" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. GIÁ VỐN sheet update', 'epic-product-cost' ); ?>" />
					</label>
				</div>

				<table class="wp-list-table widefat fixed striped epic-pc-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Product', 'epic-product-cost' ); ?></th>
							<th><?php esc_html_e( 'Current cost (250g)', 'epic-product-cost' ); ?></th>
							<th><?php esc_html_e( 'As of', 'epic-product-cost' ); ?></th>
							<th><?php esc_html_e( '500g / 1kg cost', 'epic-product-cost' ); ?></th>
							<th><?php esc_html_e( '250g price', 'epic-product-cost' ); ?></th>
							<th><?php esc_html_e( 'Margin', 'epic-product-cost' ); ?></th>
							<th><?php esc_html_e( 'New cost (250g)', 'epic-product-cost' ); ?></th>
							<th><?php esc_html_e( 'History', 'epic-product-cost' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $products ) ) : ?>
							<tr><td colspan="8"><?php esc_html_e( 'No products found.', 'epic-product-cost' ); ?></td></tr>
						<?php endif; ?>
						<?php foreach ( $products as $product ) : ?>
							<?php
							$current = Epic_Product_Cost_Store::get_cost_as_of( $product->get_id(), $today );
							$price   = Epic_Product_Cost_Store::get_reference_price_250g( $product );
							$margin  = ( $current && $price ) ? $price - (float) $current->cost_250g : null;
							$history_count = count( Epic_Product_Cost_Store::get_history( $product->get_id() ) );
							?>
							<tr>
								<td>
									<strong><a href="<?php echo esc_url( get_edit_post_link( $product->get_id() ) ); ?>"><?php echo esc_html( $product->get_name() ); ?></a></strong>
									<?php if ( 'publish' !== $product->get_status() ) : ?>
										<span class="epic-pc-status">(<?php echo esc_html( $product->get_status() ); ?>)</span>
									<?php endif; ?>
								</td>
								<td>
									<?php echo $current ? esc_html( number_format( (float) $current->cost_250g, 0, ',', '.' ) ) : '—'; ?>
								</td>
								<td><?php echo $current ? esc_html( $current->effective_date ) : '—'; ?></td>
								<td>
									<?php
									if ( $current ) {
										echo esc_html(
											number_format( (float) $current->cost_250g * 2, 0, ',', '.' ) . ' / ' .
											number_format( (float) $current->cost_250g * 4, 0, ',', '.' )
										);
									} else {
										echo '—';
									}
									?>
								</td>
								<td><?php echo null !== $price ? esc_html( number_format( $price, 0, ',', '.' ) ) : '—'; ?></td>
								<td>
									<?php
									if ( null !== $margin && $price ) {
										$pct = $price > 0 ? round( ( $margin / $price ) * 100, 1 ) : 0;
										echo esc_html( number_format( $margin, 0, ',', '.' ) . ' (' . $pct . '%)' );
									} else {
										echo '—';
									}
									?>
								</td>
								<td>
									<input type="number" step="0.01" min="0" name="cost[<?php echo esc_attr( $product->get_id() ); ?>]"
										class="small-text" placeholder="<?php echo $current ? esc_attr( $current->cost_250g ) : ''; ?>" />
								</td>
								<td>
									<?php if ( $history_count > 0 ) : ?>
										<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'epic-product-cost', 'view' => 'history', 'product_id' => $product->get_id() ), admin_url( 'admin.php' ) ) ); ?>">
											<?php
											printf(
												/* translators: %d: number of history entries */
												esc_html( _n( '%d entry', '%d entries', $history_count, 'epic-product-cost' ) ),
												(int) $history_count
											);
											?>
										</a>
									<?php else : ?>
										&mdash;
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php submit_button( __( 'Save costs', 'epic-product-cost' ) ); ?>
			</form>
		</div>
		<?php
	}
}
