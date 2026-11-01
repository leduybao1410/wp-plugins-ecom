<?php
/** Renders the standalone /ops document that boots the React application. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Epic_Ops_Template {

	public static function render() {
		$assets = self::assets();
		if ( is_wp_error( $assets ) ) {
			status_header( 500 );
			wp_die( esc_html( $assets->get_error_message() ), esc_html__( 'Bảng vận hành chưa sẵn sàng', 'epic-ops-console' ), array( 'response' => 500 ) );
		}

		$user  = wp_get_current_user();
		$token = Epic_Ops_Session::mint( $user );
		if ( is_wp_error( $token ) ) {
			status_header( 503 );
			wp_die( esc_html( $token->get_error_message() ), esc_html__( 'Bảng vận hành chưa sẵn sàng', 'epic-ops-console' ), array( 'response' => 503 ) );
		}

		$nonce  = wp_create_nonce( 'epic_ops_bootstrap' );
		$config = array(
			'base'      => '/ops',
			'apiRoot'   => esc_url_raw( rest_url( 'epic-admin/v1' ) ),
			'token'     => $token,
			'logoutUrl' => wp_logout_url( home_url( '/' ) ),
			'user'      => array(
				'id'    => (int) $user->ID,
				'name'  => $user->display_name,
				'email' => $user->user_email,
			),
			'version'   => EPIC_OPS_CONSOLE_VERSION,
		);

		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: DENY' );
		header(
			"Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; " .
			"form-action 'self'; img-src 'self' data: blob:; font-src 'self' data:; style-src 'self' 'unsafe-inline'; " .
			"script-src 'self' 'nonce-" . $nonce . "'; connect-src 'self'"
		);

		$json = wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		if ( ! is_string( $json ) ) { $json = '{}'; }

		echo '<!doctype html>' . "\n";
		echo '<html lang="vi">' . "\n<head>\n";
		echo '<meta charset="utf-8" />' . "\n";
		echo '<meta name="viewport" content="width=device-width, initial-scale=1" />' . "\n";
		echo '<meta name="robots" content="noindex, nofollow, noarchive" />' . "\n";
		echo '<title>EPIC Vận hành</title>' . "\n";
		echo '<link rel="stylesheet" href="' . esc_url( $assets['css'] ) . '" />' . "\n";
		echo '<script nonce="' . esc_attr( $nonce ) . '">'
			. 'try{var t=localStorage.getItem("epic-admin-theme");document.documentElement.dataset.theme=(t==="light"||t==="dark")?t:(matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light")}'
			. 'catch(e){document.documentElement.dataset.theme=matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light"}'
			. '</script>' . "\n";
		echo '</head>' . "\n<body>\n";
		echo '<div id="epic-ops-root"><div style="padding:48px;font:15px system-ui,sans-serif;color:#4a423a">Đang tải bảng vận hành…</div></div>' . "\n";
		echo '<noscript><p style="padding:24px;font:15px system-ui,sans-serif">Bảng vận hành cần JavaScript.</p></noscript>' . "\n";
		echo '<script nonce="' . esc_attr( $nonce ) . '">window.EPIC_OPS=' . $json . ';</script>' . "\n";
		echo '<script nonce="' . esc_attr( $nonce ) . '" type="module" src="' . esc_url( $assets['js'] ) . '"></script>' . "\n";
		echo '</body>' . "\n</html>\n";
	}

	/**
	 * Resolves the hashed Vite bundle from the build manifest so a deploy never
	 * needs to hardcode an asset name.
	 *
	 * @return array{js:string,css:string}|WP_Error
	 */
	private static function assets() {
		$manifest_path = EPIC_OPS_CONSOLE_DIR . 'dist/.vite/manifest.json';
		if ( ! is_readable( $manifest_path ) ) {
			return new WP_Error( 'epic_ops_manifest_missing', __( 'The console build is missing. Rebuild the plugin assets.', 'epic-ops-console' ) );
		}

		$raw = file_get_contents( $manifest_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local build artifact.
		$manifest = json_decode( (string) $raw, true );
		if ( ! is_array( $manifest ) ) {
			return new WP_Error( 'epic_ops_manifest_invalid', __( 'The console build manifest is unreadable. Rebuild the plugin assets.', 'epic-ops-console' ) );
		}

		$entry = null;
		foreach ( $manifest as $key => $value ) {
			if ( isset( $value['isEntry'] ) && $value['isEntry'] ) { $entry = $value; break; }
			if ( 'src/main.tsx' === $key ) { $entry = $value; break; }
		}
		if ( ! is_array( $entry ) || empty( $entry['file'] ) ) {
			return new WP_Error( 'epic_ops_entry_missing', __( 'The console entry bundle is missing. Rebuild the plugin assets.', 'epic-ops-console' ) );
		}

		$css = '';
		if ( ! empty( $entry['css'] ) && is_array( $entry['css'] ) ) {
			$css = (string) reset( $entry['css'] );
		}

		return array(
			'js'  => EPIC_OPS_CONSOLE_URL . 'dist/' . ltrim( (string) $entry['file'], '/' ),
			'css' => EPIC_OPS_CONSOLE_URL . 'dist/' . ltrim( $css, '/' ),
		);
	}
}
