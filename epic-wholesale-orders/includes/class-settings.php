<?php
/**
 * The plugin's one wp-admin screen: WooCommerce → Wholesale Orders. Top to
 * bottom:
 *
 *   1. The order log (Epic_Wholesale_Orders_List_Table over the CPT).
 *   2. **Price levels** — admin defines pricing levels (name + % discount off
 *      the base wholesale price). Each wholesale customer is assigned one
 *      level; the storefront shows prices discounted for their level.
 *   3. **Wholesale customers** — which registered users can place wholesale
 *      orders (a searchable multi-select reusing WooCommerce's
 *      `wc-enhanced-select` + `WC_AJAX::json_search_customers`), plus which
 *      level each customer is on (defaults to the site's default level).
 *   4. **Shared secret** — authenticates the Next.js site's calls into this
 *      plugin's REST routes.
 *
 * Sections 2 and 3 post to admin-post.php handlers (nonce-checked); section 4
 * uses the standard options.php form. The notification emails' own
 * subject/heading/recipient live under WooCommerce → Settings → Emails.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Wholesale_Orders_Settings {

	const PAGE_SLUG = 'epic-wholesale-orders';

	const OPTION_SECRET    = 'epic_wholesale_orders_shared_secret';
	const OPTION_CUSTOMERS = 'epic_wholesale_orders_customers';

	public static function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Wholesale Orders', 'epic-wholesale-orders' ),
			__( 'Wholesale Orders', 'epic-wholesale-orders' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register_setting() {
		// Shared secret only — the whitelist and levels are saved via the
		// admin_post handlers below, not the options.php form.
		register_setting(
			'epic_wholesale_orders',
			self::OPTION_SECRET,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		add_action( 'admin_post_epic_wo_save_levels', array( __CLASS__, 'handle_save_levels' ) );
		add_action( 'admin_post_epic_wo_add_level', array( __CLASS__, 'handle_add_level' ) );
		add_action( 'admin_post_epic_wo_save_customers', array( __CLASS__, 'handle_save_customers' ) );
	}

	/**
	 * WooCommerce's `wc-enhanced-select` script auto-initialises elements with
	 * the `wc-customer-search` class on every page where it's loaded — we just
	 * need to load it (and its styles) on our own screen.
	 */
	public static function enqueue_admin_assets( $hook ) {
		if ( 'woocommerce_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_script( 'wc-enhanced-select' );
	}

	public static function get_shared_secret() {
		return get_option( self::OPTION_SECRET, '' );
	}

	// ------------------------------------------------------------------
	// admin-post handlers
	// ------------------------------------------------------------------

	private static function redirect_with_notice( $notice ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::PAGE_SLUG,
					'notice' => $notice,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/** Saves the price levels (edit / delete / default). */
	public static function handle_save_levels() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'epic-wholesale-orders' ) );
		}
		check_admin_referer( 'epic_wo_save_levels' );

		$raw_levels = isset( $_POST['levels'] ) && is_array( $_POST['levels'] ) ? wp_unslash( $_POST['levels'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per-field below.

		$levels = array();
		foreach ( $raw_levels as $key => $level ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			if ( ! empty( $level['delete'] ) ) {
				continue;
			}
			$levels[ $key ] = array(
				'name'     => isset( $level['name'] ) ? sanitize_text_field( (string) $level['name'] ) : '',
				'discount' => isset( $level['discount'] ) ? (float) $level['discount'] : 0,
			);
		}

		$default_key = isset( $_POST['levels_default'] ) ? sanitize_key( (string) $_POST['levels_default'] ) : '';

		Epic_Wholesale_Orders_Store::save_levels( $levels, $default_key );

		self::redirect_with_notice( 'levels_saved' );
	}

	/** Appends one new level at the default discount and redirects back. */
	public static function handle_add_level() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'epic-wholesale-orders' ) );
		}
		check_admin_referer( 'epic_wo_add_level' );

		$levels = Epic_Wholesale_Orders_Store::get_levels();

		$next = 1;
		foreach ( array_keys( $levels ) as $key ) {
			if ( preg_match( '/^level_(\d+)$/', $key, $m ) ) {
				$next = max( $next, (int) $m[1] + 1 );
			}
		}
		$new_key = 'level_' . $next;
		$levels[ $new_key ] = array(
			'name'     => sprintf( /* translators: %d: level number */ __( 'Level %d', 'epic-wholesale-orders' ), $next ),
			'discount' => 0,
		);

		Epic_Wholesale_Orders_Store::save_levels( $levels, Epic_Wholesale_Orders_Store::get_default_level_key() );

		self::redirect_with_notice( 'level_added' );
	}

	/** Saves the whitelist (searchable multi-select) + each customer's level. */
	public static function handle_save_customers() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'epic-wholesale-orders' ) );
		}
		check_admin_referer( 'epic_wo_save_customers' );

		$raw_ids = isset( $_POST[ self::OPTION_CUSTOMERS ] ) && is_array( $_POST[ self::OPTION_CUSTOMERS ] ) ? wp_unslash( $_POST[ self::OPTION_CUSTOMERS ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.

		$ids = array();
		foreach ( $raw_ids as $id ) {
			$id = absint( $id );
			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}
		$ids = array_values( $ids );

		Epic_Wholesale_Orders_Store::set_customers( $ids );

		// Assign the posted levels to the still-whitelisted users; drop the
		// level meta for users no longer whitelisted.
		$raw_levels = isset( $_POST['customer_levels'] ) && is_array( $_POST['customer_levels'] ) ? wp_unslash( $_POST['customer_levels'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via Store::set_customer_level().

		$whitelist = Epic_Wholesale_Orders_Store::get_customers();
		foreach ( $whitelist as $user_id ) {
			if ( isset( $raw_levels[ $user_id ] ) ) {
				Epic_Wholesale_Orders_Store::set_customer_level( $user_id, (string) $raw_levels[ $user_id ] );
			}
		}
		foreach ( array_keys( $raw_levels ) as $user_id ) {
			if ( ! in_array( (int) $user_id, $whitelist, true ) ) {
				delete_user_meta( (int) $user_id, Epic_Wholesale_Orders_Store::USER_META_LEVEL );
			}
		}

		self::redirect_with_notice( 'customers_saved' );
	}

	/**
	 * Handles the list table's per-row "Delete" link — a plain nonce-checked
	 * GET action, same convention as epic-wholesale-inquiries.
	 */
	private static function maybe_handle_delete() {
		if ( ! isset( $_GET['action'], $_GET['id'] ) || 'delete' !== $_GET['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked via check_admin_referer() below.
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$id = (int) $_GET['id']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see check_admin_referer() below.
		check_admin_referer( 'epic_wholesale_order_delete_' . $id );

		Epic_Wholesale_Orders_Store::delete( $id );

		self::redirect_with_notice( 'deleted' );
	}

	// ------------------------------------------------------------------
	// Page
	// ------------------------------------------------------------------

	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		self::maybe_handle_delete();

		require_once EPIC_WHOLESALE_ORDERS_DIR . 'includes/class-list-table.php';
		$list_table = new Epic_Wholesale_Orders_List_Table();
		$list_table->prepare_items();

		$levels     = Epic_Wholesale_Orders_Store::get_levels();
		$default    = Epic_Wholesale_Orders_Store::get_default_level_key();
		$customers  = Epic_Wholesale_Orders_Store::get_customers();
		$email_settings_url = admin_url( 'admin.php?page=wc-settings&tab=email&section=epic_wholesale_order_admin' );

		$notices = array(
			'deleted'         => __( 'Wholesale order deleted.', 'epic-wholesale-orders' ),
			'levels_saved'    => __( 'Price levels saved.', 'epic-wholesale-orders' ),
			'level_added'     => __( 'A new price level was added.', 'epic-wholesale-orders' ),
			'customers_saved' => __( 'Wholesale customers saved.', 'epic-wholesale-orders' ),
		);
		$notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice display.
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Wholesale Orders', 'epic-wholesale-orders' ); ?></h1>

			<?php if ( isset( $notices[ $notice ] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html( $notices[ $notice ] ); ?></p>
				</div>
			<?php endif; ?>

			<style>
				.epic_wholesale_orders { table-layout: auto; }
				.epic_wholesale_orders .column-order_ref,
				.epic_wholesale_orders .column-date,
				.epic_wholesale_orders .column-payment,
				.epic_wholesale_orders .column-status,
				.epic_wholesale_orders .column-level,
				.epic_wholesale_orders .column-emails {
					white-space: nowrap;
				}
				.epic-wo-levels input.wide { width: 100%; }
			</style>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<?php $list_table->display(); ?>
			</form>

			<hr style="margin: 32px 0 24px;" />

			<h2><?php esc_html_e( 'Price levels', 'epic-wholesale-orders' ); ?></h2>
			<p class="description" style="max-width:720px;">
				<?php esc_html_e( 'Levels define a percentage discount off each product\'s base wholesale price (the "Wholesale price" set on the product). A customer on Level 2 with a 10% discount pays the base price minus 10%. Prices on the storefront are computed from the customer\'s assigned level.', 'epic-wholesale-orders' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'epic_wo_save_levels' ); ?>
				<input type="hidden" name="action" value="epic_wo_save_levels" />
				<table class="widefat striped" style="max-width:720px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Name', 'epic-wholesale-orders' ); ?></th>
							<th style="width:120px;"><?php esc_html_e( 'Discount %', 'epic-wholesale-orders' ); ?></th>
							<th style="width:110px;"><?php esc_html_e( 'Default', 'epic-wholesale-orders' ); ?></th>
							<th style="width:80px;"><?php esc_html_e( 'Delete', 'epic-wholesale-orders' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $levels as $key => $level ) : ?>
							<tr>
								<td>
									<input
										type="text"
										class="wide"
										name="levels[<?php echo esc_attr( $key ); ?>][name]"
										value="<?php echo esc_attr( $level['name'] ); ?>"
									/>
								</td>
								<td>
									<input
										type="number"
										min="0"
										max="100"
										step="any"
										name="levels[<?php echo esc_attr( $key ); ?>][discount]"
										value="<?php echo esc_attr( $level['discount'] ); ?>"
									/>
								</td>
								<td>
									<input
										type="radio"
										name="levels_default"
										value="<?php echo esc_attr( $key ); ?>"
										<?php checked( $default, $key ); ?>
									/>
								</td>
								<td>
									<input
										type="checkbox"
										name="levels[<?php echo esc_attr( $key ); ?>][delete]"
										value="1"
									/>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'At least one level must remain. Deleting a level does not delete orders — orders keep the level name they were placed with.', 'epic-wholesale-orders' ); ?></p>
				<?php submit_button( __( 'Save levels', 'epic-wholesale-orders' ), 'primary', 'save_levels_submit' ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'epic_wo_add_level' ); ?>
				<input type="hidden" name="action" value="epic_wo_add_level" />
				<?php submit_button( __( 'Add level', 'epic-wholesale-orders' ), 'secondary', 'add_level_submit', false ); ?>
			</form>

			<hr style="margin: 32px 0 24px;" />

			<h2><?php esc_html_e( 'Wholesale customers', 'epic-wholesale-orders' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'epic_wo_save_customers' ); ?>
				<input type="hidden" name="action" value="epic_wo_save_customers" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label><?php esc_html_e( 'Customers', 'epic-wholesale-orders' ); ?></label>
						</th>
						<td>
							<select
								id="epic_wholesale_orders_customers"
								name="<?php echo esc_attr( self::OPTION_CUSTOMERS ); ?>[]"
								class="wc-customer-search"
								multiple="multiple"
								data-placeholder="<?php esc_attr_e( 'Search for customers to whitelist…', 'epic-wholesale-orders' ); ?>"
								data-allow_clear="true"
							>
								<?php foreach ( $customers as $user_id ) : ?>
									<?php
									$user = get_userdata( $user_id );
									if ( ! $user ) {
										continue;
									}
									?>
									<option value="<?php echo esc_attr( $user_id ); ?>" selected>
										<?php echo esc_html( $user->user_email . ' (' . $user->display_name . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Only these users can place wholesale orders, and only they see wholesale prices on the storefront\'s wholesale page. Removing a user blocks new orders but keeps their past orders.', 'epic-wholesale-orders' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label><?php esc_html_e( 'Customer levels', 'epic-wholesale-orders' ); ?></label>
						</th>
						<td>
							<?php if ( empty( $customers ) ) : ?>
								<p class="description"><?php esc_html_e( 'No wholesale customers yet — add some above.', 'epic-wholesale-orders' ); ?></p>
							<?php else : ?>
								<table class="widefat striped" style="max-width:720px;">
									<thead>
										<tr>
											<th><?php esc_html_e( 'Customer', 'epic-wholesale-orders' ); ?></th>
											<th style="width:220px;"><?php esc_html_e( 'Level', 'epic-wholesale-orders' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $customers as $user_id ) : ?>
											<?php
											$user = get_userdata( $user_id );
											if ( ! $user ) {
												continue;
											}
											?>
											<tr>
												<td>
													<strong><?php echo esc_html( $user->display_name ); ?></strong>
													<br/>
													<a href="mailto:<?php echo esc_attr( $user->user_email ); ?>"><?php echo esc_html( $user->user_email ); ?></a>
												</td>
												<td>
													<select name="customer_levels[<?php echo esc_attr( $user_id ); ?>]">
														<?php foreach ( $levels as $key => $level ) : ?>
															<option value="<?php echo esc_attr( $key ); ?>" <?php selected( Epic_Wholesale_Orders_Store::get_customer_level( $user_id ), $key ); ?>>
																<?php echo esc_html( $level['name'] ); ?>
																<?php if ( $level['discount'] > 0 ) : ?>
																	(<?php echo esc_html( (float) $level['discount'] ); ?>%)
																<?php endif; ?>
															</option>
														<?php endforeach; ?>
													</select>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							<?php endif; ?>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save customers', 'epic-wholesale-orders' ), 'primary', 'save_customers_submit' ); ?>
			</form>

			<hr style="margin: 32px 0 24px;" />

			<h2><?php esc_html_e( 'Shared secret', 'epic-wholesale-orders' ); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields( 'epic_wholesale_orders' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="epic_wholesale_orders_shared_secret"><?php esc_html_e( 'Shared secret', 'epic-wholesale-orders' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="epic_wholesale_orders_shared_secret"
								name="<?php echo esc_attr( self::OPTION_SECRET ); ?>"
								value="<?php echo esc_attr( self::get_shared_secret() ); ?>"
								class="regular-text code"
								autocomplete="off"
							/>
							<p class="description">
								<?php esc_html_e( 'Authenticates the Next.js website\'s calls into this plugin\'s REST routes. Set the same value in the website\'s EPIC_WHOLESALE_ORDERS_SHARED_SECRET environment variable.', 'epic-wholesale-orders' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<p>
				<?php
				printf(
					/* translators: %s: link to the WooCommerce email settings screen */
					esc_html__( 'To offer a product wholesale, open it in Products → edit and enable the "Wholesale" box (variable products: per variation). Notification emails are configured under %s.', 'epic-wholesale-orders' ),
					'<a href="' . esc_url( $email_settings_url ) . '">' . esc_html__( 'WooCommerce → Settings → Emails → EPIC: Wholesale Order', 'epic-wholesale-orders' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}
}
