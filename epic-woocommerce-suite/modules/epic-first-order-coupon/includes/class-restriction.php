<?php
/**
 * Adds the "first-time customers only" checkbox to the coupon edit screen
 * and enforces it: a flagged coupon is rejected whenever the billing email
 * on the cart/checkout already has a prior real order on this store.
 *
 * Order statuses treated as "this email has ordered before":
 * processing, completed, on-hold, refunded.
 * Cancelled, failed, and pending-payment (abandoned) orders are ignored,
 * so an abandoned checkout doesn't burn a customer's first-order coupon.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_First_Order_Coupon_Restriction {

	const META_KEY = '_epic_first_order_only';

	/**
	 * Order statuses (unprefixed) that count as a genuine prior order.
	 *
	 * @var string[]
	 */
	const QUALIFYING_STATUSES = array( 'processing', 'completed', 'on-hold', 'refunded' );

	public static function init() {
		add_action( 'woocommerce_coupon_options_usage_restriction', array( __CLASS__, 'render_field' ), 10, 2 );
		add_action( 'woocommerce_coupon_options_save', array( __CLASS__, 'save_field' ), 10, 2 );

		add_filter( 'woocommerce_coupon_is_valid', array( __CLASS__, 'validate_on_cart' ), 10, 2 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_on_checkout_submit' ), 10, 2 );
	}

	/**
	 * Render the checkbox in the coupon's "Usage restriction" tab.
	 *
	 * @param int $coupon_id Post ID of the coupon being edited.
	 */
	public static function render_field( $coupon_id ) {
		woocommerce_wp_checkbox(
			array(
				'id'          => self::META_KEY,
				'label'       => __( 'First-time customers only', 'epic-first-order-coupon' ),
				'description' => __( 'Only valid if the billing email has no prior order on this store (processing, completed, on-hold, or refunded). Abandoned or cancelled orders do not count against the customer.', 'epic-first-order-coupon' ),
				'value'       => get_post_meta( $coupon_id, self::META_KEY, true ) === 'yes' ? 'yes' : 'no',
			)
		);
	}

	/**
	 * Save the checkbox value when the coupon is saved.
	 *
	 * @param int $post_id Post ID of the coupon being saved.
	 */
	public static function save_field( $post_id ) {
		$checked = isset( $_POST[ self::META_KEY ] ) ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- handled by WooCommerce's own coupon-save nonce.
		update_post_meta( $post_id, self::META_KEY, $checked );
	}

	/**
	 * Whether a coupon has the first-order-only restriction enabled.
	 *
	 * @param WC_Coupon $coupon
	 * @return bool
	 */
	protected static function is_restricted( $coupon ) {
		if ( ! $coupon instanceof WC_Coupon ) {
			return false;
		}
		return get_post_meta( $coupon->get_id(), self::META_KEY, true ) === 'yes';
	}

	/**
	 * Best-effort billing email for the current cart/checkout session.
	 * Reflects posted checkout fields once the customer has typed an email;
	 * empty for a guest who hasn't reached that point yet.
	 *
	 * @return string
	 */
	protected static function get_session_email() {
		if ( ! function_exists( 'WC' ) || ! WC()->customer ) {
			return '';
		}
		$email = WC()->customer->get_billing_email();
		return $email ? sanitize_email( $email ) : '';
	}

	/**
	 * Does this email have a qualifying prior order on the store?
	 *
	 * @param string $email
	 * @return bool
	 */
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

	/**
	 * Cart/checkout-time validation. Fires whenever WooCommerce recalculates
	 * cart totals (cart page, and every AJAX checkout field update), so this
	 * re-checks as soon as a billing email becomes known. If no email is
	 * known yet (e.g. guest just applied the code on the cart page), the
	 * coupon is allowed provisionally — {@see validate_on_checkout_submit()}
	 * is the hard stop before an order can actually be placed.
	 *
	 * @param bool      $valid
	 * @param WC_Coupon $coupon
	 * @return bool
	 * @throws Exception To surface a coupon-specific error message.
	 */
	public static function validate_on_cart( $valid, $coupon ) {
		if ( ! $valid || ! self::is_restricted( $coupon ) ) {
			return $valid;
		}

		$email = self::get_session_email();
		if ( ! $email ) {
			return $valid;
		}

		if ( self::has_prior_order( $email ) ) {
			throw new Exception(
				sprintf(
					/* translators: %s: coupon code */
					__( 'Coupon "%s" is only valid for first-time customers. This email address already has an order with us.', 'epic-first-order-coupon' ),
					$coupon->get_code()
				)
			);
		}

		return $valid;
	}

	/**
	 * Final guard at order submission, using the actually-submitted billing
	 * email rather than whatever was known when the coupon was applied.
	 *
	 * @param array         $data   Posted checkout data.
	 * @param WP_Error      $errors
	 */
	public static function validate_on_checkout_submit( $data, $errors ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$email = isset( $data['billing_email'] ) ? sanitize_email( $data['billing_email'] ) : '';
		if ( ! $email ) {
			return;
		}

		foreach ( WC()->cart->get_applied_coupons() as $code ) {
			$coupon = new WC_Coupon( $code );
			if ( ! self::is_restricted( $coupon ) ) {
				continue;
			}

			if ( self::has_prior_order( $email ) ) {
				$errors->add(
					'epic-first-order-coupon',
					sprintf(
						/* translators: %s: coupon code */
						__( 'Coupon "%s" is only valid for first-time customers. This email address already has an order with us — please remove the coupon to continue.', 'epic-first-order-coupon' ),
						$coupon->get_code()
					)
				);
			}
		}
	}
}
