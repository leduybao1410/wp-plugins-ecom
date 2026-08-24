<?php
/**
 * A single settings field — the shared secret the Next.js website's account
 * API proxy must send as `X-Epic-Secret` when calling this plugin's REST
 * routes. Lives under WooCommerce → Account Linking, mirroring
 * epic-order-codes' and epic-payment-store's one-field settings pages.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Account_Settings {

	const OPTION_KEY = 'epic_account_linking_shared_secret';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
	}

	public static function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Account Linking', 'epic-account-linking' ),
			__( 'Account Linking', 'epic-account-linking' ),
			'manage_woocommerce',
			'epic-account-linking',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register_setting() {
		register_setting(
			'epic_account_linking',
			self::OPTION_KEY,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
	}

	public static function get_shared_secret() {
		return get_option( self::OPTION_KEY, '' );
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Account Linking', 'epic-account-linking' ); ?></h1>
			<p>
				<?php esc_html_e( 'This plugin records Google-signed-in accounts and their linked WooCommerce orders for the website\'s headless account area. This secret authenticates the website\'s calls into this plugin\'s REST routes, so the storefront can fetch an account\'s order history. Set the same value in the website\'s EPIC_ACCOUNT_SHARED_SECRET environment variable.', 'epic-account-linking' ); ?>
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'epic_account_linking' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="epic_account_linking_shared_secret"><?php esc_html_e( 'Shared secret', 'epic-account-linking' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="epic_account_linking_shared_secret"
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>"
								value="<?php echo esc_attr( self::get_shared_secret() ); ?>"
								class="regular-text code"
								autocomplete="off"
							/>
							<p class="description">
								<?php esc_html_e( 'A long random string. Generate one and paste it here, then copy the same value into the website\'s environment variables.', 'epic-account-linking' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
