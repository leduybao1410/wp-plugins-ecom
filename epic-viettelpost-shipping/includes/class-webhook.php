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

		$previous = (string) $order->get_meta( Epic_VTP_Order_Meta_Box::META_STATUS );

		$order->update_meta_data( Epic_VTP_Order_Meta_Box::META_STATUS, $status );
		$order->update_meta_data( Epic_VTP_Order_Meta_Box::META_LAST_SYNCED, current_time( 'mysql' ) );

		if ( ! empty( $data['EXPECTED_DELIVERY'] ) && is_string( $data['EXPECTED_DELIVERY'] ) ) {
			$order->update_meta_data( Epic_VTP_Order_Meta_Box::META_EXPECTED, sanitize_text_field( $data['EXPECTED_DELIVERY'] ) );
		}
		if ( isset( $data['MONEY_TOTALFEE'] ) ) {
			$order->update_meta_data( Epic_VTP_Order_Meta_Box::META_FEE, (string) (int) $data['MONEY_TOTALFEE'] );
		}

		// Only note a genuine change so a repeated callback (ViettelPost
		// retries) doesn't spam the order timeline.
		if ( $status !== $previous ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: status label, 2: raw status code, 3: status timestamp, 4: note from ViettelPost */
					__( 'ViettelPost status update: %1$s (%2$s)%3$s%4$s', 'epic-viettelpost-shipping' ),
					Epic_VTP_Client::status_label( $status ),
					$status,
					! empty( $data['ORDER_STATUSDATE'] ) ? ' — ' . sanitize_text_field( $data['ORDER_STATUSDATE'] ) : '',
					! empty( $data['NOTE'] ) ? ' — ' . sanitize_text_field( $data['NOTE'] ) : ''
				)
			);
		}

		$order->save();

		/**
		 * Lets a site opt into transitioning the WooCommerce order on terminal
		 * ViettelPost statuses. Off by default — completing an order is a
		 * business decision the store owner should make explicitly.
		 *
		 * @param bool     $should_transition
		 * @param WC_Order $order
		 * @param string   $status
		 */
		if ( apply_filters( 'epic_vtp_auto_complete_on_delivered', false, $order, $status ) ) {
			if ( '501' === $status && ! $order->has_status( 'completed' ) ) {
				$order->update_status( 'completed', __( 'ViettelPost: delivered.', 'epic-viettelpost-shipping' ) );
			}
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Finds the WooCommerce order whose stored ViettelPost waybill matches.
	 */
	private static function find_order_by_waybill( $waybill ) {
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'return'     => 'objects',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- webhook lookup by waybill; infrequent and indexed enough at this store's volume.
					array(
						'key'   => Epic_VTP_Order_Meta_Box::META_ORDER_NUMBER,
						'value' => $waybill,
					),
				),
			)
		);

		return ! empty( $orders ) ? $orders[0] : false;
	}
}
