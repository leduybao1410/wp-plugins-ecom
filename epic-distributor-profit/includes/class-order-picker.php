<?php
/**
 * AJAX search for the "Import from Order" picker on the Distributor Profit add-entry form.
 *
 * @package Epic_Distributor_Profit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Distributor_Profit_Order_Picker {

	public static function init() {
		add_action( 'wp_ajax_epic_dp_search_orders', array( __CLASS__, 'search_orders' ) );
	}

	public static function search_orders() {
		check_ajax_referer( 'epic_dp_search_orders', 'nonce' );

		if ( ! current_user_can( EPIC_DISTRIBUTOR_PROFIT_CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'epic-distributor-profit' ) ), 403 );
		}

		$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
		if ( '' === $term ) {
			wp_send_json_success( array() );
		}

		$results  = array();
		$seen_ids = array();

		$add_order = function ( $order ) use ( &$results, &$seen_ids ) {
			if ( ! $order || isset( $seen_ids[ $order->get_id() ] ) ) {
				return;
			}
			$seen_ids[ $order->get_id() ] = true;
			$results[]                    = self::format_order( $order );
		};

		// A numeric term is almost always meant as a raw order ID — try that first.
		if ( ctype_digit( $term ) ) {
			$add_order( wc_get_order( (int) $term ) );
		}

		// This site's "EPIC Order Codes" plugin displays a computed code (e.g. "EPIC-7K3M9X")
		// instead of the numeric order ID — nothing stores that code anywhere, it's derived from
		// the ID via a reversible cipher, so WooCommerce's own order search can never match it
		// (that plugin only rewrites WordPress's built-in admin order-list search, which this AJAX
		// endpoint doesn't go through). Decode it back to the real order ID ourselves first.
		if ( class_exists( 'Epic_Order_Code' ) && is_callable( array( 'Epic_Order_Code', 'decode' ) ) ) {
			$decoded_id = Epic_Order_Code::decode( $term );
			if ( $decoded_id ) {
				$add_order( wc_get_order( $decoded_id ) );
			}
		}

		// Also run WooCommerce's own order search (matches billing name/email/phone/order key,
		// etc.) for anything else, capped to a handful of results.
		if ( function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders(
				array(
					's'       => $term,
					'limit'   => 10,
					'orderby' => 'date',
					'order'   => 'DESC',
				)
			);
			foreach ( $orders as $order ) {
				$add_order( $order );
			}
		}

		wp_send_json_success( array_slice( $results, 0, 10 ) );
	}

	private static function format_order( $order ) {
		$products  = array();
		$quantity  = 0;
		foreach ( $order->get_items() as $item ) {
			$products[] = $item->get_name();
			$quantity  += (int) $item->get_quantity();
		}

		return array(
			'id'             => $order->get_id(),
			'number'         => $order->get_order_number(),
			'date'           => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : '',
			'status'         => wc_get_order_status_name( $order->get_status() ),
			'total'          => wc_format_decimal( $order->get_total(), 2 ),
			'shipping_total' => wc_format_decimal( $order->get_shipping_total(), 2 ),
			'product_name'   => implode( ', ', $products ),
			'quantity'       => $quantity,
		);
	}
}
