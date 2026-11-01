<?php
/**
 * MCP ability registrations for test and controlled agent sending.
 *
 * @package Epic_Mail_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Mail_MCP_Abilities {

	const CATEGORY = 'epic-mail-mcp';

	/**
	 * Boot the plugin.
	 */
	public static function init() {
		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_register_ability_category' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'render_missing_api_notice' ) );
			return;
		}

		Epic_Mail_MCP_Sender::init();
		Epic_Mail_MCP_Audit::maybe_install();

		if ( Epic_Mail_MCP_Sender::is_disabled() ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	/**
	 * Explain a missing Abilities API.
	 */
	public static function render_missing_api_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>EPIC Mail MCP:</strong> WordPress 6.9+ (or the Abilities API feature plugin) and the MCP Adapter are required.</p></div>';
	}

	/**
	 * Register the ability category.
	 */
	public static function register_category() {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => 'Mail',
				'description' => 'Read the mailer state, send test messages and approved templates through the existing WP Mail SMTP configuration.',
			)
		);
	}

	/**
	 * Register every ability in this namespace.
	 */
	public static function register_abilities() {
		foreach ( self::definitions() as $name => $definition ) {
			wp_register_ability( self::CATEGORY . '/' . $name, $definition );
		}
	}

	/**
	 * Shared ability meta.
	 *
	 * @param array $annotations Extra MCP annotations.
	 * @return array
	 */
	private static function meta( $annotations = array() ) {
		return array(
			'public'       => true,
			'show_in_rest' => true,
			'mcp'          => array(
				'public' => true,
				'type'   => 'tool',
			),
			'annotations'  => array_merge( array( 'openWorldHint' => false ), $annotations ),
		);
	}

	/**
	 * Ability definitions.
	 *
	 * @return array
	 */
	private static function definitions() {
		return array(
			'get-mailer-status' => array(
				'label'               => 'Read mailer status',
				'description'         => 'Read the active mailer, from-address, debug logging state, the newest recorded error, allowlist size and remaining hourly quota. Optionally returns masked allowlist entries.',
				'category'            => self::CATEGORY,
				'input_schema'        => self::object_schema(
					array(
						'include_allowlist' => array( 'type' => 'boolean', 'default' => false, 'description' => 'Return masked allowlist entries.' ),
					)
				),
				'execute_callback'    => static function ( $input ) {
					return self::mailer_status( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => array( __CLASS__, 'can_manage_options' ),
				'meta'                => self::meta( array( 'readonly' => true, 'idempotent' => true, 'title' => 'Read mailer status' ) ),
			),
			'send-test' => array(
				'label'               => 'Send mail test',
				'description'         => 'Send a short fixed test message to an allowlisted address and report the exact transport error when it fails.',
				'category'            => self::CATEGORY,
				'input_schema'        => self::object_schema(
					array(
						'to'   => array( 'type' => 'string', 'description' => 'Recipient address. Must be in the allowlist.' ),
						'note' => array( 'type' => 'string', 'maxLength' => 200, 'description' => 'Optional note echoed inside the test message.' ),
					),
					array( 'to' )
				),
				'execute_callback'    => static function ( $input ) {
					return self::send_test( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => array( __CLASS__, 'can_manage_options' ),
				'meta'                => self::meta( array( 'readonly' => false, 'destructive' => false, 'idempotent' => false, 'title' => 'Send mail test' ) ),
			),
			'send-template' => array(
				'label'               => 'Send approved template',
				'description'         => 'Render a registered template with placeholder substitution and send it to an allowlisted address. Use dry_run to review the exact subject, HTML and text before sending. Arbitrary HTML is not accepted.',
				'category'            => self::CATEGORY,
				'input_schema'        => self::object_schema(
					array(
						'template'         => array(
							'type' => 'string',
							'enum' => Epic_Mail_MCP_Templates::slugs(),
						),
						'locale'           => array( 'type' => 'string', 'enum' => Epic_Mail_MCP_Templates::LOCALES, 'default' => 'vi' ),
						'to'               => array( 'type' => 'string', 'description' => 'Recipient address. Must be in the allowlist unless dry_run is true.' ),
						'vars'             => array(
							'type'        => 'object',
							'description' => 'Placeholder values. hospitality-hotel-intro requires name and hotel_name.',
							'properties'  => array(
								'name'         => array( 'type' => 'string', 'maxLength' => 200 ),
								'hotel_name'   => array( 'type' => 'string', 'maxLength' => 200 ),
								'sender_name'  => array( 'type' => 'string', 'maxLength' => 200 ),
								'sender_title' => array( 'type' => 'string', 'maxLength' => 200 ),
								'epic_email'   => array( 'type' => 'string', 'maxLength' => 200 ),
								'phone'        => array( 'type' => 'string', 'maxLength' => 200 ),
								'note'         => array( 'type' => 'string', 'maxLength' => 200 ),
							),
						),
						'subject_override' => array( 'type' => 'string', 'maxLength' => 255 ),
						'reply_to'         => array( 'type' => 'string', 'description' => 'Optional Reply-To address. The From address always comes from WP Mail SMTP.' ),
						'dry_run'          => array( 'type' => 'boolean', 'default' => false, 'description' => 'Return the rendered message without sending it.' ),
					),
					array( 'template' )
				),
				'execute_callback'    => static function ( $input ) {
					return self::send_template( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => array( __CLASS__, 'can_manage_options' ),
				'meta'                => self::meta( array( 'readonly' => false, 'destructive' => false, 'idempotent' => false, 'title' => 'Send approved template' ) ),
			),
			'get-send-log' => array(
				'label'               => 'Read send log',
				'description'         => 'Read the plugin audit log: one row per send attempt, including denied attempts.',
				'category'            => self::CATEGORY,
				'input_schema'        => self::object_schema(
					array(
						'limit'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 20 ),
						'offset' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
						'status' => array( 'type' => 'string', 'enum' => array( 'sent', 'failed', 'denied' ) ),
					)
				),
				'execute_callback'    => static function ( $input ) {
					return self::send_log( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => array( __CLASS__, 'can_manage_options' ),
				'meta'                => self::meta( array( 'readonly' => true, 'idempotent' => true, 'title' => 'Read send log' ) ),
			),
			'set-allowlist' => array(
				'label'               => 'Set recipient allowlist',
				'description'         => 'Replace the stored recipient allowlist. Accepts exact addresses and *@domain wildcards. Ignored when EPIC_MAIL_MCP_ALLOW is defined in wp-config.php.',
				'category'            => self::CATEGORY,
				'input_schema'        => self::object_schema(
					array(
						'addresses' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Exact addresses or *@domain wildcards. An empty array clears the allowlist and blocks every send.',
						),
					),
					array( 'addresses' )
				),
				'execute_callback'    => static function ( $input ) {
					return self::set_allowlist( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => array( __CLASS__, 'can_manage_options' ),
				'meta'                => self::meta( array( 'readonly' => false, 'destructive' => true, 'idempotent' => true, 'title' => 'Set recipient allowlist' ) ),
			),
		);
	}

	/**
	 * Build an object schema.
	 *
	 * @param array $properties Property definitions.
	 * @param array $required   Required property names.
	 * @return array
	 */
	private static function object_schema( $properties, $required = array() ) {
		$schema = array(
			'type'       => 'object',
			'properties' => $properties,
		);

		if ( ! empty( $required ) ) {
			$schema['required'] = $required;
		}

		return $schema;
	}

	/**
	 * Permission check for every ability.
	 *
	 * @return bool
	 */
	public static function can_manage_options() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * get-mailer-status
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	private static function mailer_status( $input ) {
		try {
			return self::build_mailer_status( $input );
		} catch ( \Throwable $e ) {
			return array(
				'abilities_available' => true,
				'status_error'        => $e->getMessage(),
			);
		}
	}

	/**
	 * Build the mailer status payload.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	private static function build_mailer_status( $input ) {
		$settings  = Epic_Mail_MCP_Sender::mailer_settings();
		$allowlist = Epic_Mail_MCP_Sender::allowlist();

		$status = array(
			'abilities_available'     => function_exists( 'wp_register_ability' ),
			'disabled'                => Epic_Mail_MCP_Sender::is_disabled(),
			'wp_mail_smtp_active'     => class_exists( '\WPMailSMTP\Options' ),
			'mailer'                  => $settings['mailer'],
			'mailer_label'            => $settings['mailer_label'],
			'from_email'              => $settings['from_email'],
			'from_name'               => $settings['from_name'],
			'from_email_forced'       => $settings['from_email_force'],
			'smtp_host'               => $settings['smtp_host'],
			'smtp_port'               => $settings['smtp_port'],
			'debug_events_logging'    => $settings['debug_logging'],
			'last_error'              => Epic_Mail_MCP_Sender::last_recorded_error(),
			'allowlist_count'         => count( $allowlist ),
			'allowlist_source'        => Epic_Mail_MCP_Sender::allowlist_source(),
			'rate_limit_max_per_hour' => Epic_Mail_MCP_Sender::max_per_hour(),
			'rate_limit_remaining'    => Epic_Mail_MCP_Sender::rate_remaining(),
			'templates'               => Epic_Mail_MCP_Templates::slugs(),
		);

		if ( ! empty( $input['include_allowlist'] ) ) {
			$status['allowlist'] = array_map( array( 'Epic_Mail_MCP_Sender', 'mask_email' ), $allowlist );
		}

		return $status;
	}

	/**
	 * send-test
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	private static function send_test( $input ) {
		$to   = isset( $input['to'] ) ? (string) $input['to'] : '';
		$note = isset( $input['note'] ) ? sanitize_text_field( (string) $input['note'] ) : '';
		$note = substr( $note, 0, 200 );

		$settings = Epic_Mail_MCP_Sender::mailer_settings();

		$rendered = Epic_Mail_MCP_Templates::render(
			'mail-test',
			'en',
			array(
				'site_name' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'timestamp' => gmdate( 'Y-m-d H:i:s' ) . ' UTC',
				'mailer'    => $settings['mailer_label'] . ' (' . $settings['mailer'] . ')',
				'note'      => $note,
			)
		);

		if ( is_wp_error( $rendered ) ) {
			return $rendered;
		}

		return Epic_Mail_MCP_Sender::send(
			array(
				'to'       => $to,
				'subject'  => $rendered['subject'],
				'html'     => $rendered['html'],
				'text'     => $rendered['text'],
				'template' => 'mail-test',
			)
		);
	}

	/**
	 * send-template
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	private static function send_template( $input ) {
		$slug     = isset( $input['template'] ) ? sanitize_key( (string) $input['template'] ) : '';
		$locale   = isset( $input['locale'] ) ? sanitize_key( (string) $input['locale'] ) : 'vi';
		$to       = isset( $input['to'] ) ? (string) $input['to'] : '';
		$dry_run  = ! empty( $input['dry_run'] );
		$override = isset( $input['subject_override'] ) ? (string) $input['subject_override'] : '';
		$reply_to = isset( $input['reply_to'] ) ? (string) $input['reply_to'] : '';

		$vars = self::normalize_vars( isset( $input['vars'] ) ? $input['vars'] : array() );
		if ( is_wp_error( $vars ) ) {
			return $vars;
		}

		$settings = Epic_Mail_MCP_Sender::mailer_settings();

		if ( empty( $vars['epic_email'] ) ) {
			$vars['epic_email'] = $settings['from_email'];
		}

		if ( empty( $vars['sender_name'] ) ) {
			$vars['sender_name'] = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		}

		$rendered = Epic_Mail_MCP_Templates::render( $slug, $locale, $vars, $override );
		if ( is_wp_error( $rendered ) ) {
			return $rendered;
		}

		if ( $dry_run ) {
			$rendered['dry_run']     = true;
			$rendered['to']          = $to;
			$rendered['allowlisted'] = Epic_Mail_MCP_Sender::is_allowed( $to );

			return $rendered;
		}

		return Epic_Mail_MCP_Sender::send(
			array(
				'to'       => $to,
				'subject'  => $rendered['subject'],
				'html'     => $rendered['html'],
				'text'     => $rendered['text'],
				'template' => $slug,
				'reply_to' => $reply_to,
			)
		);
	}

	/**
	 * get-send-log
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	private static function send_log( $input ) {
		$limit  = isset( $input['limit'] ) ? absint( $input['limit'] ) : 20;
		$offset = isset( $input['offset'] ) ? absint( $input['offset'] ) : 0;
		$status = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : '';

		return Epic_Mail_MCP_Audit::query( $limit ? $limit : 20, $offset, $status );
	}

	/**
	 * set-allowlist
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	private static function set_allowlist( $input ) {
		$raw = '';

		if ( isset( $input['addresses'] ) ) {
			$addresses = is_array( $input['addresses'] ) ? $input['addresses'] : array( $input['addresses'] );
			$raw       = implode( ',', array_map( 'strval', $addresses ) );
		}

		$rules = Epic_Mail_MCP_Sender::set_allowlist( $raw );
		if ( is_wp_error( $rules ) ) {
			return $rules;
		}

		return array(
			'allowlist' => $rules,
			'count'     => count( $rules ),
		);
	}

	/**
	 * Whitelist and bound caller-supplied placeholder values.
	 *
	 * @param mixed $vars Raw vars input.
	 * @return array|WP_Error
	 */
	private static function normalize_vars( $vars ) {
		if ( ! is_array( $vars ) ) {
			return new WP_Error(
				'epic_mail_mcp_invalid_vars',
				'vars must be an object of template placeholders.',
				array( 'status' => 400 )
			);
		}

		$clean = array();

		foreach ( $vars as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( ! in_array( $key, Epic_Mail_MCP_Templates::ALLOWED_VARS, true ) ) {
				continue;
			}

			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$value = (string) $value;

			if ( strlen( $value ) > 200 ) {
				return new WP_Error(
					'epic_mail_mcp_var_too_long',
					'Template variable "' . $key . '" exceeds 200 characters.',
					array( 'status' => 400 )
				);
			}

			$clean[ $key ] = $value;
		}

		if ( ! empty( $clean['epic_email'] ) && ! is_email( $clean['epic_email'] ) ) {
			return new WP_Error(
				'epic_mail_mcp_invalid_epic_email',
				'vars.epic_email must be a valid email address.',
				array( 'status' => 400 )
			);
		}

		return $clean;
	}
}
