<?php
/**
 * Customer-facing "thanks for your sample request" email — sent back to the
 * address the visitor provided on the /roastery page's free-sample form, to
 * confirm the request was received and set expectations for delivery.
 * Registered as an ordinary WC_Email (like every other EPIC email) so
 * WooCommerce → Settings → Emails → EPIC: Sample Thank-you is the only admin
 * screen needed for subject/heading — there's no recipient for an admin to
 * configure, the recipient is always the requester's own address.
 *
 * Distinct from the admin notification in class-email-sample-request.php,
 * which goes to the store team. The two are independently enable/disable-able
 * under WooCommerce → Settings → Emails.
 *
 * The body is bilingual (English/Vietnamese), chosen from the `locale` the
 * website captured at submission (the form sits on a bilingual storefront) —
 * so the thank-you arrives in the language the visitor actually read.
 * Unknown locales fall back to Vietnamese, the store's primary customer base
 * (same reasoning the other EPIC customer emails use). The subject/heading
 * defaults stay Vietnamese and remain editable on the settings screen.
 *
 * @package Epic_Sample_Requests
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( file_exists( __DIR__ . '/epic-email-i18n.php' ) ) {
	require_once __DIR__ . '/epic-email-i18n.php';
}

class Epic_Email_Sample_Confirmation extends WC_Email {

	/** @var string */
	public $requester_name = '';

	/** @var string Recipient — the requester's own email address. */
	public $requester_email = '';

	/** @var string Raw taste value (robusta_natural|robusta_honey|arabica_vn). */
	public $taste = '';

	/** @var string Vietnamese label for $taste, precomputed website-side. */
	public $taste_label_vi = '';

	/** @var string Raw brew value (phin|espresso|coldbrew|pourover) — optional on the form. */
	public $brew = '';

	/** @var string Vietnamese label for $brew, precomputed website-side. */
	public $brew_label_vi = '';

	/** @var string One-line delivery address assembled from the structured parts. */
	public $address = '';

	/** @var string Storefront locale the request was submitted in (en|vi|unknown) — picks the template's language. */
	public $request_locale = '';

	/** @var string MySQL datetime string, site timezone. */
	public $submitted_at = '';

	public function __construct() {
		$this->id             = 'epic_sample_confirmation';
		$this->customer_email = true;
		$this->title          = __( 'EPIC: Sample Thank-you', 'epic-sample-requests' );
		$this->description    = __( 'Sent to the requester\'s own address to confirm their free-sample request was received. No WooCommerce equivalent to collide with, so it ships enabled.', 'epic-sample-requests' );

		$this->heading        = __( 'Cảm ơn bạn đã đăng ký nhận mẫu cà phê miễn phí!', 'epic-sample-requests' );
		$this->subject        = __( '[{site_title}] Cảm ơn bạn đã đăng ký nhận mẫu cà phê miễn phí', 'epic-sample-requests' );

		$this->template_html  = 'emails/customer-sample-request.php';
		$this->template_plain = 'emails/plain/customer-sample-request.php';
		$this->template_base  = EPIC_SAMPLE_REQUESTS_DIR . 'templates/';
		$this->placeholders   = array(
			'{site_title}' => $this->get_blogname(),
			'{name}'       => '',
		);

		// Listens on the same action as the admin notification, at a later
		// priority — the REST layer stays unaware of both emails (see
		// class-rest-api.php's docblock).
		add_action( 'epic_sample_request_received', array( $this, 'trigger' ), 20, 1 );

		parent::__construct();
	}

	public function get_default_subject() {
		return __( '[{site_title}] Cảm ơn bạn đã đăng ký nhận mẫu cà phê miễn phí', 'epic-sample-requests' );
	}

	public function get_default_heading() {
		return __( 'Cảm ơn bạn đã đăng ký nhận mẫu cà phê miễn phí!', 'epic-sample-requests' );
	}

	/**
	 * @param array $data {
	 *     @type int    $id              Row id in the epic_sample_requests table (class-store.php) — 0 if that insert failed. Used only to report the delivery result back onto the row; falsy is a safe no-op everywhere it's used.
	 *     @type string $name
	 *     @type string $email
	 *     @type string $taste
	 *     @type string $taste_label_vi
	 *     @type string $address
	 *     @type string $locale
	 *     @type string $submitted_at
	 * }
	 */
	public function trigger( $data ) {
		$this->setup_locale();

		if ( ! is_array( $data ) || empty( $data['name'] ) ) {
			$this->restore_locale();
			return;
		}

		$request_id = isset( $data['id'] ) ? (int) $data['id'] : 0;

		// Email is optional on the form — without one there's nowhere to send
		// the thank-you, so record that on the row (rather than leaving it
		// "pending", which would read like a delivery failure).
		if ( empty( $data['email'] ) ) {
			Epic_Sample_Store::mark_confirm_status( $request_id, Epic_Sample_Store::STATUS_SKIPPED );
			$this->restore_locale();
			return;
		}

		$this->requester_name  = (string) $data['name'];
		$this->requester_email = (string) $data['email'];
		$this->taste           = isset( $data['taste'] ) ? (string) $data['taste'] : '';
		$this->taste_label_vi  = isset( $data['taste_label_vi'] ) ? (string) $data['taste_label_vi'] : $this->taste;
		$this->brew            = isset( $data['brew'] ) ? (string) $data['brew'] : '';
		$this->brew_label_vi   = isset( $data['brew_label_vi'] ) ? (string) $data['brew_label_vi'] : $this->brew;
		$this->address         = isset( $data['address'] ) ? (string) $data['address'] : '';
		$this->request_locale  = isset( $data['locale'] ) ? (string) $data['locale'] : 'unknown';
		$this->submitted_at    = isset( $data['submitted_at'] ) ? (string) $data['submitted_at'] : current_time( 'mysql' );
		$this->recipient       = $this->requester_email;

		if ( function_exists( 'epic_email_locale' ) && function_exists( 'epic_email_str' ) ) {
			$epic_locale   = epic_email_locale( $this->request_locale );
			$this->heading = epic_email_str( 'heading_sample', $epic_locale );
			$this->subject = epic_email_str( 'subject_sample', $epic_locale );
		}

		$this->placeholders['{name}'] = $this->requester_name;

		// Checked before the send logic below specifically so the stored row
		// can say *why* no email went out — "disabled" is a deliberate admin
		// choice, "failed" is something that needs attention.
		if ( ! $this->is_enabled() ) {
			Epic_Sample_Store::mark_confirm_status( $request_id, Epic_Sample_Store::STATUS_DISABLED );
			$this->restore_locale();
			return;
		}

		$sent = $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );

		Epic_Sample_Store::mark_confirm_status(
			$request_id,
			$sent ? Epic_Sample_Store::STATUS_SENT : Epic_Sample_Store::STATUS_FAILED
		);

		if ( ! $sent && function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error(
				sprintf( 'Sample thank-you email failed to send to "%s".', $this->requester_email ),
				array( 'source' => 'epic-sample-requests' )
			);
		}

		$this->restore_locale();
	}

	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			array(
				'requester_name'     => $this->requester_name,
				'requester_email'    => $this->requester_email,
				'taste'              => $this->taste,
				'taste_label_vi'     => $this->taste_label_vi,
				'brew'               => $this->brew,
				'brew_label_vi'      => $this->brew_label_vi,
				'address'            => $this->address,
				'request_locale'     => $this->request_locale,
				'submitted_at'       => $this->submitted_at,
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => false,
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
				'requester_name'     => $this->requester_name,
				'requester_email'    => $this->requester_email,
				'taste'              => $this->taste,
				'taste_label_vi'     => $this->taste_label_vi,
				'brew'               => $this->brew,
				'brew_label_vi'      => $this->brew_label_vi,
				'address'            => $this->address,
				'request_locale'     => $this->request_locale,
				'submitted_at'       => $this->submitted_at,
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => false,
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
				'label'   => __( 'Enable this thank-you email', 'epic-sample-requests' ),
				'default' => 'yes',
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
				'description' => __( 'Text appended to the bottom of the email. This is sent as-is in both the English and Vietnamese versions.', 'epic-sample-requests' ),
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

	/** No native WooCommerce equivalent to collide with — safe to ship enabled (default 'yes'). */
	public function is_enabled() {
		$enabled = $this->get_option( 'enabled', 'yes' );
		return apply_filters( 'woocommerce_email_enabled_' . $this->id, 'yes' === $enabled, null, $this );
	}
}
