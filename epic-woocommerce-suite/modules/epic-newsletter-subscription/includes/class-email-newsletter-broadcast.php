<?php
/**
 * The one email actually sent for a bulk newsletter campaign — instantiated
 * fresh per recipient by class-broadcast-sender.php::send_one(), never
 * registered via the `woocommerce_email_classes` filter the way the other
 * two EPIC emails are.
 *
 * That's a deliberate difference, not an oversight: those two emails have
 * ONE fixed subject/heading/body per install, configured once under
 * WooCommerce → Settings → Emails. A broadcast's subject and body are
 * different every campaign — entered fresh each time in the WooCommerce →
 * Send Newsletter composer (class-broadcast-admin.php) — so there is
 * nothing per-site to persist in a settings tab. This class exists purely
 * so sending a broadcast can reuse WC_Email's machinery: the store's
 * standard `woocommerce_email_header`/`_footer` wrapper (logo, colors, the
 * footer text configured under WooCommerce → Settings → Emails), automatic
 * CSS inlining, and `send()`'s existing multipart/plain-text handling —
 * instead of hand-rolling all of that again.
 *
 * @package Epic_Newsletter_Subscription
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Email_Newsletter_Broadcast extends WC_Email {

	/** @var string Campaign subject for the locale this particular recipient gets — also reused as the email heading. */
	public $broadcast_subject = '';

	/** @var string Raw HTML from the composer's wp_editor() field, for the locale this recipient gets. */
	public $broadcast_body = '';

	/** @var string 'en' or 'vi' — decides only the fixed unsubscribe line the template appends, not $broadcast_body itself (that's already the admin's chosen-language content). */
	public $broadcast_locale = 'vi';

	public function __construct() {
		$this->id             = 'epic_newsletter_broadcast';
		$this->customer_email = true;
		$this->title          = __( 'EPIC: Newsletter Broadcast', 'epic-newsletter-subscription' );
		$this->description    = __( 'Not configured here — sent on demand from WooCommerce → Send Newsletter, once per bulk campaign recipient. This settings-page entry exists only because every WC_Email subclass gets one; there is nothing to configure.', 'epic-newsletter-subscription' );

		$this->template_html  = 'emails/newsletter-broadcast.php';
		$this->template_plain = 'emails/plain/newsletter-broadcast.php';
		$this->template_base  = EPIC_NEWSLETTER_SUBSCRIPTION_DIR . 'templates/';

		parent::__construct();
	}

	/**
	 * Always enabled — a broadcast's "should this go out" decision already
	 * happened explicitly at the composer's confirmation step
	 * (class-broadcast-admin.php), not via a settings-screen toggle. There
	 * is deliberately no WooCommerce → Settings → Emails entry for this
	 * class to disable it from (see init_form_fields()).
	 */
	public function is_enabled() {
		return true;
	}

	/** No per-site settings — see this class's docblock. */
	public function init_form_fields() {
		$this->form_fields = array();
	}

	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			array(
				'email_heading'    => $this->broadcast_subject,
				'broadcast_body'   => $this->broadcast_body,
				'broadcast_locale' => $this->broadcast_locale,
				'plain_text'       => false,
				'email'            => $this,
			),
			'',
			$this->template_base
		);
	}

	public function get_content_plain() {
		return wc_get_template_html(
			$this->template_plain,
			array(
				'email_heading'    => $this->broadcast_subject,
				'broadcast_body'   => $this->broadcast_body,
				'broadcast_locale' => $this->broadcast_locale,
				'plain_text'       => true,
				'email'            => $this,
			),
			'',
			$this->template_base
		);
	}
}
