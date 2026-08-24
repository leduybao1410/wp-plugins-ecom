<?php
/**
 * The front-facing half of the plugin: renders the "Sign in with Zalo" button
 * ([epic_zalo_login] shortcode), starts the OAuth handshake, and — on the
 * callback — turns a successful Zalo login into a WordPress login.
 *
 * User mapping: the Zalo user's numeric `id` is stored as user meta
 * `epic_zalo_id`. On every login we look that meta up first, so the same Zalo
 * account always lands on the same WordPress user (no duplicate accounts).
 * New Zalo users are auto-created unless the site owner disabled that in
 * settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Zalo_Login {

	const ZALO_ID_META = 'epic_zalo_id';
	const ZALO_AVATAR_META = 'epic_zalo_avatar';

	/**
	 * [epic_zalo_login] — a "Sign in with Zalo" button. Logged-in visitors see
	 * a one-line "you are logged in" notice instead.
	 */
	public static function render_button() {
		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			$logout = wp_logout_url( home_url( '/' ) );
			return sprintf(
				'<p class="epic-zalo-login-status">%s <a href="%s">%s</a></p>',
				esc_html( $user->display_name ),
				esc_url( $logout ),
				esc_html__( 'Log out', 'epic-zalo-login' )
			);
		}

		if ( ! Epic_Zalo_Settings::is_configured() ) {
			return '<p class="epic-zalo-login-error">' . esc_html__( 'Zalo Login is not configured yet.', 'epic-zalo-login' ) . '</p>';
		}

		$login_url = wp_nonce_url(
			add_query_arg( 'action', 'epic_zalo_login', admin_url( 'admin-post.php' ) ),
			'epic_zalo_login'
		);

		return sprintf(
			'<a href="%s" class="epic-zalo-login-button">%s %s</a>',
			esc_url( $login_url ),
			self::zalo_icon(),
			esc_html__( 'Sign in with Zalo', 'epic-zalo-login' )
		);
	}

	/**
	 * `admin_post_nopriv_epic_zalo_login` — the button's target. Verifies the
	 * nonce, then bounces the user to Zalo's consent screen.
	 */
	public static function start_login() {
		check_admin_referer( 'epic_zalo_login' );

		$state = wp_create_nonce( 'epic_zalo_login' );
		wp_redirect( Epic_Zalo_Oauth::build_authorize_url( $state ) );
		exit;
	}

	/**
	 * `admin_post_nopriv_epic_zalo_callback` — Zalo redirects the browser back
	 * here after the user approves. Exchanges the code, fetches the profile,
	 * maps it to a WordPress user, logs them in, and sends them home.
	 */
	public static function handle_callback() {
		if ( ! isset( $_GET['code'], $_GET['state'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the `state` nonce is verified just below.
			wp_die( esc_html__( 'Zalo did not return an authorization code.', 'epic-zalo-login' ) );
		}

		$state = sanitize_text_field( wp_unslash( $_GET['state'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.
		if ( ! wp_verify_nonce( $state, 'epic_zalo_login' ) ) {
			wp_die( esc_html__( 'Invalid login state. Please try again.', 'epic-zalo-login' ) );
		}

		$code   = sanitize_text_field( wp_unslash( $_GET['code'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above.
		$tokens = Epic_Zalo_Oauth::exchange_code( $code, $state );
		if ( is_wp_error( $tokens ) ) {
			wp_die( esc_html( $tokens->get_error_message() ) );
		}

		$profile = Epic_Zalo_Oauth::get_user_profile( $tokens['access_token'] );
		if ( is_wp_error( $profile ) ) {
			wp_die( esc_html( $profile->get_error_message() ) );
		}

		$user = self::find_or_create_user( $profile );
		if ( is_wp_error( $user ) ) {
			wp_die( esc_html( $user->get_error_message() ) );
		}

		wp_set_current_user( $user->ID, $user->user_login );
		wp_set_auth_cookie( $user->ID );
		do_action( 'wp_login', $user->user_login, $user );

		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	/**
	 * Finds the WordPress user linked to this Zalo ID, or creates one (when
	 * auto-create is enabled). Zalo's `id` is the stable key; `name` becomes
	 * the display name and `picture` is stored as the avatar URL.
	 *
	 * @param array $profile Profile from get_user_profile().
	 * @return WP_User|WP_Error
	 */
	private static function find_or_create_user( $profile ) {
		$query = new WP_User_Query(
			array(
				'meta_key'   => self::ZALO_ID_META,
				'meta_value' => (string) $profile['id'],
				'number'     => 1,
				'fields'     => 'ID',
			)
		);

		if ( $query->get_total() > 0 ) {
			$user_id = (int) $query->get_results()[0];
			self::maybe_update_avatar( $user_id, $profile );
			return get_user_by( 'id', $user_id );
		}

		if ( ! Epic_Zalo_Settings::get_auto_create_users() ) {
			return new WP_Error(
				'epic_zalo_not_linked',
				__( 'No account is linked to this Zalo account. Please contact the site administrator.', 'epic-zalo-login' )
			);
		}

		$username = 'zalo_' . $profile['id'];
		if ( username_exists( $username ) ) {
			$username .= '_' . wp_rand( 1000, 9999 );
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_pass'    => wp_generate_password(),
				'display_name' => ! empty( $profile['name'] ) ? sanitize_text_field( $profile['name'] ) : $username,
				'user_email'   => ! empty( $profile['email'] ) ? sanitize_email( $profile['email'] ) : '',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		update_user_meta( $user_id, self::ZALO_ID_META, (string) $profile['id'] );
		$avatar = self::normalize_avatar( $profile );
		if ( $avatar ) {
			update_user_meta( $user_id, self::ZALO_AVATAR_META, $avatar );
		}

		return get_user_by( 'id', $user_id );
	}

	/**
	 * Refreshes the stored avatar URL on repeat logins (cheap, and avoids
	 * stale pictures) — existing links are otherwise left untouched.
	 */
	private static function maybe_update_avatar( $user_id, $profile ) {
		$avatar = self::normalize_avatar( $profile );
		if ( ! $avatar ) {
			return;
		}
		$current = get_user_meta( $user_id, self::ZALO_AVATAR_META, true );
		if ( $current !== $avatar ) {
			update_user_meta( $user_id, self::ZALO_AVATAR_META, $avatar );
		}
	}

	/**
	 * Zalo's `picture` can come back either as a plain URL string or as a
	 * nested `{ "data": { "url": ... } }` object depending on the scope/edge
	 * — normalize both to a plain URL (same defensive handling the ONTO
	 * owner-web implementation uses).
	 */
	private static function normalize_avatar( $profile ) {
		if ( empty( $profile['picture'] ) ) {
			return '';
		}
		if ( is_array( $profile['picture'] ) ) {
			if ( isset( $profile['picture']['data']['url'] ) ) {
				return esc_url_raw( $profile['picture']['data']['url'] );
			}
			return '';
		}
		return esc_url_raw( (string) $profile['picture'] );
	}

	/**
	 * A small inline Zalo mark, so the button doesn't depend on external
	 * images. Returns the raw SVG markup.
	 */
	private static function zalo_icon() {
		return '<svg class="epic-zalo-login-icon" width="20" height="20" viewBox="0 0 48 48" aria-hidden="true" focusable="false"><path fill="#0068FF" d="M24 4C12.95 4 4 12.05 4 22.7c0 9.9 7.86 15.4 13.2 19.4.6.4.9 1.2.6 1.9-.2.9-1 1.4-1.9 1.3-.7-.1-1.3-.3-1.7-.5-1-.6-9.6-4.9-13-8.8C-1.5 31.6 0 22.5 4.2 15.8 8.9 8.3 18.3 2 24 2c5.7 0 15.1 6.3 19.8 13.8 4.2 6.7 5.7 15.8 2.8 20.3-3.4 3.9-12 8.2-13 8.8-.4.2-1 .4-1.7.5-.9.1-1.7-.4-1.9-1.3-.3-.7 0-1.5.6-1.9C36.1 38.1 44 32.6 44 22.7 44 12.05 35.05 4 24 4Z"/><path fill="#fff" d="M24 15.3c3.8 0 6.9 3.1 6.9 6.9s-3.1 6.9-6.9 6.9-6.9-3.1-6.9-6.9 3.1-6.9 6.9-6.9Z"/></svg>';
	}
}
