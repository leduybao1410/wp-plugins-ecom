<?php
/**
 * Adds "ViettelPost Shipping" as a tab under WooCommerce → Settings.
 *
 * Most fields use WooCommerce's standard settings-field types and are saved
 * automatically by WC_Admin_Settings. The pickup province/ward picker is a
 * custom field type ('epic_vtp_address_picker') rendered through
 * WooCommerce's `woocommerce_admin_field_{type}` extension point and saved by
 * hand in save_address_fields().
 *
 * IMPORTANT: this file is required lazily, from inside the
 * `woocommerce_get_settings_pages` filter callback in the main plugin file —
 * never from the normal plugins_loaded include list. `extends WC_Settings_Page`
 * needs that class to already exist, and it only exists in wp-admin once
 * WooCommerce is asking for the settings pages list. Requiring this file any
 * earlier previously caused a site-wide fatal ("Class WC_Settings_Page not
 * found") in the GHN plugin this one mirrors.
 *
 * @package Epic_ViettelPost_Shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_Settings_Page' ) ) {
	return; // Should be unreachable given the lazy-require above — kept as a hard guard anyway.
}

class Epic_VTP_Settings extends WC_Settings_Page {

	public function __construct() {
		$this->id    = 'epic_vtp_shipping';
		$this->label = __( 'ViettelPost Shipping', 'epic-viettelpost-shipping' );
		parent::__construct();

		add_action( 'woocommerce_settings_save_' . $this->id, array( $this, 'save_address_fields' ) );
		add_action( 'woocommerce_admin_field_epic_vtp_address_picker', array( __CLASS__, 'render_address_picker_field' ) );
		add_action( 'woocommerce_admin_field_epic_vtp_test_connection', array( __CLASS__, 'render_test_connection_field' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function enqueue_assets( $hook ) {
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab check.
		if ( ! isset( $_GET['tab'] ) || 'epic_vtp_shipping' !== $_GET['tab'] ) {
			return;
		}
		Epic_VTP_Assets::enqueue();
	}

	public function get_settings( $current_section = '' ) {
		$settings = array(
			array(
				'title' => __( 'ViettelPost API credentials', 'epic-viettelpost-shipping' ),
				'type'  => 'title',
				'desc'  => __( 'Create an API token at viettelpost.vn → Cấu hình tài khoản → Thêm mới token, then paste it below. Partner/owner tokens are separate from sandbox tokens.', 'epic-viettelpost-shipping' ),
				'id'    => 'epic_vtp_credentials_title',
			),
			array(
				'title'    => __( 'Environment', 'epic-viettelpost-shipping' ),
				'id'       => 'epic_vtp_environment',
				'type'     => 'select',
				'default'  => 'sandbox',
				'options'  => array(
					'sandbox'    => __( 'Sandbox / testing (partnerdev.viettelpost.vn)', 'epic-viettelpost-shipping' ),
					'production' => __( 'Production (partner.viettelpost.vn)', 'epic-viettelpost-shipping' ),
				),
				'desc_tip' => __( 'Keep this on Sandbox until you\'ve confirmed shipments book correctly — sandbox shipments never dispatch a real courier.', 'epic-viettelpost-shipping' ),
			),
			array(
				'title'    => __( 'Token', 'epic-viettelpost-shipping' ),
				'id'       => 'epic_vtp_token',
				'type'     => 'password',
				'default'  => '',
				'desc_tip' => __( 'The long-lived API token for the selected environment. This is the primary credential — the username/password fields below are only a fallback.', 'epic-viettelpost-shipping' ),
			),
			array(
				'title'    => __( 'Fallback: username', 'epic-viettelpost-shipping' ),
				'id'       => 'epic_vtp_username',
				'type'     => 'text',
				'default'  => '',
				'desc_tip' => __( 'Only used if no Token is set — the plugin will mint a short-lived token via /v2/user/Login.', 'epic-viettelpost-shipping' ),
			),
			array(
				'title'   => __( 'Fallback: password', 'epic-viettelpost-shipping' ),
				'id'      => 'epic_vtp_password',
				'type'    => 'password',
				'default' => '',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'epic_vtp_credentials_end',
			),

			array(
				'title' => __( 'Verify credentials', 'epic-viettelpost-shipping' ),
				'type'  => 'title',
				'id'    => 'epic_vtp_test_title',
			),
			array(
				'title' => __( 'Test connection', 'epic-viettelpost-shipping' ),
				'id'    => 'epic_vtp_test_connection',
				'type'  => 'epic_vtp_test_connection',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'epic_vtp_test_end',
			),

			array(
				'title' => __( 'Pickup ("from") address', 'epic-viettelpost-shipping' ),
				'type'  => 'title',
				'desc'  => __( 'Where ViettelPost collects parcels from. Required before any shipment can be booked.', 'epic-viettelpost-shipping' ),
				'id'    => 'epic_vtp_from_address_title',
			),
			array(
				'title'   => __( 'Contact name', 'epic-viettelpost-shipping' ),
				'id'      => 'epic_vtp_from_name',
				'type'    => 'text',
				'default' => '',
			),
			array(
				'title'   => __( 'Contact phone', 'epic-viettelpost-shipping' ),
				'id'      => 'epic_vtp_from_phone',
				'type'    => 'text',
				'default' => '',
			),
			array(
				'title' => __( 'Province / Ward', 'epic-viettelpost-shipping' ),
				'id'    => 'epic_vtp_from_address_picker',
				'type'  => 'epic_vtp_address_picker',
			),
			array(
				'title'   => __( 'Street address', 'epic-viettelpost-shipping' ),
				'id'      => 'epic_vtp_from_address',
				'type'    => 'text',
				'default' => '',
				'css'     => 'min-width: 400px;',
				'desc_tip' => __( 'Street-level address only (house number, street) — the province/ward above are sent separately. ViettelPost geocodes the full text for NLP bookings.', 'epic-viettelpost-shipping' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'epic_vtp_from_address_end',
			),

			array(
				'title' => __( 'Shipment defaults', 'epic-viettelpost-shipping' ),
				'type'  => 'title',
				'desc'  => __( 'Used whenever a product has no weight set, or when choosing a shipping service.', 'epic-viettelpost-shipping' ),
				'id'    => 'epic_vtp_defaults_title',
			),
			array(
				'title'    => __( 'Preferred service code', 'epic-viettelpost-shipping' ),
				'id'       => 'epic_vtp_default_service',
				'type'     => 'text',
				'default'  => '',
				'desc_tip' => __( 'Optional. A ViettelPost main-service code (MA_DV_CHINH) such as VCN or VHT. Leave blank to automatically use the cheapest service quoted for each route.', 'epic-viettelpost-shipping' ),
			),
			array(
				'title'             => __( 'Default fallback item weight (grams)', 'epic-viettelpost-shipping' ),
				'id'                => 'epic_vtp_default_item_weight_g',
				'type'              => 'number',
				'default'           => '250',
				'custom_attributes' => array( 'min' => '1' ),
				'desc_tip'          => __( 'Used per line item only when the WooCommerce product itself has no weight set.', 'epic-viettelpost-shipping' ),
			),
			array(
				'title'             => __( 'Default parcel length (cm)', 'epic-viettelpost-shipping' ),
				'id'                => 'epic_vtp_default_length_cm',
				'type'              => 'number',
				'default'           => '20',
				'custom_attributes' => array( 'min' => '1' ),
			),
			array(
				'title'             => __( 'Default parcel width (cm)', 'epic-viettelpost-shipping' ),
				'id'                => 'epic_vtp_default_width_cm',
				'type'              => 'number',
				'default'           => '15',
				'custom_attributes' => array( 'min' => '1' ),
			),
			array(
				'title'             => __( 'Default parcel height (cm)', 'epic-viettelpost-shipping' ),
				'id'                => 'epic_vtp_default_height_cm',
				'type'              => 'number',
				'default'           => '10',
				'custom_attributes' => array( 'min' => '1' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'epic_vtp_defaults_end',
			),

			array(
				'title' => __( 'Free shipping promotion', 'epic-viettelpost-shipping' ),
				'type'  => 'title',
				'desc'  => __( 'Read by the website\'s checkout at order time (cached up to ~2 minutes) — changes here don\'t need a site deploy.', 'epic-viettelpost-shipping' ),
				'id'    => 'epic_vtp_free_shipping_title',
			),
			array(
				'title'             => __( 'Free shipping minimum order amount (₫)', 'epic-viettelpost-shipping' ),
				'id'                => 'epic_vtp_free_shipping_min_subtotal',
				'type'              => 'number',
				'default'           => '500000',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1000',
				),
				'desc_tip'          => __( 'Orders with a product subtotal at or above this amount get free shipping.', 'epic-viettelpost-shipping' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'epic_vtp_free_shipping_end',
			),

			array(
				'title' => __( 'Webhook (status sync)', 'epic-viettelpost-shipping' ),
				'type'  => 'title',
				'desc'  => __( 'Register the URL below under viettelpost.vn → Cấu hình tài khoản → Cấu hình webhook, and enter the same secret here. ViettelPost sends it back in the Authorization header on every callback.', 'epic-viettelpost-shipping' ),
				'id'    => 'epic_vtp_webhook_title',
			),
			array(
				'title'    => __( 'Webhook URL', 'epic-viettelpost-shipping' ),
				'id'       => 'epic_vtp_webhook_url_display',
				'type'     => 'text',
				'default'  => rest_url( 'epic-vtp/v1/webhook' ),
				'css'      => 'min-width: 400px;',
				'desc_tip' => __( 'Copy this into ViettelPost\'s webhook configuration. Read-only here.', 'epic-viettelpost-shipping' ),
				'custom_attributes' => array( 'readonly' => 'readonly' ),
			),
			array(
				'title'    => __( 'Webhook secret', 'epic-viettelpost-shipping' ),
				'id'       => 'epic_vtp_webhook_secret',
				'type'     => 'password',
				'default'  => '',
				'desc_tip' => __( 'Must match the SECRET configured on ViettelPost\'s side. Callbacks with a mismatched Authorization header are rejected.', 'epic-viettelpost-shipping' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'epic_vtp_webhook_end',
			),
		);

		return apply_filters( 'epic_vtp_settings', $settings, $current_section );
	}

	/**
	 * Renders the province/ward cascading picker. Registered against
	 * WooCommerce's generic `woocommerce_admin_field_{type}` hook, so it fires
	 * wherever the 'epic_vtp_address_picker' entry sits in get_settings().
	 */
	public static function render_address_picker_field( $value ) {
		$field_description = ! empty( $value['desc'] )
			? '<p class="description">' . wp_kses_post( $value['desc'] ) . '</p>'
			: '';
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php echo esc_html( $value['title'] ); ?></label>
			</th>
			<td class="forminp">
				<div class="epic-vtp-address-picker-wrap">
					<?php
					echo Epic_VTP_Assets::render_address_group( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped internally.
						'from',
						array(
							'province_id'   => get_option( 'epic_vtp_from_province_id', '' ),
							'province_name' => get_option( 'epic_vtp_from_province_name', '' ),
							'ward_id'       => get_option( 'epic_vtp_from_ward_id', '' ),
							'ward_name'     => get_option( 'epic_vtp_from_ward_name', '' ),
						)
					);
					?>
				</div>
				<?php echo $field_description; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from wp_kses_post above. ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Renders the "Test connection" button. Save the settings first, then
	 * click it to resolve a token (including the username/password fallback)
	 * and make one authenticated call — see Epic_VTP_Ajax::test_connection().
	 */
	public static function render_test_connection_field( $value ) {
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php echo esc_html( $value['title'] ); ?></label>
			</th>
			<td class="forminp">
				<button type="button" class="button epic-vtp-test-connection">
					<?php esc_html_e( 'Test connection', 'epic-viettelpost-shipping' ); ?>
				</button>
				<span class="epic-vtp-test-result epic-vtp-feedback"></span>
				<p class="description">
					<?php esc_html_e( 'Save changes first. Uses the stored Token if set, otherwise mints one from the username/password fallback.', 'epic-viettelpost-shipping' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Persists the four address-picker fields. WooCommerce's own save_fields()
	 * (called just before this fires) already saved every plain field declared
	 * in get_settings() — 'epic_vtp_address_picker' isn't a type WC recognizes,
	 * so those inputs are skipped by that pass and handled here instead.
	 */
	public function save_address_fields() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		check_admin_referer( 'woocommerce-settings' );

		$fields = array(
			'epic_vtp_from_province_id'   => 'absint',
			'epic_vtp_from_province_name' => 'sanitize_text_field',
			'epic_vtp_from_ward_id'       => 'absint',
			'epic_vtp_from_ward_name'     => 'sanitize_text_field',
		);

		foreach ( $fields as $option => $sanitizer ) {
			if ( isset( $_POST[ $option ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via check_admin_referer.
				$value = call_user_func( $sanitizer, wp_unslash( $_POST[ $option ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
				update_option( $option, $value );
			}
		}
	}
}
