<?php
/**
 * Admin-facing "new newsletter subscriber" notification — sent to the
 * store's marketing team, NOT the subscriber, whenever someone signs up via
 * the website footer's subscription box. Registered as an ordinary WC_Email
 * (like every other EPIC email) so WooCommerce → Settings → Emails → EPIC:
 * Newsletter Subscriber is the only admin screen needed for
 * subject/heading/recipient — the shared secret that authenticates the
 * *incoming* REST call is the only thing configured elsewhere
 * (class-settings.php).
 *
 * Unlike epic-order-emails' two customer emails, this one has no WC_Order
 * to hang off of — a subscriber isn't a WooCommerce entity. So instead of
 * $this->object being a WC_Order, the subscription fields are held on
 * public properties set by trigger() and read directly by the templates
 * (same pattern as epic-wholesale-inquiries' email).
 *
 * @package Epic_Newsletter_Subscription
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Email_Newsletter_Subscription extends WC_Email {

	/** @var string */
	public $email = '';

	/** @var string Storefront locale the subscription was made in (informational only). */
	public $subscriber_locale = '';

	/** @var string MySQL datetime string, site timezone. */
	public $subscribed_at = '';

	public function __construct() {
		$this->id          = 'epic_newsletter_subscription';
		$this->title       = __( 'EPIC: Newsletter Subscriber', 'epic-newsletter-subscription' );
		$this->description = __( 'Sent to the marketing team whenever someone subscribes via the website footer\'s newsletter box — their email address and the time of the subscription. This is an admin notification, not a customer-facing email.', 'epic-newsletter-subscription' );

		// Admin-type email: recipient defaults to the site admin address but
		// stays editable via the "Recipient(s)" field this adds in
		// init_form_fields() — same convention WooCommerce's own
		// WC_Email_New_Order uses for admin notifications.
		$this->customer_email = false;
		// Vietnamese-only content — same decision as every other EPIC email
		// (see epic-wholesale-inquiries' readme): staff read Vietnamese
		// regardless of which storefront locale the subscriber submitted
		// from, and there's no .mo translation pipeline in this project, so
		// the Vietnamese text is hard-coded directly as the msgid rather
		// than routed through gettext. Only the settings-screen field
		// labels/descriptions below (init_form_fields, $description) stay in
		// English, since those aren't email content.
		$this->heading        = __( 'Người đăng ký nhận tin mới', 'epic-newsletter-subscription' );
		$this->subject        = __( '[{site_title}] Đăng ký nhận tin mới — {subscriber_email}', 'epic-newsletter-subscription' );

		$this->template_html  = 'emails/admin-newsletter-subscription.php';
		$this->template_plain = 'emails/plain/admin-newsletter-subscription.php';
		$this->template_base  = EPIC_NEWSLETTER_SUBSCRIPTION_DIR . 'templates/';
		$this->placeholders   = array(
			'{site_title}'        => $this->get_blogname(),
			'{subscriber_email}'  => '',
		);

		add_action( 'epic_newsletter_subscription_received', array( $this, 'trigger' ), 10, 1 );

		parent::__construct();

		// WC_Email's constructor sets $this->recipient = get_option('admin_email')
		// by default; the 'recipient' form field below lets an admin override
		// it without touching code.
		$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
	}

	public function get_default_subject() {
		return __( '[{site_title}] Đăng ký nhận tin mới — {subscriber_email}', 'epic-newsletter-subscription' );
	}

	public function get_default_heading() {
		return __( 'Người đăng ký nhận tin mới', 'epic-newsletter-subscription' );
	}

	/**
	 * @param array $data {
	 *     @type int    $id             Row id in the epic_newsletter_subscribers table (class-store.php) — 0 if that insert failed. Used only to report the delivery result back onto the row; falsy is a safe no-op everywhere it's used.
	 *     @type string $email
	 *     @type string $locale
	 *     @type string $subscribed_at
	 * }
	 */
	public function trigger( $data ) {
		$this->setup_locale();

		if ( ! is_array( $data ) || empty( $data['email'] ) ) {
			$this->restore_locale();
			return;
		}

		$subscriber_id = isset( $data['id'] ) ? (int) $data['id'] : 0;

		$this->email            = (string) $data['email'];
		$this->subscriber_locale = isset( $data['locale'] ) ? (string) $data['locale'] : 'unknown';
		$this->subscribed_at    = isset( $data['subscribed_at'] ) ? (string) $data['subscribed_at'] : current_time( 'mysql' );

		$this->placeholders['{subscriber_email}'] = $this->email;

		// Checked before the recipient/send logic below (unlike the other
		// EPIC emails, which don't need this distinction) specifically so
		// the stored row can say *why* no email went out — "disabled" is a
		// deliberate admin choice, "failed" is something that needs
		// attention (bad recipient, wp_mail() failure).
		if ( ! $this->is_enabled() ) {
			Epic_Newsletter_Store::mark_email_status( $subscriber_id, Epic_Newsletter_Store::STATUS_DISABLED );
			$this->restore_locale();
			return;
		}

		// Recipient is read fresh here (rather than only in __construct)
		// so a change saved on the settings screen takes effect immediately,
		// without needing the object re-constructed.
		$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );

		if ( empty( $this->recipient ) ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					sprintf( 'Newsletter subscription for "%s" received but no recipient is configured (WooCommerce → Settings → Emails → EPIC: Newsletter Subscriber, or admin_email is empty).', $this->email ),
					array( 'source' => 'epic-newsletter-subscription' )
				);
			}
			Epic_Newsletter_Store::mark_email_status( $subscriber_id, Epic_Newsletter_Store::STATUS_FAILED );
			$this->restore_locale();
			return;
		}

		$sent = $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );

		Epic_Newsletter_Store::mark_email_status(
			$subscriber_id,
			$sent ? Epic_Newsletter_Store::STATUS_SENT : Epic_Newsletter_Store::STATUS_FAILED
		);

		if ( ! $sent && function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error(
				sprintf( 'Newsletter subscription notification failed to send for "%s".', $this->email ),
				array( 'source' => 'epic-newsletter-subscription' )
			);
		}

		$this->restore_locale();
	}

	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			array(
				'subscriber_email'   => $this->email,
				'subscriber_locale'  => $this->subscriber_locale,
				'subscribed_at'      => $this->subscribed_at,
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => true,
				'plain_text'         => false,
				'email'              => $this,
			),
			'',
			$this->template_base
		);
	}

	public function get_content_plain() {
		return wc_get_template_html(
			$this->template_plain,
			array(
				'subscriber_email'   => $this->email,
				'subscriber_locale'  => $this->subscriber_locale,
				'subscribed_at'      => $this->subscribed_at,
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => true,
				'plain_text'         => true,
				'email'              => $this,
			),
			'',
			$this->template_base
		);
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'   => array(
				'title'   => __( 'Enable/Disable', 'epic-newsletter-subscription' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable this email notification', 'epic-newsletter-subscription' ),
				'default' => 'yes',
			),
			'recipient' => array(
				'title'       => __( 'Recipient(s)', 'epic-newsletter-subscription' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => sprintf(
					/* translators: %s: default admin email */
					__( 'Comma-separated list of email addresses. Defaults to %s.', 'epic-newsletter-subscription' ),
					esc_html( get_option( 'admin_email' ) )
				),
				'placeholder' => get_option( 'admin_email' ),
				'default'     => '',
			),
			'subject'   => array(
				'title'       => __( 'Subject', 'epic-newsletter-subscription' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => sprintf( __( 'Available placeholders: %s', 'epic-newsletter-subscription' ), '{site_title}, {subscriber_email}' ),
				'placeholder' => $this->get_default_subject(),
				'default'     => '',
			),
			'heading'   => array(
				'title'       => __( 'Email heading', 'epic-newsletter-subscription' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => sprintf( __( 'Available placeholders: %s', 'epic-newsletter-subscription' ), '{site_title}, {subscriber_email}' ),
				'placeholder' => $this->get_default_heading(),
				'default'     => '',
			),
			'additional_content' => array(
				'title'       => __( 'Additional content', 'epic-newsletter-subscription' ),
				'description' => __( 'Text appended to the bottom of the email.', 'epic-newsletter-subscription' ),
				'css'         => 'width:400px; height: 75px;',
				'placeholder' => __( 'N/A', 'epic-newsletter-subscription' ),
				'type'        => 'textarea',
				'default'     => '',
				'desc_tip'    => true,
			),
			'email_type' => array(
				'title'       => __( 'Email type', 'epic-newsletter-subscription' ),
				'type'        => 'select',
				'description' => __( 'Choose which format of email to send.', 'epic-newsletter-subscription' ),
				'default'     => 'html',
				'class'       => 'email_type wc-enhanced-select',
				'options'     => $this->get_email_type_options(),
				'desc_tip'    => true,
			),
		);
	}

	/** No native WooCommerce equivalent to collide with — safe to ship enabled. */
	public function is_enabled() {
		$enabled = $this->get_option( 'enabled', 'yes' );
		return apply_filters( 'woocommerce_email_enabled_' . $this->id, 'yes' === $enabled, null, $this );
	}
}
