<?php
/**
 * Extra "is this coupon allowed right now" conditions, checked alongside
 * WooCommerce's own native ones (usage limit, expiry, min spend, etc.):
 *
 * - first-time customers only (billing email has no qualifying prior order)
 * - customer allowlist (billing email OR billing phone matches a list)
 * - recurring day-of-week / time-of-day schedule
 *
 * All three are checked at two points: live during cart/checkout
 * recalculation (so the customer sees the error as soon as possible), and
 * again as a hard stop at order submission (so nothing slips through if the
 * live check didn't get a chance to re-run).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Adv_Coupons_Restrictions {

	/**
	 * Order statuses (unprefixed) that count as a genuine prior order for
	 * the first-time-customer check.
	 *
	 * @var string[]
	 */
	const QUALIFYING_STATUSES = array( 'processing', 'completed', 'on-hold', 'refunded' );

	public static function init() {
		// Priority args 10, 3: the 3rd arg is the WC_Discounts instance doing
		// the validating. WC_Discounts::is_coupon_valid() passes itself here
		// as apply_filters( 'woocommerce_coupon_is_valid', $valid, $coupon,
		// $this ) — and it's the SAME filter whether the thing being
		// validated is a real WC_Cart (native checkout, session-backed) or a
		// WC_Order (REST `coupon_lines` on order create/update — no session
		// exists for a server-to-server call). See validate_on_cart() below
		// for why that distinction matters.
		add_filter( 'woocommerce_coupon_is_valid', array( __CLASS__, 'validate_on_cart' ), 10, 3 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_on_checkout_submit' ), 10, 2 );
	}

	/**
	 * Cart/checkout-time validation. Re-runs on every AJAX checkout field
	 * update, so it re-checks as soon as billing details become known. If
	 * the info needed isn't known yet (guest just applied the code on the
	 * cart page), the coupon is allowed provisionally — the checkout-submit
	 * check is the hard stop.
	 *
	 * Also the ONLY enforcement point for a headless order created via the
	 * WooCommerce REST API with `coupon_lines`: that path never touches
	 * `woocommerce_after_checkout_validation` (that hook only fires from
	 * WC_Checkout::process_checkout(), i.e. the native checkout form), so
	 * this filter is where first-order-only/allowlist/schedule get their
	 * one and only chance to block a headless order.
	 *
	 * @param bool                $valid
	 * @param WC_Coupon           $coupon
	 * @param WC_Discounts|null   $discounts The object doing the validating —
	 *                                        bound to either a WC_Cart or a
	 *                                        WC_Order. Optional only for
	 *                                        back-compat with any other code
	 *                                        that might call this filter with
	 *                                        just 2 args.
	 * @return bool
	 * @throws Exception To surface a coupon-specific error message.
	 */
	public static function validate_on_cart( $valid, $coupon, $discounts = null ) {
		if ( ! $valid ) {
			return $valid;
		}

		list( $email, $phone ) = self::resolve_customer_context( $discounts );

		$message = self::get_violation_message( $coupon, $email, $phone );
		if ( $message ) {
			throw new Exception( $message );
		}

		return $valid;
	}

	/**
	 * Which email/phone to check against, given what this coupon is being
	 * validated against:
	 *  - A WC_Order (headless REST `coupon_lines` application, or a manual
	 *    wp-admin order edit) — the order's OWN submitted billing details,
	 *    read directly off the order. This is the fix for a real bug: before
	 *    this existed, an order-context validation fell through to the
	 *    session-based lookup below, which is empty for a server-to-server
	 *    REST call (no cookies), so first-order-only/allowlist silently
	 *    never blocked anyone for a headless order.
	 *  - Anything else (normally a WC_Cart, native checkout) — the session
	 *    customer, as this always worked.
	 *
	 * @param WC_Discounts|null $discounts
	 * @return array{0:string,1:string} [$email, $phone]
	 */
	protected static function resolve_customer_context( $discounts ) {
		if ( $discounts && is_callable( array( $discounts, 'get_object' ) ) ) {
			$object = $discounts->get_object();
			if ( $object instanceof WC_Order ) {
				$email = $object->get_billing_email();
				$phone = $object->get_billing_phone();
				return array(
					$email ? sanitize_email( $email ) : '',
					$phone ? self::normalize_phone( $phone ) : '',
				);
			}
		}

		return array( self::get_session_email(), self::get_session_phone() );
	}

	/**
	 * Final guard at order submission, using the actually-submitted billing
	 * details rather than whatever was known when the coupon was applied.
	 *
	 * @param array    $data
	 * @param WP_Error $errors
	 */
	public static function validate_on_checkout_submit( $data, $errors ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$email = isset( $data['billing_email'] ) ? sanitize_email( $data['billing_email'] ) : '';
		$phone = isset( $data['billing_phone'] ) ? self::normalize_phone( $data['billing_phone'] ) : '';

		foreach ( WC()->cart->get_applied_coupons() as $code ) {
			$coupon  = new WC_Coupon( $code );
			$message = self::get_violation_message( $coupon, $email, $phone, true );
			if ( $message ) {
				$errors->add( 'epic-advanced-coupons', $message );
			}
		}
	}

	/**
	 * Public entry point for code outside this class — specifically the
	 * `epic/v1/coupon/quote` REST endpoint (see class-rest-quote.php), which
	 * needs to run these three restriction checks against a real customer
	 * email/phone it already has in hand, without touching WC()->customer's
	 * session or going through the woocommerce_coupon_is_valid filter at
	 * all. Same logic as validate_on_cart() above, just callable directly.
	 *
	 * @param WC_Coupon $coupon
	 * @param string    $email
	 * @param string    $phone
	 * @return string|null Violation message, or null if the coupon passes.
	 */
	public static function check( $coupon, $email, $phone ) {
		return self::get_violation_message( $coupon, (string) $email, (string) $phone );
	}

	/**
	 * Runs every enabled restriction for one coupon and returns the first
	 * violation message, or null if the coupon passes all of them.
	 *
	 * @param WC_Coupon $coupon
	 * @param string    $email
	 * @param string    $phone
	 * @param bool      $final_check True when called from checkout submit —
	 *                                skips the "not known yet" leniency.
	 * @return string|null
	 */
	protected static function get_violation_message( $coupon, $email, $phone, $final_check = false ) {
		if ( ! $coupon instanceof WC_Coupon ) {
			return null;
		}

		if ( self::meta( $coupon, Epic_Adv_Coupons_Meta::FIRST_ORDER_ONLY ) === 'yes' ) {
			if ( $email && self::has_prior_order( $email ) ) {
				return sprintf(
					/* translators: %s: coupon code */
					__( 'Coupon "%s" is only valid for first-time customers. This email address already has an order with us.', 'epic-advanced-coupons' ),
					$coupon->get_code()
				);
			}
		}

		$allowlist = trim( (string) self::meta( $coupon, Epic_Adv_Coupons_Meta::ALLOWLIST ) );
		if ( $allowlist && ( $email || $phone ) ) {
			if ( ! self::matches_allowlist( $allowlist, $email, $phone ) ) {
				return sprintf(
					/* translators: %s: coupon code */
					__( 'Coupon "%s" is not valid for this customer.', 'epic-advanced-coupons' ),
					$coupon->get_code()
				);
			}
		}

		if ( ! self::within_schedule( $coupon ) ) {
			return sprintf(
				/* translators: %s: coupon code */
				__( 'Coupon "%s" is not valid at this time.', 'epic-advanced-coupons' ),
				$coupon->get_code()
			);
		}

		return null;
	}

	protected static function meta( $coupon, $key ) {
		return get_post_meta( $coupon->get_id(), $key, true );
	}

	/* ---------------------------------------------------------------- *
	 * First-time customers only
	 * ---------------------------------------------------------------- */

	protected static function has_prior_order( $email ) {
		$email = sanitize_email( strtolower( trim( $email ) ) );
		if ( ! $email || ! is_email( $email ) ) {
			return false;
		}

		$statuses = array_map(
			function ( $status ) {
				return 'wc-' . $status;
			},
			self::QUALIFYING_STATUSES
		);

		$order_ids = wc_get_orders(
			array(
				'billing_email' => $email,
				'status'        => $statuses,
				'limit'         => 1,
				'return'        => 'ids',
			)
		);

		return ! empty( $order_ids );
	}

	/* ---------------------------------------------------------------- *
	 * Customer allowlist (email and/or phone, one entry per line)
	 * ---------------------------------------------------------------- */

	protected static function matches_allowlist( $allowlist, $email, $phone ) {
		$lines = preg_split( '/[\r\n,]+/', $allowlist );
		$email = $email ? strtolower( trim( $email ) ) : '';
		$phone = $phone ? self::normalize_phone( $phone ) : '';

		foreach ( $lines as $line ) {
			$line = strtolower( trim( $line ) );
			if ( '' === $line ) {
				continue;
			}

			// Email or email-domain-wildcard entry, e.g. "*@gmail.com".
			if ( false !== strpos( $line, '@' ) ) {
				if ( ! $email ) {
					continue;
				}
				if ( '*@' === substr( $line, 0, 2 ) ) {
					$domain = substr( $line, 2 );
					if ( $domain && substr( $email, -strlen( $domain ) - 1 ) === '@' . $domain ) {
						return true;
					}
				} elseif ( $line === $email ) {
					return true;
				}
				continue;
			}

			// Otherwise treat as a phone number entry.
			$line_phone = self::normalize_phone( $line );
			if ( $phone && $line_phone && $line_phone === $phone ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalize a phone number for comparison: digits only, then keep the
	 * last 9 so "+84901234567", "0901234567", and "901234567" all match.
	 *
	 * @param string $phone
	 * @return string
	 */
	protected static function normalize_phone( $phone ) {
		$digits = preg_replace( '/\D+/', '', (string) $phone );
		if ( strlen( $digits ) <= 9 ) {
			return $digits;
		}
		return substr( $digits, -9 );
	}

	/* ---------------------------------------------------------------- *
	 * Day/time schedule
	 * ---------------------------------------------------------------- */

	protected static function within_schedule( $coupon ) {
		$days = trim( (string) self::meta( $coupon, Epic_Adv_Coupons_Meta::SCHEDULE_DAYS ) );
		$start = trim( (string) self::meta( $coupon, Epic_Adv_Coupons_Meta::SCHEDULE_START ) );
		$end   = trim( (string) self::meta( $coupon, Epic_Adv_Coupons_Meta::SCHEDULE_END ) );

		if ( ! $days && ! $start && ! $end ) {
			return true; // No schedule configured at all — always valid.
		}

		$now = function_exists( 'current_datetime' ) ? current_datetime() : new DateTime( 'now', wp_timezone() );

		if ( $days ) {
			$allowed_days = array_filter( array_map( 'trim', explode( ',', strtolower( $days ) ) ) );
			$today        = strtolower( $now->format( 'D' ) ); // Mon, Tue, ... -> mon, tue, ...
			$today        = substr( $today, 0, 3 );
			if ( $allowed_days && ! in_array( $today, $allowed_days, true ) ) {
				return false;
			}
		}

		if ( $start && $end ) {
			$now_minutes   = ( (int) $now->format( 'H' ) ) * 60 + (int) $now->format( 'i' );
			$start_minutes = self::hhmm_to_minutes( $start );
			$end_minutes   = self::hhmm_to_minutes( $end );

			if ( false === $start_minutes || false === $end_minutes ) {
				return true; // Malformed value — don't block on a bad setting.
			}

			if ( $start_minutes <= $end_minutes ) {
				if ( $now_minutes < $start_minutes || $now_minutes > $end_minutes ) {
					return false;
				}
			} else {
				// Window wraps past midnight, e.g. 22:00-02:00.
				if ( $now_minutes > $end_minutes && $now_minutes < $start_minutes ) {
					return false;
				}
			}
		}

		return true;
	}

	protected static function hhmm_to_minutes( $value ) {
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', trim( $value ), $m ) ) {
			return false;
		}
		return ( (int) $m[1] ) * 60 + (int) $m[2];
	}

	/* ---------------------------------------------------------------- *
	 * Session helpers
	 * ---------------------------------------------------------------- */

	protected static function get_session_email() {
		if ( ! function_exists( 'WC' ) || ! WC()->customer ) {
			return '';
		}
		$email = WC()->customer->get_billing_email();
		return $email ? sanitize_email( $email ) : '';
	}

	protected static function get_session_phone() {
		if ( ! function_exists( 'WC' ) || ! WC()->customer ) {
			return '';
		}
		$phone = WC()->customer->get_billing_phone();
		return $phone ? self::normalize_phone( $phone ) : '';
	}
}
