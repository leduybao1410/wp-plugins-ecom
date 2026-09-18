<?php
/**
 * Global branding for every WooCommerce email the store sends.
 *
 * WooCommerce renders the email header through `WC_Emails::email_header()`,
 * attached to the `woocommerce_email_header` action that every HTML email
 * template fires at its top. We hook the same action at a later priority so
 * our brand bar prints immediately after WooCommerce's own header markup
 * (logo + heading), at the top of the email body.
 *
 * The footer is different: WooCommerce's `emails/email-footer.php` renders
 * whatever `woocommerce_email_footer_text` resolves to, and the EPIC
 * plain-text templates call the same filter themselves. So the footer is
 * driven entirely by that filter — one source of truth that reaches both
 * HTML and plain-text emails. The filter receives the WC_Email object as its
 * second argument from WooCommerce's HTML footer, and nothing from the plain
 * templates, which is how we know whether to emit HTML or plain text.
 *
 * No settings screen: the details are sensible, store-specific defaults
 * exposed through one filter (`epic_email_branding_contact`) so they can be
 * changed from a theme/child plugin or a snippet without touching this file.
 *
 * @package Epic_Email_Branding
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Email_Branding {

	public static function init() {
		// Priority 20 = after WC_Emails::email_header() (priority 10).
		add_action( 'woocommerce_email_header', array( __CLASS__, 'render_header' ), 20, 2 );

		// Priority 20 so we win over any built-in/plugin footer text.
		add_filter( 'woocommerce_email_footer_text', array( __CLASS__, 'footer_text' ), 20, 2 );
	}

	/**
	 * The contact details every email header/footer renders. Filter
	 * `epic_email_branding_contact` to override any key:
	 *
	 *   add_filter( 'epic_email_branding_contact', function ( $c ) {
	 *       $c['phone'] = '0900 000 000';
	 *       return $c;
	 *   } );
	 *
	 * @return array<string,string>
	 */
	public static function contact() {
		// Prefer WooCommerce's configured "from" address as the contact email,
		// falling back to the site admin address when it's unset/empty.
		$contact_email = (string) get_option( 'woocommerce_email_from_address' );
		if ( '' === $contact_email ) {
			$contact_email = (string) get_option( 'admin_email' );
		}

		$defaults = array(
			'brand'      => 'EPIC Coffee Roaster',
			'tagline'    => 'Doing better each day · Specialty coffee roasted in Sài Gòn since 2014',
			'cafe'       => '49 Ngô Thời Nhiệm, P. Võ Thị Sáu, Q. 3, TP. HCM',
			'roastery'   => '54/8 Ao Đôi, Bình Hưng Hòa, TP. HCM',
			'phone'      => '0528 390 442',
			'phone_href' => '+84528390442',
			'email'      => $contact_email,
			'website'    => 'https://www.epicroastery.coffee',
			'instagram'  => 'https://www.instagram.com/epiccoffeeroaster/',
			'hours'      => 'Open daily 7:00–22:30',
		);

		return wp_parse_args( apply_filters( 'epic_email_branding_contact', array() ), $defaults );
	}

	/**
	 * Branded bar printed at the top of every HTML email body, right after
	 * WooCommerce's own header (logo + heading).
	 *
	 * @param string        $email_heading
	 * @param WC_Email|null $email
	 */
	public static function render_header( $email_heading, $email = null ) {
		$c = self::contact();
		?>
		<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:0 0 12px;">
			<tr>
				<td style="background:#1c1c1c;padding:16px 24px;text-align:center;font-family:Helvetica,Arial,sans-serif;">
					<div style="font-size:13px;letter-spacing:3px;text-transform:uppercase;color:#f4efe6;font-weight:700;">
						<?php echo esc_html( $c['brand'] ); ?>
					</div>
					<div style="margin-top:5px;font-size:11px;line-height:1.5;color:#c7bcac;">
						<?php echo esc_html( $c['tagline'] ); ?>
					</div>
					<div style="margin-top:8px;font-size:11px;line-height:1.6;color:#c7bcac;">
						<?php esc_html_e( 'Hotline', 'epic-email-branding' ); ?>
						<a href="tel:<?php echo esc_attr( $c['phone_href'] ); ?>" style="color:#e8a35a;text-decoration:none;"><?php echo esc_html( $c['phone'] ); ?></a>
						&nbsp;·&nbsp;
						<a href="<?php echo esc_url( $c['website'] ); ?>" style="color:#e8a35a;text-decoration:none;"><?php echo esc_html( preg_replace( '#^https?://#', '', $c['website'] ) ); ?></a>
					</div>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Contact footer for both HTML and plain-text emails.
	 *
	 * WooCommerce's HTML footer calls this filter with the WC_Email object as
	 * the second argument and passes the result through wp_kses_post(), so
	 * only "post" tags are used here (strong/br/a). The EPIC plain templates
	 * call the filter without that argument, so a plain-text variant is
	 * returned in that case.
	 *
	 * @param string        $text  Existing footer text (replaced entirely).
	 * @param WC_Email|null $email
	 * @return string
	 */
	public static function footer_text( $text, $email = null ) {
		$c    = self::contact();
		$year = date_i18n( 'Y' );

		if ( $email instanceof WC_Email ) {
			ob_start();
			?>
<strong><?php echo esc_html( $c['brand'] ); ?></strong><br />
<?php esc_html_e( 'Café', 'epic-email-branding' ); ?> · <?php echo esc_html( $c['cafe'] ); ?><br />
<?php esc_html_e( 'Roastery', 'epic-email-branding' ); ?> · <?php echo esc_html( $c['roastery'] ); ?><br />
<?php esc_html_e( 'Hotline', 'epic-email-branding' ); ?> <a href="tel:<?php echo esc_attr( $c['phone_href'] ); ?>"><?php echo esc_html( $c['phone'] ); ?></a>
· <?php esc_html_e( 'Email', 'epic-email-branding' ); ?> <a href="mailto:<?php echo esc_attr( $c['email'] ); ?>"><?php echo esc_html( $c['email'] ); ?></a><br />
<a href="<?php echo esc_url( $c['website'] ); ?>"><?php echo esc_html( preg_replace( '#^https?://#', '', $c['website'] ) ); ?></a>
· <a href="<?php echo esc_url( $c['instagram'] ); ?>">Instagram</a><br />
<?php echo esc_html( $c['hours'] ); ?> · &copy; <?php echo esc_html( $year ); ?> <?php echo esc_html( $c['brand'] ); ?>. <?php esc_html_e( 'All rights reserved.', 'epic-email-branding' ); ?>
			<?php
			return trim( (string) ob_get_clean() );
		}

		// Plain-text variant.
		$lines = array(
			$c['brand'],
			'Café: ' . $c['cafe'],
			'Roastery: ' . $c['roastery'],
			'Hotline: ' . $c['phone'],
			'Email: ' . $c['email'],
			'Website: ' . $c['website'],
			'Instagram: ' . $c['instagram'],
			$c['hours'],
			'© ' . $year . ' ' . $c['brand'] . '. All rights reserved.',
		);

		return implode( "\n", $lines );
	}
}
