<?php
/**
 * Mints a short-lived dashboard session for the administrator who is already
 * signed in to WordPress.
 *
 * The dashboard API (`epic-admin/v1`) authenticates every request with an
 * opaque `X-Epic-Admin-Session` token stored as a SHA-256 hash. Instead of
 * replaying the separate host's OAuth + PKCE dance, a page render that already
 * passed WordPress authentication creates the same row the OAuth exchange
 * would have created. Tokens are indistinguishable to the API and inherit its
 * eight-hour expiry, idle timeout, capability re-check, password-reset
 * revocation and audit trail.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Epic_Ops_Session {
	const TTL             = 28800;
	const MAX_PER_USER    = 5;
	const TOKEN_PATTERN   = '/^[A-Za-z0-9_-]{40,100}$/';

	/**
	 * @param WP_User $user Signed-in administrator.
	 * @return string|WP_Error Opaque dashboard token.
	 */
	public static function mint( $user ) {
		global $wpdb;

		if ( ! class_exists( 'Epic_Admin_Dashboard' ) || ! class_exists( 'Epic_Admin_Security' ) ) {
			return new WP_Error(
				'epic_ops_adapter_missing',
				__( 'The EPIC Admin Dashboard API plugin is not active, so the console cannot reach business data.', 'epic-ops-console' ),
				array( 'status' => 503 )
			);
		}

		$table = Epic_Admin_Dashboard::tables()['sessions'];
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return new WP_Error(
				'epic_ops_store_missing',
				__( 'The EPIC Admin Dashboard session table is missing. Reactivate the adapter plugin.', 'epic-ops-console' ),
				array( 'status' => 503 )
			);
		}

		$now = time();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE expires_at < %s", gmdate( 'Y-m-d H:i:s', $now ) ) );

		$token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		if ( ! preg_match( self::TOKEN_PATTERN, $token ) ) {
			return new WP_Error( 'epic_ops_token_invalid', __( 'The console session could not be generated.', 'epic-ops-console' ), array( 'status' => 500 ) );
		}

		$inserted = $wpdb->insert(
			$table,
			array(
				'token_hash'   => hash( 'sha256', $token ),
				'auth_version' => Epic_Admin_Security::session_version( $user ),
				'user_id'      => (int) $user->ID,
				'created_at'   => gmdate( 'Y-m-d H:i:s', $now ),
				'last_used_at' => gmdate( 'Y-m-d H:i:s', $now ),
				'expires_at'   => gmdate( 'Y-m-d H:i:s', $now + self::TTL ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'epic_ops_store_unavailable', __( 'The console session could not be stored. Try reloading the page.', 'epic-ops-console' ), array( 'status' => 503 ) );
		}

		self::trim( $user->ID, $table );
		Epic_Admin_Security::audit( (int) $user->ID, 'auth.mint', 'success' );

		return $token;
	}

	/** Keeps only the newest sessions so repeated page loads cannot grow the table. */
	private static function trim( $user_id, $table ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE user_id = %d ORDER BY id DESC", $user_id ) );
		if ( ! is_array( $ids ) || count( $ids ) <= self::MAX_PER_USER ) { return; }
		foreach ( array_slice( $ids, self::MAX_PER_USER ) as $stale ) {
			$wpdb->delete( $table, array( 'id' => (int) $stale ), array( '%d' ) );
		}
	}
}
