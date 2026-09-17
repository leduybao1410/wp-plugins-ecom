<?php
/**
 * Adds "Discord Notify" as a tab under WooCommerce → Settings.
 *
 * All fields use WooCommerce's standard settings-field types and are saved
 * automatically by WC_Admin_Settings, except the "Send test message" row,
 * which is a custom field type ('epic_discord_test_button') rendered
 * through WooCommerce's own `woocommerce_admin_field_{type}` extension
 * point — it's a button + AJAX call, not something with a value to save.
 *
 * IMPORTANT: this file is required lazily, from inside the
 * `woocommerce_get_settings_pages` filter callback in the main plugin file
 * — never from a normal plugins_loaded include list. See the long comment
 * next to that filter registration for why.
 *
 * @package Epic_Discord_Notify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_Settings_Page' ) ) {
	return; // Should be unreachable given the lazy-require above — kept as a hard guard anyway.
}

class Epic_Discord_Settings extends WC_Settings_Page {

	public function __construct() {
		$this->id    = 'epic_discord_notify';
		$this->label = __( 'Discord Notify', 'epic-discord-notify' );
		parent::__construct();

		add_action( 'woocommerce_admin_field_epic_discord_test_button', array( __CLASS__, 'render_test_button_field' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function enqueue_assets( $hook ) {
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab check, not a form submission.
		if ( ! isset( $_GET['tab'] ) || 'epic_discord_notify' !== $_GET['tab'] ) {
			return;
		}

		wp_enqueue_script(
			'epic-discord-notify-admin',
			EPIC_DISCORD_NOTIFY_URL . 'assets/admin.js',
			array( 'jquery' ),
			EPIC_DISCORD_NOTIFY_VERSION,
			true
		);

		wp_localize_script(
			'epic-discord-notify-admin',
			'EpicDiscordNotify',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'epic_discord_test_webhook' ),
				'i18n'    => array(
					'sending' => __( 'Sending…', 'epic-discord-notify' ),
					'button'  => __( 'Send test message', 'epic-discord-notify' ),
				),
			)
		);
	}

	/**
	 * Standard WooCommerce settings fields, plus one custom
	 * 'epic_discord_test_button' entry rendered by render_test_button_field().
	 */
	public function get_settings( $current_section = '' ) {
		$settings = array(
			array(
				'title' => __( 'Discord webhook', 'epic-discord-notify' ),
				'type'  => 'title',
				'desc'  => __(
					'Uses a Discord channel webhook — no bot hosting required. In Discord: open Server Settings → Integrations → Webhooks → New Webhook, pick the channel you want order notifications posted to, then click "Copy Webhook URL" and paste it below.',
					'epic-discord-notify'
				),
				'id'    => 'epic_discord_webhook_title',
			),
			array(
				'title'   => __( 'Enable Discord notifications', 'epic-discord-notify' ),
				'id'      => 'epic_discord_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( 'Post a Discord message for every new order.', 'epic-discord-notify' ),
			),
			array(
				'title'    => __( 'Webhook URL', 'epic-discord-notify' ),
				'id'       => 'epic_discord_webhook_url',
				'type'     => 'password',
				'default'  => '',
				'css'      => 'min-width: 420px;',
				'desc_tip' => __( 'From Discord: Server Settings → Integrations → Webhooks.', 'epic-discord-notify' ),
			),
			array(
				'title'    => __( 'Bot display name', 'epic-discord-notify' ),
				'id'       => 'epic_discord_bot_username',
				'type'     => 'text',
				'default'  => 'EPIC Orders',
				'desc_tip' => __( 'Overrides the webhook\'s default name for these messages. Leave as-is if unsure.', 'epic-discord-notify' ),
			),
			array(
				'title'    => __( 'Bot avatar URL', 'epic-discord-notify' ),
				'id'       => 'epic_discord_avatar_url',
				'type'     => 'text',
				'default'  => '',
				'css'      => 'min-width: 420px;',
				'desc_tip' => __( 'Optional. A public image URL to use as the message avatar instead of the webhook default.', 'epic-discord-notify' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'epic_discord_webhook_end',
			),

			array(
				'title' => __( 'What gets included', 'epic-discord-notify' ),
				'type'  => 'title',
				'desc'  => __( 'Order number, items, and total are always included.', 'epic-discord-notify' ),
				'id'    => 'epic_discord_content_title',
			),
			array(
				'title'   => __( 'Customer phone', 'epic-discord-notify' ),
				'id'      => 'epic_discord_include_phone',
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => __( 'Include the customer\'s billing phone number, when available.', 'epic-discord-notify' ),
			),
			array(
				'title'   => __( 'Shipping address', 'epic-discord-notify' ),
				'id'      => 'epic_discord_include_address',
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => __( 'Include the delivery address.', 'epic-discord-notify' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'epic_discord_content_end',
			),

			array(
				'title' => __( 'Test', 'epic-discord-notify' ),
				'type'  => 'title',
				'desc'  => __( 'Save your webhook URL above first, then send a test message to confirm it reaches the channel.', 'epic-discord-notify' ),
				'id'    => 'epic_discord_test_title',
			),
			array(
				'id'   => 'epic_discord_test_button',
				'type' => 'epic_discord_test_button',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'epic_discord_test_end',
			),
		);

		return apply_filters( 'epic_discord_notify_settings', $settings );
	}

	/**
	 * Renders the "Send test message" button row. No value is saved for
	 * this field — WC_Admin_Settings::save_fields() only acts on field types
	 * it recognizes, and 'epic_discord_test_button' isn't one of the types
	 * it knows how to persist, so it's silently skipped on save (same as
	 * 'title'/'sectionend' rows).
	 */
	public static function render_test_button_field() {
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="epic-discord-test-webhook"><?php esc_html_e( 'Send test message', 'epic-discord-notify' ); ?></label>
			</th>
			<td class="forminp">
				<button type="button" id="epic-discord-test-webhook" class="button">
					<?php esc_html_e( 'Send test message', 'epic-discord-notify' ); ?>
				</button>
				<span id="epic-discord-test-webhook-result" style="margin-left: 10px;"></span>
			</td>
		</tr>
		<?php
	}
}
