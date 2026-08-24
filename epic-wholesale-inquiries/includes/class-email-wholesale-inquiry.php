<?php
/**
 * Admin-facing "new wholesale inquiry" notification — sent to the store's
 * wholesale team, NOT the customer, whenever a lead submits the /wholesale
 * page's contact form. Registered as an ordinary WC_Email (like every other
 * EPIC email) so WooCommerce → Settings → Emails → EPIC: Wholesale Inquiry
 * is the only admin screen needed for subject/heading/recipient — the
 * shared secret that authenticates the *incoming* REST call is the only
 * thing configured elsewhere (class-settings.php).
 *
 * Unlike epic-order-emails' two customer emails, this one has no WC_Order
 * to hang off of — a wholesale lead isn't a WooCommerce entity. So instead
 * of $this->object being a WC_Order, the inquiry fields are held on public
 * properties set by trigger() and read directly by the templates.
 *
 * @package Epic_Wholesale_Inquiries
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Email_Wholesale_Inquiry extends WC_Email {

	/** @var string */
	public $business_name = '';

	/** @var string Phone number the lead provided. */
	public $phone = '';

	/** @var string Email or Zalo the lead provided. */
	public $contact = '';

	/** @var string Raw topic value (wholesale|oem|setup|other). */
	public $topic = '';

	/** @var string Vietnamese label for $topic, precomputed website-side. */
	public $topic_label_vi = '';

	/** @var string Free-text "what do you need?" field — may be empty, it's optional on the form. */
	public $details = '';

	/** @var string Storefront locale the lead submitted from (informational only). */
	public $lead_locale = '';

	/** @var string MySQL datetime string, site timezone. */
	public $submitted_at = '';

	public function __construct() {
		$this->id          = 'epic_wholesale_inquiry';
		$this->title       = __( 'EPIC: Wholesale Inquiry', 'epic-wholesale-inquiries' );
		$this->description = __( 'Sent to the wholesale team whenever a lead submits the website\'s /wholesale contact form — business name, contact info, topic, and their message. This is an admin notification, not a customer-facing email.', 'epic-wholesale-inquiries' );

		// Admin-type email: recipient defaults to the site admin address but
		// stays editable via the "Recipient(s)" field this adds in
		// init_form_fields() — same convention WooCommerce's own
		// WC_Email_New_Order uses for admin notifications.
		$this->customer_email = false;
		// Vietnamese-only content — same decision as epic-order-emails'
		// customer emails (see that plugin's readme/PLAN.md): staff read
		// Vietnamese regardless of which storefront locale the lead
		// submitted from, and there's no .mo translation pipeline in this
		// project, so the Vietnamese text is hard-coded directly as the
		// msgid rather than routed through gettext. Only the settings-screen
		// field labels/descriptions below (init_form_fields, $description)
		// stay in English, since those aren't email content.
		$this->heading        = __( 'Yêu cầu báo giá sỉ mới', 'epic-wholesale-inquiries' );
		$this->subject        = __( '[{site_title}] Yêu cầu báo giá sỉ mới — {business_name}', 'epic-wholesale-inquiries' );

		$this->template_html  = 'emails/admin-wholesale-inquiry.php';
		$this->template_plain = 'emails/plain/admin-wholesale-inquiry.php';
		$this->template_base  = EPIC_WHOLESALE_INQUIRIES_DIR . 'templates/';
		$this->placeholders   = array(
			'{site_title}'    => $this->get_blogname(),
			'{business_name}' => '',
		);

		add_action( 'epic_wholesale_inquiry_received', array( $this, 'trigger' ), 10, 1 );

		parent::__construct();

		// WC_Email's constructor sets $this->recipient = get_option('admin_email')
		// by default; the 'recipient' form field below lets an admin override
		// it without touching code.
		$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
	}

	public function get_default_subject() {
		return __( '[{site_title}] Yêu cầu báo giá sỉ mới — {business_name}', 'epic-wholesale-inquiries' );
	}

	public function get_default_heading() {
		return __( 'Yêu cầu báo giá sỉ mới', 'epic-wholesale-inquiries' );
	}

	/**
	 * @param array $data {
	 *     @type int    $id             Row id in the epic_wholesale_inquiries table (class-store.php) — 0 if that insert failed. Used only to report the delivery result back onto the row; falsy is a safe no-op everywhere it's used.
	 *     @type string $business_name
	 *     @type string $phone
	 *     @type string $contact
	 *     @type string $topic
	 *     @type string $topic_label_vi
	 *     @type string $details
	 *     @type string $locale
	 *     @type string $submitted_at
	 * }
	 */
	public function trigger( $data ) {
		$this->setup_locale();

		if ( ! is_array( $data ) || empty( $data['business_name'] ) || empty( $data['contact'] ) ) {
			$this->restore_locale();
			return;
		}

		$inquiry_id = isset( $data['id'] ) ? (int) $data['id'] : 0;

		$this->business_name   = (string) $data['business_name'];
		$this->phone           = isset( $data['phone'] ) ? (string) $data['phone'] : '';
		$this->contact         = (string) $data['contact'];
		$this->topic           = isset( $data['topic'] ) ? (string) $data['topic'] : '';
		$this->topic_label_vi  = isset( $data['topic_label_vi'] ) ? (string) $data['topic_label_vi'] : $this->topic;
		$this->details         = isset( $data['details'] ) ? (string) $data['details'] : '';
		$this->lead_locale     = isset( $data['locale'] ) ? (string) $data['locale'] : 'unknown';
		$this->submitted_at    = isset( $data['submitted_at'] ) ? (string) $data['submitted_at'] : current_time( 'mysql' );

		$this->placeholders['{business_name}'] = $this->business_name;

		// Checked before the recipient/send logic below (unlike the other
		// EPIC emails, which don't need this distinction) specifically so
		// the stored row can say *why* no email went out — "disabled" is a
		// deliberate admin choice, "failed" is something that needs
		// attention (bad recipient, wp_mail() failure).
		if ( ! $this->is_enabled() ) {
			Epic_Wholesale_Store::mark_email_status( $inquiry_id, Epic_Wholesale_Store::STATUS_DISABLED );
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
					sprintf( 'Wholesale inquiry from "%s" received but no recipient is configured (WooCommerce → Settings → Emails → EPIC: Wholesale Inquiry, or admin_email is empty).', $this->business_name ),
					array( 'source' => 'epic-wholesale-inquiries' )
				);
			}
			Epic_Wholesale_Store::mark_email_status( $inquiry_id, Epic_Wholesale_Store::STATUS_FAILED );
			$this->restore_locale();
			return;
		}

		$sent = $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );

		Epic_Wholesale_Store::mark_email_status(
			$inquiry_id,
			$sent ? Epic_Wholesale_Store::STATUS_SENT : Epic_Wholesale_Store::STATUS_FAILED
		);

		if ( ! $sent && function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error(
				sprintf( 'Wholesale inquiry notification failed to send for "%s".', $this->business_name ),
				array( 'source' => 'epic-wholesale-inquiries' )
			);
		}

		$this->restore_locale();
	}

	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			array(
				'business_name'      => $this->business_name,
				'phone'              => $this->phone,
				'contact'            => $this->contact,
				'topic'              => $this->topic,
				'topic_label_vi'     => $this->topic_label_vi,
				'details'            => $this->details,
				'lead_locale'        => $this->lead_locale,
				'submitted_at'       => $this->submitted_at,
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
				'business_name'      => $this->business_name,
				'phone'              => $this->phone,
				'contact'            => $this->contact,
				'topic'              => $this->topic,
				'topic_label_vi'     => $this->topic_label_vi,
				'details'            => $this->details,
				'lead_locale'        => $this->lead_locale,
				'submitted_at'       => $this->submitted_at,
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
				'title'   => __( 'Enable/Disable', 'epic-wholesale-inquiries' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable this email notification', 'epic-wholesale-inquiries' ),
				'default' => 'yes',
			),
			'recipient' => array(
				'title'       => __( 'Recipient(s)', 'epic-wholesale-inquiries' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => sprintf(
					/* translators: %s: default admin email */
					__( 'Comma-separated list of email addresses. Defaults to %s.', 'epic-wholesale-inquiries' ),
					esc_html( get_option( 'admin_email' ) )
				),
				'placeholder' => get_option( 'admin_email' ),
				'default'     => '',
			),
			'subject'   => array(
				'title'       => __( 'Subject', 'epic-wholesale-inquiries' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => sprintf( __( 'Available placeholders: %s', 'epic-wholesale-inquiries' ), '{site_title}, {business_name}' ),
				'placeholder' => $this->get_default_subject(),
				'default'     => '',
			),
			'heading'   => array(
				'title'       => __( 'Email heading', 'epic-wholesale-inquiries' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => sprintf( __( 'Available placeholders: %s', 'epic-wholesale-inquiries' ), '{site_title}, {business_name}' ),
				'placeholder' => $this->get_default_heading(),
				'default'     => '',
			),
			'additional_content' => array(
				'title'       => __( 'Additional content', 'epic-wholesale-inquiries' ),
				'description' => __( 'Text appended to the bottom of the email.', 'epic-wholesale-inquiries' ),
				'css'         => 'width:400px; height: 75px;',
				'placeholder' => __( 'N/A', 'epic-wholesale-inquiries' ),
				'type'        => 'textarea',
				'default'     => '',
				'desc_tip'    => true,
			),
			'email_type' => array(
				'title'       => __( 'Email type', 'epic-wholesale-inquiries' ),
				'type'        => 'select',
				'description' => __( 'Choose which format of email to send.', 'epic-wholesale-inquiries' ),
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
