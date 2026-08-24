<?php
/**
 * WooCommerce → Send Newsletter — the wp-admin screen for composing and
 * sending a bulk campaign (class-campaign-store.php for storage,
 * class-broadcast-sender.php for actually delivering it).
 *
 * Deliberately plain wp-admin markup (form-table, submit_button(), a
 * <table class="widefat">) rather than a WP_List_Table or any JS framework
 * — consistent with every other admin screen in this plugin, and this
 * screen's own "list" (past campaigns) is a handful of rows at most, never
 * paginated data.
 *
 * Three sub-views, all on the same `page=epic-newsletter-broadcast` slug,
 * switched on `$_GET['view']`:
 *  - (default) the composer form + a table of past campaigns.
 *  - `view=campaign&id=N` — one campaign's detail: preview, progress
 *    (sent/failed/total, refreshed only by reloading the page — there's no
 *    AJAX poller here), "Send now" (drafts only), "Send test", and the
 *    delivery-log CSV/XLSX export links (class-export.php).
 *
 * All four state-changing actions below (create/send/test/delete) are
 * handled inside maybe_handle_actions(), called at the very top of
 * render_page() before any output — same convention
 * class-settings.php::maybe_handle_delete() uses — so each can
 * wp_safe_redirect() afterward (prevents a resubmit-on-refresh re-triggering
 * a send).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Newsletter_Broadcast_Admin {

	const PAGE_SLUG = 'epic-newsletter-broadcast';

	public static function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Send Newsletter', 'epic-newsletter-subscription' ),
			__( 'Send Newsletter', 'epic-newsletter-subscription' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		require_once EPIC_NEWSLETTER_SUBSCRIPTION_DIR . 'includes/class-campaign-store.php';
		require_once EPIC_NEWSLETTER_SUBSCRIPTION_DIR . 'includes/class-broadcast-sender.php';

		self::maybe_handle_actions();

		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch, not a state change.

		echo '<div class="wrap">';
		self::render_notices();

		if ( 'campaign' === $view && ! empty( $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
			self::render_campaign_view( (int) $_GET['id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
		} else {
			self::render_list_and_composer();
		}

		echo '</div>';
	}

	// ------------------------------------------------------------------
	// State-changing actions
	// ------------------------------------------------------------------

	private static function maybe_handle_actions() {
		if ( empty( $_POST['epic_newsletter_broadcast_action'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['epic_newsletter_broadcast_action'] ) );

		switch ( $action ) {
			case 'create':
				self::handle_create();
				break;
			case 'send':
				self::handle_send();
				break;
			case 'test':
				self::handle_test();
				break;
			case 'delete':
				self::handle_delete();
				break;
		}
	}

	private static function handle_create() {
		check_admin_referer( 'epic_newsletter_broadcast_create' );

		$subject_vi = sanitize_text_field( wp_unslash( $_POST['subject_vi'] ?? '' ) );
		$body_vi    = wp_kses_post( wp_unslash( $_POST['body_vi'] ?? '' ) );

		if ( '' === trim( $subject_vi ) || '' === trim( wp_strip_all_tags( $body_vi ) ) ) {
			set_transient( 'epic_newsletter_broadcast_notice', array( 'type' => 'error', 'text' => __( 'A Vietnamese subject and body are required (English is optional).', 'epic-newsletter-subscription' ) ), 60 );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
			exit;
		}

		$recipient_filter = sanitize_key( wp_unslash( $_POST['recipient_filter'] ?? 'all' ) );
		if ( ! in_array( $recipient_filter, array( 'all', 'vi', 'en' ), true ) ) {
			$recipient_filter = 'all';
		}

		$campaign_id = Epic_Newsletter_Campaign_Store::create_draft(
			array(
				'subject_vi'       => $subject_vi,
				'subject_en'       => sanitize_text_field( wp_unslash( $_POST['subject_en'] ?? '' ) ),
				'body_vi'          => $body_vi,
				'body_en'          => wp_kses_post( wp_unslash( $_POST['body_en'] ?? '' ) ),
				'recipient_filter' => $recipient_filter,
			)
		);

		Epic_Newsletter_Campaign_Store::snapshot_recipients( $campaign_id, $recipient_filter );

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => self::PAGE_SLUG, 'view' => 'campaign', 'id' => $campaign_id, 'created' => '1' ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private static function handle_send() {
		$campaign_id = (int) ( $_POST['campaign_id'] ?? 0 );
		check_admin_referer( 'epic_newsletter_broadcast_send_' . $campaign_id );

		if ( empty( $_POST['confirm_send'] ) ) {
			set_transient( 'epic_newsletter_broadcast_notice', array( 'type' => 'error', 'text' => __( 'Check the confirmation box to send.', 'epic-newsletter-subscription' ) ), 60 );
		} else {
			$started = Epic_Newsletter_Broadcast_Sender::start( $campaign_id );
			set_transient(
				'epic_newsletter_broadcast_notice',
				$started
					? array( 'type' => 'success', 'text' => __( 'Sending started — recipients will go out in the background over the next few minutes. Reload this page to see progress.', 'epic-newsletter-subscription' ) )
					: array( 'type' => 'error', 'text' => __( 'Could not start sending — this campaign may have already been sent.', 'epic-newsletter-subscription' ) ),
				60
			);
		}

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'view' => 'campaign', 'id' => $campaign_id ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function handle_test() {
		$campaign_id = (int) ( $_POST['campaign_id'] ?? 0 );
		check_admin_referer( 'epic_newsletter_broadcast_test_' . $campaign_id );

		$campaign = Epic_Newsletter_Campaign_Store::get( $campaign_id );
		$test_email = sanitize_email( wp_unslash( $_POST['test_email'] ?? '' ) );
		$test_locale = ( 'en' === ( $_POST['test_locale'] ?? '' ) ) ? 'en' : 'vi';

		if ( $campaign && $test_email ) {
			$sent = Epic_Newsletter_Broadcast_Sender::send_test( $campaign, $test_email, $test_locale );
			set_transient(
				'epic_newsletter_broadcast_notice',
				$sent
					/* translators: %s: test recipient email address */
					? array( 'type' => 'success', 'text' => sprintf( __( 'Test email sent to %s.', 'epic-newsletter-subscription' ), $test_email ) )
					/* translators: %s: test recipient email address */
					: array( 'type' => 'error', 'text' => sprintf( __( 'Test email to %s failed to send — check the site\'s mail delivery setup.', 'epic-newsletter-subscription' ), $test_email ) ),
				60
			);
		} else {
			set_transient( 'epic_newsletter_broadcast_notice', array( 'type' => 'error', 'text' => __( 'Enter a valid email address for the test send.', 'epic-newsletter-subscription' ) ), 60 );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'view' => 'campaign', 'id' => $campaign_id ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function handle_delete() {
		$campaign_id = (int) ( $_POST['campaign_id'] ?? 0 );
		check_admin_referer( 'epic_newsletter_broadcast_delete_' . $campaign_id );

		Epic_Newsletter_Campaign_Store::delete_draft( $campaign_id );

		set_transient( 'epic_newsletter_broadcast_notice', array( 'type' => 'success', 'text' => __( 'Draft deleted.', 'epic-newsletter-subscription' ) ), 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	private static function render_notices() {
		$notice = get_transient( 'epic_newsletter_broadcast_notice' );
		if ( ! $notice ) {
			return;
		}
		delete_transient( 'epic_newsletter_broadcast_notice' );
		$class = 'error' === $notice['type'] ? 'notice-error' : 'notice-success';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $notice['text'] ) . '</p></div>';
	}

	// ------------------------------------------------------------------
	// Views
	// ------------------------------------------------------------------

	private static function render_list_and_composer() {
		?>
		<h1><?php esc_html_e( 'Send Newsletter', 'epic-newsletter-subscription' ); ?></h1>
		<p>
			<?php esc_html_e( 'Compose one email and send it to every newsletter subscriber, or just those on the Vietnamese or English storefront. Sending happens in the background in small batches, a minute or so apart, so it won\'t time out or trip your mail provider\'s rate limits for a large list.', 'epic-newsletter-subscription' ); ?>
		</p>

		<h2><?php esc_html_e( 'New campaign', 'epic-newsletter-subscription' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( 'epic_newsletter_broadcast_create' ); ?>
			<input type="hidden" name="epic_newsletter_broadcast_action" value="create" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="subject_vi"><?php esc_html_e( 'Subject (Vietnamese)', 'epic-newsletter-subscription' ); ?></label></th>
					<td><input type="text" id="subject_vi" name="subject_vi" class="large-text" required /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Body (Vietnamese)', 'epic-newsletter-subscription' ); ?></th>
					<td>
						<?php
						wp_editor(
							'',
							'body_vi',
							array(
								'textarea_name' => 'body_vi',
								'media_buttons' => false,
								'textarea_rows' => 12,
							)
						);
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="subject_en"><?php esc_html_e( 'Subject (English)', 'epic-newsletter-subscription' ); ?></label></th>
					<td>
						<input type="text" id="subject_en" name="subject_en" class="large-text" />
						<p class="description"><?php esc_html_e( 'Optional. Leave blank to send the Vietnamese subject/body to English-storefront subscribers too.', 'epic-newsletter-subscription' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Body (English)', 'epic-newsletter-subscription' ); ?></th>
					<td>
						<?php
						wp_editor(
							'',
							'body_en',
							array(
								'textarea_name' => 'body_en',
								'media_buttons' => false,
								'textarea_rows' => 12,
							)
						);
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="recipient_filter"><?php esc_html_e( 'Send to', 'epic-newsletter-subscription' ); ?></label></th>
					<td>
						<select id="recipient_filter" name="recipient_filter">
							<option value="all"><?php esc_html_e( 'All subscribers', 'epic-newsletter-subscription' ); ?></option>
							<option value="vi"><?php esc_html_e( 'Vietnamese-storefront subscribers only', 'epic-newsletter-subscription' ); ?></option>
							<option value="en"><?php esc_html_e( 'English-storefront subscribers only', 'epic-newsletter-subscription' ); ?></option>
						</select>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Create draft', 'epic-newsletter-subscription' ) ); ?>
			<p class="description"><?php esc_html_e( 'Creates a draft you can preview, test-send, and review the recipient count for — nothing is emailed yet.', 'epic-newsletter-subscription' ); ?></p>
		</form>

		<hr style="margin: 32px 0 24px;" />

		<h2><?php esc_html_e( 'Past campaigns', 'epic-newsletter-subscription' ); ?></h2>
		<?php
		$campaigns = Epic_Newsletter_Campaign_Store::list_all();
		if ( empty( $campaigns ) ) {
			echo '<p>' . esc_html__( 'No campaigns yet.', 'epic-newsletter-subscription' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Subject', 'epic-newsletter-subscription' ); ?></th>
					<th><?php esc_html_e( 'Sent to', 'epic-newsletter-subscription' ); ?></th>
					<th><?php esc_html_e( 'Status', 'epic-newsletter-subscription' ); ?></th>
					<th><?php esc_html_e( 'Progress', 'epic-newsletter-subscription' ); ?></th>
					<th><?php esc_html_e( 'Created', 'epic-newsletter-subscription' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $campaigns as $campaign ) : ?>
					<tr>
						<td><?php echo esc_html( $campaign['subject_vi'] ); ?></td>
						<td><?php echo esc_html( self::filter_label( $campaign['recipient_filter'] ) ); ?></td>
						<td><?php echo esc_html( self::status_label( $campaign['status'] ) ); ?></td>
						<td>
							<?php
							printf(
								/* translators: 1: sent count 2: failed count 3: total recipients */
								esc_html__( '%1$d sent, %2$d failed, of %3$d', 'epic-newsletter-subscription' ),
								(int) $campaign['sent_count'],
								(int) $campaign['failed_count'],
								(int) $campaign['total_recipients']
							);
							?>
						</td>
						<td><?php echo esc_html( mysql2date( 'Y-m-d H:i', $campaign['created_at'] ) ); ?></td>
						<td>
							<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'view' => 'campaign', 'id' => $campaign['id'] ), admin_url( 'admin.php' ) ) ); ?>">
								<?php esc_html_e( 'View', 'epic-newsletter-subscription' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_campaign_view( $campaign_id ) {
		$campaign = Epic_Newsletter_Campaign_Store::get( $campaign_id );

		if ( ! $campaign ) {
			echo '<h1>' . esc_html__( 'Campaign not found', 'epic-newsletter-subscription' ) . '</h1>';
			return;
		}

		$is_draft = Epic_Newsletter_Campaign_Store::STATUS_DRAFT === $campaign['status'];

		require_once EPIC_NEWSLETTER_SUBSCRIPTION_DIR . 'includes/class-export.php';
		$csv_log_url  = wp_nonce_url(
			add_query_arg( array( 'page' => self::PAGE_SLUG, 'view' => 'campaign', 'id' => $campaign_id, 'epic_newsletter_campaign_export' => 'csv', 'campaign_id' => $campaign_id ), admin_url( 'admin.php' ) ),
			Epic_Newsletter_Export::CAMPAIGN_NONCE_ACTION . '_' . $campaign_id
		);
		$xlsx_log_url = wp_nonce_url(
			add_query_arg( array( 'page' => self::PAGE_SLUG, 'view' => 'campaign', 'id' => $campaign_id, 'epic_newsletter_campaign_export' => 'xlsx', 'campaign_id' => $campaign_id ), admin_url( 'admin.php' ) ),
			Epic_Newsletter_Export::CAMPAIGN_NONCE_ACTION . '_' . $campaign_id
		);
		?>
		<h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">&larr; <?php esc_html_e( 'Send Newsletter', 'epic-newsletter-subscription' ); ?></a>
		</h1>

		<h2><?php echo esc_html( $campaign['subject_vi'] ); ?></h2>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Status', 'epic-newsletter-subscription' ); ?></th>
				<td><?php echo esc_html( self::status_label( $campaign['status'] ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Recipients', 'epic-newsletter-subscription' ); ?></th>
				<td>
					<?php echo esc_html( self::filter_label( $campaign['recipient_filter'] ) ); ?>
					&mdash;
					<?php
					printf(
						/* translators: 1: sent 2: failed 3: total */
						esc_html__( '%1$d sent, %2$d failed, of %3$d total', 'epic-newsletter-subscription' ),
						(int) $campaign['sent_count'],
						(int) $campaign['failed_count'],
						(int) $campaign['total_recipients']
					);
					?>
					<?php if ( ! $is_draft ) : ?>
						<p class="description"><?php esc_html_e( 'Sending runs in the background — reload this page to refresh these counts.', 'epic-newsletter-subscription' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<?php if ( $campaign['subject_en'] || $campaign['body_en'] ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'English subject', 'epic-newsletter-subscription' ); ?></th>
					<td><?php echo esc_html( $campaign['subject_en'] ? $campaign['subject_en'] : $campaign['subject_vi'] ); ?></td>
				</tr>
			<?php endif; ?>
		</table>

		<h3><?php esc_html_e( 'Preview (Vietnamese)', 'epic-newsletter-subscription' ); ?></h3>
		<div style="border: 1px solid #ccd0d4; padding: 16px; max-width: 640px; background: #fff;">
			<?php echo wp_kses_post( $campaign['body_vi'] ); ?>
		</div>

		<?php if ( $campaign['body_en'] ) : ?>
			<h3><?php esc_html_e( 'Preview (English)', 'epic-newsletter-subscription' ); ?></h3>
			<div style="border: 1px solid #ccd0d4; padding: 16px; max-width: 640px; background: #fff;">
				<?php echo wp_kses_post( $campaign['body_en'] ); ?>
			</div>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Send a test', 'epic-newsletter-subscription' ); ?></h3>
		<form method="post" style="margin-bottom: 24px;">
			<?php wp_nonce_field( 'epic_newsletter_broadcast_test_' . $campaign_id ); ?>
			<input type="hidden" name="epic_newsletter_broadcast_action" value="test" />
			<input type="hidden" name="campaign_id" value="<?php echo esc_attr( $campaign_id ); ?>" />
			<input type="email" name="test_email" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
			<select name="test_locale">
				<option value="vi"><?php esc_html_e( 'Vietnamese version', 'epic-newsletter-subscription' ); ?></option>
				<option value="en"><?php esc_html_e( 'English version', 'epic-newsletter-subscription' ); ?></option>
			</select>
			<?php submit_button( __( 'Send test', 'epic-newsletter-subscription' ), 'secondary', 'submit', false ); ?>
		</form>

		<?php if ( $is_draft ) : ?>
			<h3><?php esc_html_e( 'Send', 'epic-newsletter-subscription' ); ?></h3>
			<form method="post">
				<?php wp_nonce_field( 'epic_newsletter_broadcast_send_' . $campaign_id ); ?>
				<input type="hidden" name="epic_newsletter_broadcast_action" value="send" />
				<input type="hidden" name="campaign_id" value="<?php echo esc_attr( $campaign_id ); ?>" />
				<label>
					<input type="checkbox" name="confirm_send" value="1" required />
					<?php
					printf(
						/* translators: %d: recipient count */
						esc_html__( 'I understand this will email %d subscribers and cannot be undone.', 'epic-newsletter-subscription' ),
						(int) $campaign['total_recipients']
					);
					?>
				</label>
				<?php submit_button( __( 'Send now', 'epic-newsletter-subscription' ), 'primary', 'submit', false ); ?>
			</form>

			<form method="post" style="margin-top: 16px;" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this draft campaign? This cannot be undone.', 'epic-newsletter-subscription' ) ); ?>');">
				<?php wp_nonce_field( 'epic_newsletter_broadcast_delete_' . $campaign_id ); ?>
				<input type="hidden" name="epic_newsletter_broadcast_action" value="delete" />
				<input type="hidden" name="campaign_id" value="<?php echo esc_attr( $campaign_id ); ?>" />
				<?php submit_button( __( 'Delete draft', 'epic-newsletter-subscription' ), 'delete', 'submit', false ); ?>
			</form>
		<?php else : ?>
			<h3><?php esc_html_e( 'Delivery log', 'epic-newsletter-subscription' ); ?></h3>
			<p>
				<a href="<?php echo esc_url( $csv_log_url ); ?>" class="button"><?php esc_html_e( 'Export CSV', 'epic-newsletter-subscription' ); ?></a>
				<a href="<?php echo esc_url( $xlsx_log_url ); ?>" class="button"><?php esc_html_e( 'Export XLSX', 'epic-newsletter-subscription' ); ?></a>
			</p>
		<?php endif; ?>
		<?php
	}

	private static function status_label( $status ) {
		$labels = array(
			Epic_Newsletter_Campaign_Store::STATUS_DRAFT   => __( 'Draft', 'epic-newsletter-subscription' ),
			Epic_Newsletter_Campaign_Store::STATUS_SENDING => __( 'Sending…', 'epic-newsletter-subscription' ),
			Epic_Newsletter_Campaign_Store::STATUS_DONE    => __( 'Done', 'epic-newsletter-subscription' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( (string) $status );
	}

	private static function filter_label( $filter ) {
		$labels = array(
			'all' => __( 'All subscribers', 'epic-newsletter-subscription' ),
			'vi'  => __( 'Vietnamese storefront', 'epic-newsletter-subscription' ),
			'en'  => __( 'English storefront', 'epic-newsletter-subscription' ),
		);
		return isset( $labels[ $filter ] ) ? $labels[ $filter ] : $filter;
	}
}
