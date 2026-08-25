<?php
/**
 * Customer "wholesale order received" confirmation email (plain text).
 *
 * @var string   $order_number
 * @var string   $customer_name
 * @var array    $items
 * @var string   $note
 * @var float    $total
 * @var string   $email_heading
 * @var string   $additional_content
 * @var WC_Email $email
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo "= " . esc_html( $email_heading ) . " =\n\n";

echo esc_html(
	sprintf(
		/* translators: 1: customer name, 2: order number */
		__( 'Chào %1$s, chúng tôi đã nhận được đơn hàng sỉ %2$s của bạn.', 'epic-wholesale-orders' ),
		$customer_name,
		$order_number
	)
) . "\n\n";

echo esc_html__( 'Danh sách sản phẩm', 'epic-wholesale-orders' ) . ":\n";
foreach ( $items as $item ) {
	/* translators: 1: quantity, 2: product name, 3: line total */
	echo esc_html( sprintf( '- %1$d x %2$s = %3$s', $item['quantity'], $item['name'], wp_strip_all_tags( wc_price( $item['line_total'] ) ) ) ) . "\n";
}

echo "\n" . esc_html__( 'Tổng', 'epic-wholesale-orders' ) . ': ' . esc_html( wp_strip_all_tags( wc_price( $total ) ) ) . "\n\n";

if ( $note ) {
	echo esc_html__( 'Ghi chú của bạn', 'epic-wholesale-orders' ) . ': ' . esc_html( $note ) . "\n\n";
}

echo esc_html__( 'Chúng tôi sẽ xác nhận đơn hàng và liên hệ với bạn sớm nhất có thể.', 'epic-wholesale-orders' ) . "\n\n";

if ( $additional_content ) {
	echo "----------\n\n";
	echo esc_html( $additional_content ) . "\n\n";
}

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
