<?php
/**
 * A single settings field — the shared secret the Next.js website must send
 * as `X-Epic-Secret` on every call into the `epic/v1/coupon/quote` REST
 * route (class-rest-quote.php). Same pattern as epic-payment-store's
 * Epic_Payment_Settings — a dedicated submenu rather than a full
 * WC_Settings_Page subclass, since it's one field.
 *
 * Deliberately a SEPARATE secret from epic-payment-store's, even though the
 * mechanism is identical — this endpoint is read-only (never writes
 * checkout/payment state) and reachable from a different Next.js code path,
 * so there's no reason to let a leak of one secret compromise the other.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Adv_Coupons_Quote_Settings {

	const OPTION_KEY = 'epic_coupon_shared_secret';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
	}

	public static function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Coupon Quote API', 'epic-advanced-coupons' ),
			__( 'Coupon Quote API', 'epic-advanced-coupons' ),
			'manage_woocommerce',
			'epic-advanced-coupons-quote',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register_setting() {
		register_setting(
			'epic_coupon_quote',
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
			<h1><?php esc_html_e( 'Coupon Quote API', 'epic-advanced-coupons' ); ?></h1>
			<p>
				<?php esc_html_e( 'This secret authenticates the Next.js website\'s calls into POST /wp-json/epic/v1/coupon/quote — the endpoint the checkout page calls to preview a coupon\'s discount before placing an order (also used to apply the discount for real at order creation). Set the same value in the website\'s EPIC_COUPON_SHARED_SECRET environment variable — it never leaves your own infrastructure.', 'epic-advanced-coupons' ); ?>
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'epic_coupon_quote' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="epic_coupon_shared_secret"><?php esc_html_e( 'Shared secret', 'epic-advanced-coupons' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="epic_coupon_shared_secret"
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>"
								value="<?php echo esc_attr( self::get_shared_secret() ); ?>"
								class="regular-text code"
								autocomplete="off"
							/>
							<p class="description">
								<?php esc_html_e( 'A long random string, separate from the Payment Store secret. Generate one and paste it here, then copy the same value into the website\'s environment variables.', 'epic-advanced-coupons' ); ?>
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
