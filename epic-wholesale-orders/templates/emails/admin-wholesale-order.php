<?php
/**
 * Admin "new wholesale order" notification email (HTML).
 *
 * Vietnamese-only content — same decision as every other EPIC email (see the
 * docblock on class-email-wholesale-order-admin.php).
 *
 * @var string   $order_number
 * @var string   $customer_name
 * @var string   $customer_email
 * @var array    $items
 * @var string   $note
 * @var float    $total
 * @var string   $payment_status
 * @var string   $email_heading
 * @var string   $additional_content
 * @var WC_Email $email
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p>
	<?php
	printf(
		/* translators: 1: order number, 2: customer name */
		esc_html__( 'Có một đơn hàng sỉ mới %1$s từ khách hàng %2$s (%3$s).', 'epic-wholesale-orders' ),
		'<strong>' . esc_html( $order_number ) . '</strong>',
		esc_html( $customer_name ),
		esc_html( $customer_email )
	);
	?>
</p>

<table cellspacing="0" cellpadding="6" style="width:100%; border:1px solid #e5e5e5; margin: 16px 0;">
	<thead>
		<tr>
			<th style="padding:12px; background:#f7f7f7; text-align:left;"><?php esc_html_e( 'Sản phẩm', 'epic-wholesale-orders' ); ?></th>
			<th style="padding:12px; background:#f7f7f7; text-align:left;"><?php esc_html_e( 'SKU', 'epic-wholesale-orders' ); ?></th>
			<th style="padding:12px; background:#f7f7f7; text-align:left;"><?php esc_html_e( 'Số lượng', 'epic-wholesale-orders' ); ?></th>
			<th style="padding:12px; background:#f7f7f7; text-align:right;"><?php esc_html_e( 'Đơn giá', 'epic-wholesale-orders' ); ?></th>
			<th style="padding:12px; background:#f7f7f7; text-align:right;"><?php esc_html_e( 'Thành tiền', 'epic-wholesale-orders' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $items as $item ) : ?>
			<tr>
				<td style="padding:12px;"><?php echo esc_html( $item['name'] ); ?></td>
				<td style="padding:12px;"><?php echo esc_html( $item['sku'] ); ?></td>
				<td style="padding:12px;"><?php echo esc_html( $item['quantity'] ); ?></td>
				<td style="padding:12px; text-align:right;"><?php echo wp_kses_post( wc_price( $item['unit_price'] ) ); ?></td>
				<td style="padding:12px; text-align:right;"><?php echo wp_kses_post( wc_price( $item['line_total'] ) ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
	<tfoot>
		<tr>
			<th colspan="4" style="padding:12px; text-align:right;"><?php esc_html_e( 'Tổng', 'epic-wholesale-orders' ); ?></th>
			<td style="padding:12px; text-align:right;"><strong><?php echo wp_kses_post( wc_price( $total ) ); ?></strong></td>
		</tr>
	</tfoot>
</table>

<?php if ( $note ) : ?>
	<p>
		<strong><?php esc_html_e( 'Ghi chú của khách hàng', 'epic-wholesale-orders' ); ?>:</strong><br/>
		<?php echo esc_html( $note ); ?>
	</p>
<?php endif; ?>

<p style="margin-top:24px;">
	<?php esc_html_e( 'Xử lý đơn hàng này trong WooCommerce → Wholesale Orders.', 'epic-wholesale-orders' ); ?>
</p>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
