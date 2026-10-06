<?php
/**
 * Inbound ViettelPost webhook — keeps each order's shipment status current.
 *
 * Register the route's URL under viettelpost.vn → Cấu hình tài khoản → Cấu
 * hình webhook and set the same SECRET in the plugin settings. ViettelPost
 * sends that secret back in the Authorization header on every callback.
 *
 * Payload shape (verified against the Open API docs, 2026-09-22):
 *   { "DATA": { "ORDER_NUMBER": "...", "ORDER_STATUS": 104,
 *               "ORDER_STATUSDATE": "...", "STATUS_NAME": "...",
 *               "MONEY_COLLECTION": 0, "MONEY_TOTAL": ..., ... },
 *     "TOKEN": "..." }
 *
 * ViettelPost retries failed callbacks, so the handler must be idempotent
 * (safe to process the same ORDER_NUMBER+status twice) and always return
 * HTTP 200 once processed, even when the update is a no-op.
 *
 * @package Epic_ViettelPost_Shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_VTP_Webhook {

	const NAMESPACE = 'epic-vtp/v1';

	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true', // Authenticated by the shared secret below.
			)
		);
	}

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle( WP_REST_Request $request ) {
		$settings = Epic_VTP_Client::get_settings();
		$secret   = $settings['webhook_secret'];

		if ( '' !== $secret ) {
			$provided = (string) $request->get_header( 'authorization' );
			if ( '' === $provided ) {
				$provided = (string) $request->get_header( 'secret' );
			}
			// Constant-time comparison; also accept a bare value (some
			// accounts send the raw secret, others "Bearer <secret>").
			$provided = preg_replace( '/^Bearer\s+/i', '', $provided );
			if ( ! hash_equals( $secret, $provided ) ) {
				return new WP_REST_Response( array( 'success' => false, 'message' => 'Invalid secret.' ), 401 );
			}
		}

		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = $request->get_params();
		}

		$data = isset( $payload['DATA'] ) && is_array( $payload['DATA'] ) ? $payload['DATA'] : $payload;

		$order_number = isset( $data['ORDER_NUMBER'] ) ? (string) $data['ORDER_NUMBER'] : '';
		$status       = isset( $data['ORDER_STATUS'] ) ? (string) $data['ORDER_STATUS'] : '';

		if ( '' === $order_number ) {
			// Nothing actionable, but acknowledge so ViettelPost stops retrying.
			return new WP_REST_Response( array( 'success' => true, 'message' => 'No order number.' ), 200 );
		}

		$order = self::find_order_by_waybill( $order_number );
		if ( ! $order instanceof WC_Order ) {
			// Unknown waybill — acknowledge, don't retry forever.
			return new WP_REST_Response( array( 'success' => true, 'message' => 'Order not found.' ), 200 );
		}

		$previous      = (string) $order->get_meta( Epic_VTP_Order_Meta_Box::META_STATUS );
		$incoming_date = isset( $data['ORDER_STATUSDATE'] ) ? sanitize_text_field( (string) $data['ORDER_STATUSDATE'] ) : '';
		$stored_date   = (string) $order->get_meta( Epic_VTP_Order_Meta_Box::META_STATUS_DATE );

		// Out-of-order guard: ViettelPost retries and can deliver callbacks out
		// of sequence. If this update is older than the last one applied, ack
		// it (so retries stop) without regressing the shipment status.
		if ( '' !== $incoming_date && '' !== $stored_date ) {
			$incoming_ts = strtotime( $incoming_date );
			$stored_ts   = strtotime( $stored_date );
			if ( false !== $incoming_ts && false !== $stored_ts && $incoming_ts < $stored_ts ) {
				return new WP_REST_Response( array( 'success' => true, 'message' => 'Stale update ignored.' ), 200 );
			}
		}

		$order->update_meta_data( Epic_VTP_Order_Meta_Box::META_STATUS, $status );
		$order->update_meta_data( Epic_VTP_Order_Meta_Box::META_LAST_SYNCED, current_time( 'mysql' ) );
		if ( '' !== $incoming_date ) {
			$order->update_meta_data( Epic_VTP_Order_Meta_Box::META_STATUS_DATE, $incoming_date );
		}

		if ( ! empty( $data['EXPECTED_DELIVERY'] ) && is_string( $data['EXPECTED_DELIVERY'] ) ) {
			$order->update_meta_data( Epic_VTP_Order_Meta_Box::META_EXPECTED, sanitize_text_field( $data['EXPECTED_DELIVERY'] ) );
		}
		if ( isset( $data['MONEY_TOTALFEE'] ) ) {
			$order->update_meta_data( Epic_VTP_Order_Meta_Box::META_FEE, (string) (int) $data['MONEY_TOTALFEE'] );
		}

		// Persist the itemized cost structure ViettelPost reports on every
		// status event (main freight, fuel surcharge, VAT, total, COD fee +
		// collected amount, weight, service, payment type). The dashboard's
		// shipment detail renders this; it is the authoritative breakdown and
		// avoids a re-query (VTP exposes no order-detail API).
		$breakdown = Epic_VTP_Client::parse_cost_breakdown( $data );
		if ( $breakdown ) {
			$order->update_meta_data( Epic_VTP_Order_Meta_Box::META_COST_BREAKDOWN, $breakdown );
		}

		// Append a structured journey event (idempotent: deduped by
		// status+date+note) so the dashboard timeline survives even without
		// the per-status order notes.
		self::append_tracking_event(
			$order,
			$status,
			$incoming_date,
			isset( $data['NOTE'] ) ? sanitize_text_field( (string) $data['NOTE'] ) : ''
		);

		// Only note a genuine change so a repeated callback (ViettelPost
		// retries) doesn't spam the order timeline.
		if ( $status !== $previous ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: status label, 2: raw status code, 3: status timestamp, 4: note from ViettelPost */
					__( 'ViettelPost status update: %1$s (%2$s)%3$s%4$s', 'epic-viettelpost-shipping' ),
					Epic_VTP_Client::status_label( $status ),
					$status,
					'' !== $incoming_date ? ' — ' . $incoming_date : '',
					! empty( $data['NOTE'] ) ? ' — ' . sanitize_text_field( $data['NOTE'] ) : ''
				)
			);
		}

		// Return / delivery-issue workflow: flag the order so staff know to
		// restock and (if needed) refund; optionally hold it.
		if ( Epic_VTP_Client::is_return_or_issue( $status ) ) {
			$order->update_meta_data( Epic_VTP_Order_Meta_Box::META_NEEDS_ACTION, 'return/issue' );
			if ( 'yes' === $settings['return_hold'] && ! $order->has_status( array( 'on-hold', 'cancelled', 'refunded' ) ) ) {
				$order->update_status( 'on-hold', __( 'ViettelPost: return/issue — held for restock/refund.', 'epic-viettelpost-shipping' ) );
			} else {
				$order->add_order_note( __( 'ViettelPost return/issue — check restock and refund.', 'epic-viettelpost-shipping' ) );
			}
		}

		$order->save();

		/**
		 * Fires on every applied status change (webhook or manual override).
		 * Lets other plugins react — e.g. epic-order-emails sends a "delivered"
		 * email — without depending on this plugin's internals.
		 *
		 * @param WC_Order $order
		 * @param string   $status
		 * @param string   $source 'webhook' | 'manual'.
		 */
		do_action( 'epic_vtp_status_changed', $order, $status, 'webhook' );

		// Complete-on-delivery: the setting (or the legacy filter) opts in.
		$auto_complete = 'yes' === $settings['auto_complete'] || apply_filters( 'epic_vtp_auto_complete_on_delivered', false, $order, $status );
		if ( $auto_complete && '501' === $status && ! $order->has_status( 'completed' ) ) {
			$order->update_status( 'completed', __( 'ViettelPost: delivered.', 'epic-viettelpost-shipping' ) );
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Finds the WooCommerce order whose stored ViettelPost waybill matches.
	 *
	 * The lookup is storage-aware: `meta_query` is only supported by the HPOS
	 * (custom order tables) datastore — on the legacy post-based store
	 * WooCommerce 9.2+ logs "Order query argument (meta_query) is not
	 * supported on the current order datastore" and may stop honoring it, so
	 * the legacy branch queries the order postmeta directly instead.
	 */
	private static function find_order_by_waybill( $waybill ) {
		$order_util = '\Automattic\WooCommerce\Utilities\OrderUtil';
		$hpos       = class_exists( $order_util )
			&& method_exists( $order_util, 'custom_orders_table_usage_is_enabled' )
			&& $order_util::custom_orders_table_usage_is_enabled();

		if ( $hpos ) {
			$orders = wc_get_orders(
				array(
					'limit'      => 1,
					'return'     => 'objects',
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- HPOS-only lookup by waybill; infrequent and indexed enough at this store's volume.
						array(
							'key'   => Epic_VTP_Order_Meta_Box::META_ORDER_NUMBER,
							'value' => $waybill,
						),
					),
				)
			);

			return ! empty( $orders ) ? $orders[0] : false;
		}

		// Legacy (wp_posts) order storage: a direct postmeta lookup, which
		// avoids the unsupported-meta_query warning and is not subject to its
		// future removal.
		$post_ids = get_posts(
			array(
				'post_type'      => 'shop_order',
				'post_status'    => array_keys( wc_get_order_statuses() ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => Epic_VTP_Order_Meta_Box::META_ORDER_NUMBER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- webhook lookup by waybill.
				'meta_value'     => $waybill, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return ! empty( $post_ids ) ? wc_get_order( $post_ids[0] ) : false;
	}

	/**
	 * Appends one {status,date,note} journey event to the order's structured
	 * history (oldest first; the dashboard reverses it). ViettelPost retries
	 * callbacks, so identical events are dropped.
	 */
	private static function append_tracking_event( $order, $status, $date, $note ) {
		if ( '' === (string) $status ) {
			return;
		}

		$stored = $order->get_meta( Epic_VTP_Order_Meta_Box::META_TRACKING_HISTORY );
		$events = is_array( $stored ) ? $stored : array();

		$event = array(
			'status' => (string) $status,
			'date'   => (string) $date,
			'note'   => (string) $note,
		);

		foreach ( $events as $existing ) {
			if ( ! is_array( $existing ) ) {
				continue;
			}
			if ( (string) ( $existing['status'] ?? '' ) === $event['status']
				&& (string) ( $existing['date'] ?? '' ) === $event['date']
				&& (string) ( $existing['note'] ?? '' ) === $event['note'] ) {
				return;
			}
		}

		$events[] = $event;
		if ( count( $events ) > 200 ) {
			$events = array_slice( $events, -200 );
		}

		$order->update_meta_data( Epic_VTP_Order_Meta_Box::META_TRACKING_HISTORY, $events );
	}
}
