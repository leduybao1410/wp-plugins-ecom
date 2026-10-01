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

$epic_email_branding_i18n = __DIR__ . '/epic-email-i18n.php';
if ( file_exists( $epic_email_branding_i18n ) ) {
	require_once $epic_email_branding_i18n;
}

class Epic_Email_Branding {

	/** Locale of the email currently rendering — set by render_header so the
	 *  plain-text footer (which receives no WC_Email object) can reuse it. */
	private static $current_locale = 'vi';

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
	 * The storefront locale this email should render in, from the richest
	 * signal available: a locale property the EPIC email classes set
	 * (subscriber_locale / request_locale / lead_locale / broadcast_locale),
	 * else the order's `_epic_locale` meta, else the last header's locale,
	 * else Vietnamese (the store's primary customer base).
	 *
	 * @param mixed $email WC_Email|null
	 * @return string One of en|vi|ru|hi|zh|ko|ja.
	 */
	private static function resolve_locale( $email = null ) {
		$supported = array( 'en', 'vi', 'ru', 'hi', 'zh', 'ko', 'ja' );
		$candidates = array();

		if ( is_object( $email ) ) {
			foreach ( array( 'subscriber_locale', 'request_locale', 'lead_locale', 'broadcast_locale', 'epic_locale' ) as $prop ) {
				if ( isset( $email->$prop ) && is_string( $email->$prop ) && '' !== trim( $email->$prop ) ) {
					$candidates[] = $email->$prop;
				}
			}
			$obj = method_exists( $email, 'get_object' ) ? $email->get_object() : ( $email->object ?? null );
			if ( is_object( $obj ) && method_exists( $obj, 'get_meta' ) ) {
				$meta = (string) $obj->get_meta( '_epic_locale' );
				if ( '' !== $meta && 'unknown' !== $meta ) {
					$candidates[] = $meta;
				}
			}
		}

		foreach ( $candidates as $candidate ) {
			$value = is_string( $candidate ) ? strtolower( trim( $candidate ) ) : '';
			if ( strlen( $value ) > 2 && false !== strpos( $value, '-' ) ) {
				$value = substr( $value, 0, 2 );
			}
			if ( in_array( $value, $supported, true ) ) {
				return $value;
			}
		}

		return in_array( self::$current_locale, $supported, true ) ? self::$current_locale : 'vi';
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
		$locale = self::resolve_locale( $email );
		self::$current_locale = $locale;
		?>
		<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:0 0 12px;">
			<tr>
				<td style="background:#1c1c1c;padding:16px 24px;text-align:center;font-family:Helvetica,Arial,sans-serif;">
					<div style="font-size:13px;letter-spacing:3px;text-transform:uppercase;color:#f4efe6;font-weight:700;">
						<?php echo esc_html( $c['brand'] ); ?>
					</div>
					<div style="margin-top:5px;font-size:11px;line-height:1.5;color:#c7bcac;">
						<?php echo esc_html( epic_email_str( 'brand_tagline', $locale ) ); ?>
					</div>
					<div style="margin-top:8px;font-size:11px;line-height:1.6;color:#c7bcac;">
						<?php echo esc_html( epic_email_str( 'label_hotline', $locale ) ); ?>
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
		$c                    = self::contact();
		$locale               = self::resolve_locale( $email );
		self::$current_locale = $locale;
		$year = date_i18n( 'Y' );

		$label_cafe     = epic_email_str( 'label_cafe', $locale );
		$label_roastery = epic_email_str( 'label_roastery', $locale );
		$label_hotline  = epic_email_str( 'label_hotline', $locale );
		$label_email    = epic_email_str( 'label_email', $locale );
		$hours          = epic_email_str( 'brand_hours', $locale );
		$rights         = epic_email_str( 'brand_rights', $locale );

		if ( $email instanceof WC_Email ) {
			ob_start();
			?>
<strong><?php echo esc_html( $c['brand'] ); ?></strong><br />
<?php echo esc_html( $label_cafe ); ?> · <?php echo esc_html( $c['cafe'] ); ?><br />
<?php echo esc_html( $label_roastery ); ?> · <?php echo esc_html( $c['roastery'] ); ?><br />
<?php echo esc_html( $label_hotline ); ?> <a href="tel:<?php echo esc_attr( $c['phone_href'] ); ?>"><?php echo esc_html( $c['phone'] ); ?></a>
· <?php echo esc_html( $label_email ); ?> <a href="mailto:<?php echo esc_attr( $c['email'] ); ?>"><?php echo esc_html( $c['email'] ); ?></a><br />
<a href="<?php echo esc_url( $c['website'] ); ?>"><?php echo esc_html( preg_replace( '#^https?://#', '', $c['website'] ) ); ?></a>
· <a href="<?php echo esc_url( $c['instagram'] ); ?>">Instagram</a><br />
<?php echo esc_html( $hours ); ?> · &copy; <?php echo esc_html( $year ); ?> <?php echo esc_html( $c['brand'] ); ?>. <?php echo esc_html( $rights ); ?>
			<?php
			return trim( (string) ob_get_clean() );
		}

		// Plain-text variant.
		$lines = array(
			$c['brand'],
			$label_cafe . ': ' . $c['cafe'],
			$label_roastery . ': ' . $c['roastery'],
			$label_hotline . ': ' . $c['phone'],
			$label_email . ': ' . $c['email'],
			'Website: ' . $c['website'],
			'Instagram: ' . $c['instagram'],
			$hours,
			'© ' . $year . ' ' . $c['brand'] . '. ' . $rights,
		);

		return implode( "\n", $lines );
	}
}
