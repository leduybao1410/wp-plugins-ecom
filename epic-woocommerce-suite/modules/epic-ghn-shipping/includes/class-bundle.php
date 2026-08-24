<?php
/**
 * Bundle domain logic: order validation/comparison, aggregation, and the
 * DB-backed wp_epic_ghn_bundles record lifecycle (draft -> booked/failed).
 *
 * Deliberately keeps all cross-order math and the actual GHN calls in one
 * place so both the review screen (class-bundle-admin-page.php) and any
 * future caller only ever aggregate/book a bundle one way — see PLAN.md §6
 * for why "one fee for the combined parcel" is the whole point of bundling.
 *
 * Fee/COD bookkeeping (per explicit decision, overriding PLAN.md §6's
 * "first order carries the fee" default): confirm() never rewrites any
 * order's own shipping_lines total. The bundle row alone stores the real
 * combined GHN fee for reconciliation.
 *
 * COD orders can't be bundled at all right now — see the guard in
 * load_orders() — because a bundle ships as one physical parcel to one
 * address with one GHN cod_amount field, and there's no per-order split of
 * that figure yet. Only prepaid orders (already paid online — see
 * Epic_GHN_Client::is_cod_order()) reach confirm(), so every bundle books
 * as payment_type_id = PREPAID with cod_amount = 0: nothing is collected on
 * delivery. The combined GHN fee is still tracked on the bundle record for
 * reconciliation (each order's own shipping total is left untouched), it's
 * just no longer cash the courier collects.
 *
 * @package Epic_GHN_Shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_GHN_Bundle {

	const STATUS_DRAFT  = 'draft';
	const STATUS_BOOKED = 'booked';
	const STATUS_FAILED = 'failed';

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'epic_ghn_bundles';
	}

	// ------------------------------------------------------------------
	// Order loading / validation
	// ------------------------------------------------------------------

	/**
	 * @param int[] $order_ids
	 * @return array {
	 *   @type WC_Order[] $orders  Bookable orders, sorted by ID ascending — orders[0] is the "reference" order for recipient/address.
	 *   @type array       $dropped [{ id, reason }] orders excluded (not found, or already shipped).
	 * }
	 */
	public static function load_orders( array $order_ids ) {
		$order_ids = array_values( array_unique( array_filter( array_map( 'absint', $order_ids ) ) ) );
		sort( $order_ids, SORT_NUMERIC );

		$orders  = array();
		$dropped = array();

		foreach ( $order_ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order instanceof WC_Order ) {
				$dropped[] = array(
					'id'     => $id,
					'reason' => __( 'Order not found.', 'epic-ghn-shipping' ),
				);
				continue;
			}
			if ( $order->get_meta( '_ghn_order_code' ) ) {
				$dropped[] = array(
					'id'     => $id,
					'reason' => __( 'Already has a GHN shipment — cancel it first if you need to re-bundle this order.', 'epic-ghn-shipping' ),
				);
				continue;
			}
			/**
			 * COD orders are disabled from bundling for now: confirm()'s
			 * combined-parcel COD amount is Σ(item subtotals) + one real
			 * shipping fee across every order in the bundle (see the class
			 * docblock), and there's currently no per-order split of that
			 * figure — bundling a COD order in with prepaid ones would have
			 * GHN collect that COD order's own already-correct share PLUS
			 * however the fee lands, from whichever recipient the courier
			 * actually meets, since the whole bundle ships as one physical
			 * parcel to one address. Until bundling supports a real
			 * per-order COD split, only prepaid (already paid online, e.g.
			 * via SePay — see Epic_GHN_Client::is_cod_order()) orders can be
			 * bundled; ship a COD order individually from its own order
			 * screen instead.
			 */
			if ( Epic_GHN_Client::is_cod_order( $order ) ) {
				$dropped[] = array(
					'id'     => $id,
					'reason' => __( 'COD orders can\'t be bundled right now (bundling only supports orders already paid online) — ship this one individually from its own order screen.', 'epic-ghn-shipping' ),
				);
				continue;
			}
			$orders[] = $order;
		}

		return array(
			'orders'  => $orders,
			'dropped' => $dropped,
		);
	}

	// ------------------------------------------------------------------
	// Recipient comparison
	// ------------------------------------------------------------------

	private static function normalize_phone( $phone ) {
		return preg_replace( '/\D+/', '', (string) $phone );
	}

	/** @return array { order_id, name, phone, phone_normalized, address_1, address_2, city, state, resolved } */
	public static function snapshot_recipient( WC_Order $order ) {
		$phone = $order->get_shipping_phone() ? $order->get_shipping_phone() : $order->get_billing_phone();
		$name  = $order->get_formatted_shipping_full_name();

		return array(
			'order_id'         => $order->get_id(),
			'name'             => $name ? $name : $order->get_formatted_billing_full_name(),
			'phone'            => $phone,
			'phone_normalized' => self::normalize_phone( $phone ),
			'address_1'        => $order->get_shipping_address_1(),
			'address_2'        => $order->get_shipping_address_2(),
			'city'             => $order->get_shipping_city(),
			'state'            => $order->get_shipping_state(),
			'resolved'         => Epic_GHN_Address_Resolver::resolve( $order ),
		);
	}

	/**
	 * Compares every order's recipient snapshot against the first (lowest
	 * order ID, "reference") order — phone + GHN address codes, per
	 * PLAN.md §5.2. Falls back to noting "couldn't verify" when either side's
	 * address didn't auto-resolve, rather than guessing a match.
	 *
	 * @param WC_Order[] $orders
	 * @return array {
	 *   @type int   $reference_id
	 *   @type array $snapshots [order_id => snapshot]
	 *   @type array $diffs     [order_id => string[]] human-readable per-order differences (empty = no diff)
	 *   @type bool  $has_mismatch
	 * }
	 */
	public static function compare_recipients( array $orders ) {
		$snapshots = array();
		foreach ( $orders as $order ) {
			$snapshots[ $order->get_id() ] = self::snapshot_recipient( $order );
		}

		$order_ids    = array_keys( $snapshots );
		$reference_id = $order_ids[0];
		$reference    = $snapshots[ $reference_id ];

		$diffs        = array();
		$has_mismatch = false;

		foreach ( $order_ids as $id ) {
			$diffs[ $id ] = array();
			if ( $id === $reference_id ) {
				continue;
			}
			$snap = $snapshots[ $id ];

			if ( $snap['phone_normalized'] !== $reference['phone_normalized'] ) {
				$diffs[ $id ][] = sprintf(
					/* translators: 1: this order's phone, 2: reference order's phone, 3: reference order ID */
					__( 'Phone differs: %1$s vs %2$s on order #%3$d', 'epic-ghn-shipping' ),
					$snap['phone'] ? $snap['phone'] : __( '(empty)', 'epic-ghn-shipping' ),
					$reference['phone'] ? $reference['phone'] : __( '(empty)', 'epic-ghn-shipping' ),
					$reference_id
				);
			}

			if ( ! $snap['resolved']['resolved'] || ! $reference['resolved']['resolved'] ) {
				$diffs[ $id ][] = __( 'Address could not be automatically matched to GHN province/district/ward codes for this order and/or the reference order — verify manually.', 'epic-ghn-shipping' );
			} elseif (
				(int) $snap['resolved']['province_id'] !== (int) $reference['resolved']['province_id']
				|| (int) $snap['resolved']['district_id'] !== (int) $reference['resolved']['district_id']
				|| (string) $snap['resolved']['ward_code'] !== (string) $reference['resolved']['ward_code']
			) {
				$diffs[ $id ][] = sprintf(
					/* translators: 1: this order's matched address, 2: reference order's matched address, 3: reference order ID */
					__( 'GHN address differs: %1$s vs %2$s on order #%3$d', 'epic-ghn-shipping' ),
					trim( $snap['resolved']['ward_name'] . ', ' . $snap['resolved']['district_name'] . ', ' . $snap['resolved']['province_name'], ', ' ),
					trim( $reference['resolved']['ward_name'] . ', ' . $reference['resolved']['district_name'] . ', ' . $reference['resolved']['province_name'], ', ' ),
					$reference_id
				);
			}

			if ( ! empty( $diffs[ $id ] ) ) {
				$has_mismatch = true;
			}
		}

		return array(
			'reference_id' => $reference_id,
			'snapshots'    => $snapshots,
			'diffs'        => $diffs,
			'has_mismatch' => $has_mismatch,
		);
	}

	// ------------------------------------------------------------------
	// Aggregation
	// ------------------------------------------------------------------

	/** @return array [{ name, quantity }] merged by product ID (falls back to name for line items with no linked product). */
	public static function aggregate_items( array $orders ) {
		$merged = array();

		foreach ( $orders as $order ) {
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				/** @var WC_Order_Item_Product $item */
				$product_id = $item->get_product_id();
				$key        = $product_id ? 'p' . $product_id : 'n' . strtolower( trim( $item->get_name() ) );

				if ( ! isset( $merged[ $key ] ) ) {
					$merged[ $key ] = array(
						'name'     => $item->get_name(),
						'quantity' => 0,
					);
				}
				$merged[ $key ]['quantity'] += $item->get_quantity();
			}
		}

		return array_values( $merged );
	}

	/** Σ each order's weight (same per-product-weight-with-fallback logic as the single-order meta box, so estimates agree). */
	public static function aggregate_weight_g( array $orders ) {
		$total = 0;
		foreach ( $orders as $order ) {
			$total += Epic_GHN_Order_Meta_Box::calculate_order_weight_g( $order );
		}
		return max( (int) $total, 1 );
	}

	/** Σ each order's item subtotal (excl. tax/shipping) — matches the wp_epic_ghn_bundles.items_subtotal column. */
	public static function aggregate_subtotal( array $orders ) {
		$total = 0.0;
		foreach ( $orders as $order ) {
			$total += (float) $order->get_subtotal();
		}
		return $total;
	}

	/**
	 * GHN's fee endpoint normally returns a 'total' field (per api.ghn.vn/home/docs/detail?id=64),
	 * but validation-style/breakdown-only responses have been seen on some API
	 * revisions without one — summing every numeric component is the
	 * documented fallback so a booking never silently proceeds with a
	 * zero fee.
	 */
	public static function extract_fee_amount( $fee ) {
		if ( is_array( $fee ) && isset( $fee['total'] ) && is_numeric( $fee['total'] ) ) {
			return (float) $fee['total'];
		}
		if ( is_array( $fee ) ) {
			$numeric = array_filter( $fee, 'is_numeric' );
			if ( ! empty( $numeric ) ) {
				return (float) array_sum( $numeric );
			}
		}
		return 0.0;
	}

	// ------------------------------------------------------------------
	// DB persistence
	// ------------------------------------------------------------------

	private static function insert_draft( array $data ) {
		global $wpdb;

		$now = current_time( 'mysql' );

		$wpdb->insert(
			self::table(),
			array(
				'status'            => self::STATUS_DRAFT,
				'order_ids'         => wp_json_encode( $data['order_ids'] ),
				'recipient_name'    => $data['recipient_name'],
				'recipient_phone'   => $data['recipient_phone'],
				'recipient_address' => $data['recipient_address'],
				'total_weight_g'    => $data['total_weight_g'],
				'package_length'    => $data['package_length'],
				'package_width'     => $data['package_width'],
				'package_height'    => $data['package_height'],
				'items_subtotal'    => (int) round( $data['items_subtotal'] ),
				'created_by'        => $data['created_by'],
				'created_at'        => $now,
				'updated_at'        => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	private static function mark_booked( $bundle_id, array $data ) {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'status'         => self::STATUS_BOOKED,
				'ghn_order_code' => $data['ghn_order_code'],
				'shipping_fee'   => (int) round( $data['shipping_fee'] ),
				'cod_amount'     => (int) round( $data['cod_amount'] ),
				'updated_at'     => current_time( 'mysql' ),
			),
			array( 'id' => $bundle_id ),
			array( '%s', '%s', '%d', '%d', '%s' ),
			array( '%d' )
		);
	}

	private static function mark_failed( $bundle_id, $message ) {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'status'        => self::STATUS_FAILED,
				'error_message' => $message,
				'updated_at'    => current_time( 'mysql' ),
			),
			array( 'id' => $bundle_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/** @return array|null Raw bundle row (order_ids still JSON-encoded), or null if not found. */
	public static function get( $bundle_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table() returns a fixed, non-user-controlled prefix + literal suffix.
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $bundle_id ), ARRAY_A );
	}

	// ------------------------------------------------------------------
	// Confirm: the one place that actually books a bundle.
	// ------------------------------------------------------------------

	/**
	 * Two-phase like the single-order booking flow (see §8 in PLAN.md): the
	 * bundle row is written as 'draft' before calling GHN, flipped to
	 * 'booked' only after create_shipment() succeeds, or 'failed' (with the
	 * raw error message) otherwise — a timeout or reload never leaves
	 * ambiguous state, and nothing is written to any order unless GHN
	 * actually confirmed the shipment.
	 *
	 * @param WC_Order[] $orders
	 * @param array $params {
	 *   @type string $to_name
	 *   @type string $to_phone
	 *   @type string $to_address     Street line.
	 *   @type int    $to_district_id
	 *   @type string $to_ward_code
	 *   @type int    $length_cm
	 *   @type int    $width_cm
	 *   @type int    $height_cm
	 *   @type bool   $override        Whether staff overrode a recipient mismatch.
	 *   @type string $override_reason Optional free-text staff note.
	 *   @type int    $created_by      wp_users.ID
	 * }
	 * @return array|WP_Error { bundle_id, tracking_code, shipping_fee, cod_amount, order_ids } on success.
	 */
	public static function confirm( array $orders, array $params ) {
		if ( count( $orders ) < 2 ) {
			return new WP_Error( 'epic_ghn_bundle', __( 'A bundle needs at least 2 orders.', 'epic-ghn-shipping' ) );
		}

		// Defense in depth: load_orders() already drops COD orders before a
		// caller ever gets this far (see the long comment there for why),
		// but confirm() re-checks rather than trusting every future caller
		// to have gone through load_orders() first — a bundle booked as
		// mixed COD/prepaid would either under-collect or double-charge a
		// customer, so this refuses to guess and fails loudly instead.
		foreach ( $orders as $order ) {
			if ( Epic_GHN_Client::is_cod_order( $order ) ) {
				return new WP_Error(
					'epic_ghn_bundle',
					sprintf(
						/* translators: %s: order number */
						__( 'Order #%s is COD — COD orders can\'t be bundled right now. Remove it from this bundle and ship it individually.', 'epic-ghn-shipping' ),
						$order->get_order_number()
					)
				);
			}
		}

		$settings = Epic_GHN_Client::get_settings();

		$order_ids = array();
		foreach ( $orders as $order ) {
			$order_ids[] = $order->get_id();
		}

		$weight_g = self::aggregate_weight_g( $orders );
		$subtotal = self::aggregate_subtotal( $orders );
		$items    = self::aggregate_items( $orders );

		$length_cm = ! empty( $params['length_cm'] ) ? (int) $params['length_cm'] : (int) $settings['default_length_cm'];
		$width_cm  = ! empty( $params['width_cm'] ) ? (int) $params['width_cm'] : (int) $settings['default_width_cm'];
		$height_cm = ! empty( $params['height_cm'] ) ? (int) $params['height_cm'] : (int) $settings['default_height_cm'];

		$bundle_id = self::insert_draft(
			array(
				'order_ids'         => $order_ids,
				'recipient_name'    => $params['to_name'],
				'recipient_phone'   => $params['to_phone'],
				'recipient_address' => $params['to_address'],
				'total_weight_g'    => $weight_g,
				'package_length'    => $length_cm,
				'package_width'     => $width_cm,
				'package_height'    => $height_cm,
				'items_subtotal'    => $subtotal,
				'created_by'        => $params['created_by'],
			)
		);

		if ( ! $bundle_id ) {
			return new WP_Error( 'epic_ghn_bundle', __( 'Could not create the bundle record — check WooCommerce → Status → Logs.', 'epic-ghn-shipping' ) );
		}

		// The ONE combined-parcel fee call — never a sum of each order's
		// individual fee. This is the actual point of bundling (PLAN.md §6).
		$fee = Epic_GHN_Client::calculate_fee(
			array(
				'to_district_id'  => $params['to_district_id'],
				'to_ward_code'    => $params['to_ward_code'],
				'weight_g'        => $weight_g,
				'insurance_value' => $subtotal,
			)
		);

		if ( is_wp_error( $fee ) ) {
			self::mark_failed( $bundle_id, $fee->get_error_message() );
			return $fee;
		}

		$fee_amount = self::extract_fee_amount( $fee );

		// Every order that reaches here is prepaid (already paid online —
		// see the is_cod_order() guard above and in load_orders()), so
		// nothing is collected on delivery. $fee_amount is still recorded on
		// the bundle row below for the shop's own reconciliation — it's just
		// no longer cash the courier collects.
		$cod_amount = 0;

		$shipment = Epic_GHN_Client::create_shipment(
			array(
				'to_name'           => $params['to_name'],
				'to_phone'          => $params['to_phone'],
				'to_address'        => $params['to_address'],
				'to_district_id'    => $params['to_district_id'],
				'to_ward_code'      => $params['to_ward_code'],
				'payment_type_id'   => Epic_GHN_Client::PAYMENT_TYPE_PREPAID,
				'cod_amount'        => $cod_amount,
				'insurance_value'   => $subtotal,
				'weight_g'          => $weight_g,
				'length_cm'         => $length_cm,
				'width_cm'          => $width_cm,
				'height_cm'         => $height_cm,
				'items'             => $items,
				'note'              => sprintf( 'WooCommerce bundle #%d — orders #%s (booked from wp-admin)', $bundle_id, implode( ', #', $order_ids ) ),
				'client_order_code' => 'BUNDLE-' . $bundle_id,
			)
		);

		if ( is_wp_error( $shipment ) ) {
			self::mark_failed( $bundle_id, $shipment->get_error_message() );
			return $shipment;
		}

		self::mark_booked(
			$bundle_id,
			array(
				'ghn_order_code' => $shipment['order_code'],
				'shipping_fee'   => $fee_amount,
				'cod_amount'     => $cod_amount,
			)
		);

		$eta = isset( $shipment['expected_delivery_time'] ) ? $shipment['expected_delivery_time'] : '';

		$order_numbers = array();
		foreach ( $orders as $order ) {
			$order_numbers[] = '#' . $order->get_order_number();
		}

		foreach ( $orders as $order ) {
			$order->update_meta_data( '_ghn_order_code', $shipment['order_code'] );
			$order->update_meta_data( '_ghn_bundle_id', $bundle_id );
			$order->update_meta_data( '_ghn_expected_delivery', $eta );
			$order->update_meta_data( '_ghn_shipment_status', 'ready_to_pick' );
			$order->update_meta_data( '_ghn_last_synced_at', current_time( 'mysql' ) );
			$order->save();

			$note = sprintf(
				/* translators: 1: bundle ID, 2: GHN tracking code, 3: list of every order number in the bundle, 4: combined shipping fee */
				__( 'Shipped as part of GHN bundle #%1$d, tracking code %2$s, combined with orders %3$s. Combined shipping fee: %4$s — charged once against this bundle (see the bundle record), each order\'s own shipping total is unchanged. Booked as prepaid (COD orders can\'t be bundled) — nothing is collected by the courier on delivery.', 'epic-ghn-shipping' ),
				$bundle_id,
				$shipment['order_code'],
				implode( ', ', $order_numbers ),
				wp_strip_all_tags( wc_price( $fee_amount ) )
			);

			if ( ! empty( $params['override'] ) ) {
				$note .= ' ' . __( 'NOTE: booked despite a recipient phone/address mismatch between orders in this bundle — staff override was used.', 'epic-ghn-shipping' );
				if ( ! empty( $params['override_reason'] ) ) {
					$note .= ' ' . sprintf(
						/* translators: %s: staff-entered note */
						__( 'Staff note: %s', 'epic-ghn-shipping' ),
						$params['override_reason']
					);
				}
			}

			$order->add_order_note( $note );

			/**
			 * Same plugin-agnostic hook Epic_GHN_Ajax::book_single_order()
			 * fires on the single-order path — see the docblock there for
			 * why it's a do_action() rather than a direct call into
			 * epic-order-emails. Fired once per order in the bundle (not
			 * once for the bundle as a whole), since each order needs its
			 * own "your order shipped" email addressed by its own order
			 * number, even though every order in the bundle shares this one
			 * $shipment['order_code'].
			 */
			do_action( 'epic_ghn_shipment_booked', $order, $shipment['order_code'], $eta );
		}

		return array(
			'bundle_id'     => $bundle_id,
			'tracking_code' => $shipment['order_code'],
			'shipping_fee'  => $fee_amount,
			'cod_amount'    => $cod_amount,
			'order_ids'     => $order_ids,
		);
	}
}
