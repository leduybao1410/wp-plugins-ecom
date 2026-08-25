<?php
/**
 * Customer-facing "wholesale order received" confirmation — sent to the
 * wholesale customer who just placed an order on the website's wholesale page.
 * Same no-WC_Order pattern as the admin email; the recipient is the customer's
 * account email (never a billing address — wholesale orders have no shipping).
 *
 * Vietnamese-only content, English settings labels — same convention as every
 * other EPIC email.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Email_Wholesale_Order_Customer extends WC_Email {

	public $order_number = '';
	public $customer_name = '';
	public $items = array();
	public $note = '';
	public $total = 0;

	public function __construct() {
		$this->id          = 'epic_wholesale_order_customer';
		$this->title       = __( 'EPIC: Wholesale Order — Customer', 'epic-wholesale-orders' );
		$this->description = __( 'Sent to the wholesale customer when they submit an order on the website — their line items, total, and any note they left. No payment or shipping is involved.', 'epic-wholesale-orders' );

		$this->customer_email = true;
		$this->heading        = __( 'Đã nhận đơn hàng sỉ của bạn', 'epic-wholesale-orders' );
		$this->subject        = __( '[{site_title}] Xác nhận đơn hàng sỉ {order_number}', 'epic-wholesale-orders' );

		$this->template_html  = 'emails/customer-wholesale-order.php';
		$this->template_plain = 'emails/plain/customer-wholesale-order.php';
		$this->template_base  = EPIC_WHOLESALE_ORDERS_DIR . 'templates/';
		$this->placeholders   = array(
			'{site_title}'   => $this->get_blogname(),
			'{order_number}' => '',
		);

		add_action( 'epic_wholesale_order_created', array( $this, 'trigger' ), 20, 1 );

		parent::__construct();
	}

	public function get_default_subject() {
		return __( '[{site_title}] Xác nhận đơn hàng sỉ {order_number}', 'epic-wholesale-orders' );
	}

	public function get_default_heading() {
		return __( 'Đã nhận đơn hàng sỉ của bạn', 'epic-wholesale-orders' );
	}

	public function trigger( $order ) {
		$this->setup_locale();

		if ( ! is_array( $order ) || empty( $order['order_number'] ) || empty( $order['customer_email'] ) ) {
			$this->restore_locale();
			return;
		}

		$this->order_number   = (string) $order['order_number'];
		$this->customer_name  = isset( $order['customer_name'] ) ? (string) $order['customer_name'] : '';
		$this->items          = isset( $order['items'] ) && is_array( $order['items'] ) ? $order['items'] : array();
		$this->note           = isset( $order['note'] ) ? (string) $order['note'] : '';
		$this->total          = isset( $order['total'] ) ? (float) $order['total'] : 0;

		$this->placeholders['{order_number}'] = $this->order_number;

		// Read fresh so a settings change takes effect immediately.
		$this->recipient = $order['customer_email'];

		if ( ! $this->is_enabled() ) {
			update_post_meta( (int) $order['id'], Epic_Wholesale_Orders_Store::META_CUSTOMER_EMAIL_STATUS, 'disabled' );
			$this->restore_locale();
			return;
		}

		if ( empty( $this->recipient ) || ! is_email( $this->recipient ) ) {
			update_post_meta( (int) $order['id'], Epic_Wholesale_Orders_Store::META_CUSTOMER_EMAIL_STATUS, 'failed' );
			$this->restore_locale();
			return;
		}

		$sent = $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );

		update_post_meta( (int) $order['id'], Epic_Wholesale_Orders_Store::META_CUSTOMER_EMAIL_STATUS, $sent ? 'sent' : 'failed' );

		if ( ! $sent && function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error(
				sprintf( 'Wholesale order confirmation (customer) failed to send for %s.', $this->order_number ),
				array( 'source' => 'epic-wholesale-orders' )
			);
		}

		$this->restore_locale();
	}

	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			array(
				'order_number'       => $this->order_number,
				'customer_name'      => $this->customer_name,
				'items'              => $this->items,
				'note'               => $this->note,
				'total'              => $this->total,
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
				'order_number'       => $this->order_number,
				'customer_name'      => $this->customer_name,
				'items'              => $this->items,
				'note'               => $this->note,
				'total'              => $this->total,
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
			'enabled' => array(
				'title'   => __( 'Enable/Disable', 'epic-wholesale-orders' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable this email notification', 'epic-wholesale-orders' ),
				'default' => 'yes',
			),
			'subject' => array(
				'title'       => __( 'Subject', 'epic-wholesale-orders' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => sprintf( __( 'Available placeholders: %s', 'epic-wholesale-orders' ), '{site_title}, {order_number}' ),
				'placeholder' => $this->get_default_subject(),
				'default'     => '',
			),
			'heading' => array(
				'title'       => __( 'Email heading', 'epic-wholesale-orders' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => sprintf( __( 'Available placeholders: %s', 'epic-wholesale-orders' ), '{site_title}, {order_number}' ),
				'placeholder' => $this->get_default_heading(),
				'default'     => '',
			),
			'additional_content' => array(
				'title'       => __( 'Additional content', 'epic-wholesale-orders' ),
				'description' => __( 'Text appended to the bottom of the email.', 'epic-wholesale-orders' ),
				'css'         => 'width:400px; height: 75px;',
				'placeholder' => __( 'N/A', 'epic-wholesale-orders' ),
				'type'        => 'textarea',
				'default'     => '',
				'desc_tip'    => true,
			),
			'email_type' => array(
				'title'       => __( 'Email type', 'epic-wholesale-orders' ),
				'type'        => 'select',
				'description' => __( 'Choose which format of email to send.', 'epic-wholesale-orders' ),
				'default'     => 'html',
				'class'       => 'email_type wc-enhanced-select',
				'options'     => $this->get_email_type_options(),
				'desc_tip'    => true,
			),
		);
	}

	public function is_enabled() {
		$enabled = $this->get_option( 'enabled', 'yes' );
		return apply_filters( 'woocommerce_email_enabled_' . $this->id, 'yes' === $enabled, null, $this );
	}
}
