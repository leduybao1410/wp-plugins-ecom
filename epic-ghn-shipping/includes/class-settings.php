<?php
/**
 * Adds "GHN Shipping" as a tab under WooCommerce → Settings.
 *
 * Most fields use WooCommerce's standard settings-field types and are saved
 * automatically by WC_Admin_Settings. The from-address province/district/
 * ward picker is a custom field type ('epic_ghn_address_picker') — a live
 * cascading control populated from GHN's master-data endpoints via AJAX
 * (assets/admin.js) — rendered through WooCommerce's own
 * `woocommerce_admin_field_{type}` extension point and saved by hand in
 * save_address_fields(), since it posts three ID/name pairs that don't map
 * onto a single WooCommerce settings field.
 *
 * IMPORTANT: this file is required lazily, from inside the
 * `woocommerce_get_settings_pages` filter callback in the main plugin file —
 * never from the plugin's normal plugins_loaded include list. `extends
 * WC_Settings_Page` below needs that class to already exist, and
 * WC_Settings_Page is only ever loaded by WooCommerce itself in wp-admin,
 * specifically by the time it's asking for the settings pages list. Requiring
 * this file any earlier previously caused a site-wide fatal error ("Class
 * WC_Settings_Page not found") on every request, admin and front-end alike.
 *
 * @package Epic_GHN_Shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_Settings_Page' ) ) {
	return; // Should be unreachable given the lazy-require above — kept as a hard guard anyway.
}

class Epic_GHN_Settings extends WC_Settings_Page {

	/**
	 * Instantiated once, lazily, from the `woocommerce_get_settings_pages`
	 * filter registered in the main plugin file — see the long comment next
	 * to that filter for why it has to happen there and not at
	 * `plugins_loaded` time. All hook registration happens here in the
	 * constructor rather than a separate static init(), since by the time
	 * this constructor runs we're already guaranteed to be inside a live
	 * WooCommerce settings-page request.
	 */
	public function __construct() {
		$this->id    = 'epic_ghn_shipping';
		$this->label = __( 'GHN Shipping', 'epic-ghn-shipping' );
		parent::__construct();

		add_action( 'woocommerce_settings_save_' . $this->id, array( $this, 'save_address_fields' ) );
		add_action( 'woocommerce_admin_field_epic_ghn_address_picker', array( __CLASS__, 'render_address_picker_field' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function enqueue_assets( $hook ) {
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab check, not a form submission.
		if ( ! isset( $_GET['tab'] ) || 'epic_ghn_shipping' !== $_GET['tab'] ) {
			return;
		}
		Epic_GHN_Assets::enqueue();
	}

	/**
	 * Standard WooCommerce settings fields, plus one custom
	 * 'epic_ghn_address_picker' entry rendered by render_address_picker_field().
	 */
	public function get_settings( $current_section = '' ) {
		$settings = array(
			array(
				'title' => __( 'GHN API credentials', 'epic-ghn-shipping' ),
				'type'  => 'title',
				'desc'  => __( 'From your GHN merchant dashboard: khachhang.ghn.vn (production) or 5sao.ghn.dev (sandbox/testing).', 'epic-ghn-shipping' ),
				'id'    => 'epic_ghn_credentials_title',
			),
			array(
				'title'    => __( 'Environment', 'epic-ghn-shipping' ),
				'id'       => 'epic_ghn_environment',
				'type'     => 'select',
				'default'  => 'sandbox',
				'options'  => array(
					'sandbox'    => __( 'Sandbox / testing (dev-online-gateway.ghn.vn)', 'epic-ghn-shipping' ),
					'production' => __( 'Production (online-gateway.ghn.vn)', 'epic-ghn-shipping' ),
				),
				'desc_tip' => __( 'Keep this on Sandbox until you\'ve confirmed shipments book correctly — sandbox shipments never dispatch a real courier.', 'epic-ghn-shipping' ),
			),
			array(
				'title'    => __( 'Token', 'epic-ghn-shipping' ),
				'id'       => 'epic_ghn_token',
				'type'     => 'password',
				'default'  => '',
				'desc_tip' => __( 'The API token for the selected environment — sandbox and production tokens are different.', 'epic-ghn-shipping' ),
			),
			array(
				'title'   => __( 'Shop ID', 'epic-ghn-shipping' ),
				'id'      => 'epic_ghn_shop_id',
				'type'    => 'text',
				'default' => '',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'epic_ghn_credentials_end',
			),

			array(
				'title' => __( 'Pickup ("from") address', 'epic-ghn-shipping' ),
				'type'  => 'title',
				'desc'  => __( 'Where GHN collects parcels from. Required before any shipment can be booked.', 'epic-ghn-shipping' ),
				'id'    => 'epic_ghn_from_address_title',
			),
			array(
				'title'   => __( 'Contact name', 'epic-ghn-shipping' ),
				'id'      => 'epic_ghn_from_name',
				'type'    => 'text',
				'default' => '',
			),
			array(
				'title'   => __( 'Contact phone', 'epic-ghn-shipping' ),
				'id'      => 'epic_ghn_from_phone',
				'type'    => 'text',
				'default' => '',
			),
			array(
				'title' => __( 'Province / District / Ward', 'epic-ghn-shipping' ),
				'id'    => 'epic_ghn_from_address_picker',
				'type'  => 'epic_ghn_address_picker',
			),
			array(
				'title'   => __( 'Street address', 'epic-ghn-shipping' ),
				'id'      => 'epic_ghn_from_address',
				'type'    => 'text',
				'default' => '',
				'css'     => 'min-width: 400px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'epic_ghn_from_address_end',
			),

			array(
				'title' => __( 'Shipment defaults', 'epic-ghn-shipping' ),
				'type'  => 'title',
				'desc'  => __( 'Used whenever a product has no weight set, or staff don\'t override the parcel size when booking.', 'epic-ghn-shipping' ),
				'id'    => 'epic_ghn_defaults_title',
			),
			array(
				'title'   => __( 'Service type', 'epic-ghn-shipping' ),
				'id'      => 'epic_ghn_service_type_id',
				'type'    => 'select',
				'default' => '2',
				'options' => array(
					'2' => __( 'Standard (nationwide)', 'epic-ghn-shipping' ),
					'5' => __( 'Express', 'epic-ghn-shipping' ),
				),
			),
			array(
				'title'             => __( 'Default fallback item weight (grams)', 'epic-ghn-shipping' ),
				'id'                => 'epic_ghn_default_item_weight_g',
				'type'              => 'number',
				'default'           => '250',
				'custom_attributes' => array( 'min' => '1' ),
				'desc_tip'          => __( 'Used per line item only when the WooCommerce product itself has no weight set.', 'epic-ghn-shipping' ),
			),
			array(
				'title'             => __( 'Default parcel length (cm)', 'epic-ghn-shipping' ),
				'id'                => 'epic_ghn_default_length_cm',
				'type'              => 'number',
				'default'           => '20',
				'custom_attributes' => array( 'min' => '1' ),
			),
			array(
				'title'             => __( 'Default parcel width (cm)', 'epic-ghn-shipping' ),
				'id'                => 'epic_ghn_default_width_cm',
				'type'              => 'number',
				'default'           => '15',
				'custom_attributes' => array( 'min' => '1' ),
			),
			array(
				'title'             => __( 'Default parcel height (cm)', 'epic-ghn-shipping' ),
				'id'                => 'epic_ghn_default_height_cm',
				'type'              => 'number',
				'default'           => '10',
				'custom_attributes' => array( 'min' => '1' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'epic_ghn_defaults_end',
			),

			array(
				'title' => __( 'Free shipping promotion', 'epic-ghn-shipping' ),
				'type'  => 'title',
				'desc'  => __( 'Applied by the website\'s own checkout code (not a WooCommerce coupon or shipping method) — see the field description below for why.', 'epic-ghn-shipping' ),
				'id'    => 'epic_ghn_free_shipping_title',
			),
			array(
				'title'             => __( 'Free shipping minimum order amount (₫)', 'epic-ghn-shipping' ),
				'id'                => 'epic_ghn_free_shipping_min_subtotal',
				'type'              => 'number',
				'default'           => '500000',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1000',
				),
				'desc_tip'          => __( 'Orders with a product subtotal at or above this amount get free shipping. Read by the website\'s checkout at order time (cached up to ~2 minutes) — changes here don\'t need a site deploy.', 'epic-ghn-shipping' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'epic_ghn_free_shipping_end',
			),
		);

		return apply_filters( 'epic_ghn_settings', $settings, $current_section );
	}

	/**
	 * Renders the province/district/ward cascading picker. Registered
	 * against WooCommerce's generic `woocommerce_admin_field_{type}` hook,
	 * so it fires wherever the 'epic_ghn_address_picker' entry sits in
	 * get_settings() — no manual position-injection needed.
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
				<div class="epic-ghn-address-picker-wrap">
					<?php
					echo Epic_GHN_Assets::render_address_group( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped internally.
						'from',
						array(
							'province_id'   => get_option( 'epic_ghn_from_province_id', '' ),
							'province_name' => get_option( 'epic_ghn_from_province_name', '' ),
							'district_id'   => get_option( 'epic_ghn_from_district_id', '' ),
							'district_name' => get_option( 'epic_ghn_from_district_name', '' ),
							'ward_code'     => get_option( 'epic_ghn_from_ward_code', '' ),
							'ward_name'     => get_option( 'epic_ghn_from_ward_name', '' ),
						)
					);
					?>
					<p class="description">
						<?php esc_html_e( 'Above is what GHN actually books from — GHN\'s own delivery-zone codes still use the pre-2025-merger province/district/ward structure. If you only know the new (post-merger) address, enter it below and convert it.', 'epic-ghn-shipping' ); ?>
					</p>
					<?php
					echo Epic_GHN_Assets::render_new_address_group( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped internally.
						'from',
						array(
							'province_id'   => get_option( 'epic_ghn_from_new_province_id', '' ),
							'province_name' => get_option( 'epic_ghn_from_new_province_name', '' ),
							'ward_id'       => get_option( 'epic_ghn_from_new_ward_id', '' ),
							'ward_name'     => get_option( 'epic_ghn_from_new_ward_name', '' ),
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
	 * Persists the six address-picker fields. WooCommerce's own save_fields()
	 * (called just before this fires) already saved every plain field
	 * declared in get_settings() — 'epic_ghn_address_picker' isn't a type WC
	 * recognizes, so those six inputs are skipped by that pass and handled
	 * here instead.
	 */
	public function save_address_fields() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		check_admin_referer( 'woocommerce-settings' );

		$fields = array(
			'epic_ghn_from_province_id'       => 'absint',
			'epic_ghn_from_province_name'     => 'sanitize_text_field',
			'epic_ghn_from_district_id'       => 'absint',
			'epic_ghn_from_district_name'     => 'sanitize_text_field',
			'epic_ghn_from_ward_code'         => 'sanitize_text_field',
			'epic_ghn_from_ward_name'         => 'sanitize_text_field',
			// New-format (post-2025-merger) address — reference/conversion-source
			// only, never sent to GHN directly (see class-legacy-address.php).
			'epic_ghn_from_new_province_id'   => 'sanitize_text_field',
			'epic_ghn_from_new_province_name' => 'sanitize_text_field',
			'epic_ghn_from_new_ward_id'       => 'sanitize_text_field',
			'epic_ghn_from_new_ward_name'     => 'sanitize_text_field',
		);

		foreach ( $fields as $option => $sanitizer ) {
			if ( isset( $_POST[ $option ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via check_admin_referer.
				$value = call_user_func( $sanitizer, wp_unslash( $_POST[ $option ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
				update_option( $option, $value );
			}
		}
	}
}
