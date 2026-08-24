<?php
/**
 * Business logic on top of Epic_Account_Store: creating/retrieving a
 * WooCommerce customer for a Google account, auto-linking orders by billing
 * email, manual order claiming by code + email/phone, and building the order
 * summary/detail payloads the Next.js website renders.
 *
 * Everything here runs inside WordPress with full WooCommerce loaded — it is
 * only ever invoked from the plugin's own REST handlers (class-rest-api.php).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Account_Service {

	/**
	 * Ensure a WooCommerce customer (WP user with the customer role) exists
	 * for a Google account, so orders placed while the customer is signed in
	 * can carry a real `customer_id`. Best-effort: reuses an existing user by
	 * email; creates one with a random password (the password is never used —
	 * Google is the only login path on the headless storefront).
	 *
	 * @return int WP user ID, or 0 on failure.
	 */
	public static function ensure_wc_customer( $google_sub, $email, $display_name ) {
		$email = strtolower( trim( (string) $email ) );
		if ( empty( $email ) || ! is_email( $email ) ) {
			return 0;
		}

		$existing = get_user_by( 'email', $email );
		if ( $existing ) {
			update_user_meta( $existing->ID, 'epic_google_sub', (string) $google_sub );
			return (int) $existing->ID;
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $email,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'display_name' => $display_name ? (string) $display_name : $email,
				'role'         => 'customer',
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return 0;
		}
		update_user_meta( $user_id, 'epic_google_sub', (string) $google_sub );
		return (int) $user_id;
	}

	/**
	 * Link every order whose billing email matches the account's Google email.
	 * This is the "auto-link by email" behavior: historical guest orders that
	 * were placed under the same address become part of the account history on
	 * the next sign-in. New links are reported; already-linked orders are
	 * skipped silently.
	 *
	 * @return int Number of newly linked orders.
	 */
	public static function auto_link_by_email( $account_id, $email ) {
		$email = strtolower( trim( (string) $email ) );
		if ( empty( $email ) || ! is_email( $email ) || ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		$order_ids = wc_get_orders(
			array(
				'billing_email' => $email,
				'limit'         => -1,
				'return'        => 'ids',
			)
		);

		$count = 0;
		foreach ( (array) $order_ids as $order_id ) {
			$order_id = (int) $order_id;
			if ( $order_id < 1 ) {
				continue;
			}
			if ( Epic_Account_Store::add_order_link( $account_id, $order_id, 'email' ) ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * All of the account's linked orders as summaries, most recent first.
	 * Deleted/trashed orders are skipped, and the list is capped to keep the
	 * payload reasonable.
	 *
	 * @return array List of order summaries (see order_summary()).
	 */
	public static function list_orders( $account_id ) {
		$order_ids = Epic_Account_Store::get_linked_order_ids( $account_id );
		if ( empty( $order_ids ) ) {
			return array();
		}

		$summaries = array();
		foreach ( array_slice( $order_ids, 0, 200 ) as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order || 'shop_order' !== $order->get_type() ) {
				continue;
			}
			$summaries[] = self::order_summary( $order );
		}

		// Newest first by date created; a few orders share a timestamp so the
		// tie-break keeps the ordering stable across calls.
		usort(
			$summaries,
			function ( $a, $b ) {
				$cmp = strcmp( (string) $b['date_created'], (string) $a['date_created'] );
				return 0 !== $cmp ? $cmp : ( (int) $b['order_id'] <=> (int) $a['order_id'] );
			}
		);
		return $summaries;
	}

	/**
	 * A single linked order's full detail. Returns null if the account has no
	 * link to the order (callers must not reveal the order exists otherwise).
	 *
	 * @return array|null Order detail, or null if not linked/unknown.
	 */
	public static function get_order( $account_id, $order_id ) {
		if ( ! Epic_Account_Store::has_order_link( $account_id, (int) $order_id ) ) {
			return null;
		}
		$order = wc_get_order( (int) $order_id );
		if ( ! $order || 'shop_order' !== $order->get_type() ) {
			return null;
		}
		return self::order_detail( $order );
	}

	/**
	 * Manual claim: attach an order to the account by its unguessable order
	 * code, verified against the email or phone the customer used at checkout.
	 *
	 * @return array{status:string, order?:array, error?:string}
	 *   status is one of: linked | already_linked | not_found | no_match | config_error.
	 */
	public static function claim_order( $account_id, $order_code, $email, $phone ) {
		if ( ! class_exists( 'Epic_Order_Code' ) ) {
			return array(
				'status' => 'config_error',
				'error'  => 'The epic-order-codes plugin is required to claim an order by its code.',
			);
		}

		$order_id = Epic_Order_Code::decode( (string) $order_code );
		if ( ! $order_id ) {
			return array( 'status' => 'not_found' );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || 'shop_order' !== $order->get_type() ) {
			return array( 'status' => 'not_found' );
		}

		$billing_email = strtolower( trim( (string) $order->get_billing_email() ) );
		$billing_phone = preg_replace( '/\s+/', '', (string) $order->get_billing_phone() );
		$provided_email = strtolower( trim( (string) $email ) );
		$provided_phone = preg_replace( '/\s+/', '', (string) $phone );

		$matches = ( $provided_email && $billing_email && $provided_email === $billing_email )
			|| ( $provided_phone && $billing_phone && $provided_phone === $billing_phone );
		if ( ! $matches ) {
			return array( 'status' => 'no_match' );
		}

		if ( Epic_Account_Store::add_order_link( $account_id, $order_id, 'claim' ) ) {
			return array( 'status' => 'linked', 'order' => self::order_summary( $order ) );
		}
		return array( 'status' => 'already_linked', 'order' => self::order_summary( $order ) );
	}

	/**
	 * The list-item payload — status + shipping info, deliberately no PII and
	 * no line items (same boundary the epic-order-codes /lookup route keeps).
	 */
	public static function order_summary( $order ) {
		$tracking = (string) $order->get_meta( '_ghn_order_code' );
		$eta      = (string) $order->get_meta( '_ghn_expected_delivery' );
		$is_cod   = 'cod' === $order->get_payment_method();
		$is_paid  = $order->is_paid();

		return array(
			'order_id'             => (int) $order->get_id(),
			'order_number'         => $order->get_order_number(),
			'status'               => $order->get_status(),
			'date_created'         => $order->get_date_created() ? $order->get_date_created()->format( 'c' ) : null,
			'is_paid'              => $is_paid,
			'payment_method_title' => (string) $order->get_payment_method_title(),
			'cod_amount'           => ( $is_cod && ! $is_paid ) ? (float) $order->get_total() : 0,
			'tracking_code'        => $tracking ? $tracking : null,
			'tracking_url'         => $tracking ? self::tracking_url( $tracking ) : null,
			'expected_delivery'    => $eta ? $eta : null,
			'total'                => (float) $order->get_total(),
			'currency'             => $order->get_currency(),
		);
	}

	/** Summary plus line items, addresses and the order's totals. */
	public static function order_detail( $order ) {
		$summary = self::order_summary( $order );

		$line_items = array();
		foreach ( $order->get_items() as $item ) {
			$meta = array();
			foreach ( $item->get_meta_data() as $meta_entry ) {
				$key = (string) $meta_entry->key;
				// Skip hidden (underscore-prefixed) meta — only the customer-
				// visible per-line fields (e.g. grind/pack labels) come through.
				if ( 0 === strpos( $key, '_' ) ) {
					continue;
				}
				$meta[] = array( 'key' => $key, 'value' => (string) $meta_entry->value );
			}
			$line_items[] = array(
				'name'     => (string) $item->get_name(),
				'quantity' => (int) $item->get_quantity(),
				'total'    => (string) $item->get_total(),
				'meta'     => $meta,
			);
		}

		return array_merge(
			$summary,
			array(
				'line_items'            => $line_items,
				'subtotal'              => (float) $order->get_subtotal(),
				'shipping_total'        => (float) $order->get_shipping_total(),
				'discount_total'        => (float) $order->get_discount_total(),
				'shipping_method_title' => (string) $order->get_shipping_method(),
				'customer_note'         => (string) $order->get_customer_note(),
				'billing'               => array(
					'first_name' => (string) $order->get_billing_first_name(),
					'phone'      => (string) $order->get_billing_phone(),
					'email'      => (string) $order->get_billing_email(),
					'address_1'  => (string) $order->get_billing_address_1(),
					'address_2'  => (string) $order->get_billing_address_2(),
					'city'       => (string) $order->get_billing_city(),
					'state'      => (string) $order->get_billing_state(),
					'country'    => (string) $order->get_billing_country(),
				),
				'shipping'              => array(
					'first_name' => (string) $order->get_shipping_first_name(),
					'phone'      => (string) $order->get_shipping_phone(),
					'address_1'  => (string) $order->get_shipping_address_1(),
					'address_2'  => (string) $order->get_shipping_address_2(),
					'city'       => (string) $order->get_shipping_city(),
					'state'      => (string) $order->get_shipping_state(),
					'country'    => (string) $order->get_shipping_country(),
				),
			)
		);
	}

	/** GHN public tracking link — same format as epic-order-codes; filterable. */
	private static function tracking_url( $tracking_code ) {
		$url = 'https://donhang.ghn.vn/?order_code=' . rawurlencode( $tracking_code );
		return apply_filters( 'epic_account_linking_ghn_tracking_url', $url, $tracking_code );
	}
}
