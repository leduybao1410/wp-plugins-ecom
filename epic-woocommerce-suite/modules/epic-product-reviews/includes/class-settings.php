<?php
/**
 * The plugin's one wp-admin screen: WooCommerce → Product Reviews. Shows the
 * moderation log (Epic_Reviews_List_Table, backed by class-store.php) with
 * per-row Approve / Unapprove / Delete actions and a pending/approved/all
 * filter, then — below it — the single settings field this plugin actually
 * needs: the shared secret the Next.js site must send as `X-Epic-Secret` on
 * every POST into its REST route. One field doesn't warrant a whole
 * WC_Settings_Page tab, same pattern as epic-wholesale-inquiries.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Reviews_Settings {

	const OPTION_KEY = 'epic_product_reviews_shared_secret';

	public static function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Product Reviews', 'epic-product-reviews' ),
			__( 'Product Reviews', 'epic-product-reviews' ),
			'manage_woocommerce',
			'epic-product-reviews',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register_setting() {
		register_setting(
			'epic_product_reviews',
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
	 * Handles the list table's per-row "Approve"/"Unapprove"/"Delete"
	 * actions — plain nonce-checked GET actions, same convention
	 * epic-wholesale-inquiries uses for its Delete. Runs at the top of
	 * render_page(), before anything is output, so it can redirect
	 * afterward (avoids a state change firing again on refresh).
	 */
	private static function maybe_handle_action() {
		if ( ! isset( $_GET['action'], $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce is checked via check_admin_referer() immediately below.
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_GET['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see check_admin_referer() below.
		if ( ! in_array( $action, array( 'approve', 'unapprove', 'delete' ), true ) ) {
			return;
		}

		$id = (int) $_GET['id']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see check_admin_referer() below.
		check_admin_referer( 'epic_reviews_' . $action . '_' . $id );

		if ( 'delete' === $action ) {
			Epic_Reviews_Store::delete( $id );
		} else {
			Epic_Reviews_Store::set_status(
				$id,
				'approve' === $action ? Epic_Reviews_Store::STATUS_APPROVED : Epic_Reviews_Store::STATUS_PENDING
			);
		}

		$redirect = array(
			'page'       => 'epic-product-reviews',
			'updated'    => '1',
			'status'     => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter value echoed back onto the redirect.
		);
		if ( isset( $_GET['paged'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination echoed back onto the redirect.
			$redirect['paged'] = max( 1, absint( wp_unslash( $_GET['paged'] ) ) );
		}
		if ( isset( $_GET['per_page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination size echoed back onto the redirect.
			$redirect['per_page'] = absint( wp_unslash( $_GET['per_page'] ) );
		}

		wp_safe_redirect( add_query_arg( $redirect, admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		self::maybe_handle_action();

		// Lazily required — WP_List_Table (which this extends) only exists
		// in wp-admin, so this is loaded here rather than in the
		// unconditional include list in the main plugin file, same
		// "only load an admin-only class from inside the code path that
		// needs it" convention this codebase already uses.
		require_once EPIC_PRODUCT_REVIEWS_DIR . 'includes/class-list-table.php';
		$list_table = new Epic_Reviews_List_Table();
		$list_table->prepare_items();

		$pending_count = Epic_Reviews_Store::count( Epic_Reviews_Store::STATUS_PENDING );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Product Reviews', 'epic-product-reviews' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- just controls whether a notice renders, not a state change. ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Review updated.', 'epic-product-reviews' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $pending_count > 0 ) : ?>
				<div class="notice notice-warning is-dismissible">
					<p>
						<?php
						printf(
							/* translators: %d: number of reviews awaiting approval */
							esc_html( _n( '%d review is awaiting approval. Approve it to publish it on the site and in structured data.', '%d reviews are awaiting approval. Approve them to publish them on the site and in structured data.', $pending_count, 'epic-product-reviews' ) ),
							(int) $pending_count
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<form method="get" id="epic-reviews-filter" onsubmit="return epic_reviews_confirm_bulk_delete( this );">
				<input type="hidden" name="page" value="epic-product-reviews" />
				<?php wp_nonce_field( 'bulk-' . $list_table->_args['plural'] ); ?>
				<?php $list_table->display(); ?>
			</form>
			<script>
				( function () {
					var message = <?php echo wp_json_encode( __( 'Delete the selected reviews permanently? This cannot be undone.', 'epic-product-reviews' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode() output is safe inside a <script> context. ?>;
					window.epic_reviews_confirm_bulk_delete = function ( form ) {
						var selects  = form.querySelectorAll( 'select[name="action"], select[name="action2"]' );
						var selected = '';
						for ( var i = 0; i < selects.length; i++ ) {
							if ( '-1' !== selects[i].value ) {
								selected = selects[i].value;
								break;
							}
						}
						if ( 'delete' !== selected || 0 === form.querySelectorAll( 'input[name="review_ids[]"]:checked' ).length ) {
							return true;
						}
						return window.confirm( message );
					};
				} )();
			</script>

			<hr style="margin: 32px 0 24px;" />

			<h2><?php esc_html_e( 'Shared secret', 'epic-product-reviews' ); ?></h2>
			<p>
				<?php esc_html_e( 'This secret authenticates the Next.js website\'s calls into this plugin\'s REST route (submitting a customer review from the product detail page). Set the same value in the website\'s EPIC_REVIEWS_SHARED_SECRET environment variable — it never leaves your own infrastructure. Reading reviews is public and needs no secret.', 'epic-product-reviews' ); ?>
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'epic_product_reviews' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="epic_product_reviews_shared_secret"><?php esc_html_e( 'Shared secret', 'epic-product-reviews' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="epic_product_reviews_shared_secret"
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>"
								value="<?php echo esc_attr( self::get_shared_secret() ); ?>"
								class="regular-text code"
								autocomplete="off"
							/>
							<p class="description">
								<?php esc_html_e( 'A long random string. Generate one and paste it here, then copy the same value into the website\'s environment variables.', 'epic-product-reviews' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
