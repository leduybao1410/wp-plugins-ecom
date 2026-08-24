<?php
/**
 * Shared admin asset loading + the province/district/ward cascading-select
 * markup used by both the Settings screen (pickup address) and the order
 * meta box (manual override when an order's address can't be auto-resolved).
 *
 * Keeping this in one place means both pickers are driven by the same
 * assets/admin.js code path (see EpicGhnAddress.init() there) instead of
 * two parallel implementations drifting apart.
 *
 * @package Epic_GHN_Shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_GHN_Assets {

	private static $enqueued = false;

	public static function enqueue() {
		if ( self::$enqueued ) {
			return;
		}
		self::$enqueued = true;

		wp_enqueue_style( 'epic-ghn-admin', EPIC_GHN_PLUGIN_URL . 'assets/admin.css', array(), EPIC_GHN_VERSION );
		wp_enqueue_script( 'epic-ghn-admin', EPIC_GHN_PLUGIN_URL . 'assets/admin.js', array( 'jquery' ), EPIC_GHN_VERSION, true );
		wp_localize_script(
			'epic-ghn-admin',
			'EpicGhnAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'epic_ghn_admin' ),
				'i18n'    => array(
					'selectProvince'  => __( 'Select province/city…', 'epic-ghn-shipping' ),
					'selectDistrict'  => __( 'Select district…', 'epic-ghn-shipping' ),
					'selectWard'      => __( 'Select ward…', 'epic-ghn-shipping' ),
					'firstProvince'   => __( 'Select province/city first', 'epic-ghn-shipping' ),
					'firstDistrict'   => __( 'Select district first', 'epic-ghn-shipping' ),
					'loading'         => __( 'Loading…', 'epic-ghn-shipping' ),
					'loadFailed'      => __( 'Could not load from GHN — check your Token/Shop ID under GHN Shipping settings.', 'epic-ghn-shipping' ),
					'addressMatched'    => __( 'Address matched to GHN.', 'epic-ghn-shipping' ),
					'addressNotMatched' => __( 'Couldn\'t automatically match this address to a GHN province/district/ward. Pick them manually below before shipping.', 'epic-ghn-shipping' ),
					'confirmCancel'   => __( 'Cancel this GHN shipment? This cannot be undone — you\'ll need to book a new shipment if you cancel by mistake.', 'epic-ghn-shipping' ),
					'confirmBulkShip' => __( 'Book a GHN shipment for %d selected order(s) now? Each becomes its own separate parcel and tracking code — nothing is combined. This isn\'t undoable from here; cancel a mistaken shipment from that order\'s own screen.', 'epic-ghn-shipping' ),
					'shipping'        => __( 'Booking with GHN…', 'epic-ghn-shipping' ),
					'selectNewProvince' => __( 'Select new-format province/city…', 'epic-ghn-shipping' ),
					'selectNewWard'     => __( 'Select new-format ward…', 'epic-ghn-shipping' ),
					'firstNewProvince'  => __( 'Select new-format province/city first', 'epic-ghn-shipping' ),
					'firstNewWard'      => __( 'Pick a new-format province and ward first', 'epic-ghn-shipping' ),
					'convertToOld'      => __( 'Convert to pre-merger address', 'epic-ghn-shipping' ),
					'converting'        => __( 'Converting…', 'epic-ghn-shipping' ),
					'convertAmbiguous'  => __( 'This new-format ward spans more than one pre-merger area — pick the closest match:', 'epic-ghn-shipping' ),
					'useThisCandidate'  => __( 'Use this', 'epic-ghn-shipping' ),
					'convertApplied'    => __( 'Filled in above — double-check it before shipping.', 'epic-ghn-shipping' ),
					'newAddressToggle'  => __( 'I have the new-format (post-merger) address instead', 'epic-ghn-shipping' ),
					'convertedNotice'   => __( 'Auto-resolved by converting from a new-format address (%1$s, %2$s) — please double-check before shipping.', 'epic-ghn-shipping' ),
					'legacyHintPrefix'  => __( 'This address looks like it may be one of several pre-merger areas — check the picker below carefully, or switch to entering the new-format address:', 'epic-ghn-shipping' ),
					'cancelling'      => __( 'Cancelling…', 'epic-ghn-shipping' ),
					'syncing'         => __( 'Checking status…', 'epic-ghn-shipping' ),
					'generatingLabel' => __( 'Generating label…', 'epic-ghn-shipping' ),
					'genericError'    => __( 'Something went wrong — check WooCommerce → Status → Logs (source "epic-ghn") for details.', 'epic-ghn-shipping' ),
				),
			)
		);
	}

	/**
	 * @param string $group   Field-name prefix, e.g. 'from' or 'ship_42'.
	 * @param array  $current { province_id, province_name, district_id, district_name, ward_code, ward_name }
	 * @return string Escaped HTML for the three cascading selects + hidden name fields.
	 */
	public static function render_address_group( $group, array $current ) {
		$current = wp_parse_args(
			$current,
			array(
				'province_id'   => '',
				'province_name' => '',
				'district_id'   => '',
				'district_name' => '',
				'ward_code'     => '',
				'ward_name'     => '',
			)
		);

		ob_start();
		?>
		<div class="epic-ghn-address-group" data-group="<?php echo esc_attr( $group ); ?>">
			<select
				class="epic-ghn-select epic-ghn-province"
				name="epic_ghn_<?php echo esc_attr( $group ); ?>_province_id"
				data-selected="<?php echo esc_attr( $current['province_id'] ); ?>"
			>
				<?php if ( $current['province_id'] && $current['province_name'] ) : ?>
					<option value="<?php echo esc_attr( $current['province_id'] ); ?>" selected="selected"><?php echo esc_html( $current['province_name'] ); ?></option>
				<?php else : ?>
					<option value=""><?php esc_html_e( 'Loading…', 'epic-ghn-shipping' ); ?></option>
				<?php endif; ?>
			</select>
			<input type="hidden" class="epic-ghn-province-name" name="epic_ghn_<?php echo esc_attr( $group ); ?>_province_name" value="<?php echo esc_attr( $current['province_name'] ); ?>" />

			<select
				class="epic-ghn-select epic-ghn-district"
				name="epic_ghn_<?php echo esc_attr( $group ); ?>_district_id"
				data-selected="<?php echo esc_attr( $current['district_id'] ); ?>"
				<?php disabled( empty( $current['province_id'] ) ); ?>
			>
				<?php if ( $current['district_id'] && $current['district_name'] ) : ?>
					<option value="<?php echo esc_attr( $current['district_id'] ); ?>" selected="selected"><?php echo esc_html( $current['district_name'] ); ?></option>
				<?php else : ?>
					<option value=""><?php esc_html_e( 'Select province/city first', 'epic-ghn-shipping' ); ?></option>
				<?php endif; ?>
			</select>
			<input type="hidden" class="epic-ghn-district-name" name="epic_ghn_<?php echo esc_attr( $group ); ?>_district_name" value="<?php echo esc_attr( $current['district_name'] ); ?>" />

			<?php
			/**
			 * Ward is a free-text combo (input + our own JS-rendered
			 * suggestion list), not a <select> or native <datalist> — a
			 * district can have dozens of wards, and the new-format wards
			 * under a whole province can run into the hundreds (see
			 * class-legacy-address.php), which makes scrolling a plain
			 * dropdown impractical. <datalist> was tried first but dropped:
			 * browsers filter it by raw substring match against the literal
			 * option text, so typing "Dien Bien" (no diacritics, how most
			 * staff will actually type) shows nothing for "Phường Điện
			 * Biên" — no way to plug in accent-insensitive matching. The
			 * list below is populated and filtered entirely by
			 * assets/admin.js's filterComboItems()/renderSuggestions()
			 * (accent-folding, prefix-ranked, live as you type); wireCombo()
			 * only accepts an exact match — typed exactly or clicked from
			 * the list — into the hidden code field, so a half-typed search
			 * can't silently submit the wrong ward.
			 */
			?>
			<span class="epic-ghn-combo-wrap">
				<input
					type="text"
					class="epic-ghn-combo epic-ghn-ward"
					name="epic_ghn_<?php echo esc_attr( $group ); ?>_ward_name"
					autocomplete="off"
					role="combobox"
					aria-autocomplete="list"
					aria-expanded="false"
					aria-controls="epic-ghn-ward-list-<?php echo esc_attr( $group ); ?>"
					placeholder="<?php echo empty( $current['district_id'] ) ? esc_attr__( 'Select district first', 'epic-ghn-shipping' ) : esc_attr__( 'Type to search wards…', 'epic-ghn-shipping' ); ?>"
					value="<?php echo esc_attr( $current['ward_name'] ); ?>"
					<?php disabled( empty( $current['district_id'] ) ); ?>
				/>
				<ul class="epic-ghn-combo-suggestions epic-ghn-ward-list" id="epic-ghn-ward-list-<?php echo esc_attr( $group ); ?>" role="listbox" hidden="hidden"></ul>
			</span>
			<input type="hidden" class="epic-ghn-ward-code" name="epic_ghn_<?php echo esc_attr( $group ); ?>_ward_code" value="<?php echo esc_attr( $current['ward_code'] ); ?>" />
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Renders the new-format (post-2025-merger) province/ward picker plus a
	 * "Convert to pre-merger address" button, shown alongside
	 * render_address_group()'s old-format picker on both the Settings
	 * screen's pickup address and the order meta box's manual override. Only
	 * two levels (province -> ward), since the new administrative structure
	 * has no district tier — see includes/class-legacy-address.php's
	 * docblock for why GHN's own API still needs the old, 3-tier codes
	 * regardless. Client side: assets/admin.js's initNewAddressGroup() and
	 * the '.epic-ghn-convert-new-address' click handler.
	 *
	 * @param string $group   Field-name prefix, matching the sibling old-format render_address_group() call for the same address (e.g. 'from' or 'ship_42').
	 * @param array  $current { province_id, province_name, ward_id, ward_name }
	 * @return string Escaped HTML.
	 */
	public static function render_new_address_group( $group, array $current ) {
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
		<div class="epic-ghn-new-address-group" data-group="<?php echo esc_attr( $group ); ?>">
			<select
				class="epic-ghn-select epic-ghn-new-province"
				name="epic_ghn_<?php echo esc_attr( $group ); ?>_new_province_id"
				data-selected="<?php echo esc_attr( $current['province_id'] ); ?>"
			>
				<?php if ( $current['province_id'] && $current['province_name'] ) : ?>
					<option value="<?php echo esc_attr( $current['province_id'] ); ?>" selected="selected"><?php echo esc_html( $current['province_name'] ); ?></option>
				<?php else : ?>
					<option value=""><?php esc_html_e( 'Loading…', 'epic-ghn-shipping' ); ?></option>
				<?php endif; ?>
			</select>
			<input type="hidden" class="epic-ghn-new-province-name" name="epic_ghn_<?php echo esc_attr( $group ); ?>_new_province_name" value="<?php echo esc_attr( $current['province_name'] ); ?>" />

			<?php
			// Free-text combo, same reasoning and same JS-rendered suggestion
			// list as the old-format ward field above — a new-format province
			// can have 100+ wards (e.g. Hà Nội alone has 126 post-merger), and
			// native <datalist> can't do accent-insensitive filtering.
			?>
			<span class="epic-ghn-combo-wrap">
				<input
					type="text"
					class="epic-ghn-combo epic-ghn-new-ward"
					name="epic_ghn_<?php echo esc_attr( $group ); ?>_new_ward_name"
					autocomplete="off"
					role="combobox"
					aria-autocomplete="list"
					aria-expanded="false"
					aria-controls="epic-ghn-new-ward-list-<?php echo esc_attr( $group ); ?>"
					placeholder="<?php echo empty( $current['province_id'] ) ? esc_attr__( 'Select province/city first', 'epic-ghn-shipping' ) : esc_attr__( 'Type to search wards…', 'epic-ghn-shipping' ); ?>"
					value="<?php echo esc_attr( $current['ward_name'] ); ?>"
					<?php disabled( empty( $current['province_id'] ) ); ?>
				/>
				<ul class="epic-ghn-combo-suggestions epic-ghn-new-ward-list" id="epic-ghn-new-ward-list-<?php echo esc_attr( $group ); ?>" role="listbox" hidden="hidden"></ul>
			</span>
			<input type="hidden" class="epic-ghn-new-ward-id" name="epic_ghn_<?php echo esc_attr( $group ); ?>_new_ward_id" value="<?php echo esc_attr( $current['ward_id'] ); ?>" />

			<button type="button" class="button epic-ghn-convert-new-address">
				<?php esc_html_e( 'Convert to pre-merger address', 'epic-ghn-shipping' ); ?>
			</button>
			<div class="epic-ghn-convert-feedback"></div>
		</div>
		<?php
		return ob_get_clean();
	}
}
