<?php
/**
 * A single settings field — the shared secret the Next.js site must send as
 * `X-Epic-Secret` on every call into this plugin's REST routes. Lives under
 * its own top-level-adjacent submenu (WooCommerce → Payment Store) rather
 * than a full WC_Settings_Page subclass, since one field doesn't warrant a
 * whole settings tab.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Payment_Settings {

	const OPTION_KEY = 'epic_payment_shared_secret';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
	}

	public static function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Payment Store', 'epic-payment-store' ),
			__( 'Payment Store', 'epic-payment-store' ),
			'manage_woocommerce',
			'epic-payment-store',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register_setting() {
		register_setting(
			'epic_payment_store',
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
			<h1><?php esc_html_e( 'Payment Store', 'epic-payment-store' ); ?></h1>
			<p>
				<?php esc_html_e( 'This secret authenticates the Next.js website\'s calls into this plugin\'s REST routes (storing/claiming pending checkout data for prepaid payment methods — currently SePay bank transfer). Set the same value in the website\'s EPIC_PAYMENT_SHARED_SECRET environment variable — it never leaves your own infrastructure.', 'epic-payment-store' ); ?>
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'epic_payment_store' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="epic_payment_shared_secret"><?php esc_html_e( 'Shared secret', 'epic-payment-store' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="epic_payment_shared_secret"
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>"
								value="<?php echo esc_attr( self::get_shared_secret() ); ?>"
								class="regular-text code"
								autocomplete="off"
							/>
							<p class="description">
								<?php esc_html_e( 'A long random string. Generate one and paste it here, then copy the same value into the website\'s environment variables.', 'epic-payment-store' ); ?>
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
