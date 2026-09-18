<?php
/**
 * Admin-facing "new free-sample request" notification — sent to the store's
 * team, NOT the customer, whenever a visitor submits the /roastery page's
 * sample form. Registered as an ordinary WC_Email (like every other EPIC
 * email) so WooCommerce → Settings → Emails → EPIC: Sample Request is the
 * only admin screen needed for subject/heading/recipient — the shared
 * secret that authenticates the *incoming* REST call is the only thing
 * configured elsewhere (class-settings.php).
 *
 * Like epic-wholesale-inquiries' notification, there's no WC_Order to hang
 * off of — a sample request isn't a WooCommerce entity. So instead of
 * $this->object being a WC_Order, the request fields are held on public
 * properties set by trigger() and read directly by the templates.
 *
 * @package Epic_Sample_Requests
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Email_Sample_Request extends WC_Email {

	/** @var string */
	public $name = '';

	/** @var string Email address the requester provided (also receives the thank-you email — see class-email-sample-confirmation.php). */
	public $requester_email = '';

	/** @var string Phone number the requester provided. */
	public $phone = '';

	/** @var string Free-text street line. */
	public $street = '';

	/** @var string Ward/commune name (post-merger format). */
	public $ward = '';

	/** @var string Province/city name (post-merger format). */
	public $province = '';

	/** @var string One-line delivery address assembled from the structured parts. */
	public $address = '';

	/** @var string Raw taste value (robusta_natural|robusta_honey|arabica_vn). */
	public $taste = '';

	/** @var string Vietnamese label for $taste, precomputed website-side. */
	public $taste_label_vi = '';

	/** @var string Raw brew value (phin|espresso|coldbrew|pourover) — optional on the form. */
	public $brew = '';

	/** @var string Vietnamese label for $brew, precomputed website-side. */
	public $brew_label_vi = '';

	/** @var string Storefront locale the requester submitted from (informational only). */
	public $request_locale = '';

	/** @var string MySQL datetime string, site timezone. */
	public $submitted_at = '';

	public function __construct() {
		$this->id          = 'epic_sample_request';
		$this->title       = __( 'EPIC: Sample Request', 'epic-sample-requests' );
		$this->description = __( 'Sent to the store team whenever a visitor submits the website\'s free-coffee-sample form on the /roastery page — name, phone, delivery address and favourite taste. This is an admin notification, not a customer-facing email.', 'epic-sample-requests' );

		// Admin-type email: recipient defaults to the site admin address but
		// stays editable via the "Recipient(s)" field this adds in
		// init_form_fields() — same convention as every other EPIC admin email.
		$this->customer_email = false;
		// Vietnamese-only content — same decision as epic-order-emails/
		// epic-wholesale-inquiries: staff read Vietnamese regardless of which
		// storefront locale the requester submitted from, and there's no .mo
		// translation pipeline in this project, so the Vietnamese text is
		// hard-coded directly as the msgid rather than routed through
		// gettext. Only the settings-screen field labels/descriptions below
		// stay in English, since those aren't email content.
		$this->heading        = __( 'Yêu cầu nhận mẫu cà phê miễn phí', 'epic-sample-requests' );
		$this->subject        = __( '[{site_title}] Yêu cầu nhận mẫu miễn phí — {name}', 'epic-sample-requests' );

		$this->template_html  = 'emails/admin-sample-request.php';
		$this->template_plain = 'emails/plain/admin-sample-request.php';
		$this->template_base  = EPIC_SAMPLE_REQUESTS_DIR . 'templates/';
		$this->placeholders   = array(
			'{site_title}' => $this->get_blogname(),
			'{name}'       => '',
		);

		add_action( 'epic_sample_request_received', array( $this, 'trigger' ), 10, 1 );

		parent::__construct();

		$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
	}

	public function get_default_subject() {
		return __( '[{site_title}] Yêu cầu nhận mẫu miễn phí — {name}', 'epic-sample-requests' );
	}

	public function get_default_heading() {
		return __( 'Yêu cầu nhận mẫu cà phê miễn phí', 'epic-sample-requests' );
	}

	/**
	 * @param array $data {
	 *     @type int    $id              Row id in the epic_sample_requests table (class-store.php) — 0 if that insert failed. Used only to report the delivery result back onto the row; falsy is a safe no-op everywhere it's used.
	 *     @type string $name
	 *     @type string $phone
	 *     @type string $province
	 *     @type string $ward
	 *     @type string $street
	 *     @type string $address
	 *     @type string $taste
	 *     @type string $taste_label_vi
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

		$this->name            = (string) $data['name'];
		$this->requester_email = isset( $data['email'] ) ? (string) $data['email'] : '';
		$this->phone           = isset( $data['phone'] ) ? (string) $data['phone'] : '';
		$this->province        = isset( $data['province'] ) ? (string) $data['province'] : '';
		$this->ward            = isset( $data['ward'] ) ? (string) $data['ward'] : '';
		$this->street          = isset( $data['street'] ) ? (string) $data['street'] : '';
		$this->address         = isset( $data['address'] ) ? (string) $data['address'] : '';
		$this->taste           = isset( $data['taste'] ) ? (string) $data['taste'] : '';
		$this->taste_label_vi  = isset( $data['taste_label_vi'] ) ? (string) $data['taste_label_vi'] : $this->taste;
		$this->brew            = isset( $data['brew'] ) ? (string) $data['brew'] : '';
		$this->brew_label_vi   = isset( $data['brew_label_vi'] ) ? (string) $data['brew_label_vi'] : $this->brew;
		$this->request_locale  = isset( $data['locale'] ) ? (string) $data['locale'] : 'unknown';
		$this->submitted_at    = isset( $data['submitted_at'] ) ? (string) $data['submitted_at'] : current_time( 'mysql' );

		$this->placeholders['{name}'] = $this->name;

		// Checked before the recipient/send logic below specifically so the
		// stored row can say *why* no email went out — "disabled" is a
		// deliberate admin choice, "failed" needs attention.
		if ( ! $this->is_enabled() ) {
			Epic_Sample_Store::mark_email_status( $request_id, Epic_Sample_Store::STATUS_DISABLED );
			$this->restore_locale();
			return;
		}

		// Recipient is read fresh here (rather than only in __construct) so a
		// change saved on the settings screen takes effect immediately.
		$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );

		if ( empty( $this->recipient ) ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					sprintf( 'Sample request from "%s" received but no recipient is configured (WooCommerce → Settings → Emails → EPIC: Sample Request, or admin_email is empty).', $this->name ),
					array( 'source' => 'epic-sample-requests' )
				);
			}
			Epic_Sample_Store::mark_email_status( $request_id, Epic_Sample_Store::STATUS_FAILED );
			$this->restore_locale();
			return;
		}

		$sent = $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );

		Epic_Sample_Store::mark_email_status(
			$request_id,
			$sent ? Epic_Sample_Store::STATUS_SENT : Epic_Sample_Store::STATUS_FAILED
		);

		if ( ! $sent && function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error(
				sprintf( 'Sample request notification failed to send for "%s".', $this->name ),
				array( 'source' => 'epic-sample-requests' )
			);
		}

		$this->restore_locale();
	}

	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			array(
				'name'               => $this->name,
				'requester_email'    => $this->requester_email,
				'phone'              => $this->phone,
				'province'           => $this->province,
				'ward'               => $this->ward,
				'street'             => $this->street,
				'address'            => $this->address,
				'taste'              => $this->taste,
				'taste_label_vi'     => $this->taste_label_vi,
				'brew'               => $this->brew,
				'brew_label_vi'      => $this->brew_label_vi,
				'request_locale'     => $this->request_locale,
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
				'name'               => $this->name,
				'requester_email'    => $this->requester_email,
				'phone'              => $this->phone,
				'province'           => $this->province,
				'ward'               => $this->ward,
				'street'             => $this->street,
				'address'            => $this->address,
				'taste'              => $this->taste,
				'taste_label_vi'     => $this->taste_label_vi,
				'brew'               => $this->brew,
				'brew_label_vi'      => $this->brew_label_vi,
				'request_locale'     => $this->request_locale,
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
				'title'   => __( 'Enable/Disable', 'epic-sample-requests' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable this email notification', 'epic-sample-requests' ),
				'default' => 'yes',
			),
			'recipient' => array(
				'title'       => __( 'Recipient(s)', 'epic-sample-requests' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => sprintf(
					/* translators: %s: default admin email */
					__( 'Comma-separated list of email addresses. Defaults to %s.', 'epic-sample-requests' ),
					esc_html( get_option( 'admin_email' ) )
				),
				'placeholder' => get_option( 'admin_email' ),
				'default'     => '',
			),
			'subject'   => array(
				'title'       => __( 'Subject', 'epic-sample-requests' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => sprintf( __( 'Available placeholders: %s', 'epic-sample-requests' ), '{site_title}, {name}' ),
				'placeholder' => $this->get_default_subject(),
				'default'     => '',
			),
			'heading'   => array(
				'title'       => __( 'Email heading', 'epic-sample-requests' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => sprintf( __( 'Available placeholders: %s', 'epic-sample-requests' ), '{site_title}, {name}' ),
				'placeholder' => $this->get_default_heading(),
				'default'     => '',
			),
			'additional_content' => array(
				'title'       => __( 'Additional content', 'epic-sample-requests' ),
				'description' => __( 'Text appended to the bottom of the email.', 'epic-sample-requests' ),
				'css'         => 'width:400px; height: 75px;',
				'placeholder' => __( 'N/A', 'epic-sample-requests' ),
				'type'        => 'textarea',
				'default'     => '',
				'desc_tip'    => true,
			),
			'email_type' => array(
				'title'       => __( 'Email type', 'epic-sample-requests' ),
				'type'        => 'select',
				'description' => __( 'Choose which format of email to send.', 'epic-sample-requests' ),
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
