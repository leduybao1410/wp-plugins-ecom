<?php
/**
 * "Order received" customer email — fires when an order first moves out of
 * `pending` into `processing` or `on-hold`, which is exactly what happens the
 * moment the Next.js checkout creates the order via the WooCommerce REST API
 * (src/lib/woocommerce.ts createOrder() defaults status to "processing" —
 * WooCommerce still treats a brand-new order's implicit starting status as
 * `pending`, so setting it straight to `processing` at creation IS a
 * `pending_to_processing` transition as far as WC_Order::status_transition()
 * is concerned). The same hooks fire for an order created any other way too
 * (a manually-placed wp-admin order, a future sales channel), so this isn't
 * coupled to the Next.js checkout specifically.
 *
 * NOTE: WooCommerce ships its own built-in email on these same two hooks
 * (WC_Email_Customer_Processing_Order / WC_Email_Customer_On_Hold_Order). If
 * either of those is enabled under WooCommerce -> Settings -> Emails, a
 * customer with a billing email would get BOTH the native email and this one
 * for the same order. Before activating this plugin on the live site,
 * disable those two native emails (or leave this one disabled) to avoid a
 * double-send — see PLAN.md §6.
 *
 * @package Epic_Order_Emails
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Email_Order_Created extends WC_Email {

	public function __construct() {
		$this->id             = 'epic_order_created';
		$this->customer_email = true;
		$this->title          = __( 'EPIC: Order Received', 'epic-order-emails' );
		$this->description    = __( 'Sent to the customer as soon as their order is placed (order status moves to Processing or On hold). Distinct from WooCommerce\'s own built-in "Processing order" / "On-hold order" emails — disable those if you enable this one, to avoid sending both.', 'epic-order-emails' );

		$this->template_html  = 'emails/customer-order-created.php';
		$this->template_plain = 'emails/plain/customer-order-created.php';
		$this->template_base  = EPIC_ORDER_EMAILS_DIR . 'templates/';
		$this->placeholders   = array(
			'{order_number}' => '',
			'{order_date}'   => '',
		);

		// Same two transitions WooCommerce's own "order received" emails use
		// — either one means "the order now exists and the customer should
		// hear about it," regardless of whether it'll be COD (on-hold is
		// what epic-ghn-shipping's website-side flagging uses when GHN
		// booking fails at checkout — see website/src/lib/woocommerce.ts
		// flagOrderForManualShipping()) or already paid (processing).
		add_action( 'woocommerce_order_status_pending_to_processing_notification', array( $this, 'trigger' ), 10, 2 );
		add_action( 'woocommerce_order_status_pending_to_on-hold_notification', array( $this, 'trigger' ), 10, 2 );

		parent::__construct();
	}

	public function get_default_subject() {
		return __( '[{site_title}] Xác nhận đơn hàng #{order_number}', 'epic-order-emails' );
	}

	public function get_default_heading() {
		return __( 'Cảm ơn bạn đã đặt hàng tại EPIC Roastery!', 'epic-order-emails' );
	}

	/**
	 * @param int           $order_id
	 * @param WC_Order|null $order
	 */
	public function trigger( $order_id, $order = null ) {
		$this->setup_locale();

		if ( $order_id && ! is_a( $order, 'WC_Order' ) ) {
			$order = wc_get_order( $order_id );
		}

		if ( is_a( $order, 'WC_Order' ) ) {
			$this->object                         = $order;
			$this->recipient                       = $order->get_billing_email();
			$this->placeholders['{order_number}'] = $order->get_order_number();
			$this->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() );
		}

		if ( ! $this->object instanceof WC_Order ) {
			$this->restore_locale();
			return;
		}

		// Per plan: no billing email on file (checkout's email field is
		// optional — common for COD/phone-only Vietnamese customers) means
		// skip silently, log only. No admin-facing flag.
		if ( empty( $this->recipient ) ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->info(
					sprintf( 'Order #%s has no billing email — "order received" email not sent.', $this->object->get_order_number() ),
					array( 'source' => 'epic-order-emails' )
				);
			}
			$this->restore_locale();
			return;
		}

		// Guard against a duplicate send if this hook ever fires twice for
		// the same order (e.g. a future integration re-saves the order
		// through the same transition) — same convention as the shipped
		// email's idempotency check.
		if ( 'yes' === $this->object->get_meta( '_epic_order_email_sent' ) ) {
			$this->restore_locale();
			return;
		}

		if ( $this->is_enabled() && $this->get_recipient() ) {
			$sent = $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );

			if ( $sent ) {
				$this->object->update_meta_data( '_epic_order_email_sent', 'yes' );
				$this->object->save();
			} elseif ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					sprintf( '"Order received" email failed to send for order #%s.', $this->object->get_order_number() ),
					array( 'source' => 'epic-order-emails' )
				);
			}
		}

		$this->restore_locale();
	}

	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			array(
				'order'         => $this->object,
				'email_heading' => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin' => false,
				'plain_text'    => false,
				'email'         => $this,
			),
			'',
			$this->template_base
		);
	}

	public function get_content_plain() {
		return wc_get_template_html(
			$this->template_plain,
			array(
				'order'         => $this->object,
				'email_heading' => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin' => false,
				'plain_text'    => true,
				'email'         => $this,
			),
			'',
			$this->template_base
		);
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'    => array(
				'title'   => __( 'Enable/Disable', 'epic-order-emails' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable this email notification', 'epic-order-emails' ),
				'default' => 'no',
			),
			'subject'    => array(
				'title'       => __( 'Subject', 'epic-order-emails' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => sprintf( __( 'Available placeholders: %s', 'epic-order-emails' ), '{site_title}, {order_number}, {order_date}' ),
				'placeholder' => $this->get_default_subject(),
				'default'     => '',
			),
			'heading'    => array(
				'title'       => __( 'Email heading', 'epic-order-emails' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => sprintf( __( 'Available placeholders: %s', 'epic-order-emails' ), '{site_title}, {order_number}, {order_date}' ),
				'placeholder' => $this->get_default_heading(),
				'default'     => '',
			),
			'additional_content' => array(
				'title'       => __( 'Additional content', 'epic-order-emails' ),
				'description' => __( 'Text appended to the bottom of the email.', 'epic-order-emails' ),
				'css'         => 'width:400px; height: 75px;',
				'placeholder' => __( 'N/A', 'epic-order-emails' ),
				'type'        => 'textarea',
				'default'     => '',
				'desc_tip'    => true,
			),
			'email_type' => array(
				'title'       => __( 'Email type', 'epic-order-emails' ),
				'type'        => 'select',
				'description' => __( 'Choose which format of email to send.', 'epic-order-emails' ),
				'default'     => 'html',
				'class'       => 'email_type wc-enhanced-select',
				'options'     => $this->get_email_type_options(),
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Note: default 'no' (unlike a default WC_Email's usual 'yes') —
	 * deliberately opt-in. Whoever activates this plugin needs to first
	 * disable WooCommerce's own native "Processing order" / "On-hold order"
	 * customer emails (see the class docblock) before switching this on,
	 * otherwise the customer gets two order-received emails for one order.
	 */
	public function is_enabled() {
		$enabled = $this->get_option( 'enabled', 'no' );
		return apply_filters( 'woocommerce_email_enabled_' . $this->id, 'yes' === $enabled, $this->object, $this );
	}
}
