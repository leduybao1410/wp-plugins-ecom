<?php
/**
 * Auto-apply coupons: no code entry needed. Trigger condition reuses
 * WooCommerce's own native "Minimum spend" field (no duplicate setting)
 * plus an optional "cart must contain this category" filter.
 *
 * A coupon this plugin added automatically is tracked in the session so it
 * can be auto-removed again if the cart later drops below the threshold.
 * If the customer explicitly removes it themselves, that's tracked too, so
 * it won't be silently reinstated for the rest of the session even if the
 * cart still qualifies — the point is convenience, not fighting the
 * customer's own choice.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Adv_Coupons_Auto_Apply {

	const SESSION_ADDED   = 'epic_auto_apply_added';
	const SESSION_REMOVED = 'epic_auto_apply_removed';

	/** @var WC_Coupon[]|null Per-request cache of enabled auto-apply coupons. */
	protected static $coupons = null;

	/** @var bool Re-entrancy guard — apply_coupon()/remove_coupon() themselves trigger recalculation. */
	protected static $running = false;

	public static function init() {
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'maybe_apply_or_remove' ), 20 );

		// Priority 5: run before WooCommerce's own remove-coupon AJAX handler
		// (registered at the default priority, 10) so we can note the
		// customer's own removal before the coupon is actually taken off.
		add_action( 'wp_ajax_woocommerce_remove_coupon', array( __CLASS__, 'mark_user_removed' ), 5 );
		add_action( 'wp_ajax_nopriv_woocommerce_remove_coupon', array( __CLASS__, 'mark_user_removed' ), 5 );
	}

	public static function mark_user_removed() {
		if ( empty( $_POST['coupon'] ) || ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$code     = wc_format_coupon_code( wp_unslash( $_POST['coupon'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only lookup, WC's own handler verifies the nonce for the actual removal.
		$removed  = (array) WC()->session->get( self::SESSION_REMOVED, array() );
		$removed[ $code ] = true;
		WC()->session->set( self::SESSION_REMOVED, $removed );
	}

	public static function maybe_apply_or_remove( $cart ) {
		if ( self::$running || is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		self::$running = true;

		$applied = array_map( 'wc_format_coupon_code', $cart->get_applied_coupons() );
		$added   = (array) WC()->session->get( self::SESSION_ADDED, array() );
		$removed = (array) WC()->session->get( self::SESSION_REMOVED, array() );

		$items = self::items_from_cart( $cart );

		foreach ( self::get_coupons() as $coupon ) {
			$code       = wc_format_coupon_code( $coupon->get_code() );
			$is_applied = in_array( $code, $applied, true );
			$qualifies  = self::qualifies_for_items( $items, (float) $cart->get_subtotal(), $coupon );

			if ( $qualifies && ! $is_applied && empty( $removed[ $code ] ) ) {
				$cart->apply_coupon( $coupon->get_code() );
				$added[ $code ] = true;
				WC()->session->set( self::SESSION_ADDED, $added );
			} elseif ( ! $qualifies && $is_applied && ! empty( $added[ $code ] ) ) {
				$cart->remove_coupon( $coupon->get_code() );
				unset( $added[ $code ] );
				WC()->session->set( self::SESSION_ADDED, $added );
			}
		}

		self::$running = false;
	}

	/**
	 * Builds the plain-array item shape `qualifies_for_items()` needs from a
	 * real WC_Cart — used only by the native `$cart`-based path above
	 * (session-backed, native checkout). The headless REST quote endpoint
	 * (class-rest-quote.php) builds the same shape directly from the request
	 * payload instead.
	 *
	 * @param WC_Cart $cart
	 * @return array<int,array{product_id:int}>
	 */
	protected static function items_from_cart( $cart ) {
		$items = array();
		foreach ( $cart->get_cart() as $cart_item ) {
			$items[] = array( 'product_id' => (int) $cart_item['product_id'] );
		}
		return $items;
	}

	/**
	 * Same eligibility check as before ("does the cart clear this coupon's
	 * own native Minimum Spend, and does it contain the optional required
	 * category"), decoupled from WC_Cart — callable with just a subtotal and
	 * a plain items array, so the headless REST quote endpoint can reuse it
	 * without ever touching a real cart or session.
	 *
	 * @param array<int,array{product_id:int}> $items
	 * @param float     $subtotal
	 * @param WC_Coupon $coupon
	 * @return bool
	 */
	public static function qualifies_for_items( array $items, $subtotal, $coupon ) {
		$minimum = (float) $coupon->get_minimum_amount();
		if ( $minimum > 0 && (float) $subtotal < $minimum ) {
			return false;
		}

		$category = (int) get_post_meta( $coupon->get_id(), Epic_Adv_Coupons_Meta::AUTO_APPLY_CATEGORY, true );
		if ( $category ) {
			$has_category = false;
			foreach ( $items as $item ) {
				if ( has_term( $category, 'product_cat', $item['product_id'] ) ) {
					$has_category = true;
					break;
				}
			}
			if ( ! $has_category ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Every enabled auto-apply coupon, store-wide. Public so the headless
	 * REST quote endpoint (class-rest-quote.php) can list candidates and run
	 * `qualifies_for_items()` against each one when the checkout asks "do
	 * any auto-apply coupons qualify for this cart" without a code.
	 *
	 * @return WC_Coupon[]
	 */
	public static function get_coupons() {
		if ( null !== self::$coupons ) {
			return self::$coupons;
		}

		self::$coupons = array();

		$ids = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => Epic_Adv_Coupons_Meta::AUTO_APPLY_ENABLED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- small, admin-configured table; not user-scaled data.
				'meta_value'     => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		foreach ( $ids as $id ) {
			self::$coupons[] = new WC_Coupon( $id );
		}

		return self::$coupons;
	}
}
