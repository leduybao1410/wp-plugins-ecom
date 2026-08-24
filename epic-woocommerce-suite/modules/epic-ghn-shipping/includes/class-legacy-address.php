<?php
/**
 * Bridges Vietnam's post-2025-merger administrative units (34 provinces,
 * 2-tier: province + ward, no district) back to the pre-merger structure
 * GHN's own API still speaks (63 provinces, 3-tier: province/district/ward —
 * re-verified against api.ghn.vn/home/docs on 2026-08-20; GHN has not
 * migrated its delivery-zone codes to the government's new boundaries).
 *
 * That gap matters because every shipment this plugin books still has to
 * resolve to GHN's *old* codes, but a customer today may well address their
 * order using the *new* merged province/ward names — which won't be found
 * anywhere in GHN's still-old master data by Epic_GHN_Address_Resolver's
 * direct name match. This class is the conversion layer: given a new-format
 * province + ward, look up which old province/district/ward name(s) it was
 * assembled from, for the caller to then fuzzy-match against GHN's live
 * master data (Epic_GHN_Address_Resolver::resolve_from_names() does that
 * part, so there's one place that turns names into GHN's numeric IDs).
 *
 * Bundled data (includes/data/*.json) is trimmed from vietmap-company's
 * "Vietnam Administrative Data" dataset — VietMap Administrative Data
 * License, free for direct use in an end-user product, no attribution
 * required for that use: https://github.com/vietmap-company/vietnam_administrative_address
 *   - new-provinces.json / new-wards.json: the new (post-merger) 34-province,
 *     2-tier structure, keyed by the government's own numeric code, trimmed
 *     to {name, name_with_type[, parent_code]}.
 *   - legacy-ward-map.json: the same repo's official old->new mapping
 *     (admin_mapping/admin_mapping_old_to_new_10_25.xlsx), regenerated here
 *     as new_ward_code => { candidates: [{p,d,w,n}], district_confident }.
 *     Old ward boundaries didn't nest cleanly inside new ones during the
 *     reform, so one new ward can trace back to several old
 *     province/district/ward combinations (see $district_confident below) —
 *     candidates are sorted most-frequent-first.
 *
 * @package Epic_GHN_Shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_GHN_Legacy_Address {

	private static $new_provinces;
	private static $new_wards;
	private static $ward_map;

	private static function new_provinces() {
		if ( null === self::$new_provinces ) {
			self::$new_provinces = self::load_json( 'new-provinces.json' );
		}
		return self::$new_provinces;
	}

	private static function new_wards() {
		if ( null === self::$new_wards ) {
			self::$new_wards = self::load_json( 'new-wards.json' );
		}
		return self::$new_wards;
	}

	private static function ward_map() {
		if ( null === self::$ward_map ) {
			self::$ward_map = self::load_json( 'legacy-ward-map.json' );
		}
		return self::$ward_map;
	}

	private static function load_json( $filename ) {
		$path = EPIC_GHN_PLUGIN_DIR . 'includes/data/' . $filename;
		if ( ! file_exists( $path ) ) {
			return array();
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bundled plugin asset, not a remote/user-supplied path.
		$data = json_decode( file_get_contents( $path ), true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * @return array code => { name, name_with_type } for every new-format province.
	 */
	public static function new_provinces_list() {
		return self::new_provinces();
	}

	/**
	 * @return array code => { name, name_with_type, parent_code } for every
	 *               new-format ward under the given new-format province.
	 */
	public static function new_wards_for_province( $province_code ) {
		$province_code = (string) $province_code;
		$wards         = array();
		foreach ( self::new_wards() as $code => $row ) {
			if ( isset( $row['parent_code'] ) && (string) $row['parent_code'] === $province_code ) {
				$wards[ $code ] = $row;
			}
		}
		return $wards;
	}

	/**
	 * Lowercases, strips diacritics, and removes the administrative-unit
	 * prefixes Vietnamese addresses commonly include (or omit), so e.g.
	 * "Phường Điện Biên" and "Điện Biên" compare equal. Single source of
	 * truth for both this class and Epic_GHN_Address_Resolver, which
	 * delegates its own normalize()/find_best_match() here so the old-format
	 * direct match and this new-format conversion path can't drift apart.
	 */
	public static function normalize( $value ) {
		$value = remove_accents( (string) $value );
		$value = strtolower( trim( $value ) );

		$prefixes = array(
			'thanh pho ', 'tp. ', 'tp ',
			'tinh ',
			'quan ', 'huyen ', 'thi xa ',
			'phuong ', 'xa ', 'thi tran ',
		);
		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( $value, $prefix ) ) {
				$value = substr( $value, strlen( $prefix ) );
				break;
			}
		}

		$value = preg_replace( '/\s+/', ' ', $value );
		return trim( $value );
	}

	/**
	 * Exact match after normalization first; falls back to "needle is
	 * contained in haystack" (either direction) so minor variations still
	 * resolve rather than forcing a manual pick for every near-miss.
	 *
	 * @param array  $rows       Each a name-keyed array, e.g. GHN's province/district/ward rows or this class's new-format rows.
	 * @param string $name_field Which key in each row holds the name to compare against.
	 * @param string $needle     Free-text name to match.
	 */
	public static function find_best_match( array $rows, $name_field, $needle ) {
		$normalized_needle = self::normalize( $needle );
		if ( '' === $normalized_needle ) {
			return null;
		}

		foreach ( $rows as $row ) {
			if ( ! isset( $row[ $name_field ] ) ) {
				continue;
			}
			if ( self::normalize( $row[ $name_field ] ) === $normalized_needle ) {
				return $row;
			}
		}

		foreach ( $rows as $row ) {
			if ( ! isset( $row[ $name_field ] ) ) {
				continue;
			}
			$normalized_row = self::normalize( $row[ $name_field ] );
			if ( '' !== $normalized_row && false !== strpos( $normalized_row, $normalized_needle ) ) {
				return $row;
			}
			if ( false !== strpos( $normalized_needle, $normalized_row ) ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * @return array|null { code, name, name_with_type }
	 */
	public static function match_new_province( $name ) {
		if ( ! $name ) {
			return null;
		}

		$rows = array();
		foreach ( self::new_provinces() as $code => $row ) {
			$row['code'] = $code;
			$rows[]      = $row;
		}

		$match = self::find_best_match( $rows, 'name_with_type', $name );
		return $match ? $match : self::find_best_match( $rows, 'name', $name );
	}

	/**
	 * @return array|null { code, name, name_with_type, parent_code }
	 */
	public static function match_new_ward( $province_code, $name ) {
		if ( ! $name || ! $province_code ) {
			return null;
		}

		$rows = array();
		foreach ( self::new_wards_for_province( $province_code ) as $code => $row ) {
			$row['code'] = $code;
			$rows[]      = $row;
		}

		$match = self::find_best_match( $rows, 'name_with_type', $name );
		return $match ? $match : self::find_best_match( $rows, 'name', $name );
	}

	/**
	 * @param string $new_ward_code
	 * @return array|null {
	 *   @type array $candidates         { p: old province name, d: old district name, w: old ward name, n: frequency }[], most-common first.
	 *   @type bool  $district_confident True when every candidate shares the same old province+district.
	 * }
	 */
	public static function old_candidates_for_new_ward( $new_ward_code ) {
		$map = self::ward_map();
		$key = (string) $new_ward_code;
		return isset( $map[ $key ] ) ? $map[ $key ] : null;
	}

	/**
	 * Full free-text pipeline: new-format province + ward names -> old-format
	 * name candidates. Used by Epic_GHN_Address_Resolver's automatic
	 * second-pass resolution, where the input is whatever text is already on
	 * the order (state/city), not something staff picked from a dropdown —
	 * see Epic_GHN_Ajax::convert_new_address() for the exact-code version
	 * used by the Settings screen's and order meta box's explicit "Convert"
	 * button, which needs no fuzzy matching since staff pick from a list.
	 *
	 * @param string $province_text
	 * @param string $ward_text
	 * @return array {
	 *   @type bool        $matched_new        Whether both province and ward matched the new-format dataset at all.
	 *   @type string|null $new_province_name
	 *   @type string|null $new_ward_name
	 *   @type array       $candidates         See old_candidates_for_new_ward(). Empty if not matched or no mapping row exists.
	 *   @type bool        $district_confident
	 *   @type array|null  $best_guess         { province_name, district_name, ward_name } — the most frequent candidate, only set when district_confident.
	 * }
	 */
	public static function resolve_new_address( $province_text, $ward_text ) {
		$result = array(
			'matched_new'        => false,
			'new_province_name'  => null,
			'new_ward_name'      => null,
			'candidates'         => array(),
			'district_confident' => false,
			'best_guess'         => null,
		);

		$province = self::match_new_province( $province_text );
		if ( ! $province ) {
			return $result;
		}
		$ward = self::match_new_ward( $province['code'], $ward_text );
		if ( ! $ward ) {
			return $result;
		}

		$result['matched_new']       = true;
		$result['new_province_name'] = $province['name_with_type'];
		$result['new_ward_name']     = $ward['name_with_type'];

		$mapped = self::old_candidates_for_new_ward( $ward['code'] );
		if ( ! $mapped || empty( $mapped['candidates'] ) ) {
			return $result;
		}

		$result['candidates']         = $mapped['candidates'];
		$result['district_confident'] = ! empty( $mapped['district_confident'] );

		if ( $result['district_confident'] ) {
			$top               = $mapped['candidates'][0]; // Pre-sorted most-common-first when the data file was generated.
			$result['best_guess'] = array(
				'province_name' => $top['p'],
				'district_name' => $top['d'],
				'ward_name'     => $top['w'],
			);
		}

		return $result;
	}
}
