<?php
/**
 * "Your order has shipped" customer email — carries the GHN tracking code.
 *
 * Unlike class-email-order-created.php, this one has no WooCommerce order
 * status transition to hook: GHN shipment booking doesn't change the order's
 * WooCommerce status at all (see epic-ghn-shipping's book_single_order() /
 * Epic_GHN_Bundle::confirm() — both only ever write `_ghn_order_code` and
 * friends onto order meta, plus an order note). Instead this listens for a
 * plugin-agnostic action, `epic_ghn_shipment_booked`, fired from both of
 * epic-ghn-shipping's successful-booking call sites once that plugin has
 * been updated to fire it (see its class-ajax.php / class-bundle.php).
 *
 * Deliberately NOT a hard dependency on epic-ghn-shipping being active: this
 * class only ever runs its trigger() method if something actually calls
 * do_action( 'epic_ghn_shipment_booked', ... ) — if epic-ghn-shipping is
 * deactivated, this email class still registers (so it shows up, disabled,
 * under WooCommerce -> Settings -> Emails) but simply never fires.
 *
 * @package Epic_Order_Emails
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Email_Order_Shipped extends WC_Email {

	/** @var string GHN tracking code for the current send — set by trigger(), read by the templates. */
	public $tracking_code = '';

	/** @var string GHN's ETA string for the current send, if any. */
	public $eta = '';

	/** @var bool Whether this order collects COD on delivery — the courier still needs cash ready. */
	public $is_cod = false;

	/** @var float COD amount due, when $is_cod is true. */
	public $cod_amount = 0;

	public function __construct() {
		$this->id             = 'epic_order_shipped';
		$this->customer_email = true;
		$this->title          = __( 'EPIC: Order Shipped (GHN tracking code)', 'epic-order-emails' );
		$this->description    = __( 'Sent to the customer once a staff member books the GHN shipment for their order (from the order screen\'s "Ship via GHN" button, the Orders list bulk action, or a bundle confirm) — carries the GHN tracking code and, for COD orders, the amount due on delivery.', 'epic-order-emails' );

		$this->template_html  = 'emails/customer-order-shipped.php';
		$this->template_plain = 'emails/plain/customer-order-shipped.php';
		$this->template_base  = EPIC_ORDER_EMAILS_DIR . 'templates/';
		$this->placeholders   = array(
			'{order_number}'   => '',
			'{tracking_code}'  => '',
		);

		add_action( 'epic_ghn_shipment_booked', array( $this, 'trigger' ), 10, 3 );

		parent::__construct();
	}

	public function get_default_subject() {
		return __( '[{site_title}] Đơn hàng #{order_number} đã được gửi đi — Mã vận đơn {tracking_code}', 'epic-order-emails' );
	}

	public function get_default_heading() {
		return __( 'Đơn hàng của bạn đang trên đường giao!', 'epic-order-emails' );
	}

	/**
	 * @param WC_Order|int $order          Order object (both current call sites in epic-ghn-shipping have one in hand) or an order ID.
	 * @param string       $tracking_code  GHN order_code returned by create_shipment().
	 * @param string       $eta            GHN's expected_delivery_time, if any.
	 */
	public function trigger( $order, $tracking_code, $eta = '' ) {
		$this->setup_locale();

		if ( ! is_a( $order, 'WC_Order' ) ) {
			$order = wc_get_order( $order );
		}

		if ( ! is_a( $order, 'WC_Order' ) || empty( $tracking_code ) ) {
			$this->restore_locale();
			return;
		}

		// Idempotency: compare against the code we last emailed for this
		// order, not just a yes/no flag — so if a shipment is ever
		// legitimately cancelled and re-booked with a *different* tracking
		// code, the customer still gets a fresh email, but a duplicate fire
		// of this action for the SAME code (e.g. a retry) doesn't double-send.
		if ( $order->get_meta( '_epic_ship_email_sent_code' ) === $tracking_code ) {
			$this->restore_locale();
			return;
		}

		$this->object                          = $order;
		$this->tracking_code                   = $tracking_code;
		$this->eta                             = $eta;
		$this->recipient                       = $order->get_billing_email();
		$this->placeholders['{order_number}']  = $order->get_order_number();
		$this->placeholders['{tracking_code}'] = $tracking_code;

		// Best-effort — only used to show a COD-due amount in the email.
		// Guarded rather than a hard dependency (see class docblock): this
		// action only ever fires from epic-ghn-shipping in practice, so the
		// class will really be there, but nothing about *this* class should
		// fatal if it somehow isn't.
		if ( class_exists( 'Epic_GHN_Client' ) && method_exists( 'Epic_GHN_Client', 'is_cod_order' ) ) {
			$this->is_cod     = Epic_GHN_Client::is_cod_order( $order );
			$this->cod_amount = $this->is_cod ? (float) $order->get_total() : 0;
		}

		// Same rule as the order-received email: no billing email on file,
		// skip silently, log only.
		if ( empty( $this->recipient ) ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->info(
					sprintf( 'Order #%s has no billing email — "order shipped" email (tracking %s) not sent.', $order->get_order_number(), $tracking_code ),
					array( 'source' => 'epic-order-emails' )
				);
			}
			$this->restore_locale();
			return;
		}

		if ( $this->is_enabled() && $this->get_recipient() ) {
			$sent = $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );

			if ( $sent ) {
				$order->update_meta_data( '_epic_ship_email_sent_code', $tracking_code );
				$order->save();
			} elseif ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					sprintf( '"Order shipped" email failed to send for order #%s (tracking %s).', $order->get_order_number(), $tracking_code ),
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
				'order'               => $this->object,
				'tracking_code'       => $this->tracking_code,
				'eta'                 => $this->eta,
				'is_cod'              => $this->is_cod,
				'cod_amount'          => $this->cod_amount,
				'email_heading'       => $this->get_heading(),
				'additional_content'  => $this->get_additional_content(),
				'sent_to_admin'       => false,
				'plain_text'          => false,
				'email'               => $this,
			),
			'',
			$this->template_base
		);
	}

	public function get_content_plain() {
		return wc_get_template_html(
			$this->template_plain,
			array(
				'order'               => $this->object,
				'tracking_code'       => $this->tracking_code,
				'eta'                 => $this->eta,
				'is_cod'              => $this->is_cod,
				'cod_amount'          => $this->cod_amount,
				'email_heading'       => $this->get_heading(),
				'additional_content'  => $this->get_additional_content(),
				'sent_to_admin'       => false,
				'plain_text'          => true,
				'email'               => $this,
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
				'default' => 'yes',
			),
			'subject'    => array(
				'title'       => __( 'Subject', 'epic-order-emails' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => sprintf( __( 'Available placeholders: %s', 'epic-order-emails' ), '{site_title}, {order_number}, {tracking_code}' ),
				'placeholder' => $this->get_default_subject(),
				'default'     => '',
			),
			'heading'    => array(
				'title'       => __( 'Email heading', 'epic-order-emails' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => sprintf( __( 'Available placeholders: %s', 'epic-order-emails' ), '{site_title}, {order_number}, {tracking_code}' ),
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
	 * Default 'yes' — unlike the order-received email, this one has no
	 * native WooCommerce equivalent to collide with (WooCommerce has no
	 * built-in "shipment tracking" email), so it's safe to ship enabled.
	 */
	public function is_enabled() {
		$enabled = $this->get_option( 'enabled', 'yes' );
		return apply_filters( 'woocommerce_email_enabled_' . $this->id, 'yes' === $enabled, $this->object, $this );
	}
}
