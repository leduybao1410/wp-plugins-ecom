<?php
/**
 * The plugin's only wp-admin screen: Settings → Zalo Login. Holds the two
 * pieces of Zalo-app configuration the OAuth flow needs — the App ID and the
 * App Secret Key (found at developers.zalo.me → Quản lý ứng dụng → the app's
 * "Thông tin ứng dụng" / "Secret key" section). It also shows the exact
 * redirect_uri that must be whitelisted under the app's "Đăng nhập" settings.
 *
 * Everything the plugin does is gated on these two fields being present.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Zalo_Settings {

	const OPTION_APP_ID      = 'epic_zalo_app_id';
	const OPTION_SECRET_KEY  = 'epic_zalo_secret_key';
	const OPTION_AUTO_CREATE = 'epic_zalo_auto_create_users';

	public static function add_menu() {
		add_options_page(
			__( 'Zalo Login', 'epic-zalo-login' ),
			__( 'Zalo Login', 'epic-zalo-login' ),
			'manage_options',
			'epic-zalo-login',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register_setting() {
		register_setting(
			'epic_zalo_login',
			self::OPTION_APP_ID,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'absint',
				'default'           => '',
			)
		);
		register_setting(
			'epic_zalo_login',
			self::OPTION_SECRET_KEY,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		register_setting(
			'epic_zalo_login',
			self::OPTION_AUTO_CREATE,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			)
		);
	}

	public static function get_app_id() {
		return get_option( self::OPTION_APP_ID, '' );
	}

	public static function get_secret_key() {
		return get_option( self::OPTION_SECRET_KEY, '' );
	}

	public static function get_auto_create_users() {
		return (bool) get_option( self::OPTION_AUTO_CREATE, true );
	}

	public static function is_configured() {
		return '' !== self::get_app_id() && '' !== self::get_secret_key();
	}

	/**
	 * The exact URL Zalo must redirect the user back to after they approve the
	 * login. Whitelist this under the Zalo app's "Quản lý ứng dụng → Đăng nhập"
	 * as the Callback URL — and never change it once users have started logging
	 * in, because Zalo matches it exactly.
	 */
	public static function get_callback_url() {
		return add_query_arg( 'action', 'epic_zalo_callback', admin_url( 'admin-post.php' ) );
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Zalo Login', 'epic-zalo-login' ); ?></h1>
			<p>
				<?php esc_html_e( 'Configure the Zalo app used for "Sign in with Zalo". Create the app at developers.zalo.me, then paste its credentials here. The App ID and Secret Key never leave your own server.', 'epic-zalo-login' ); ?>
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'epic_zalo_login' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( self::OPTION_APP_ID ); ?>"><?php esc_html_e( 'App ID', 'epic-zalo-login' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="<?php echo esc_attr( self::OPTION_APP_ID ); ?>"
								name="<?php echo esc_attr( self::OPTION_APP_ID ); ?>"
								value="<?php echo esc_attr( self::get_app_id() ); ?>"
								class="regular-text code"
								autocomplete="off"
							/>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( self::OPTION_SECRET_KEY ); ?>"><?php esc_html_e( 'App Secret Key', 'epic-zalo-login' ); ?></label>
						</th>
						<td>
							<input
								type="password"
								id="<?php echo esc_attr( self::OPTION_SECRET_KEY ); ?>"
								name="<?php echo esc_attr( self::OPTION_SECRET_KEY ); ?>"
								value="<?php echo esc_attr( self::get_secret_key() ); ?>"
								class="regular-text code"
								autocomplete="off"
							/>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Callback URL', 'epic-zalo-login' ); ?>
						</th>
						<td>
							<code><?php echo esc_html( self::get_callback_url() ); ?></code>
							<p class="description">
								<?php esc_html_e( 'Enter this exact URL as the Callback URL in the Zalo app\'s "Đăng nhập" settings.', 'epic-zalo-login' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Create users automatically', 'epic-zalo-login' ); ?>
						</th>
						<td>
							<label>
								<input
									type="checkbox"
									name="<?php echo esc_attr( self::OPTION_AUTO_CREATE ); ?>"
									value="1"
									<?php checked( self::get_auto_create_users() ); ?>
								/>
								<?php esc_html_e( 'Automatically create a WordPress user on first Zalo login. When off, only existing users who already have a Zalo ID linked can sign in.', 'epic-zalo-login' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
