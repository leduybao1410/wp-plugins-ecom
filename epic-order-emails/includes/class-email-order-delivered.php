<?php
/**
 * "Your order has been delivered" customer email.
 *
 * Triggered by epic-viettelpost-shipping's plugin-agnostic
 * `epic_vtp_status_changed( $order, $status, $source )` action when the
 * shipment reaches ViettelPost status 501 (Delivered). Like the shipped
 * email, this class registers unconditionally but only ever fires if
 * something actually calls that action — no hard dependency on the shipping
 * plugin being active.
 *
 * Sent at most once per order (`_epic_delivered_email_sent`), so repeated or
 * retried webhooks don't re-send.
 *
 * @package Epic_Order_Emails
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( file_exists( __DIR__ . '/epic-email-i18n.php' ) ) {
	require_once __DIR__ . '/epic-email-i18n.php';
}

class Epic_Email_Order_Delivered extends WC_Email {

	/** ViettelPost ORDER_STATUS code this email fires on. */
	const DELIVERED_STATUS = '501';

	public function __construct() {
		$this->id             = 'epic_order_delivered';
		$this->customer_email = true;
		$this->title          = __( 'EPIC: Order Delivered', 'epic-order-emails' );
		$this->description    = __( 'Sent to the customer once the ViettelPost webhook reports the shipment as delivered.', 'epic-order-emails' );

		$this->template_html  = 'emails/customer-order-delivered.php';
		$this->template_plain = 'emails/plain/customer-order-delivered.php';
		$this->template_base  = EPIC_ORDER_EMAILS_DIR . 'templates/';
		$this->placeholders   = array(
			'{order_number}' => '',
		);

		add_action( 'epic_vtp_status_changed', array( $this, 'trigger' ), 10, 3 );

		parent::__construct();
	}

	public function get_default_subject() {
		return __( '[{site_title}] Đơn hàng #{order_number} đã giao thành công', 'epic-order-emails' );
	}

	public function get_default_heading() {
		return __( 'Đơn hàng của bạn đã được giao!', 'epic-order-emails' );
	}

	/**
	 * @param WC_Order|int $order  Order object or ID.
	 * @param string       $status Raw ViettelPost ORDER_STATUS code.
	 * @param string       $source 'webhook' | 'manual'.
	 */
	public function trigger( $order, $status = '', $source = '' ) {
		if ( self::DELIVERED_STATUS !== (string) $status ) {
			return;
		}

		$this->setup_locale();

		if ( ! is_a( $order, 'WC_Order' ) ) {
			$order = wc_get_order( $order );
		}

		if ( ! is_a( $order, 'WC_Order' ) ) {
			$this->restore_locale();
			return;
		}

		// Idempotent: delivered is terminal, so one email per order.
		if ( $order->get_meta( '_epic_delivered_email_sent' ) ) {
			$this->restore_locale();
			return;
		}

		$this->object                         = $order;
		$this->recipient                      = $order->get_billing_email();
		$this->placeholders['{order_number}'] = $order->get_order_number();

		if ( function_exists( 'epic_email_locale' ) && function_exists( 'epic_email_str' ) ) {
			$epic_locale   = epic_email_locale( $order->get_meta( '_epic_locale' ) );
			$this->heading = epic_email_str( 'heading_order_delivered', $epic_locale );
			$this->subject = epic_email_str( 'subject_order_delivered', $epic_locale );
		}

		if ( empty( $this->recipient ) ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->info(
					sprintf( 'Order #%s has no billing email — "order delivered" email not sent.', $order->get_order_number() ),
					array( 'source' => 'epic-order-emails' )
				);
			}
			$this->restore_locale();
			return;
		}

		if ( $this->is_enabled() && $this->get_recipient() ) {
			$sent = $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );

			if ( $sent ) {
				$order->update_meta_data( '_epic_delivered_email_sent', current_time( 'mysql' ) );
				$order->save();
			} elseif ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					sprintf( '"Order delivered" email failed to send for order #%s.', $order->get_order_number() ),
					array( 'source' => 'epic-order-emails' )
				);
			}
		}

		$this->restore_locale();
	}

	public function get_heading() {
		if ( function_exists( 'epic_email_locale' ) && function_exists( 'epic_email_str' ) ) {
			$locale = epic_email_locale( $this->object instanceof WC_Order ? $this->object->get_meta( '_epic_locale' ) : '' );
			return epic_email_str( 'heading_order_delivered', $locale );
		}
		return parent::get_heading();
	}

	public function get_subject() {
		if ( function_exists( 'epic_email_locale' ) && function_exists( 'epic_email_str' ) ) {
			$locale = epic_email_locale( $this->object instanceof WC_Order ? $this->object->get_meta( '_epic_locale' ) : '' );
			return $this->format_string( epic_email_str( 'subject_order_delivered', $locale ) );
		}
		return parent::get_subject();
	}

	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			array(
				'order'              => $this->object,
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
				'order'              => $this->object,
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
				'description' => sprintf( __( 'Available placeholders: %s', 'epic-order-emails' ), '{site_title}, {order_number}' ),
				'placeholder' => $this->get_default_subject(),
				'default'     => '',
			),
			'heading'    => array(
				'title'       => __( 'Email heading', 'epic-order-emails' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => sprintf( __( 'Available placeholders: %s', 'epic-order-emails' ), '{site_title}, {order_number}' ),
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

	public function is_enabled() {
		$enabled = $this->get_option( 'enabled', 'yes' );
		return apply_filters( 'woocommerce_email_enabled_' . $this->id, 'yes' === $enabled, $this->object, $this );
	}
}
