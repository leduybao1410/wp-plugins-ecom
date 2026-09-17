<?php
/**
 * Admin "new wholesale order" notification email (plain text).
 *
 * @var string   $order_number
 * @var string   $customer_name
 * @var string   $customer_email
 * @var array    $items
 * @var string   $note
 * @var float    $total
 * @var string   $payment_status
 * @var bool     $is_vip
 * @var bool     $priced
 * @var string   $email_heading
 * @var string   $additional_content
 * @var WC_Email $email
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Guard against direct template use / WC email preview with no order payload.
$is_vip  = isset( $is_vip ) ? $is_vip : false;
$priced  = isset( $priced ) ? $priced : true;

$show_prices = ! $is_vip || $priced;

echo "= " . esc_html( $email_heading ) . " =\n\n";

echo esc_html__( 'Có một đơn hàng sỉ mới được gửi từ website.', 'epic-wholesale-orders' ) . "\n\n";
echo esc_html( $order_number ) . ' — ' . esc_html( $customer_name ) . ' (' . esc_html( $customer_email ) . ")\n\n";

if ( $is_vip && ! $priced ) {
	echo esc_html__( 'Đơn hàng VIP — khách hàng không thấy giá. Vui lòng định giá từng sản phẩm trong wp-admin trước khi duyệt đơn.', 'epic-wholesale-orders' ) . "\n\n";
}

echo esc_html__( 'Danh sách sản phẩm', 'epic-wholesale-orders' ) . ":\n";
foreach ( $items as $item ) {
	if ( isset( $item['line_total'] ) && '' !== $item['line_total'] && is_numeric( $item['line_total'] ) ) {
		/* translators: 1: quantity, 2: product name, 3: line total */
		echo esc_html( sprintf( '- %1$d x %2$s = %3$s', $item['quantity'], $item['name'], wp_strip_all_tags( wc_price( $item['line_total'] ) ) ) ) . "\n";
	} else {
		echo esc_html( sprintf( '- %1$d x %2$s (chưa định giá)', $item['quantity'], $item['name'] ) ) . "\n";
	}
}

if ( $show_prices ) {
	echo "\n" . esc_html__( 'Tổng', 'epic-wholesale-orders' ) . ': ' . esc_html( wp_strip_all_tags( wc_price( $total ) ) ) . "\n\n";
}

if ( $note ) {
	echo esc_html__( 'Ghi chú của khách hàng', 'epic-wholesale-orders' ) . ': ' . esc_html( $note ) . "\n\n";
}

echo esc_html__( 'Xử lý đơn hàng này trong WooCommerce → Wholesale Orders.', 'epic-wholesale-orders' ) . "\n\n";

if ( $additional_content ) {
	echo "----------\n\n";
	echo esc_html( $additional_content ) . "\n\n";
}

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
