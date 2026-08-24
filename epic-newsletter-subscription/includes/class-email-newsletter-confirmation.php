<?php
/**
 * Customer-facing "thanks for subscribing" confirmation email — sent back
 * to the address that just subscribed via the website footer's newsletter
 * box, to confirm the signup and set expectations for what arrives next.
 * Registered as an ordinary WC_Email (like every other EPIC email) so
 * WooCommerce → Settings → Emails → EPIC: Newsletter Confirmation is the
 * only admin screen needed for subject/heading — there's no
 * recipient/subject for the subscriber to configure, the recipient is
 * always the subscribing address itself.
 *
 * Distinct from the admin notification in
 * class-email-newsletter-subscription.php, which goes to the store's
 * marketing team. The two are independently enable/disable-able under
 * WooCommerce → Settings → Emails.
 *
 * The body is bilingual (English/Vietnamese), chosen from the
 * `subscriber_locale` the website captured at signup (the footer box sits
 * on a bilingual storefront) — so the thank-you arrives in the language
 * the visitor actually read while subscribing. Unknown locales fall back
 * to Vietnamese, the store's primary customer base (same reasoning the
 * other EPIC customer emails use for going Vietnamese-only). The subject/
 * heading defaults stay Vietnamese, matching every other EPIC email, and
 * remain editable on the settings screen.
 *
 * @package Epic_Newsletter_Subscription
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Email_Newsletter_Confirmation extends WC_Email {

	/** @var string */
	public $email = '';

	/** @var string Storefront locale the subscription was made in (en|vi|unknown) — picks the template's language. */
	public $subscriber_locale = '';

	/** @var string MySQL datetime string, site timezone. */
	public $subscribed_at = '';

	public function __construct() {
		$this->id             = 'epic_newsletter_confirmation';
		$this->customer_email = true;
		$this->title          = __( 'EPIC: Newsletter Confirmation', 'epic-newsletter-subscription' );
		$this->description    = __( 'Sent to the subscriber\'s own address to confirm they\'ve been added to the newsletter list. No WooCommerce equivalent to collide with, so it ships enabled.', 'epic-newsletter-subscription' );

		$this->heading        = __( 'Cảm ơn bạn đã đăng ký nhận tin từ EPIC Roastery!', 'epic-newsletter-subscription' );
		$this->subject        = __( '[{site_title}] Cảm ơn bạn đã đăng ký nhận tin', 'epic-newsletter-subscription' );

		$this->template_html  = 'emails/customer-newsletter-subscribed.php';
		$this->template_plain = 'emails/plain/customer-newsletter-subscribed.php';
		$this->template_base  = EPIC_NEWSLETTER_SUBSCRIPTION_DIR . 'templates/';
		$this->placeholders   = array(
			'{site_title}' => $this->get_blogname(),
		);

		// Listens on the same action as the admin notification, at a later
		// priority — the REST layer stays unaware of both emails (see
		// class-rest-api.php's docblock). Fires only for NEW subscriptions:
		// the REST controller returns early with `already => true` for a
		// re-subscribed address before ever firing this action, so a
		// duplicate signup never triggers a second confirmation email.
		add_action( 'epic_newsletter_subscription_received', array( $this, 'trigger' ), 20, 1 );

		parent::__construct();
	}

	public function get_default_subject() {
		return __( '[{site_title}] Cảm ơn bạn đã đăng ký nhận tin', 'epic-newsletter-subscription' );
	}

	public function get_default_heading() {
		return __( 'Cảm ơn bạn đã đăng ký nhận tin từ EPIC Roastery!', 'epic-newsletter-subscription' );
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

		$this->email             = (string) $data['email'];
		$this->subscriber_locale = isset( $data['locale'] ) ? (string) $data['locale'] : 'unknown';
		$this->subscribed_at     = isset( $data['subscribed_at'] ) ? (string) $data['subscribed_at'] : current_time( 'mysql' );
		$this->recipient         = $this->email;

		// Checked before the send logic below specifically so the stored row
		// can say *why* no email went out — "disabled" is a deliberate admin
		// choice, "failed" is something that needs attention (bad
		// subscriber address, wp_mail() failure).
		if ( ! $this->is_enabled() ) {
			Epic_Newsletter_Store::mark_confirm_status( $subscriber_id, Epic_Newsletter_Store::STATUS_DISABLED );
			$this->restore_locale();
			return;
		}

		$sent = $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );

		Epic_Newsletter_Store::mark_confirm_status(
			$subscriber_id,
			$sent ? Epic_Newsletter_Store::STATUS_SENT : Epic_Newsletter_Store::STATUS_FAILED
		);

		if ( ! $sent && function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error(
				sprintf( 'Newsletter confirmation email failed to send to "%s".', $this->email ),
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
				'subscriber_email'   => $this->email,
				'subscriber_locale'  => $this->subscriber_locale,
				'subscribed_at'      => $this->subscribed_at,
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
				'title'   => __( 'Enable/Disable', 'epic-newsletter-subscription' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable this confirmation email', 'epic-newsletter-subscription' ),
				'default' => 'yes',
			),
			'subject'   => array(
				'title'       => __( 'Subject', 'epic-newsletter-subscription' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => sprintf( __( 'Available placeholders: %s', 'epic-newsletter-subscription' ), '{site_title}' ),
				'placeholder' => $this->get_default_subject(),
				'default'     => '',
			),
			'heading'   => array(
				'title'       => __( 'Email heading', 'epic-newsletter-subscription' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => sprintf( __( 'Available placeholders: %s', 'epic-newsletter-subscription' ), '{site_title}' ),
				'placeholder' => $this->get_default_heading(),
				'default'     => '',
			),
			'additional_content' => array(
				'title'       => __( 'Additional content', 'epic-newsletter-subscription' ),
				'description' => __( 'Text appended to the bottom of the email. This is sent as-is in both the English and Vietnamese versions.', 'epic-newsletter-subscription' ),
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

	/** No native WooCommerce equivalent to collide with — safe to ship enabled (default 'yes'). */
	public function is_enabled() {
		$enabled = $this->get_option( 'enabled', 'yes' );
		return apply_filters( 'woocommerce_email_enabled_' . $this->id, 'yes' === $enabled, null, $this );
	}
}
