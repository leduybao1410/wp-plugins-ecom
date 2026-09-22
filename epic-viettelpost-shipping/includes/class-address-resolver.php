<?php
/**
 * Matches a WooCommerce order's shipping address to a ViettelPost
 * new-format (post-2025-merger) province ID.
 *
 * ViettelPost's order/fee NLP endpoints accept the receiver's province ID
 * plus the full free-text address, so resolving the province is the only
 * code lookup required to book — the rest of the address is geocoded by
 * ViettelPost itself. Matching is accent- and prefix-insensitive because
 * staff/customer-typed city names rarely match ViettelPost's canonical form
 * byte-for-byte (e.g. "TP. Hồ Chí Minh" vs "Thành phố Hồ Chí Minh").
 *
 * @package Epic_ViettelPost_Shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_VTP_Address_Resolver {

	/**
	 * Folds a Vietnamese address fragment down to a comparable form:
	 * lowercase, diacritics stripped, administrative prefixes removed
	 * ("thành phố", "tỉnh", "tp.", "quận", "huyện", "phường", "xã", …),
	 * punctuation collapsed. Two fragments that differ only by those
	 * decorations compare equal.
	 */
	public static function normalize( $value ) {
		$value = (string) $value;
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );

		// Strip Vietnamese diacritics (works on both NFC and NFD input).
		$value = remove_accents( $value );
		$value = str_replace( 'đ', 'd', $value );
		$value = str_replace( 'Đ', 'd', $value );

		$value = preg_replace( '/[^a-z0-9\s]/u', ' ', $value );

		$prefixes = array(
			'thanh pho', 'tinh', 'tp', 'quan', 'huyen', 'thi xa', 'thi tran',
			'phuong', 'xa', 'dac khu', 'khu',
		);
		foreach ( $prefixes as $prefix ) {
			$value = preg_replace( '/\b' . preg_quote( $prefix, '/' ) . '\b/u', ' ', $value );
		}

		$value = preg_replace( '/\s+/u', ' ', $value );
		return trim( $value );
	}

	/**
	 * Finds a new-format province by name.
	 *
	 * @param string $name Province name as typed (with or without prefix/diacritics).
	 * @return array|false { id, name } or false if no confident match.
	 */
	public static function find_province_by_name( $name ) {
		$provinces = Epic_VTP_Client::get_provinces_new();
		if ( is_wp_error( $provinces ) || ! is_array( $provinces ) ) {
			return false;
		}

		$target = self::normalize( $name );
		if ( '' === $target ) {
			return false;
		}

		$fallback = false;
		foreach ( $provinces as $province ) {
			if ( ! isset( $province['PROVINCE_ID'], $province['PROVINCE_NAME'] ) ) {
				continue;
			}
			$candidate = self::normalize( $province['PROVINCE_NAME'] );

			if ( $candidate === $target ) {
				return array(
					'id'   => (string) $province['PROVINCE_ID'],
					'name' => (string) $province['PROVINCE_NAME'],
				);
			}

			// Remember a prefix/containment match as a weaker fallback.
			if ( ! $fallback && '' !== $candidate && ( 0 === strpos( $candidate, $target ) || false !== strpos( $candidate, $target ) ) ) {
				$fallback = array(
					'id'   => (string) $province['PROVINCE_ID'],
					'name' => (string) $province['PROVINCE_NAME'],
				);
			}
		}

		return $fallback;
	}

	/**
	 * Resolves the receiver province for an order from its shipping city/state
	 * (falling back to the billing city/state).
	 *
	 * @return array {
	 *   resolved: bool,
	 *   province_id: string,
	 *   province_name: string,
	 *   error: string,
	 *   matched_name: string,
	 * }
	 */
	public static function resolve( WC_Order $order ) {
		$city  = $order->get_shipping_city();
		$state = $order->get_shipping_state();
		if ( '' === $city && '' === $state ) {
			$city  = $order->get_billing_city();
			$state = $order->get_billing_state();
		}

		$error = '';
		$provinces = Epic_VTP_Client::get_provinces_new();
		if ( is_wp_error( $provinces ) ) {
			$error = $provinces->get_error_message();
		}

		$candidates = array_filter( array( $city, $state ) );
		foreach ( $candidates as $candidate ) {
			$match = self::find_province_by_name( $candidate );
			if ( $match ) {
				return array(
					'resolved'      => true,
					'province_id'   => $match['id'],
					'province_name' => $match['name'],
					'error'         => '',
					'matched_name'  => $candidate,
				);
			}
		}

		return array(
			'resolved'      => false,
			'province_id'   => '',
			'province_name' => $city ? $city : $state,
			'error'         => $error,
			'matched_name'  => '',
		);
	}
}
