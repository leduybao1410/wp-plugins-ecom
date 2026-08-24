<?php
/**
 * Buy X Get Y / bundle discount.
 *
 * WooCommerce prices a cart line per unit, so a partial-quantity discount
 * (e.g. "2 of the 5 units in the cart are free") can't be expressed as a
 * single per-unit price change on that line. Instead, once we know how
 * many reward units actually qualify, the equivalent money value is taken
 * off as a negative cart fee — the standard, safe way to do conditional
 * partial-quantity discounts in WooCommerce without fighting its per-line
 * pricing model.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Adv_Coupons_Bxgy {

	public static function init() {
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'apply_fees' ) );
	}

	public static function apply_fees( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		if ( ! $cart || empty( $cart->get_applied_coupons() ) ) {
			return;
		}

		foreach ( $cart->get_applied_coupons() as $code ) {
			$coupon = new WC_Coupon( $code );
			if ( get_post_meta( $coupon->get_id(), Epic_Adv_Coupons_Meta::BXGY_ENABLED, true ) !== 'yes' ) {
				continue;
			}

			$discount = self::calculate_discount_for_items( self::items_from_cart( $cart ), $coupon );
			if ( $discount > 0 ) {
				$cart->add_fee(
					sprintf(
						/* translators: %s: coupon code */
						__( '%s discount (Buy X Get Y)', 'epic-advanced-coupons' ),
						$coupon->get_code()
					),
					-$discount,
					true
				);
			}
		}
	}

	/**
	 * Builds the plain-array item shape `calculate_discount_for_items()`
	 * needs from a real WC_Cart — used only by the native `$cart`-based path
	 * above (admin/native-checkout). The headless REST quote endpoint
	 * (class-rest-quote.php) builds the same shape directly from the request
	 * payload instead, without ever touching a WC_Cart.
	 *
	 * @param WC_Cart $cart
	 * @return array<int,array{product_id:int,variation_id:int,quantity:int,unit_price:float}>
	 */
	protected static function items_from_cart( $cart ) {
		$items = array();
		foreach ( $cart->get_cart() as $cart_item ) {
			$items[] = array(
				'product_id'   => (int) $cart_item['product_id'],
				'variation_id' => (int) ( $cart_item['variation_id'] ?? 0 ),
				'quantity'     => (int) $cart_item['quantity'],
				'unit_price'   => (float) $cart_item['data']->get_price(),
			);
		}
		return $items;
	}

	/**
	 * Same Buy-X-Get-Y math as before, just decoupled from WC_Cart — this is
	 * the version both `apply_fees()` (real cart) and the headless REST
	 * quote endpoint (plain items array built straight from the request
	 * payload — no cart, no session) call. It never actually needed more
	 * than each item's product_id/variation_id/quantity/unit_price, so
	 * nothing about the arithmetic changes.
	 *
	 * @param array<int,array{product_id:int,variation_id:int,quantity:int,unit_price:float}> $items
	 * @param WC_Coupon $coupon
	 * @return float
	 */
	public static function calculate_discount_for_items( array $items, $coupon ) {
		$id             = $coupon->get_id();
		$trigger_type   = get_post_meta( $id, Epic_Adv_Coupons_Meta::BXGY_TRIGGER_TYPE, true );
		$trigger_id     = (int) get_post_meta( $id, Epic_Adv_Coupons_Meta::BXGY_TRIGGER_ID, true );
		$trigger_qty    = max( 1, (int) get_post_meta( $id, Epic_Adv_Coupons_Meta::BXGY_TRIGGER_QTY, true ) );
		$reward_type    = get_post_meta( $id, Epic_Adv_Coupons_Meta::BXGY_REWARD_TYPE, true );
		$reward_id      = (int) get_post_meta( $id, Epic_Adv_Coupons_Meta::BXGY_REWARD_ID, true );
		$reward_qty     = max( 1, (int) get_post_meta( $id, Epic_Adv_Coupons_Meta::BXGY_REWARD_QTY, true ) );
		$discount_type  = get_post_meta( $id, Epic_Adv_Coupons_Meta::BXGY_DISCOUNT_TYPE, true );
		$discount_value = (float) get_post_meta( $id, Epic_Adv_Coupons_Meta::BXGY_DISCOUNT_VALUE, true );
		$max_repeats    = (int) get_post_meta( $id, Epic_Adv_Coupons_Meta::BXGY_MAX_REPEATS, true );

		if ( ! $trigger_id || ! $reward_id ) {
			return 0.0;
		}

		$trigger_qty_in_cart = 0;
		foreach ( $items as $item ) {
			if ( self::matches( $item, $trigger_type, $trigger_id ) ) {
				$trigger_qty_in_cart += $item['quantity'];
			}
		}

		$sets = (int) floor( $trigger_qty_in_cart / $trigger_qty );
		if ( $sets < 1 ) {
			return 0.0;
		}
		if ( $max_repeats > 0 ) {
			$sets = min( $sets, $max_repeats );
		}

		$eligible_reward_units = $sets * $reward_qty;
		if ( $eligible_reward_units < 1 ) {
			return 0.0;
		}

		$remaining = $eligible_reward_units;
		$discount  = 0.0;

		foreach ( $items as $item ) {
			if ( $remaining <= 0 ) {
				break;
			}
			if ( ! self::matches( $item, $reward_type, $reward_id ) ) {
				continue;
			}

			$unit_price = (float) $item['unit_price'];
			$take       = min( $remaining, (int) $item['quantity'] );

			switch ( $discount_type ) {
				case 'percent':
					$discount += $take * $unit_price * ( min( 100, max( 0, $discount_value ) ) / 100 );
					break;
				case 'fixed':
					$discount += $take * min( $unit_price, max( 0, $discount_value ) );
					break;
				case 'free':
				default:
					$discount += $take * $unit_price;
					break;
			}

			$remaining -= $take;
		}

		return round( $discount, wc_get_price_decimals() );
	}

	/**
	 * @param array  $item Plain item array — see calculate_discount_for_items().
	 * @param string $type 'product'|'category'
	 * @param int    $id   Product ID or product_cat term ID.
	 * @return bool
	 */
	protected static function matches( $item, $type, $id ) {
		if ( ! $id ) {
			return false;
		}

		$product_id = ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'];

		if ( 'category' === $type ) {
			return has_term( $id, 'product_cat', $item['product_id'] );
		}

		return (int) $product_id === $id || (int) $item['product_id'] === $id;
	}
}
