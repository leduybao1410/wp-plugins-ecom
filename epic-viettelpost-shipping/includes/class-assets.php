<?php
/**
 * Shared admin asset loading + the province/ward cascading-select markup used
 * by both the Settings screen (pickup address) and the order meta box (manual
 * override when an order's province can't be auto-resolved).
 *
 * ViettelPost's current (post-2025-merger) address structure is two-level —
 * province then ward, no district — and the order/NLP endpoints accept the
 * receiver's province ID plus the free-text address, so this picker is
 * deliberately simpler than the GHN plugin's three-level one.
 *
 * @package Epic_ViettelPost_Shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_VTP_Assets {

	private static $enqueued = false;

	public static function enqueue() {
		if ( self::$enqueued ) {
			return;
		}
		self::$enqueued = true;

		wp_enqueue_style( 'epic-vtp-admin', EPIC_VTP_PLUGIN_URL . 'assets/admin.css', array(), EPIC_VTP_VERSION );
		wp_enqueue_script( 'epic-vtp-admin', EPIC_VTP_PLUGIN_URL . 'assets/admin.js', array( 'jquery' ), EPIC_VTP_VERSION, true );
		wp_localize_script(
			'epic-vtp-admin',
			'EpicVtpAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'epic_vtp_admin' ),
				'i18n'    => array(
					'selectProvince'    => __( 'Select province/city…', 'epic-viettelpost-shipping' ),
					'selectWard'        => __( 'Select ward…', 'epic-viettelpost-shipping' ),
					'firstProvince'     => __( 'Select province/city first', 'epic-viettelpost-shipping' ),
					'typeToSearchWards' => __( 'Type to search wards…', 'epic-viettelpost-shipping' ),
					'loading'           => __( 'Loading…', 'epic-viettelpost-shipping' ),
					'loadFailed'        => __( 'Could not load from ViettelPost — check your Token under ViettelPost Shipping settings.', 'epic-viettelpost-shipping' ),
					'addressMatched'    => __( 'Address matched to a ViettelPost province.', 'epic-viettelpost-shipping' ),
					'addressNotMatched' => __( 'Couldn\'t automatically match this order to a ViettelPost province. Pick it manually below before shipping.', 'epic-viettelpost-shipping' ),
					'confirmCancel'     => __( 'Cancel this ViettelPost shipment? This cannot be undone — you\'ll need to book a new shipment if you cancel by mistake.', 'epic-viettelpost-shipping' ),
					'confirmBulkShip'   => __( 'Book a ViettelPost shipment for %d selected order(s) now? Each becomes its own separate parcel and tracking code — nothing is combined. This isn\'t undoable from here; cancel a mistaken shipment from that order\'s own screen.', 'epic-viettelpost-shipping' ),
					'shipping'          => __( 'Booking with ViettelPost…', 'epic-viettelpost-shipping' ),
					'cancelling'        => __( 'Cancelling…', 'epic-viettelpost-shipping' ),
					'syncing'           => __( 'Checking status…', 'epic-viettelpost-shipping' ),
					'generatingLabel'   => __( 'Generating label…', 'epic-viettelpost-shipping' ),
					'genericError'      => __( 'Something went wrong — check WooCommerce → Status → Logs (source "epic-vtp") for details.', 'epic-viettelpost-shipping' ),
				),
			)
		);
	}

	/**
	 * @param string $group   Field-name prefix, e.g. 'from' or 'ship_42'.
	 * @param array  $current { province_id, province_name, ward_id, ward_name }
	 * @return string Escaped HTML for the province select + ward combo + hidden fields.
	 */
	public static function render_address_group( $group, array $current ) {
		$current = wp_parse_args(
			$current,
			array(
				'province_id'   => '',
				'province_name' => '',
				'ward_id'       => '',
				'ward_name'     => '',
			)
		);

		ob_start();
		?>
		<div class="epic-vtp-address-group" data-group="<?php echo esc_attr( $group ); ?>">
			<select
				class="epic-vtp-select epic-vtp-province"
				name="epic_vtp_<?php echo esc_attr( $group ); ?>_province_id"
				data-selected="<?php echo esc_attr( $current['province_id'] ); ?>"
			>
				<?php if ( $current['province_id'] && $current['province_name'] ) : ?>
					<option value="<?php echo esc_attr( $current['province_id'] ); ?>" selected="selected"><?php echo esc_html( $current['province_name'] ); ?></option>
				<?php else : ?>
					<option value=""><?php esc_html_e( 'Loading…', 'epic-viettelpost-shipping' ); ?></option>
				<?php endif; ?>
			</select>
			<input type="hidden" class="epic-vtp-province-name" name="epic_vtp_<?php echo esc_attr( $group ); ?>_province_name" value="<?php echo esc_attr( $current['province_name'] ); ?>" />

			<?php
			/**
			 * Ward is a free-text combo (input + our own JS-rendered suggestion
			 * list), not a <select> or native <datalist>: a post-merger
			 * province can have 100+ wards and browsers filter <datalist> by
			 * raw substring match, so typing without diacritics (how most
			 * staff actually type) would show nothing. The list below is
			 * populated and filtered by assets/admin.js; wireCombo() only
			 * accepts an exact match into the hidden ID field.
			 */
			?>
			<span class="epic-vtp-combo-wrap">
				<input
					type="text"
					class="epic-vtp-combo epic-vtp-ward"
					name="epic_vtp_<?php echo esc_attr( $group ); ?>_ward_name"
					autocomplete="off"
					role="combobox"
					aria-autocomplete="list"
					aria-expanded="false"
					aria-controls="epic-vtp-ward-list-<?php echo esc_attr( $group ); ?>"
					placeholder="<?php echo empty( $current['province_id'] ) ? esc_attr__( 'Select province/city first', 'epic-viettelpost-shipping' ) : esc_attr__( 'Type to search wards…', 'epic-viettelpost-shipping' ); ?>"
					value="<?php echo esc_attr( $current['ward_name'] ); ?>"
					<?php disabled( empty( $current['province_id'] ) ); ?>
				/>
				<ul class="epic-vtp-combo-suggestions epic-vtp-ward-list" id="epic-vtp-ward-list-<?php echo esc_attr( $group ); ?>" role="listbox" hidden="hidden"></ul>
			</span>
			<input type="hidden" class="epic-vtp-ward-id" name="epic_vtp_<?php echo esc_attr( $group ); ?>_ward_id" value="<?php echo esc_attr( $current['ward_id'] ); ?>" />
		</div>
		<?php
		return ob_get_clean();
	}
}
