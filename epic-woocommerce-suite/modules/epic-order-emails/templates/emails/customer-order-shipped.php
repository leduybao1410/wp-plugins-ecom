<?php
/**
 * Customer "order shipped" email (HTML) — the GHN tracking code email.
 *
 * Vietnamese-only content — see the docblock on customer-order-created.php
 * for why this isn't routed through a .mo translation file.
 *
 * @var WC_Order $order
 * @var string   $tracking_code
 * @var string   $eta
 * @var bool     $is_cod
 * @var float    $cod_amount
 * @var string   $email_heading
 * @var string   $additional_content
 * @var bool     $sent_to_admin
 * @var bool     $plain_text
 * @var WC_Email $email
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

do_action( 'woocommerce_email_header', $email_heading, $email );

$tracking_url = function_exists( 'epic_order_emails_ghn_tracking_url' ) ? epic_order_emails_ghn_tracking_url( $tracking_code ) : '';
?>

<p>
	<?php
	printf(
		/* translators: %s: customer first name */
		esc_html__( 'Chào %s,', 'epic-order-emails' ),
		esc_html( $order->get_billing_first_name() )
	);
	?>
</p>
<p>
	<?php
	printf(
		/* translators: %s: order number */
		esc_html__( 'Đơn hàng #%s của bạn đã được bàn giao cho đơn vị vận chuyển GHN (Giao Hàng Nhanh).', 'epic-order-emails' ),
		esc_html( $order->get_order_number() )
	);
	?>
</p>

<table cellspacing="0" cellpadding="6" style="width:100%; border:1px solid #e5e5e5; margin: 16px 0;">
	<tr>
		<td style="padding:12px; background:#f7f7f7;">
			<strong><?php esc_html_e( 'Mã vận đơn GHN', 'epic-order-emails' ); ?></strong><br />
			<span style="font-size:18px; letter-spacing:1px;"><?php echo esc_html( $tracking_code ); ?></span>
		</td>
	</tr>
	<?php if ( $tracking_url ) : ?>
	<tr>
		<td style="padding:0 12px 12px;">
			<a href="<?php echo esc_url( $tracking_url ); ?>"><?php esc_html_e( 'Theo dõi đơn hàng', 'epic-order-emails' ); ?></a>
			<br />
			<span style="font-size:12px; color:#666;">
				<?php esc_html_e( 'Nếu liên kết trên không mở đúng đơn hàng, hãy truy cập donhang.ghn.vn và nhập mã vận đơn ở trên.', 'epic-order-emails' ); ?>
			</span>
		</td>
	</tr>
	<?php endif; ?>
	<?php if ( $eta ) : ?>
	<tr>
		<td style="padding:0 12px 12px;">
			<strong><?php esc_html_e( 'Dự kiến giao hàng:', 'epic-order-emails' ); ?></strong>
			<?php echo esc_html( $eta ); ?>
		</td>
	</tr>
	<?php endif; ?>
	<?php if ( $is_cod ) : ?>
	<tr>
		<td style="padding:0 12px 12px;">
			<strong><?php esc_html_e( 'Số tiền thanh toán khi nhận hàng (COD):', 'epic-order-emails' ); ?></strong>
			<?php echo wp_kses_post( wc_price( $cod_amount ) ); ?>
		</td>
	</tr>
	<?php endif; ?>
</table>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
