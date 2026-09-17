<?php
/**
 * Posts a Discord message when a new order is placed.
 *
 * Hooked to the same two status transitions epic-order-emails/
 * class-email-order-created.php uses for its "order received" customer
 * email: `pending -> processing` and `pending -> on-hold`. Both are exactly
 * what happens the moment the Next.js checkout creates the order via the
 * WooCommerce REST API (src/lib/woocommerce.ts createOrder() defaults
 * status to "processing"; on-hold is what epic-ghn-shipping's website-side
 * flagging uses when GHN booking fails at checkout — see
 * website/src/lib/woocommerce.ts flagOrderForManualShipping()). A brand-new
 * WC_Order's implicit starting status is `pending`, so setting it straight
 * to either of those at creation IS a transition as far as
 * WC_Order::status_transition() is concerned — this also covers a manually
 * placed wp-admin order, or any future sales channel, without extra code.
 *
 * Deliberately using the plain `woocommerce_order_status_{from}_to_{to}`
 * hooks, not the `_notification` suffixed variants epic-order-emails hooks
 * for its WC_Email subclasses — both fire on every transition regardless of
 * any email being enabled, but the plain name doesn't imply a dependency on
 * WooCommerce's email system for a plugin that isn't sending email.
 *
 * @package Epic_Discord_Notify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Discord_Notifier {

	public static function init() {
		add_action( 'woocommerce_order_status_pending_to_processing', array( __CLASS__, 'handle_new_order' ) );
		add_action( 'woocommerce_order_status_pending_to_on-hold', array( __CLASS__, 'handle_new_order' ) );
	}

	/**
	 * @param int $order_id
	 */
	public static function handle_new_order( $order_id ) {
		if ( 'yes' !== get_option( 'epic_discord_enabled', 'no' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		// Duplicate guard: pending->processing and pending->on-hold can never
		// both fire for the same order (a status can only transition away
		// from `pending` once), but a meta flag protects against any future
		// retrigger (e.g. a manual re-save) sending a second message.
		if ( 'yes' === $order->get_meta( '_epic_discord_notified' ) ) {
			return;
		}

		$webhook_url = trim( (string) get_option( 'epic_discord_webhook_url', '' ) );
		if ( '' === $webhook_url ) {
			wc_get_logger()->info(
				sprintf( 'Order #%s: Discord notification skipped — no webhook URL configured under WooCommerce > Settings > Discord Notify.', $order->get_order_number() ),
				array( 'source' => 'epic-discord-notify' )
			);
			return;
		}

		$payload = self::build_order_payload( $order );
		$result  = self::send( $webhook_url, $payload );

		if ( is_wp_error( $result ) ) {
			wc_get_logger()->error(
				sprintf( 'Order #%s: Discord webhook request failed — %s', $order->get_order_number(), $result->get_error_message() ),
				array( 'source' => 'epic-discord-notify' )
			);
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $result );
		if ( $code < 200 || $code >= 300 ) {
			wc_get_logger()->error(
				sprintf( 'Order #%s: Discord webhook returned HTTP %d — %s', $order->get_order_number(), $code, wp_remote_retrieve_body( $result ) ),
				array( 'source' => 'epic-discord-notify' )
			);
			return;
		}

		$order->update_meta_data( '_epic_discord_notified', 'yes' );
		$order->save();
	}

	/**
	 * Shared base payload (username/avatar override) used by both a real
	 * order notification and the settings screen's test message, so the two
	 * never drift out of sync on how those two options are applied.
	 */
	public static function base_payload() {
		$payload = array();

		$username = trim( (string) get_option( 'epic_discord_bot_username', 'EPIC Orders' ) );
		if ( '' !== $username ) {
			$payload['username'] = $username;
		}

		$avatar_url = trim( (string) get_option( 'epic_discord_avatar_url', '' ) );
		if ( '' !== $avatar_url ) {
			$payload['avatar_url'] = $avatar_url;
		}

		return $payload;
	}

	/**
	 * Builds the Discord webhook JSON payload (one rich embed) for a given
	 * order.
	 *
	 * @param WC_Order $order
	 * @return array
	 */
	public static function build_order_payload( $order ) {
		$fields = array();

		$fields[] = array(
			'name'   => __( 'Customer', 'epic-discord-notify' ),
			'value'  => self::customer_name( $order ),
			'inline' => true,
		);

		$fields[] = array(
			'name'   => __( 'Total', 'epic-discord-notify' ),
			'value'  => wp_strip_all_tags( $order->get_formatted_order_total() ),
			'inline' => true,
		);

		$payment_title = $order->get_payment_method_title();
		if ( '' !== $payment_title ) {
			$fields[] = array(
				'name'   => __( 'Payment', 'epic-discord-notify' ),
				'value'  => $payment_title,
				'inline' => true,
			);
		}

		if ( 'yes' === get_option( 'epic_discord_include_phone', 'yes' ) ) {
			$phone = $order->get_billing_phone();
			if ( '' !== $phone ) {
				$fields[] = array(
					'name'   => __( 'Phone', 'epic-discord-notify' ),
					'value'  => $phone,
					'inline' => true,
				);
			}
		}

		if ( 'yes' === get_option( 'epic_discord_include_address', 'yes' ) ) {
			$address = self::shipping_address( $order );
			if ( '' !== $address ) {
				$fields[] = array(
					'name'   => __( 'Delivery address', 'epic-discord-notify' ),
					'value'  => $address,
					'inline' => false,
				);
			}
		}

		$items = self::items_summary( $order );
		if ( '' !== $items ) {
			$fields[] = array(
				'name'   => __( 'Items', 'epic-discord-notify' ),
				'value'  => $items,
				'inline' => false,
			);
		}

		$embed = array(
			'title'     => sprintf(
				/* translators: %s: order number */
				__( '🛒 New order #%s', 'epic-discord-notify' ),
				$order->get_order_number()
			),
			'url'       => $order->get_edit_order_url(),
			'color'     => 5793266, // Discord "blurple".
			'fields'    => $fields,
			'timestamp' => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : gmdate( 'c' ),
		);

		$payload           = self::base_payload();
		$payload['embeds'] = array( $embed );

		return apply_filters( 'epic_discord_notify_order_payload', $payload, $order );
	}

	private static function customer_name( $order ) {
		$name = trim( $order->get_formatted_billing_full_name() );
		if ( '' === $name ) {
			$name = __( 'Guest', 'epic-discord-notify' );
		}
		return $name;
	}

	private static function shipping_address( $order ) {
		$address = $order->has_shipping_address() ? $order->get_formatted_shipping_address() : $order->get_formatted_billing_address();
		if ( ! $address ) {
			return '';
		}
		return wp_strip_all_tags( str_replace( '<br/>', "\n", $address ) );
	}

	/**
	 * "• Name × qty" lines, one per order item, capped to stay well under
	 * Discord's 1024-character embed field value limit.
	 */
	private static function items_summary( $order ) {
		$lines = array();

		foreach ( $order->get_items() as $item ) {
			/** @var WC_Order_Item_Product $item */
			$lines[] = sprintf( '• %s × %d', $item->get_name(), $item->get_quantity() );
		}

		$summary = implode( "\n", $lines );

		if ( strlen( $summary ) > 1000 ) {
			$summary = substr( $summary, 0, 1000 ) . "\n…";
		}

		return $summary;
	}

	/**
	 * Posts a JSON payload to a Discord webhook URL. Shared by the real
	 * order notification and the settings screen's "send test message"
	 * (Epic_Discord_Ajax), so both go through the exact same HTTP call.
	 *
	 * @param string $webhook_url
	 * @param array  $payload
	 * @return array|WP_Error
	 */
	public static function send( $webhook_url, $payload ) {
		return wp_remote_post(
			$webhook_url,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 10,
			)
		);
	}
}
