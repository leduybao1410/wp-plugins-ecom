<?php
/**
 * Resolves a WooCommerce order's free-text shipping address into the numeric
 * province_id / district_id / ward_code GHN actually requires.
 *
 * Two passes, since two address eras can show up on an order:
 *
 * 1. resolve_from_names(): the classic 3-tier layout this plugin has always
 *    assumed (province/district/ward *names*) — matches GHN's own master
 *    data, which is still pre-2025-merger (see class-ghn-client.php's
 *    docblock). The website's own checkout (src/lib/woocommerce.ts) already
 *    stores GHN's exact names into state/city/address_2, but an order placed
 *    any other way (phone order entered by staff, a future sales channel,
 *    manual edit) might not match exactly.
 * 2. If that fails, the order's address might instead be in the *new*
 *    (post-2025-merger) 2-tier layout — no district anymore, so a checkout
 *    built for the new structure likely put the ward straight into the city
 *    field. resolve() tries reading it that way and converts back to GHN's
 *    still-old names via Epic_GHN_Legacy_Address, then feeds the result back
 *    through resolve_from_names() so both passes end up at GHN's numeric IDs
 *    the same way.
 *
 * When neither pass resolves, the order meta box falls back to a manual
 * province/district/ward picker instead of guessing — see
 * Epic_GHN_Legacy_Address's docblock for why the new-format conversion is
 * often ambiguous rather than a single confident answer.
 *
 * @package Epic_GHN_Shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_GHN_Address_Resolver {

	/**
	 * @param WC_Order $order
	 * @return array {
	 *   @type bool        $resolved
	 *   @type int|null    $province_id
	 *   @type string|null $province_name
	 *   @type int|null    $district_id
	 *   @type string|null $district_name
	 *   @type string|null $ward_code
	 *   @type string|null $ward_name
	 *   @type string|null $error                     Set when a lookup failed outright (e.g. GHN unreachable).
	 *   @type string|null $source                     'old' | 'new_converted' | null (unresolved).
	 *   @type string|null $new_province_name          Set whenever the order's address also matched the new-format dataset, resolved or not.
	 *   @type string|null $new_ward_name
	 *   @type array       $legacy_candidates          Old-address candidates for the matched new-format ward — see Epic_GHN_Legacy_Address::old_candidates_for_new_ward().
	 *   @type bool        $legacy_district_confident
	 * }
	 */
	public static function resolve( WC_Order $order ) {
		$result = array(
			'resolved'                  => false,
			'province_id'               => null,
			'province_name'             => null,
			'district_id'               => null,
			'district_name'             => null,
			'ward_code'                 => null,
			'ward_name'                 => null,
			'error'                     => null,
			'source'                    => null,
			'new_province_name'         => null,
			'new_ward_name'             => null,
			'legacy_candidates'         => array(),
			'legacy_district_confident' => false,
		);

		$state = $order->get_shipping_state() ? $order->get_shipping_state() : $order->get_billing_state();
		$city  = $order->get_shipping_city() ? $order->get_shipping_city() : $order->get_billing_city();
		$ward  = $order->get_shipping_address_2() ? $order->get_shipping_address_2() : $order->get_billing_address_2();

		if ( ! $state || ! $city ) {
			return $result; // Nothing to match against — caller shows the manual picker.
		}

		// Pass 1: old (pre-merger) 3-tier layout — state=province, city=district, address_2=ward.
		if ( $ward ) {
			$old = self::resolve_from_names( $state, $city, $ward );
			if ( $old['error'] ) {
				$result['error'] = $old['error'];
				return $result;
			}
			if ( $old['resolved'] ) {
				return array_merge( $result, $old, array( 'source' => 'old' ) );
			}
		}

		// Pass 2: new (post-merger) 2-tier layout — state=province, city=ward, no district.
		// Convert to old names first, then resolve those against GHN exactly like pass 1.
		$new_match = Epic_GHN_Legacy_Address::resolve_new_address( $state, $city );
		if ( $new_match['matched_new'] ) {
			$result['new_province_name']         = $new_match['new_province_name'];
			$result['new_ward_name']              = $new_match['new_ward_name'];
			$result['legacy_candidates']          = $new_match['candidates'];
			$result['legacy_district_confident']  = $new_match['district_confident'];

			if ( $new_match['best_guess'] ) {
				$old = self::resolve_from_names(
					$new_match['best_guess']['province_name'],
					$new_match['best_guess']['district_name'],
					$new_match['best_guess']['ward_name']
				);
				if ( $old['resolved'] ) {
					return array_merge( $result, $old, array( 'source' => 'new_converted' ) );
				}
				if ( $old['error'] ) {
					$result['error'] = $old['error'];
				}
			}
		}

		return $result;
	}

	/**
	 * Resolves one province/district/ward *name* triple against GHN's live
	 * master data. Public (not just resolve()'s internal first pass) because
	 * Epic_GHN_Ajax::convert_new_address() needs the exact same name ->
	 * GHN-numeric-ID resolution when staff explicitly convert a new-format
	 * address via the Settings screen or order meta box's "Convert" button.
	 *
	 * @param string $province_text
	 * @param string $district_text
	 * @param string $ward_text
	 * @return array { resolved, province_id, province_name, district_id, district_name, ward_code, ward_name, error }
	 */
	public static function resolve_from_names( $province_text, $district_text, $ward_text ) {
		$result = array(
			'resolved'      => false,
			'province_id'   => null,
			'province_name' => null,
			'district_id'   => null,
			'district_name' => null,
			'ward_code'     => null,
			'ward_name'     => null,
			'error'         => null,
		);

		$provinces = Epic_GHN_Client::get_provinces();
		if ( is_wp_error( $provinces ) ) {
			$result['error'] = $provinces->get_error_message();
			return $result;
		}
		if ( ! is_array( $provinces ) ) {
			$result['error'] = __( 'GHN returned an unexpected response for the province list.', 'epic-ghn-shipping' );
			return $result;
		}

		$province = Epic_GHN_Legacy_Address::find_best_match( $provinces, 'ProvinceName', $province_text );
		if ( ! $province ) {
			return $result;
		}
		$result['province_id']   = (int) $province['ProvinceID'];
		$result['province_name'] = $province['ProvinceName'];

		$districts = Epic_GHN_Client::get_districts( $result['province_id'] );
		if ( is_wp_error( $districts ) ) {
			$result['error'] = $districts->get_error_message();
			return $result;
		}
		if ( ! is_array( $districts ) ) {
			$result['error'] = __( 'GHN returned an unexpected response for the district list.', 'epic-ghn-shipping' );
			return $result;
		}

		$district = Epic_GHN_Legacy_Address::find_best_match( $districts, 'DistrictName', $district_text );
		if ( ! $district ) {
			return $result;
		}
		$result['district_id']   = (int) $district['DistrictID'];
		$result['district_name'] = $district['DistrictName'];

		$wards = Epic_GHN_Client::get_wards( $result['district_id'] );
		if ( is_wp_error( $wards ) ) {
			$result['error'] = $wards->get_error_message();
			return $result;
		}
		if ( ! is_array( $wards ) ) {
			$result['error'] = __( 'GHN returned an unexpected response for the ward list.', 'epic-ghn-shipping' );
			return $result;
		}

		$matched_ward = Epic_GHN_Legacy_Address::find_best_match( $wards, 'WardName', $ward_text );
		if ( ! $matched_ward ) {
			return $result;
		}
		$result['ward_code'] = (string) $matched_ward['WardCode'];
		$result['ward_name'] = $matched_ward['WardName'];

		$result['resolved'] = true;
		return $result;
	}
}
