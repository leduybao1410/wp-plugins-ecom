<?php
/**
 * A single settings field — the shared secret the Next.js website's order
 * lookup proxy must send as `X-Epic-Secret` when calling this plugin's
 * `/lookup` REST route. Lives under WooCommerce → Order Codes, mirroring
 * epic-payment-store's one-field settings page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Order_Code_Settings {

	const OPTION_KEY = 'epic_order_codes_shared_secret';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
	}

	public static function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Order Codes', 'epic-order-codes' ),
			__( 'Order Codes', 'epic-order-codes' ),
			'manage_woocommerce',
			'epic-order-codes',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register_setting() {
		register_setting(
			'epic_order_codes',
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
			<h1><?php esc_html_e( 'Order Codes', 'epic-order-codes' ); ?></h1>
			<p>
				<?php esc_html_e( 'This plugin replaces sequential order numbers with unguessable letter+digit codes (EPIC-XXXXXX). This secret authenticates the website\'s order-lookup calls into this plugin\'s /lookup REST route, so customers can check their order status by entering their code. Set the same value in the website\'s EPIC_ORDER_CODES_SHARED_SECRET environment variable.', 'epic-order-codes' ); ?>
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'epic_order_codes' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="epic_order_codes_shared_secret"><?php esc_html_e( 'Shared secret', 'epic-order-codes' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="epic_order_codes_shared_secret"
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>"
								value="<?php echo esc_attr( self::get_shared_secret() ); ?>"
								class="regular-text code"
								autocomplete="off"
							/>
							<p class="description">
								<?php esc_html_e( 'A long random string. Generate one and paste it here, then copy the same value into the website\'s environment variables.', 'epic-order-codes' ); ?>
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
