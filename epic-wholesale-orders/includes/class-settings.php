<?php
/**
 * The plugin's one wp-admin screen: WooCommerce → Wholesale Orders. Shows the
 * order log (Epic_Wholesale_Orders_List_Table over the CPT) first, then —
 * below it — the two things this plugin actually needs configured:
 *
 *   1. The wholesale-customer whitelist (which registered users can place
 *      wholesale orders). A searchable multi-select reusing WooCommerce's own
 *      `wc-enhanced-select` + `WC_AJAX::json_search_customers` AJAX search —
 *      the same picker the order editor uses — rather than a hand-rolled one.
 *   2. The shared secret the Next.js site must send as `X-Epic-Secret` on
 *      every call into this plugin's REST routes.
 *
 * The notification emails' own subject/heading/recipient live under
 * WooCommerce → Settings → Emails, like every other EPIC email.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Wholesale_Settings {

	const PAGE_SLUG = 'epic-wholesale-orders';

	const OPTION_SECRET   = 'epic_wholesale_orders_shared_secret';
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
		register_setting(
			'epic_wholesale_orders',
			self::OPTION_SECRET,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		register_setting(
			'epic_wholesale_orders',
			self::OPTION_CUSTOMERS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_customers' ),
				'default'           => array(),
			)
		);
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

	public static function sanitize_customers( $value ) {
		$ids = is_array( $value ) ? $value : array();
		$clean = array();
		foreach ( $ids as $id ) {
			$id = absint( $id );
			if ( $id > 0 ) {
				$clean[ $id ] = $id;
			}
		}
		return array_values( $clean );
	}

	public static function get_shared_secret() {
		return get_option( self::OPTION_SECRET, '' );
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

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'deleted' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		self::maybe_handle_delete();

		require_once EPIC_WHOLESALE_ORDERS_DIR . 'includes/class-list-table.php';
		$list_table = new Epic_Wholesale_Orders_List_Table();
		$list_table->prepare_items();

		$customers   = Epic_Wholesale_Orders_Store::get_customers();
		$email_settings_url = admin_url( 'admin.php?page=wc-settings&tab=email&section=epic_wholesale_order_admin' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Wholesale Orders', 'epic-wholesale-orders' ); ?></h1>

			<?php if ( isset( $_GET['deleted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- just controls whether a notice renders. ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Wholesale order deleted.', 'epic-wholesale-orders' ); ?></p>
				</div>
			<?php endif; ?>

			<style>
				.epic_wholesale_orders { table-layout: auto; }
				.epic_wholesale_orders .column-order_ref,
				.epic_wholesale_orders .column-date,
				.epic_wholesale_orders .column-payment,
				.epic_wholesale_orders .column-status,
				.epic_wholesale_orders .column-emails {
					white-space: nowrap;
				}
			</style>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<?php $list_table->display(); ?>
			</form>

			<hr style="margin: 32px 0 24px;" />

			<form method="post" action="options.php">
				<?php settings_fields( 'epic_wholesale_orders' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label><?php esc_html_e( 'Wholesale customers', 'epic-wholesale-orders' ); ?></label>
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
