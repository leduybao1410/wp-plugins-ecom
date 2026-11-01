<?php
/**
 * Allowlist, rate limit, transport and error capture.
 *
 * Sending always goes through wp_mail() so the site keeps exactly one SMTP
 * configuration: WP Mail SMTP. This class never configures a transport.
 *
 * @package Epic_Mail_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Mail_MCP_Sender {

	const DEFAULT_MAX_PER_HOUR = 10;
	const RATE_WINDOW          = 3600;
	const ALLOWLIST_OPTION     = 'epic_mail_mcp_allow';

	/**
	 * PHPMailer instance captured for the current send.
	 *
	 * @var object|null
	 */
	private static $phpmailer = null;

	/**
	 * Plain-text alternative for the current send.
	 *
	 * @var string
	 */
	private static $alt_body = '';

	/**
	 * Error message captured from wp_mail_failed.
	 *
	 * @var string
	 */
	private static $last_error = '';

	/**
	 * Register transport hooks.
	 */
	public static function init() {
		add_action( 'phpmailer_init', array( __CLASS__, 'capture_phpmailer' ) );
		add_action( 'wp_mail_failed', array( __CLASS__, 'capture_failure' ) );
	}

	/**
	 * Attach the plain-text alternative to the outgoing message.
	 *
	 * @param object $phpmailer PHPMailer instance.
	 */
	public static function capture_phpmailer( $phpmailer ) {
		self::$phpmailer = $phpmailer;

		if ( '' !== self::$alt_body && is_object( $phpmailer ) ) {
			$phpmailer->AltBody = self::$alt_body;
		}
	}

	/**
	 * Remember the error WordPress reported for a failed send.
	 *
	 * @param WP_Error $error Error object.
	 */
	public static function capture_failure( $error ) {
		if ( is_wp_error( $error ) ) {
			self::$last_error = wp_strip_all_tags( (string) $error->get_error_message() );
		}
	}

	/**
	 * Whether the kill switch is on.
	 *
	 * @return bool
	 */
	public static function is_disabled() {
		return defined( 'EPIC_MAIL_MCP_DISABLED' ) && EPIC_MAIL_MCP_DISABLED;
	}

	/**
	 * Where the allowlist comes from.
	 *
	 * @return string constant|option|none
	 */
	public static function allowlist_source() {
		if ( defined( 'EPIC_MAIL_MCP_ALLOW' ) ) {
			return 'constant';
		}

		if ( '' !== trim( (string) get_option( self::ALLOWLIST_OPTION, '' ) ) ) {
			return 'option';
		}

		return 'none';
	}

	/**
	 * Raw allowlist string. The constant wins over the stored option.
	 *
	 * @return string
	 */
	public static function allowlist_raw() {
		if ( defined( 'EPIC_MAIL_MCP_ALLOW' ) ) {
			return (string) EPIC_MAIL_MCP_ALLOW;
		}

		return (string) get_option( self::ALLOWLIST_OPTION, '' );
	}

	/**
	 * Parse a comma-separated allowlist into normalised rules.
	 *
	 * @param string $raw Raw rule list.
	 * @return string[]
	 */
	public static function parse_allowlist( $raw ) {
		$rules = array();

		foreach ( preg_split( '/[\s,;]+/', (string) $raw ) as $rule ) {
			$rule = strtolower( trim( (string) $rule ) );

			if ( '' === $rule ) {
				continue;
			}

			if ( 0 === strpos( $rule, '*@' ) ) {
				$domain = substr( $rule, 2 );
				if ( '' !== $domain && preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain ) ) {
					$rules[] = '*@' . $domain;
				}
				continue;
			}

			if ( is_email( $rule ) ) {
				$rules[] = $rule;
			}
		}

		return array_values( array_unique( $rules ) );
	}

	/**
	 * Normalised allowlist rules.
	 *
	 * @return string[]
	 */
	public static function allowlist() {
		return self::parse_allowlist( self::allowlist_raw() );
	}

	/**
	 * Whether a recipient may receive mail from this plugin.
	 *
	 * @param string $email Recipient address.
	 * @return bool
	 */
	public static function is_allowed( $email ) {
		$email = strtolower( trim( (string) $email ) );

		if ( ! is_email( $email ) ) {
			return false;
		}

		foreach ( self::allowlist() as $rule ) {
			if ( $rule === $email ) {
				return true;
			}

			if ( 0 === strpos( $rule, '*@' ) ) {
				$suffix = substr( $rule, 1 );
				if ( strlen( $email ) > strlen( $suffix ) && substr( $email, -strlen( $suffix ) ) === $suffix ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Replace the stored allowlist.
	 *
	 * @param string $raw Comma-separated rules.
	 * @return string[]|WP_Error
	 */
	public static function set_allowlist( $raw ) {
		if ( defined( 'EPIC_MAIL_MCP_ALLOW' ) ) {
			return new WP_Error(
				'epic_mail_mcp_allowlist_locked',
				'EPIC_MAIL_MCP_ALLOW is defined in wp-config.php, so the stored allowlist is ignored. Edit the constant instead.',
				array( 'status' => 409 )
			);
		}

		$rules = self::parse_allowlist( $raw );
		update_option( self::ALLOWLIST_OPTION, implode( ',', $rules ), false );

		return $rules;
	}

	/**
	 * Sends allowed per rolling hour.
	 *
	 * @return int
	 */
	public static function max_per_hour() {
		$max = defined( 'EPIC_MAIL_MCP_MAX_PER_HOUR' ) ? (int) EPIC_MAIL_MCP_MAX_PER_HOUR : self::DEFAULT_MAX_PER_HOUR;

		return max( 0, $max );
	}

	/**
	 * Per-user transient key for the rate limit.
	 *
	 * @return string
	 */
	private static function rate_key() {
		return 'epic_mail_mcp_rl_' . get_current_user_id();
	}

	/**
	 * Sends already used in the current window.
	 *
	 * @return int
	 */
	public static function rate_used() {
		return (int) get_transient( self::rate_key() );
	}

	/**
	 * Sends remaining in the current window.
	 *
	 * @return int
	 */
	public static function rate_remaining() {
		return max( 0, self::max_per_hour() - self::rate_used() );
	}

	/**
	 * Consume one send slot.
	 */
	private static function rate_consume() {
		set_transient( self::rate_key(), self::rate_used() + 1, self::RATE_WINDOW );
	}

	/**
	 * Read the WP Mail SMTP settings without assuming a plugin version.
	 *
	 * @return array
	 */
	public static function mailer_settings() {
		$settings = array(
			'mailer'        => '',
			'mailer_label'  => 'unknown',
			'from_email'    => '',
			'from_name'     => '',
			'debug_logging' => false,
			'smtp_host'     => '',
		);

		if ( class_exists( '\WPMailSMTP\Options' ) && method_exists( '\WPMailSMTP\Options', 'init' ) ) {
			$options                   = \WPMailSMTP\Options::init();
			$settings['mailer']        = (string) $options->get( 'mail', 'mailer' );
			$settings['from_email']    = (string) $options->get( 'mail', 'from_email' );
			$settings['from_name']     = (string) $options->get( 'mail', 'from_name' );
			// WP Mail SMTP gates verbose debug events on debug_events.email_debug.
			// Errors are always recorded; only the verbose entries are optional.
			$settings['debug_logging'] = (bool) $options->get( 'debug_events', 'email_debug' );
			$settings['smtp_host']     = (string) $options->get( 'smtp', 'host' );
		} else {
			$option = get_option( 'wp_mail_smtp', array() );
			if ( is_array( $option ) ) {
				$settings['mailer']        = isset( $option['mail']['mailer'] ) ? (string) $option['mail']['mailer'] : '';
				$settings['from_email']    = isset( $option['mail']['from_email'] ) ? (string) $option['mail']['from_email'] : '';
				$settings['from_name']     = isset( $option['mail']['from_name'] ) ? (string) $option['mail']['from_name'] : '';
				$settings['smtp_host']     = isset( $option['smtp']['host'] ) ? (string) $option['smtp']['host'] : '';
				$settings['debug_logging'] = ! empty( $option['debug_events']['email_debug'] );
			}
		}

		$settings['mailer_label'] = self::mailer_label( $settings['mailer'] );

		if ( '' === $settings['from_email'] ) {
			$settings['from_email'] = (string) get_option( 'admin_email' );
		}

		return $settings;
	}

	/**
	 * Active mailer slug.
	 *
	 * @return string
	 */
	public static function mailer_slug() {
		$settings = self::mailer_settings();

		return $settings['mailer'];
	}

	/**
	 * Human label for a mailer slug.
	 *
	 * @param string $slug Mailer slug.
	 * @return string
	 */
	private static function mailer_label( $slug ) {
		$labels = array(
			'smtp'         => 'Other SMTP',
			'gmail'        => 'Gmail',
			'outlook'      => 'Outlook / Office 365',
			'zoho'         => 'Zoho Mail',
			'amazonses'    => 'Amazon SES',
			'sendlayer'    => 'SendLayer',
			'brevo'        => 'Brevo',
			'sendinblue'   => 'Brevo',
			'mailgun'      => 'Mailgun',
			'sendgrid'     => 'SendGrid',
			'smtpcom'      => 'SMTP.com',
			'sparkpost'    => 'SparkPost',
			'postmark'     => 'Postmark',
			'mailjet'      => 'Mailjet',
			'elasticemail' => 'Elastic Email',
			'mandrill'     => 'Mailchimp Transactional',
			'loops'        => 'Loops',
			'mail'         => 'PHP mail()',
		);

		$slug = strtolower( (string) $slug );

		if ( '' === $slug ) {
			return 'unknown';
		}

		return isset( $labels[ $slug ] ) ? $labels[ $slug ] : $slug;
	}

	/**
	 * Newest error recorded by WP Mail SMTP debug events.
	 *
	 * WP Mail SMTP stores `event_type` as an integer (0 = error, 1 = debug), so
	 * this prefers the plugin's own collection class and only falls back to a
	 * raw query when that class is unavailable.
	 *
	 * @return array|null
	 */
	public static function last_recorded_error() {
		if (
			class_exists( '\WPMailSMTP\Admin\DebugEvents\EventsCollection' ) &&
			class_exists( '\WPMailSMTP\Admin\DebugEvents\Event' )
		) {
			try {
				$collection = new \WPMailSMTP\Admin\DebugEvents\EventsCollection(
					array(
						'per_page' => 5,
						'offset'   => 0,
						'type'     => \WPMailSMTP\Admin\DebugEvents\Event::TYPE_ERROR,
					)
				);

				foreach ( $collection->get() as $event ) {
					$created = $event->get_created_at();

					return array(
						'created_at' => $created ? $created->format( 'c' ) : '',
						'severity'   => 'error',
						'content'    => wp_strip_all_tags( (string) $event->get_content() ),
					);
				}

				return null;
			} catch ( \Throwable $e ) {
				// Fall through to the raw query below.
			}
		}

		return self::last_recorded_error_from_db();
	}

	/**
	 * Raw fallback for reading the newest debug event error.
	 *
	 * @return array|null
	 */
	private static function last_recorded_error_from_db() {
		global $wpdb;

		$table = $wpdb->prefix . 'wpmailsmtp_debug_events';
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		if ( ! $found ) {
			return null;
		}

		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 50", ARRAY_A );
		if ( empty( $rows ) ) {
			return null;
		}

		foreach ( (array) $rows as $row ) {
			$is_error = false;

			if ( isset( $row['event_type'] ) ) {
				$type     = $row['event_type'];
				$is_error = is_numeric( $type )
					? ( 0 === (int) $type )
					: ( 'error' === strtolower( (string) $type ) );
			} elseif ( isset( $row['severity'] ) ) {
				$is_error = ( 'error' === strtolower( (string) $row['severity'] ) );
			}

			if ( ! $is_error ) {
				continue;
			}

			$created = '';
			foreach ( array( 'created_at', 'created_date', 'date' ) as $key ) {
				if ( ! empty( $row[ $key ] ) ) {
					$created = (string) $row[ $key ];
					break;
				}
			}

			return array(
				'created_at' => $created,
				'severity'   => 'error',
				'content'    => isset( $row['content'] ) ? wp_strip_all_tags( (string) $row['content'] ) : '',
			);
		}

		return null;
	}

	/**
	 * Mask an address for display: l***@g***.com.
	 *
	 * @param string $email Address.
	 * @return string
	 */
	public static function mask_email( $email ) {
		$email = (string) $email;
		$at    = strrpos( $email, '@' );

		if ( false === $at ) {
			return str_repeat( '*', max( 3, strlen( $email ) ) );
		}

		$local  = substr( $email, 0, $at );
		$domain = substr( $email, $at + 1 );
		$dot    = strrpos( $domain, '.' );
		$host   = false === $dot ? $domain : substr( $domain, 0, $dot );
		$tld    = false === $dot ? '' : substr( $domain, $dot );

		return substr( $local, 0, 1 ) . '***@' . substr( $host, 0, 1 ) . '***' . $tld;
	}

	/**
	 * Best available error text for the last failed send.
	 *
	 * @return string
	 */
	private static function last_error_message() {
		if ( '' !== self::$last_error ) {
			return self::$last_error;
		}

		if ( is_object( self::$phpmailer ) && ! empty( self::$phpmailer->ErrorInfo ) ) {
			return wp_strip_all_tags( (string) self::$phpmailer->ErrorInfo );
		}

		return 'wp_mail() returned false without an error message.';
	}

	/**
	 * Send one message after every rail has been checked.
	 *
	 * @param array $args to, subject, html, text, template, reply_to.
	 * @return array|WP_Error
	 */
	public static function send( $args ) {
		$to       = strtolower( trim( (string) ( isset( $args['to'] ) ? $args['to'] : '' ) ) );
		$subject  = (string) ( isset( $args['subject'] ) ? $args['subject'] : '' );
		$html     = (string) ( isset( $args['html'] ) ? $args['html'] : '' );
		$text     = (string) ( isset( $args['text'] ) ? $args['text'] : '' );
		$template = (string) ( isset( $args['template'] ) ? $args['template'] : '' );
		$reply_to = strtolower( trim( (string) ( isset( $args['reply_to'] ) ? $args['reply_to'] : '' ) ) );

		if ( self::is_disabled() ) {
			return new WP_Error(
				'epic_mail_mcp_disabled',
				'EPIC Mail MCP is disabled by EPIC_MAIL_MCP_DISABLED.',
				array( 'status' => 503 )
			);
		}

		if ( ! is_email( $to ) ) {
			Epic_Mail_MCP_Audit::record( $to, $subject, $template, 'denied', 'Invalid recipient address.' );

			return new WP_Error(
				'epic_mail_mcp_invalid_recipient',
				'to must be a valid email address.',
				array( 'status' => 400 )
			);
		}

		if ( ! self::is_allowed( $to ) ) {
			Epic_Mail_MCP_Audit::record( $to, $subject, $template, 'denied', 'epic_mail_mcp_recipient_blocked' );

			return new WP_Error(
				'epic_mail_mcp_recipient_blocked',
				'Recipient is not in the EPIC Mail MCP allowlist. Add it with set-allowlist, or define EPIC_MAIL_MCP_ALLOW in wp-config.php.',
				array( 'status' => 403 )
			);
		}

		if ( '' !== $reply_to && ! is_email( $reply_to ) ) {
			Epic_Mail_MCP_Audit::record( $to, $subject, $template, 'denied', 'Invalid Reply-To address.' );

			return new WP_Error(
				'epic_mail_mcp_invalid_reply_to',
				'reply_to must be a valid email address.',
				array( 'status' => 400 )
			);
		}

		if ( self::rate_remaining() <= 0 ) {
			Epic_Mail_MCP_Audit::record( $to, $subject, $template, 'denied', 'epic_mail_mcp_rate_limited' );

			return new WP_Error(
				'epic_mail_mcp_rate_limited',
				'Hourly send limit reached (' . self::max_per_hour() . '). Try again later.',
				array( 'status' => 429 )
			);
		}

		self::$last_error = '';
		self::$phpmailer  = null;
		self::$alt_body   = $text;

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( '' !== $reply_to ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		$sent = wp_mail( $to, $subject, $html, $headers );

		self::$alt_body = '';

		if ( ! $sent ) {
			$error = self::last_error_message();
			Epic_Mail_MCP_Audit::record( $to, $subject, $template, 'failed', $error );

			return new WP_Error( 'epic_mail_mcp_send_failed', $error, array( 'status' => 502 ) );
		}

		self::rate_consume();
		Epic_Mail_MCP_Audit::record( $to, $subject, $template, 'sent', '' );

		return array(
			'to'                   => $to,
			'subject'              => $subject,
			'template'             => '' === $template ? null : $template,
			'sent'                 => true,
			'mailer'               => self::mailer_slug(),
			'rate_limit_remaining' => self::rate_remaining(),
		);
	}
}
