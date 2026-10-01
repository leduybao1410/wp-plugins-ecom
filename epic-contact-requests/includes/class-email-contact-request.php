<?php
/**
 * Admin-facing "new contact request" notification — sent to the store's
 * team, NOT the customer, whenever someone submits the /contact page's form.
 * Registered as an ordinary WC_Email (like every other EPIC email) so
 * WooCommerce → Settings → Emails → EPIC: Contact Request is the only admin
 * screen needed for subject/heading/recipient — the shared secret that
 * authenticates the *incoming* REST call is the only thing configured
 * elsewhere (class-settings.php).
 *
 * Unlike epic-order-emails' customer emails, this one has no WC_Order to
 * hang off of — a contact lead isn't a WooCommerce entity. So instead of
 * $this->object being a WC_Order, the request fields are held on public
 * properties set by trigger() and read directly by the templates.
 *
 * Staff-facing content stays Vietnamese on purpose (same decision as
 * epic-wholesale-inquiries' admin email), so the strings are hard-coded in
 * the templates rather than routed through the shared epic-email-i18n.php
 * map — which is guarded by function_exists() and would be skipped if
 * another EPIC plugin had already loaded it.
 *
 * @package Epic_Contact_Requests
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Email_Contact_Request extends WC_Email {

	/** @var string */
	public $name = '';

	/** @var string Optional — may be empty, it's optional on the form. */
	public $email = '';

	/** @var string Phone number the lead provided. */
	public $phone = '';

	/** @var bool Whether the lead asked for a direct (in-person) consultation. */
	public $direct_consult = false;

	/** @var string Province/city — only set for a direct consultation (HCMC only). */
	public $province = '';

	/** @var string Ward/commune — only set for a direct consultation. */
	public $ward = '';

	/** @var string Street line — only set for a direct consultation. */
	public $street = '';

	/** @var string Free-text message — may be empty, it's optional on the form. */
	public $message = '';

	/** @var string Storefront locale the lead submitted from (informational only). */
	public $lead_locale = '';

	/** @var string MySQL datetime string, site timezone. */
	public $submitted_at = '';

	public function __construct() {
		$this->id          = 'epic_contact_request';
		$this->title       = __( 'EPIC: Contact Request', 'epic-contact-requests' );
		$this->description = __( 'Sent to the store team whenever someone submits the website\'s /contact form — name, contact info, an optional message, and an optional request for a direct consultation in Ho Chi Minh City. This is an admin notification, not a customer-facing email.', 'epic-contact-requests' );

		// Admin-type email: recipient defaults to the site admin address but
		// stays editable via the "Recipient(s)" field this adds in
		// init_form_fields() — same convention WooCommerce's own
		// WC_Email_New_Order uses for admin notifications.
		$this->customer_email = false;
		// Vietnamese-only content — staff read Vietnamese regardless of which
		// storefront locale the lead submitted from.
		$this->heading        = __( 'Yêu cầu liên hệ mới', 'epic-contact-requests' );
		$this->subject        = __( '[{site_title}] Yêu cầu liên hệ mới — {name}', 'epic-contact-requests' );

		$this->template_html  = 'emails/admin-contact-request.php';
		$this->template_plain = 'emails/plain/admin-contact-request.php';
		$this->template_base  = EPIC_CONTACT_REQUESTS_DIR . 'templates/';
		$this->placeholders   = array(
			'{site_title}' => $this->get_blogname(),
			'{name}'       => '',
		);

		add_action( 'epic_contact_request_received', array( $this, 'trigger' ), 10, 1 );

		parent::__construct();

		// WC_Email's constructor sets $this->recipient = get_option('admin_email')
		// by default; the 'recipient' form field below lets an admin override
		// it without touching code.
		$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
	}

	public function get_default_subject() {
		return __( '[{site_title}] Yêu cầu liên hệ mới — {name}', 'epic-contact-requests' );
	}

	public function get_default_heading() {
		return __( 'Yêu cầu liên hệ mới', 'epic-contact-requests' );
	}

	/**
	 * @param array $data {
	 *     @type int    $id             Row id in the epic_contact_requests table (class-store.php) — 0 if that insert failed.
	 *     @type string $name
	 *     @type string $email
	 *     @type string $phone
	 *     @type int    $direct_consult
	 *     @type string $province
	 *     @type string $ward
	 *     @type string $street
	 *     @type string $message
	 *     @type string $locale
	 *     @type string $submitted_at
	 * }
	 */
	public function trigger( $data ) {
		$this->setup_locale();

		if ( ! is_array( $data ) || empty( $data['name'] ) || empty( $data['phone'] ) ) {
			$this->restore_locale();
			return;
		}

		$request_id = isset( $data['id'] ) ? (int) $data['id'] : 0;

		$this->name           = (string) $data['name'];
		$this->email          = isset( $data['email'] ) ? (string) $data['email'] : '';
		$this->phone          = (string) $data['phone'];
		$this->direct_consult = ! empty( $data['direct_consult'] );
		$this->province       = isset( $data['province'] ) ? (string) $data['province'] : '';
		$this->ward           = isset( $data['ward'] ) ? (string) $data['ward'] : '';
		$this->street         = isset( $data['street'] ) ? (string) $data['street'] : '';
		$this->message        = isset( $data['message'] ) ? (string) $data['message'] : '';
		$this->lead_locale    = isset( $data['locale'] ) ? (string) $data['locale'] : 'unknown';
		$this->submitted_at   = isset( $data['submitted_at'] ) ? (string) $data['submitted_at'] : current_time( 'mysql' );

		$this->placeholders['{name}'] = $this->name;

		// Checked before the recipient/send logic below specifically so the
		// stored row can say *why* no email went out — "disabled" is a
		// deliberate admin choice, "failed" is something that needs attention.
		if ( ! $this->is_enabled() ) {
			Epic_Contact_Store::mark_email_status( $request_id, Epic_Contact_Store::STATUS_DISABLED );
			$this->restore_locale();
			return;
		}

		// Recipient is read fresh here (rather than only in __construct) so a
		// change saved on the settings screen takes effect immediately.
		$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );

		if ( empty( $this->recipient ) ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					sprintf( 'Contact request from "%s" received but no recipient is configured (WooCommerce → Settings → Emails → EPIC: Contact Request, or admin_email is empty).', $this->name ),
					array( 'source' => 'epic-contact-requests' )
				);
			}
			Epic_Contact_Store::mark_email_status( $request_id, Epic_Contact_Store::STATUS_FAILED );
			$this->restore_locale();
			return;
		}

		$sent = $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );

		Epic_Contact_Store::mark_email_status(
			$request_id,
			$sent ? Epic_Contact_Store::STATUS_SENT : Epic_Contact_Store::STATUS_FAILED
		);

		if ( ! $sent && function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error(
				sprintf( 'Contact request notification failed to send for "%s".', $this->name ),
				array( 'source' => 'epic-contact-requests' )
			);
		}

		$this->restore_locale();
	}

	/** Human-readable full address for the notification email, or '' when not a direct consultation. */
	public function get_address_line() {
		if ( ! $this->direct_consult ) {
			return '';
		}
		$parts = array_filter( array( $this->street, $this->ward, $this->province ) );
		return implode( ', ', $parts );
	}

	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			array(
				'name'           => $this->name,
				'lead_email'     => $this->email,
				'phone'          => $this->phone,
				'direct_consult' => $this->direct_consult,
				'address_line'   => $this->get_address_line(),
				'message'        => $this->message,
				'lead_locale'    => $this->lead_locale,
				'submitted_at'   => $this->submitted_at,
				'email_heading'  => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'  => true,
				'plain_text'     => false,
				'email'          => $this,
			),
			'',
			$this->template_base
		);
	}

	public function get_content_plain() {
		return wc_get_template_html(
			$this->template_plain,
			array(
				'name'           => $this->name,
				'lead_email'     => $this->email,
				'phone'          => $this->phone,
				'direct_consult' => $this->direct_consult,
				'address_line'   => $this->get_address_line(),
				'message'        => $this->message,
				'lead_locale'    => $this->lead_locale,
				'submitted_at'   => $this->submitted_at,
				'email_heading'  => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'  => true,
				'plain_text'     => true,
				'email'          => $this,
			),
			'',
			$this->template_base
		);
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'   => array(
				'title'   => __( 'Enable/Disable', 'epic-contact-requests' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable this email notification', 'epic-contact-requests' ),
				'default' => 'yes',
			),
			'recipient' => array(
				'title'       => __( 'Recipient(s)', 'epic-contact-requests' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => sprintf(
					/* translators: %s: default admin email */
					__( 'Comma-separated list of email addresses. Defaults to %s.', 'epic-contact-requests' ),
					esc_html( get_option( 'admin_email' ) )
				),
				'placeholder' => get_option( 'admin_email' ),
				'default'     => '',
			),
			'subject'   => array(
				'title'       => __( 'Subject', 'epic-contact-requests' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => sprintf( __( 'Available placeholders: %s', 'epic-contact-requests' ), '{site_title}, {name}' ),
				'placeholder' => $this->get_default_subject(),
				'default'     => '',
			),
			'heading'   => array(
				'title'       => __( 'Email heading', 'epic-contact-requests' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => sprintf( __( 'Available placeholders: %s', 'epic-contact-requests' ), '{site_title}, {name}' ),
				'placeholder' => $this->get_default_heading(),
				'default'     => '',
			),
			'additional_content' => array(
				'title'       => __( 'Additional content', 'epic-contact-requests' ),
				'description' => __( 'Text appended to the bottom of the email.', 'epic-contact-requests' ),
				'css'         => 'width:400px; height: 75px;',
				'placeholder' => __( 'N/A', 'epic-contact-requests' ),
				'type'        => 'textarea',
				'default'     => '',
				'desc_tip'    => true,
			),
			'email_type' => array(
				'title'       => __( 'Email type', 'epic-contact-requests' ),
				'type'        => 'select',
				'description' => __( 'Choose which format of email to send.', 'epic-contact-requests' ),
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
