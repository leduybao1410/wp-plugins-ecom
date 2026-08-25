<?php
/**
 * Customer "wholesale order received" confirmation email (HTML).
 *
 * Vietnamese-only content — same decision as every other EPIC email.
 *
 * @var string   $order_number
 * @var string   $customer_name
 * @var array    $items
 * @var string   $note
 * @var float    $total
 * @var string   $level_name
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
		/* translators: 1: customer name, 2: order number */
		esc_html__( 'Chào %1$s, chúng tôi đã nhận được đơn hàng sỉ %2$s của bạn.', 'epic-wholesale-orders' ),
		esc_html( $customer_name ),
		'<strong>' . esc_html( $order_number ) . '</strong>'
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
	<p><strong><?php esc_html_e( 'Ghi chú của bạn', 'epic-wholesale-orders' ); ?>:</strong><br/><?php echo esc_html( $note ); ?></p>
<?php endif; ?>

<?php if ( $level_name ) : ?>
	<p><strong><?php esc_html_e( 'Mức giá', 'epic-wholesale-orders' ); ?>:</strong> <?php echo esc_html( $level_name ); ?></p>
<?php endif; ?>

<p style="margin-top:24px;">
	<?php esc_html_e( 'Chúng tôi sẽ xác nhận đơn hàng và liên hệ với bạn sớm nhất có thể.', 'epic-wholesale-orders' ); ?>
</p>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
