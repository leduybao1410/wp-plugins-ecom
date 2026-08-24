<?php
/**
 * The Zalo OAuth v4 client. This is the part of the plugin that talks to Zalo
 * itself — there are exactly three calls:
 *
 *  1. build_authorize_url()  → redirect the user to Zalo's consent screen.
 *  2. exchange_code()        → swap the one-time `code` for an access token.
 *  3. get_user_profile()     → fetch the user's Zalo profile with that token.
 *
 * Zalo v4 uses PKCE: each login generates a random `code_verifier` (stored in
 * a transient keyed by the login `state`), sends only its SHA-256 hash as the
 * `code_challenge` during the authorize step, then presents the verifier again
 * when exchanging the code. See docs.zaloplatforms.com → User Access Token V4.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Zalo_Oauth {

	const AUTHORIZE_ENDPOINT = 'https://oauth.zaloapp.com/v4/permission';
	const TOKEN_ENDPOINT     = 'https://oauth.zaloapp.com/v4/access_token';
	const USERINFO_ENDPOINT  = 'https://graph.zalo.me/v2.0/me';

	const VERIFIER_TRANSIENT = 'epic_zalo_verifier_';
	const VERIFIER_TTL       = 10 * MINUTE_IN_SECONDS;

	/**
	 * Zalo requires a 43-character verifier of mixed-case letters and digits.
	 */
	private static function generate_code_verifier() {
		$chars  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
		$max    = strlen( $chars ) - 1;
		$result = '';
		for ( $i = 0; $i < 43; $i++ ) {
			$result .= $chars[ random_int( 0, $max ) ];
		}
		return $result;
	}

	/**
	 * code_challenge = Base64URL(SHA-256(ASCII(code_verifier))) — URL-safe
	 * base64 without padding, per RFC 7636.
	 */
	private static function code_challenge( $verifier ) {
		return rtrim(
			strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ),
			'='
		);
	}

	/**
	 * Builds the URL that sends the user to Zalo's consent screen. The
	 * verifier is stashed server-side under the same `state` so the callback
	 * can recover it; never expose it to the browser.
	 *
	 * @param string $state Anti-CSRF token, echoed back verbatim by Zalo.
	 */
	public static function build_authorize_url( $state ) {
		$verifier = self::generate_code_verifier();
		set_transient( self::VERIFIER_TRANSIENT . $state, $verifier, self::VERIFIER_TTL );

		return add_query_arg(
			array(
				'app_id'         => Epic_Zalo_Settings::get_app_id(),
				'redirect_uri'   => Epic_Zalo_Settings::get_callback_url(),
				'code_challenge' => self::code_challenge( $verifier ),
				'state'          => $state,
			),
			self::AUTHORIZE_ENDPOINT
		);
	}

	/**
	 * Exchanges the one-time authorization code for an access token. The code
	 * expires after 10 minutes and can only be used once.
	 *
	 * @param string $code  The `code` Zalo appended to the callback URL.
	 * @param string $state The `state` from the callback, used to find the verifier.
	 * @return array|WP_Error Token payload on success, WP_Error otherwise.
	 */
	public static function exchange_code( $code, $state ) {
		$verifier = get_transient( self::VERIFIER_TRANSIENT . $state );
		if ( ! $verifier ) {
			return new WP_Error(
				'epic_zalo_no_verifier',
				__( 'The login session has expired or the state does not match. Please try again.', 'epic-zalo-login' )
			);
		}
		delete_transient( self::VERIFIER_TRANSIENT . $state );

		$response = wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
					'secret_key'   => Epic_Zalo_Settings::get_secret_key(),
				),
				'body'    => array(
					'code'          => $code,
					'app_id'        => Epic_Zalo_Settings::get_app_id(),
					'grant_type'    => 'authorization_code',
					'code_verifier' => $verifier,
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['access_token'] ) ) {
			$error = isset( $data['error_description'] ) ? $data['error_description'] : __( 'Zalo returned no access token.', 'epic-zalo-login' );
			return new WP_Error( 'epic_zalo_token_error', $error );
		}

		return $data;
	}

	/**
	 * Fetches the logged-in user's Zalo profile (id, name, avatar). The `id`
	 * is the stable key we use to map Zalo accounts to WordPress users.
	 *
	 * @param string $access_token Token from exchange_code().
	 * @return array|WP_Error Profile fields on success, WP_Error otherwise.
	 */
	public static function get_user_profile( $access_token ) {
		$response = wp_remote_get(
			add_query_arg( array( 'fields' => 'id,name,picture' ), self::USERINFO_ENDPOINT ),
			array(
				'headers' => array(
					'access_token' => $access_token,
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			$data = $data['data'];
		}
		if ( empty( $data['id'] ) ) {
			$error = isset( $data['error_description'] ) ? $data['error_description'] : __( 'Zalo could not identify the user.', 'epic-zalo-login' );
			return new WP_Error( 'epic_zalo_profile_error', $error );
		}

		return $data;
	}
}
