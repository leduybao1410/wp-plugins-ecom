<?php
/**
 * AJAX handler behind the "Send test message" button on the settings
 * screen (WooCommerce → Settings → Discord Notify). Lets staff confirm the
 * saved webhook URL actually reaches the Discord channel without having to
 * place a real test order.
 *
 * @package Epic_Discord_Notify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Discord_Ajax {

	public static function init() {
		add_action( 'wp_ajax_epic_discord_test_webhook', array( __CLASS__, 'test_webhook' ) );
	}

	public static function test_webhook() {
		check_ajax_referer( 'epic_discord_test_webhook', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'epic-discord-notify' ) ) );
		}

		$webhook_url = trim( (string) get_option( 'epic_discord_webhook_url', '' ) );
		if ( '' === $webhook_url ) {
			wp_send_json_error( array( 'message' => __( 'No webhook URL saved yet — enter one above, click Save changes, then try again.', 'epic-discord-notify' ) ) );
		}

		$payload           = Epic_Discord_Notifier::base_payload();
		$payload['embeds'] = array(
			array(
				'title'       => __( '✅ Test message from EPIC Discord Order Notifications', 'epic-discord-notify' ),
				'description' => __( 'If you can see this in Discord, the webhook is working. Real order notifications will look like this, with the order\'s details in place of this text.', 'epic-discord-notify' ),
				'color'       => 5793266,
				'timestamp'   => gmdate( 'c' ),
			),
		);

		$result = Epic_Discord_Notifier::send( $webhook_url, $payload );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $result );
		if ( $code < 200 || $code >= 300 ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: 1: HTTP status code 2: response body */
						__( 'Discord responded with HTTP %1$d: %2$s', 'epic-discord-notify' ),
						$code,
						wp_remote_retrieve_body( $result )
					),
				)
			);
		}

		wp_send_json_success( array( 'message' => __( 'Sent — check your Discord channel.', 'epic-discord-notify' ) ) );
	}
}
