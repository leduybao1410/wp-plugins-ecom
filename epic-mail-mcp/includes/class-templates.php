<?php
/**
 * Template registry, rendering and placeholder substitution.
 *
 * Templates are PHP files that contain the exact production markup. Rendering
 * never accepts caller-supplied HTML: the caller may only name a registered
 * template and supply placeholder values.
 *
 * @package Epic_Mail_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Mail_MCP_Templates {

	const LOCALES = array( 'vi', 'en' );

	/**
	 * Placeholders a caller may supply.
	 *
	 * @var string[]
	 */
	const ALLOWED_VARS = array(
		'name',
		'hotel_name',
		'sender_name',
		'sender_title',
		'epic_email',
		'phone',
		'note',
		'site_name',
		'timestamp',
		'mailer',
	);

	/**
	 * Template registry.
	 *
	 * @return array
	 */
	public static function registry() {
		return array(
			'mail-test'               => array(
				'label'    => 'Mail transport test',
				'files'    => array(
					'vi' => 'mail-test.php',
					'en' => 'mail-test.php',
				),
				'subjects' => array(
					'vi' => 'Kiểm tra gửi mail · {{site_name}}',
					'en' => 'Mail delivery test · {{site_name}}',
				),
				'required' => array(),
			),
			'hospitality-hotel-intro' => array(
				'label'    => 'Hospitality hotel introduction',
				'files'    => array(
					'vi' => 'hospitality-hotel-intro.vi.php',
					'en' => 'hospitality-hotel-intro.en.php',
				),
				'subjects' => array(
					'vi' => 'Một nguồn cà phê ổn định cho toàn bộ khách sạn | EPIC Coffee Roaster',
					'en' => 'A stable coffee supply for the whole property | EPIC Coffee Roaster',
				),
				'required' => array( 'name', 'hotel_name' ),
			),
		);
	}

	/**
	 * Registered template slugs.
	 *
	 * @return string[]
	 */
	public static function slugs() {
		return array_keys( self::registry() );
	}

	/**
	 * One template definition, or null.
	 *
	 * @param string $slug Template slug.
	 * @return array|null
	 */
	public static function definition( $slug ) {
		$registry = self::registry();

		return isset( $registry[ $slug ] ) ? $registry[ $slug ] : null;
	}

	/**
	 * Render a registered template.
	 *
	 * @param string $slug             Template slug.
	 * @param string $locale           vi or en.
	 * @param array  $vars             Placeholder values.
	 * @param string $subject_override Optional subject line.
	 * @return array|WP_Error
	 */
	public static function render( $slug, $locale, $vars, $subject_override = '' ) {
		$definition = self::definition( $slug );
		if ( null === $definition ) {
			return new WP_Error(
				'epic_mail_mcp_unknown_template',
				'Unknown template. Choose one of: ' . implode( ', ', self::slugs() ) . '.',
				array( 'status' => 400 )
			);
		}

		$locale = in_array( $locale, self::LOCALES, true ) ? $locale : 'vi';

		$missing = array();
		foreach ( $definition['required'] as $key ) {
			if ( ! isset( $vars[ $key ] ) || '' === trim( (string) $vars[ $key ] ) ) {
				$missing[] = $key;
			}
		}
		if ( ! empty( $missing ) ) {
			return new WP_Error(
				'epic_mail_mcp_missing_vars',
				'Missing template variables: ' . implode( ', ', $missing ) . '.',
				array( 'status' => 400 )
			);
		}

		$file = EPIC_MAIL_MCP_DIR . 'templates/' . $definition['files'][ $locale ];
		if ( ! is_readable( $file ) ) {
			return new WP_Error(
				'epic_mail_mcp_template_missing',
				'Template file is missing: ' . $definition['files'][ $locale ],
				array( 'status' => 500 )
			);
		}

		$tokens = self::tokens( $vars );

		ob_start();
		include $file;
		$html = (string) ob_get_clean();

		$html = strtr( $html, $tokens );

		$unresolved = array();
		if ( preg_match_all( '/\{\{([a-z0-9_]+)\}\}/i', $html, $matches ) ) {
			$unresolved = array_values( array_unique( $matches[1] ) );
			$replace    = array();
			foreach ( $unresolved as $token ) {
				$replace[] = '{{' . $token . '}}';
			}
			$html = str_replace( $replace, '', $html );
		}

		$subject = '' !== trim( (string) $subject_override )
			? sanitize_text_field( (string) $subject_override )
			: (string) $definition['subjects'][ $locale ];

		$subject = strtr( $subject, $tokens );
		$subject = trim( str_replace( array( '{{', '}}' ), '', $subject ) );

		return array(
			'template'   => $slug,
			'locale'     => $locale,
			'subject'    => $subject,
			'html'       => $html,
			'text'       => self::html_to_text( $html ),
			'unresolved' => $unresolved,
		);
	}

	/**
	 * Build the placeholder replacement map.
	 *
	 * Every substituted value is HTML-escaped. The URL variant is encoded for
	 * use inside a mailto: query string.
	 *
	 * @param array $vars Placeholder values.
	 * @return array
	 */
	private static function tokens( $vars ) {
		$tokens = array();

		foreach ( $vars as $key => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$tokens[ '{{' . $key . '}}' ] = esc_html( (string) $value );
		}

		$hotel_name                   = isset( $vars['hotel_name'] ) ? (string) $vars['hotel_name'] : '';
		$tokens['{{hotel_name_url}}'] = rawurlencode( $hotel_name );

		return $tokens;
	}

	/**
	 * Plain-text alternative derived from the rendered HTML.
	 *
	 * @param string $html Rendered HTML.
	 * @return string
	 */
	private static function html_to_text( $html ) {
		$text = preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', '', (string) $html );
		$text = preg_replace( '#<br\s*/?>#i', "\n", (string) $text );
		$text = preg_replace( '#</(p|div|tr|h1|h2|h3|table|li)>#i', "\n", (string) $text );
		$text = wp_strip_all_tags( (string) $text );
		$text = html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/[ \t]+/', ' ', (string) $text );
		$text = preg_replace( "/\n[ ]+/", "\n", (string) $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", (string) $text );

		return trim( (string) $text );
	}
}
