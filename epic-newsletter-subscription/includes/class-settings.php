<?php
/**
 * The plugin's one wp-admin screen: WooCommerce → Newsletter Subscribers.
 * Shows the list of subscribers (Epic_Newsletter_List_Table, backed by
 * class-store.php) first, then — below it — the single settings field this
 * plugin actually needs: the shared secret the Next.js site must send as
 * `X-Epic-Secret` on every call into its REST route. One field doesn't
 * warrant a whole WC_Settings_Page tab, same pattern as
 * epic-wholesale-inquiries/includes/class-settings.php.
 *
 * The notification email's own subject/heading/recipient are NOT configured
 * here — those live under WooCommerce → Settings → Emails →
 * "EPIC: Newsletter Subscriber" once the plugin is active, like every other
 * EPIC WC_Email.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Newsletter_Settings {

	const OPTION_KEY = 'epic_newsletter_shared_secret';

	public static function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Newsletter Subscribers', 'epic-newsletter-subscription' ),
			__( 'Newsletter Subscribers', 'epic-newsletter-subscription' ),
			'manage_woocommerce',
			'epic-newsletter-subscription',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register_setting() {
		register_setting(
			'epic_newsletter_subscription',
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
		check_admin_referer( 'epic_newsletter_delete_' . $id );

		Epic_Newsletter_Store::delete( $id );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'epic-newsletter-subscription',
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
		// needs it" convention this codebase already uses.
		require_once EPIC_NEWSLETTER_SUBSCRIPTION_DIR . 'includes/class-list-table.php';
		$list_table = new Epic_Newsletter_List_Table();
		$list_table->prepare_items();

		$email_settings_url = admin_url( 'admin.php?page=wc-settings&tab=email&section=epic_newsletter_subscription' );

		$csv_export_url  = wp_nonce_url(
			add_query_arg(
				array(
					'page'                    => 'epic-newsletter-subscription',
					'epic_newsletter_export'  => 'csv',
				),
				admin_url( 'admin.php' )
			),
			Epic_Newsletter_Export::NONCE_ACTION
		);
		$xlsx_export_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'                    => 'epic-newsletter-subscription',
					'epic_newsletter_export'  => 'xlsx',
				),
				admin_url( 'admin.php' )
			),
			Epic_Newsletter_Export::NONCE_ACTION
		);
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Newsletter Subscribers', 'epic-newsletter-subscription' ); ?></h1>
			<a href="<?php echo esc_url( $csv_export_url ); ?>" class="page-title-action"><?php esc_html_e( 'Export CSV', 'epic-newsletter-subscription' ); ?></a>
			<a href="<?php echo esc_url( $xlsx_export_url ); ?>" class="page-title-action"><?php esc_html_e( 'Export XLSX', 'epic-newsletter-subscription' ); ?></a>
			<hr class="wp-header-end" />

			<?php if ( isset( $_GET['deleted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- just controls whether a notice renders, not a state change. ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Subscriber deleted.', 'epic-newsletter-subscription' ); ?></p>
				</div>
			<?php endif; ?>

			<style>
				.epic_newsletter_subscribers { table-layout: auto; }
				.epic_newsletter_subscribers .column-subscribed_at,
				.epic_newsletter_subscribers .column-email_status,
				.epic_newsletter_subscribers .column-confirm_status,
				.epic_newsletter_subscribers .column-locale {
					white-space: nowrap;
				}
			</style>

			<form method="get">
				<input type="hidden" name="page" value="epic-newsletter-subscription" />
				<?php $list_table->display(); ?>
			</form>

			<hr style="margin: 32px 0 24px;" />

			<h2><?php esc_html_e( 'Shared secret', 'epic-newsletter-subscription' ); ?></h2>
			<p>
				<?php esc_html_e( 'This secret authenticates the Next.js website\'s calls into this plugin\'s REST route (submitting a newsletter subscription from the footer box). Set the same value in the website\'s EPIC_NEWSLETTER_SHARED_SECRET environment variable — it never leaves your own infrastructure.', 'epic-newsletter-subscription' ); ?>
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'epic_newsletter_subscription' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="epic_newsletter_shared_secret"><?php esc_html_e( 'Shared secret', 'epic-newsletter-subscription' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="epic_newsletter_shared_secret"
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>"
								value="<?php echo esc_attr( self::get_shared_secret() ); ?>"
								class="regular-text code"
								autocomplete="off"
							/>
							<p class="description">
								<?php esc_html_e( 'A long random string. Generate one and paste it here, then copy the same value into the website\'s environment variables.', 'epic-newsletter-subscription' ); ?>
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
					esc_html__( 'The notification email itself — recipient address, subject, heading — is configured under %s.', 'epic-newsletter-subscription' ),
					'<a href="' . esc_url( $email_settings_url ) . '">' . esc_html__( 'WooCommerce → Settings → Emails → EPIC: Newsletter Subscriber', 'epic-newsletter-subscription' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}
}
