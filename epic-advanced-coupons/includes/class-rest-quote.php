<?php
/**
 * POST /wp-json/epic/v1/coupon/quote — the headless website's answer to
 * "what does this coupon do for this cart", since the real checkout
 * (Next.js) never instantiates a WC_Cart and never runs the native
 * checkout form, so none of this plugin's hooks fire on their own. Called
 * twice per order in practice: once live as the customer types a code (or
 * silently, with no code, to check for auto-apply eligibility), and once
 * more server-side right before the real order is created — the caller
 * must never trust its own earlier quote as the final amount, same as it
 * never trusts a client-computed shipping fee.
 *
 * Deliberately does NOT spin up a real WC_Cart to reuse this plugin's
 * WC_Cart-only hooks (class-bxgy.php's woocommerce_cart_calculate_fees,
 * class-auto-apply.php's woocommerce_before_calculate_totals) — a one-shot,
 * cookie-less WC_Cart per REST request is a known-fragile pattern (even
 * WooCommerce's own headless Store API needs a persistent Cart-Token
 * session to do this safely). Instead:
 *  - Native discount math (percent/fixed_cart/fixed_product/percent_product)
 *    and the standard restriction checks (expiry, usage limit, min/max
 *    spend, product/category eligibility) are reimplemented directly
 *    against WC_Coupon's own public getters/get_discount_amount() — no cart,
 *    no order object needed.
 *  - Buy X Get Y and auto-apply reuse this plugin's own math via the
 *    items-array methods added for exactly this purpose:
 *    Epic_Adv_Coupons_Bxgy::calculate_discount_for_items() and
 *    Epic_Adv_Coupons_Auto_Apply::qualifies_for_items().
 *  - The three custom restrictions reuse Epic_Adv_Coupons_Restrictions::check().
 *
 * The SAME validity/discount logic here is what actually gets enforced a
 * second time, for real, when the order is created: the website sends
 * `coupon_lines` (for the native-discount case) on the WC REST order-create
 * call, which WooCommerce validates independently via WC_Discounts — now
 * correctly reading the order's own billing email/phone rather than a
 * (nonexistent, for a REST call) session, per the fix in
 * class-restrictions.php. Buy X Get Y and auto-apply discounts are sent as
 * a negative `fee_lines` entry instead, computed from this same endpoint's
 * response, since there's no native WooCommerce mechanism for a
 * partial-quantity cart-fee discount outside of WC_Cart.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Adv_Coupons_Rest_Quote {

	const NAMESPACE = 'epic/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/coupon/quote',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_quote' ),
				'permission_callback' => array( __CLASS__, 'check_secret' ),
			)
		);
	}

	/** Constant-time comparison against the secret configured in WooCommerce → Coupon Quote API. */
	public static function check_secret( \WP_REST_Request $request ) {
		$configured = Epic_Adv_Coupons_Quote_Settings::get_shared_secret();
		if ( empty( $configured ) ) {
			return new \WP_Error( 'epic_coupon_not_configured', 'Shared secret not configured.', array( 'status' => 500 ) );
		}
		$provided = $request->get_header( 'x-epic-secret' );
		if ( empty( $provided ) || ! hash_equals( $configured, $provided ) ) {
			return new \WP_Error( 'epic_coupon_forbidden', 'Invalid or missing X-Epic-Secret header.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * @param \WP_REST_Request $request Body: { code?, email?, phone?, items: [{product_id, variation_id?, quantity, unit_price}] }
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_quote( \WP_REST_Request $request ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return new \WP_Error( 'epic_coupon_no_woocommerce', 'WooCommerce is not active.', array( 'status' => 500 ) );
		}

		$body  = $request->get_json_params();
		$items = self::sanitize_items( is_array( $body['items'] ?? null ) ? $body['items'] : array() );
		if ( empty( $items ) ) {
			return new \WP_Error( 'epic_coupon_bad_request', 'At least one item is required.', array( 'status' => 400 ) );
		}

		$email = isset( $body['email'] ) ? sanitize_email( (string) $body['email'] ) : '';
		$phone = isset( $body['phone'] ) ? (string) $body['phone'] : '';
		$code  = isset( $body['code'] ) ? wc_format_coupon_code( (string) $body['code'] ) : '';

		$subtotal = array_reduce(
			$items,
			function ( $sum, $item ) {
				return $sum + ( $item['unit_price'] * $item['quantity'] );
			},
			0.0
		);

		$applied  = array();
		$messages = array();

		// 1) Auto-apply coupons — every enabled one that currently qualifies,
		// same as the (headless-only-firing, so unused on the real site)
		// woocommerce_before_calculate_totals hook would have applied.
		foreach ( Epic_Adv_Coupons_Auto_Apply::get_coupons() as $coupon ) {
			if ( ! Epic_Adv_Coupons_Auto_Apply::qualifies_for_items( $items, $subtotal, $coupon ) ) {
				continue;
			}
			$result = self::quote_one_coupon( $coupon, $items, $subtotal, $email, $phone );
			if ( $result['valid'] ) {
				$result['autoApplied'] = true;
				$applied[]             = $result;
			}
			// An auto-apply coupon that fails restrictions/native checks is
			// silently skipped, not surfaced as an error — the customer
			// never typed it, so there's nothing to show them a message
			// about (matches the original session-based behavior, which
			// just wouldn't apply it either).
		}

		// 2) The manually-typed code, if any and not already covered above.
		if ( $code ) {
			$already = false;
			foreach ( $applied as $a ) {
				if ( $a['code'] === $code ) {
					$already = true;
					break;
				}
			}
			if ( ! $already ) {
				$coupon_id = wc_get_coupon_id_by_code( $code );
				if ( ! $coupon_id ) {
					$messages[] = sprintf(
						/* translators: %s: coupon code */
						__( 'Coupon "%s" was not found.', 'epic-advanced-coupons' ),
						$code
					);
				} else {
					$coupon = new WC_Coupon( $coupon_id );
					$result = self::quote_one_coupon( $coupon, $items, $subtotal, $email, $phone );
					if ( $result['valid'] ) {
						$result['autoApplied'] = false;
						$applied[]             = $result;
					} else {
						$messages[] = $result['message'];
					}
				}
			}
		}

		$discount_amount = array_sum( array_column( $applied, 'discountAmount' ) );
		$free_shipping   = in_array( true, array_column( $applied, 'freeShipping' ), true );

		return new \WP_REST_Response(
			array(
				'valid'          => empty( $messages ) || ! empty( $applied ),
				'message'        => $messages ? implode( ' ', $messages ) : null,
				'items'          => array_map(
					function ( $a ) {
						return array(
							'code'                 => $a['code'],
							'discountType'         => $a['discountType'],
							// Send these two separately — see quote_one_coupon()'s
							// docblock for why the website must apply them via
							// different mechanisms (coupon_lines vs fee_lines)
							// rather than one combined amount.
							'nativeDiscountAmount' => round( $a['nativeDiscountAmount'] ),
							'bxgyDiscountAmount'   => round( $a['bxgyDiscountAmount'] ),
							'discountAmount'       => round( $a['discountAmount'] ),
							'freeShipping'         => $a['freeShipping'],
							'autoApplied'          => $a['autoApplied'],
						);
					},
					$applied
				),
				'discountAmount' => round( $discount_amount ),
				'freeShipping'   => $free_shipping,
			),
			200
		);
	}

	/**
	 * Validates + prices one coupon against the given cart. Never throws —
	 * every failure mode is `valid => false` with a human message, since a
	 * bad coupon code is an expected, routine outcome, not a server error.
	 *
	 * Splits the discount into `nativeDiscountAmount` and
	 * `bxgyDiscountAmount` rather than one combined number — deliberately,
	 * because the website has to *apply* these two very differently at
	 * order-creation time: the native amount is never sent explicitly, it's
	 * whatever WooCommerce's own `coupon_lines` machinery computes for
	 * itself when the code is applied for real (same WC_Coupon math this
	 * method already mirrors); the BxGy amount has no native WooCommerce
	 * concept at all, so the website must send it as its own explicit
	 * negative `fee_lines` entry. Collapsing them into one number here would
	 * leave the website with no way to tell WooCommerce how much of a
	 * combined discount to attribute to which mechanism.
	 *
	 * @return array{valid:bool,message:?string,code:string,discountType:?string,nativeDiscountAmount:float,bxgyDiscountAmount:float,discountAmount:float,freeShipping:bool}
	 */
	protected static function quote_one_coupon( WC_Coupon $coupon, array $items, $subtotal, $email, $phone ) {
		$code = $coupon->get_code();
		$fail = function ( $message ) use ( $code ) {
			return array(
				'valid'                => false,
				'message'              => $message,
				'code'                 => $code,
				'discountType'         => null,
				'nativeDiscountAmount' => 0.0,
				'bxgyDiscountAmount'   => 0.0,
				'discountAmount'       => 0.0,
				'freeShipping'         => false,
			);
		};

		if ( ! $coupon->get_id() ) {
			return $fail( sprintf( __( 'Coupon "%s" was not found.', 'epic-advanced-coupons' ), $code ) );
		}

		$native_error = self::validate_native( $coupon, $items, $subtotal );
		if ( $native_error ) {
			return $fail( $native_error );
		}

		// The 3 custom restrictions — first-order-only, allowlist, schedule.
		// Same check the order-creation-time woocommerce_coupon_is_valid
		// filter runs (see class-restrictions.php) — running it again here
		// is what lets the checkout show the customer a message *before*
		// they submit, rather than only finding out at order creation.
		$restriction_error = Epic_Adv_Coupons_Restrictions::check( $coupon, $email, $phone );
		if ( $restriction_error ) {
			return $fail( $restriction_error );
		}

		$native_amount = 0.0;
		$discount_type = null;

		if ( (float) $coupon->get_amount() > 0 ) {
			$native_amount = self::calculate_native_discount( $coupon, $items, $subtotal );
			$discount_type = $coupon->get_discount_type();
		}

		$bxgy_amount = 0.0;
		if ( get_post_meta( $coupon->get_id(), Epic_Adv_Coupons_Meta::BXGY_ENABLED, true ) === 'yes' ) {
			$bxgy_amount = Epic_Adv_Coupons_Bxgy::calculate_discount_for_items( $items, $coupon );
			if ( $bxgy_amount > 0 ) {
				// A coupon can combine a native discount AND Buy X Get Y —
				// "bxgy" wins the label in that case since it's the more
				// specific/unusual thing about this coupon worth surfacing.
				$discount_type = 'bxgy';
			}
		}

		if ( 0.0 === $native_amount && 0.0 === $bxgy_amount ) {
			return $fail( sprintf( __( 'Coupon "%s" does not apply to this order.', 'epic-advanced-coupons' ), $code ) );
		}

		return array(
			'valid'                => true,
			'message'              => null,
			'code'                 => $code,
			'discountType'         => $discount_type,
			'nativeDiscountAmount' => $native_amount,
			'bxgyDiscountAmount'   => $bxgy_amount,
			'discountAmount'       => $native_amount + $bxgy_amount,
			'freeShipping'         => (bool) $coupon->get_free_shipping(),
		);
	}

	/**
	 * Native WooCommerce restriction checks that don't need a cart/order —
	 * expiry, usage limit, min/max spend, and product/category eligibility.
	 * Deliberately NOT covering: individual_use (meaningless for a single
	 * coupon, no combination happening here), exclude_sale_items,
	 * usage_limit_per_user (would need an order-history query per email on
	 * every keystroke — left to native WooCommerce to enforce for real at
	 * order-creation time via `coupon_lines`, same as it always would). A
	 * gap here fails SAFE: the customer would see a discount previewed that
	 * then gets rejected by WooCommerce at order creation, not the reverse.
	 *
	 * @return string|null
	 */
	protected static function validate_native( WC_Coupon $coupon, array $items, $subtotal ) {
		$expires = $coupon->get_date_expires();
		if ( $expires && $expires->getTimestamp() < time() ) {
			return sprintf( __( 'Coupon "%s" has expired.', 'epic-advanced-coupons' ), $coupon->get_code() );
		}

		$usage_limit = (int) $coupon->get_usage_limit();
		if ( $usage_limit > 0 && (int) $coupon->get_usage_count() >= $usage_limit ) {
			return sprintf( __( 'Coupon "%s" has reached its usage limit.', 'epic-advanced-coupons' ), $coupon->get_code() );
		}

		$minimum = (float) $coupon->get_minimum_amount();
		if ( $minimum > 0 && $subtotal < $minimum ) {
			return sprintf(
				/* translators: 1: coupon code, 2: formatted minimum amount */
				__( 'Coupon "%1$s" requires a minimum spend of %2$s.', 'epic-advanced-coupons' ),
				$coupon->get_code(),
				wc_price( $minimum )
			);
		}

		$maximum = (float) $coupon->get_maximum_amount();
		if ( $maximum > 0 && $subtotal > $maximum ) {
			return sprintf(
				/* translators: %s: coupon code */
				__( 'Coupon "%s" is not valid for orders this large.', 'epic-advanced-coupons' ),
				$coupon->get_code()
			);
		}

		if ( self::eligible_items( $coupon, $items ) === null ) {
			return sprintf( __( 'Coupon "%s" is not valid for the products in your cart.', 'epic-advanced-coupons' ), $coupon->get_code() );
		}

		return null;
	}

	/**
	 * Items this coupon's product_ids/product_categories restrictions allow
	 * it to discount (and not excluded by exclude_product_ids/
	 * exclude_product_categories). Returns null (not an empty array) when a
	 * restriction is configured but nothing in the cart matches it — that's
	 * the "not applicable" case; an empty *restriction* (nothing configured)
	 * returns every item.
	 *
	 * @return array|null
	 */
	protected static function eligible_items( WC_Coupon $coupon, array $items ) {
		$include_products   = array_map( 'intval', $coupon->get_product_ids() );
		$exclude_products   = array_map( 'intval', $coupon->get_excluded_product_ids() );
		$include_categories = array_map( 'intval', $coupon->get_product_categories() );
		$exclude_categories = array_map( 'intval', $coupon->get_excluded_product_categories() );

		$has_restriction = $include_products || $include_categories;

		$eligible = array();
		foreach ( $items as $item ) {
			$product_id = $item['variation_id'] ?: $item['product_id'];

			if ( $exclude_products && in_array( $item['product_id'], $exclude_products, true ) ) {
				continue;
			}
			if ( $exclude_categories && has_term( $exclude_categories, 'product_cat', $item['product_id'] ) ) {
				continue;
			}

			if ( $has_restriction ) {
				$matches_product  = $include_products && ( in_array( $product_id, $include_products, true ) || in_array( $item['product_id'], $include_products, true ) );
				$matches_category = $include_categories && has_term( $include_categories, 'product_cat', $item['product_id'] );
				if ( ! $matches_product && ! $matches_category ) {
					continue;
				}
			}

			$eligible[] = $item;
		}

		if ( $has_restriction && empty( $eligible ) ) {
			return null;
		}

		return $eligible;
	}

	/**
	 * @return float
	 */
	protected static function calculate_native_discount( WC_Coupon $coupon, array $items, $subtotal ) {
		$eligible = self::eligible_items( $coupon, $items );
		if ( null === $eligible ) {
			return 0.0;
		}

		$type = $coupon->get_discount_type();

		if ( in_array( $type, array( 'percent', 'fixed_cart' ), true ) ) {
			$eligible_subtotal = array_reduce(
				$eligible,
				function ( $sum, $item ) {
					return $sum + ( $item['unit_price'] * $item['quantity'] );
				},
				0.0
			);
			// get_discount_amount() with a numeric $discounting_amount and no
			// $cart_item is the cart-wide code path (percent of amount, or
			// fixed capped at the amount) — the same public method
			// WC_Cart/WC_Discounts call internally, just invoked directly.
			return max( 0.0, (float) $coupon->get_discount_amount( $eligible_subtotal ) );
		}

		// fixed_product / percent_product — per matching line, at unit price,
		// multiplied by quantity (get_discount_amount()'s own $single=true
		// path returns the per-unit discount).
		$discount = 0.0;
		foreach ( $eligible as $item ) {
			$per_unit  = max( 0.0, (float) $coupon->get_discount_amount( $item['unit_price'], null, true ) );
			$discount += $per_unit * $item['quantity'];
		}
		return $discount;
	}

	/**
	 * @param array $raw
	 * @return array<int,array{product_id:int,variation_id:int,quantity:int,unit_price:float}>
	 */
	protected static function sanitize_items( array $raw ) {
		$items = array();
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['product_id'] ) || empty( $entry['quantity'] ) ) {
				continue;
			}
			$items[] = array(
				'product_id'   => (int) $entry['product_id'],
				'variation_id' => (int) ( $entry['variation_id'] ?? 0 ),
				'quantity'     => max( 1, (int) $entry['quantity'] ),
				'unit_price'   => max( 0.0, (float) ( $entry['unit_price'] ?? 0 ) ),
			);
		}
		return $items;
	}
}
