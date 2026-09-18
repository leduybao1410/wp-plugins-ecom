<?php
/**
 * This module's one wp-admin screen: WooCommerce → Sample Requests. Shows
 * the log of submitted requests (Epic_Sample_List_Table, backed by
 * class-store.php) first, then — below it — the single settings field this
 * module actually needs: the shared secret the Next.js site must send as
 * `X-Epic-Secret` on every call into its REST route.
 *
 * The notification email's own subject/heading/recipient are NOT configured
 * here — those live under WooCommerce → Settings → Emails → "EPIC: Sample
 * Request" once the module is active, like every other EPIC WC_Email.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Sample_Settings {

	const OPTION_KEY = 'epic_sample_shared_secret';

	public static function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Sample Requests', 'epic-sample-requests' ),
			__( 'Sample Requests', 'epic-sample-requests' ),
			'manage_woocommerce',
			'epic-sample-requests',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register_setting() {
		register_setting(
			'epic_sample_requests',
			self::OPTION_KEY,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_shared_secret' ),
				'default'           => '',
			)
		);
	}

	public static function get_shared_secret() {
		return get_option( self::OPTION_KEY, '' );
	}

	/**
	 * Keeps the stored secret when the masked field is submitted empty. The
	 * field renders blank on purpose (see render_page) so the value never
	 * appears in the page source; an empty POST therefore means "leave the
	 * saved secret alone", not "clear it".
	 */
	public static function sanitize_shared_secret( $value ) {
		$value = sanitize_text_field( $value );
		return '' === $value ? self::get_shared_secret() : $value;
	}

	/**
	 * Handles the list table's per-row "Delete" link — a plain nonce-checked
	 * GET action, same convention WordPress core uses for single-row deletes
	 * in its own list tables.
	 */
	private static function maybe_handle_delete() {
		if ( ! isset( $_GET['action'], $_GET['id'] ) || 'delete' !== $_GET['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce is checked via check_admin_referer() immediately below.
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$id = (int) $_GET['id']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see check_admin_referer() below.
		check_admin_referer( 'epic_sample_delete_' . $id );

		Epic_Sample_Store::delete( $id );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'epic-sample-requests',
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
		// unconditional include list in the main module file.
		require_once EPIC_SAMPLE_REQUESTS_DIR . 'includes/class-list-table.php';
		$list_table = new Epic_Sample_List_Table();
		$list_table->prepare_items();

		$email_settings_url = admin_url( 'admin.php?page=wc-settings&tab=email&section=epic_sample_request' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Sample Requests', 'epic-sample-requests' ); ?></h1>

			<?php if ( isset( $_GET['deleted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- just controls whether a notice renders, not a state change. ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Sample request deleted.', 'epic-sample-requests' ); ?></p>
				</div>
			<?php endif; ?>

			<style>
				.epic_sample_requests { table-layout: auto; }
				.epic_sample_requests .column-submitted_at,
				.epic_sample_requests .column-email_status,
				.epic_sample_requests .column-confirm_status,
				.epic_sample_requests .column-email,
				.epic_sample_requests .column-phone {
					white-space: nowrap;
				}
				.epic_sample_requests .column-address {
					max-width: 320px;
					overflow-wrap: break-word;
				}
			</style>

			<form method="get">
				<input type="hidden" name="page" value="epic-sample-requests" />
				<?php $list_table->display(); ?>
			</form>

			<hr style="margin: 32px 0 24px;" />

			<h2><?php esc_html_e( 'Shared secret', 'epic-sample-requests' ); ?></h2>
			<p>
				<?php esc_html_e( 'This secret authenticates the Next.js website\'s calls into this module\'s REST route (submitting a free-sample request from the /roastery page). Set the same value in the website\'s EPIC_SAMPLE_SHARED_SECRET environment variable — it never leaves your own infrastructure.', 'epic-sample-requests' ); ?>
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'epic_sample_requests' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="epic_sample_shared_secret"><?php esc_html_e( 'Shared secret', 'epic-sample-requests' ); ?></label>
						</th>
						<td>
							<?php $epic_secret_is_set = '' !== self::get_shared_secret(); ?>
							<input
								type="password"
								id="epic_sample_shared_secret"
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>"
								value=""
								placeholder="<?php echo esc_attr( $epic_secret_is_set ? '••••••••••' : '' ); ?>"
								class="regular-text code"
								autocomplete="new-password"
							/>
							<p class="description">
								<?php esc_html_e( 'A long random string. Generate one and paste it here, then copy the same value into the website\'s environment variables.', 'epic-sample-requests' ); ?>
								<?php if ( $epic_secret_is_set ) : ?>
									<br />
									<?php esc_html_e( 'A secret is saved. Leave this field blank to keep it, or paste a new value to replace it.', 'epic-sample-requests' ); ?>
								<?php endif; ?>
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
					esc_html__( 'The notification email itself — recipient address, subject, heading — is configured under %s.', 'epic-sample-requests' ),
					'<a href="' . esc_url( $email_settings_url ) . '">' . esc_html__( 'WooCommerce → Settings → Emails → EPIC: Sample Request', 'epic-sample-requests' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}
}
