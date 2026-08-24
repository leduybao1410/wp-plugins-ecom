<?php
/**
 * The plugin's one wp-admin screen: WooCommerce → Wholesale Inquiries.
 * Shows the log of submitted inquiries (Epic_Wholesale_List_Table, backed
 * by class-store.php) first, then — below it — the single settings field
 * this plugin actually needs: the shared secret the Next.js site must send
 * as `X-Epic-Secret` on every call into its REST route. One field doesn't
 * warrant a whole WC_Settings_Page tab, same pattern as
 * epic-payment-store/includes/class-settings.php.
 *
 * The notification email's own subject/heading/recipient are NOT configured
 * here — those live under WooCommerce → Settings → Emails →
 * "EPIC: Wholesale Inquiry" once the plugin is active, like every other
 * EPIC WC_Email.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Wholesale_Settings {

	const OPTION_KEY = 'epic_wholesale_shared_secret';

	public static function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Wholesale Inquiries', 'epic-wholesale-inquiries' ),
			__( 'Wholesale Inquiries', 'epic-wholesale-inquiries' ),
			'manage_woocommerce',
			'epic-wholesale-inquiries',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register_setting() {
		register_setting(
			'epic_wholesale_inquiries',
			self::OPTION_KEY,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
	}

	public static function get_shared_secret() {
		return get_option( self::OPTION_KEY, '' );
	}

	/**
	 * Handles the list table's per-row "Delete" link — a plain nonce-checked
	 * GET action, same convention WordPress core uses for single-row deletes
	 * in its own list tables (e.g. Posts). Runs at the top of render_page(),
	 * before anything is output, so it can redirect afterward (avoids a
	 * delete firing again on refresh).
	 */
	private static function maybe_handle_delete() {
		if ( ! isset( $_GET['action'], $_GET['id'] ) || 'delete' !== $_GET['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce is checked via check_admin_referer() immediately below.
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$id = (int) $_GET['id']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see check_admin_referer() below.
		check_admin_referer( 'epic_wholesale_delete_' . $id );

		Epic_Wholesale_Store::delete( $id );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'epic-wholesale-inquiries',
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

		// Lazily required — WP_List_Table (which this extends) only exists
		// in wp-admin, so this is loaded here rather than in the
		// unconditional include list in the main plugin file, same
		// "only load an admin-only class from inside the code path that
		// needs it" convention this codebase already uses for
		// WooCommerce-dependent classes.
		require_once EPIC_WHOLESALE_INQUIRIES_DIR . 'includes/class-list-table.php';
		$list_table = new Epic_Wholesale_List_Table();
		$list_table->prepare_items();

		$email_settings_url = admin_url( 'admin.php?page=wc-settings&tab=email&section=epic_wholesale_inquiry' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Wholesale Inquiries', 'epic-wholesale-inquiries' ); ?></h1>

			<?php if ( isset( $_GET['deleted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- just controls whether a notice renders, not a state change. ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Inquiry deleted.', 'epic-wholesale-inquiries' ); ?></p>
				</div>
			<?php endif; ?>

			<?php
			// Table CSS class is WP_List_Table's own `plural` arg
			// ('epic_wholesale_inquiries', see class-list-table.php's
			// constructor) — these selectors target that, not a class we
			// add ourselves.
			//
			// `table-layout: auto` overrides WP_List_Table's own `fixed`
			// class. That matters here: under `fixed` layout, a `width: 1%`
			// hint (the usual "shrink this column to its content" trick) is
			// instead taken LITERALLY as 1% of the table's width — nowhere
			// near enough for "2026-08-21 16:58" or a phone number, so the
			// text overflowed its cell and visually bled into the next
			// column (that's what produced the garbled/overlapping text).
			// `auto` layout sizes each column from its actual content, which
			// is what every width hint below actually wants.
			?>
			<style>
				.epic_wholesale_inquiries { table-layout: auto; }
				.epic_wholesale_inquiries .column-submitted_at,
				.epic_wholesale_inquiries .column-email_status,
				.epic_wholesale_inquiries .column-phone {
					white-space: nowrap;
				}
				.epic_wholesale_inquiries .column-details {
					max-width: 320px;
					overflow-wrap: break-word;
				}
			</style>

			<form method="get">
				<input type="hidden" name="page" value="epic-wholesale-inquiries" />
				<?php $list_table->display(); ?>
			</form>

			<hr style="margin: 32px 0 24px;" />

			<h2><?php esc_html_e( 'Shared secret', 'epic-wholesale-inquiries' ); ?></h2>
			<p>
				<?php esc_html_e( 'This secret authenticates the Next.js website\'s calls into this plugin\'s REST route (submitting a wholesale contact-form lead from the /wholesale page). Set the same value in the website\'s EPIC_WHOLESALE_SHARED_SECRET environment variable — it never leaves your own infrastructure.', 'epic-wholesale-inquiries' ); ?>
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'epic_wholesale_inquiries' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="epic_wholesale_shared_secret"><?php esc_html_e( 'Shared secret', 'epic-wholesale-inquiries' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="epic_wholesale_shared_secret"
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>"
								value="<?php echo esc_attr( self::get_shared_secret() ); ?>"
								class="regular-text code"
								autocomplete="off"
							/>
							<p class="description">
								<?php esc_html_e( 'A long random string. Generate one and paste it here, then copy the same value into the website\'s environment variables.', 'epic-wholesale-inquiries' ); ?>
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
					esc_html__( 'The notification email itself — recipient address, subject, heading — is configured under %s.', 'epic-wholesale-inquiries' ),
					'<a href="' . esc_url( $email_settings_url ) . '">' . esc_html__( 'WooCommerce → Settings → Emails → EPIC: Wholesale Inquiry', 'epic-wholesale-inquiries' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}
}
