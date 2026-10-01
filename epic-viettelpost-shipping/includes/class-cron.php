<?php
/**
 * Stale-shipment safety net.
 *
 * ViettelPost exposes no status-query API — the webhook is the only inbound
 * channel — so a missed callback would otherwise leave a shipment frozen at
 * its last-known status forever. This daily scan flags any in-flight shipment
 * whose last webhook update is older than the configured number of days, and
 * the Shipments dashboard surfaces those for a manual status check.
 *
 * @package Epic_ViettelPost_Shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_VTP_Cron {

	const HOOK     = 'epic_vtp_stale_scan';
	const STALE_META = '_vtp_stale';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'scan' ) );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Flags in-flight shipments with no webhook update within the stale window.
	 * Terminal shipments (delivered/returned/cancelled) are ignored, and a
	 * shipment that has since updated is unflagged.
	 */
	public static function scan() {
		$settings = Epic_VTP_Client::get_settings();
		$days     = max( 1, (int) $settings['stale_days'] );
		$cutoff   = time() - ( $days * DAY_IN_SECONDS );

		$result = Epic_VTP_Order_Query::booked( array(), 200, 1 );

		foreach ( $result['orders'] as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$status = (string) $order->get_meta( Epic_VTP_Order_Meta_Box::META_STATUS );
			$synced = strtotime( (string) $order->get_meta( Epic_VTP_Order_Meta_Box::META_LAST_SYNCED ) );

			$is_stale = ! in_array( $status, Epic_VTP_Order_Query::TERMINAL_STATUSES, true )
				&& ( false === $synced || $synced < $cutoff );

			$currently = (string) $order->get_meta( self::STALE_META );

			if ( $is_stale && '1' !== $currently ) {
				$order->update_meta_data( self::STALE_META, '1' );
				$order->save();
			} elseif ( ! $is_stale && '' !== $currently ) {
				$order->delete_meta_data( self::STALE_META );
				$order->save();
			}
		}
	}
}
