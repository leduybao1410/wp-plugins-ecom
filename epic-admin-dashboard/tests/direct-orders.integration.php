<?php
// Run only inside the disposable direct-order WordPress/WooCommerce fixture.
if ( getenv( 'WORDPRESS_DB_PASSWORD' ) !== 'isolated-security-test' || getenv( 'EPIC_DIRECT_ORDER_TEST' ) !== '1' ) { exit( 'Wrong test environment' ); }
if ( '1' === getenv( 'EPIC_DIRECT_ORDER_BOOTSTRAP' ) ) { define( 'WP_INSTALLING', true ); }
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
if ( ! is_blog_installed() ) {
	wp_install( 'Direct order fixture', 'direct-order-fixture', 'fixture@example.invalid', false, '', 'isolated-test-password' );
	$activation = activate_plugin( 'woocommerce/woocommerce.php' );
	if ( is_wp_error( $activation ) ) { throw new RuntimeException( $activation->get_error_message() ); }
	$activation = activate_plugin( 'epic-admin-dashboard/epic-admin-dashboard.php' );
	if ( is_wp_error( $activation ) ) { throw new RuntimeException( $activation->get_error_message() ); }
	echo "Fresh fixture installed; rerun this script for integration checks.\n";
	exit( 0 );
}
if ( ! class_exists( 'WooCommerce' ) || version_compare( WC()->version, '11.0.1', '!=' ) ) { throw new RuntimeException( 'This fixture requires WooCommerce 11.0.1.' ); }
if ( ! class_exists( 'Epic_Admin_Dashboard' ) ) { throw new RuntimeException( 'The dashboard adapter must be active.' ); }

function direct_order_check( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $message ); }
	echo "PASS: {$message}\n";
}
function direct_order_fresh_stock( $product_id ) {
	wp_cache_delete( $product_id, 'post_meta' );
	return (int) get_post_meta( $product_id, '_stock', true );
}
function direct_order_response_data( $response ) { return $response instanceof WP_REST_Response ? $response->get_data() : $response; }
function direct_order_call( $method, $body, $key = '' ) {
	$request = new WP_REST_Request( $method, '/epic-admin/v1/orders/' . ( 'preview' === $method ? 'preview' : 'apply' ) );
	$request->set_header( 'content-type', 'application/json' );
	if ( '' !== $key ) { $request->set_header( 'idempotency-key', $key ); }
	$request->set_body( wp_json_encode( $body ) );
	return 'preview' === $method ? Epic_Admin_Dashboard::order_preview( $request ) : Epic_Admin_Dashboard::order_apply( $request );
}
if ( isset( $argv[1] ) && '--apply-worker' === $argv[1] ) {
	$worker_user = get_user_by( 'login', 'direct-order-fixture' );
	if ( $worker_user ) { wp_set_current_user( $worker_user->ID ); }
	add_filter( 'pre_wp_mail', static function () { return true; }, 10, 2 );
	$barrier = (string) ( $argv[4] ?? '' );
	$deadline = microtime( true ) + 10;
	while ( '' !== $barrier && ! file_exists( $barrier ) && microtime( true ) < $deadline ) { usleep( 10000 ); }
	$worker_result = direct_order_call( 'apply', array( 'preview_token' => (string) ( $argv[2] ?? '' ) ), (string) ( $argv[3] ?? '' ) );
	if ( is_wp_error( $worker_result ) ) { echo wp_json_encode( array( 'ok' => false, 'code' => $worker_result->get_error_code(), 'status' => (int) $worker_result->get_error_data()['status'] ) ); }
	else { $worker_result = direct_order_response_data( $worker_result ); echo wp_json_encode( array( 'ok' => true, 'id' => (int) $worker_result['id'] ) ); }
	exit( 0 );
}

$fixture_user = get_user_by( 'login', 'direct-order-fixture' );
if ( ! $fixture_user ) { $fixture_user_id = wp_insert_user( array( 'user_login' => 'direct-order-fixture', 'user_pass' => 'isolated-test-password', 'user_email' => 'fixture@example.invalid', 'role' => 'administrator' ) ); $fixture_user = get_user_by( 'id', $fixture_user_id ); }
$fixture_user->set_role( 'administrator' );
wp_set_current_user( $fixture_user->ID );
$captured_mail = array();
add_filter( 'pre_wp_mail', static function ( $return, $atts ) use ( &$captured_mail ) { $captured_mail[] = $atts; return true; }, 10, 2 );
$wc_probe = new WC_Order();
direct_order_check( ! method_exists( $wc_probe, 'set_paid' ), 'WooCommerce 11.0.1 does not expose the unsupported set_paid method' );
$product = new WC_Product_Simple();
$product->set_name( 'Disposable direct-order QA product' );
$product->set_status( 'publish' );
$product->set_regular_price( '123000' );
$product->set_manage_stock( true );
$product->set_stock_quantity( 5 );
$product_id = $product->save();
$draft = array(
	'action' => 'create',
	'customer' => array( 'first_name' => 'QA', 'last_name' => 'Test', 'phone' => '0900000001', 'email' => '', 'address_1' => '12 Le Loi', 'address_2' => '', 'province_id' => 79, 'province_name' => 'Thanh pho Ho Chi Minh', 'ward_id' => 26734, 'ward_name' => 'Phuong Sai Gon', 'postcode' => '' ),
	'fulfillment' => 'courier', 'shipping_fee' => 30000,
	'items' => array( array( 'product_id' => $product_id, 'variation_id' => 0, 'quantity' => 1, 'unit_price' => 123000, 'override_reason' => '' ) ),
	'coupon_codes' => array(), 'manual_discount' => null, 'fee_lines' => array(), 'note' => 'Disposable direct-order integration test',
);
$invalid_drafts = array();
$invalid = $draft; $invalid['customer']['first_name'] = ''; $invalid['customer']['last_name'] = ''; $invalid_drafts['missing name'] = $invalid;
$invalid = $draft; $invalid['customer']['phone'] = 'bad'; $invalid_drafts['bad phone'] = $invalid;
$invalid = $draft; $invalid['customer']['province_id'] = 0; $invalid['customer']['province_name'] = ''; $invalid_drafts['missing province'] = $invalid;
$invalid = $draft; $invalid['items'] = array(); $invalid_drafts['empty items'] = $invalid;
$orders_before = wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'status' => array_keys( wc_get_order_statuses() ) ) );
foreach ( $invalid_drafts as $label => $invalid_draft ) {
	$response = direct_order_call( 'preview', $invalid_draft );
	direct_order_check( is_wp_error( $response ) && 400 === (int) $response->get_error_data()['status'], $label . ' returns HTTP 400' );
}
$orders_after_invalid = wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'status' => array_keys( wc_get_order_statuses() ) ) );
direct_order_check( count( $orders_before ) === count( $orders_after_invalid ), 'invalid previews create no orders' );

$preview = direct_order_response_data( direct_order_call( 'preview', $draft ) );
if ( is_wp_error( $preview ) ) { throw new RuntimeException( 'Valid preview failed: ' . $preview->get_error_code() . ' ' . $preview->get_error_message() ); }
direct_order_check( is_array( $preview ) && ! empty( $preview['preview_token'] ), 'valid preview returns a token' );
direct_order_check( 153000.0 === (float) $preview['totals']['total'], 'preview recomputes the expected total' );
$pending_preview = direct_order_response_data( direct_order_call( 'preview', $draft ) );
$pending_key = wp_generate_uuid4();
$pending_hash = hash( 'sha256', $pending_key . ':order-direct-apply:' . get_current_user_id() );
$wpdb->insert( $wpdb->prefix . 'epic_admin_receipts', array( 'receipt_key' => $pending_hash, 'user_id' => get_current_user_id(), 'action' => 'order-direct-apply', 'outcome' => 'pending', 'response' => wp_json_encode( array( 'preview_hash' => hash( 'sha256', $pending_preview['preview_token'] ), 'result' => null ) ), 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$pending_result = direct_order_call( 'apply', array( 'preview_token' => $pending_preview['preview_token'] ), $pending_key );
direct_order_check( is_wp_error( $pending_result ) && 'mutation_reconcile' === $pending_result->get_error_code() && 409 === (int) $pending_result->get_error_data()['status'], 'pending same-key request returns mutation_reconcile' );
$wpdb->delete( $wpdb->prefix . 'epic_admin_receipts', array( 'receipt_key' => $pending_hash ) );
$key = wp_generate_uuid4();
$applied = direct_order_response_data( direct_order_call( 'apply', array( 'preview_token' => $preview['preview_token'] ), $key ) );
direct_order_check( ! empty( $applied['ok'] ) && 'on-hold' === $applied['status'], 'apply creates an on-hold order' );
$order_id = (int) $applied['id'];
$order = wc_get_order( $order_id );
$line_items = $order->get_items();
$line_item = reset( $line_items );
$product = wc_get_product( $product_id );
direct_order_check( 'cod' === $order->get_payment_method() && ! $order->get_date_paid(), 'order is COD and unpaid' );
direct_order_check( 'QA' === $order->get_billing_first_name() && 'Test' === $order->get_billing_last_name() && '0900000001' === $order->get_billing_phone() && '12 Le Loi' === $order->get_billing_address_1(), 'customer name, phone and street persist' );
direct_order_check( 'Phuong Sai Gon' === $order->get_shipping_address_2(), 'selected ward persists in shipping address_2' );
direct_order_check( $line_item && 123000.0 === (float) $line_item->get_total(), 'line item price persists' );
direct_order_check( 30000.0 === (float) $order->get_shipping_total() && 153000.0 === (float) $order->get_total(), 'shipping and order total persist' );
direct_order_check( 4 === (int) $product->get_stock_quantity(), 'stock is reduced exactly once' );
direct_order_check( is_array( $captured_mail ), 'outbound mail is intercepted by the disposable fixture' );
$replay = direct_order_response_data( direct_order_call( 'apply', array( 'preview_token' => $preview['preview_token'] ), $key ) );
direct_order_check( (int) $replay['id'] === $order_id && 4 === (int) wc_get_product( $product_id )->get_stock_quantity(), 'same key and token replay the order without a second stock reduction' );
$second_preview = direct_order_response_data( direct_order_call( 'preview', $draft ) );
$conflict = direct_order_call( 'apply', array( 'preview_token' => $second_preview['preview_token'] ), $key );
direct_order_check( is_wp_error( $conflict ) && 'idempotency_conflict' === $conflict->get_error_code() && 409 === (int) $conflict->get_error_data()['status'], 'same key with another token returns idempotency_conflict' );
$consumed = direct_order_call( 'apply', array( 'preview_token' => $preview['preview_token'] ), wp_generate_uuid4() );
direct_order_check( is_wp_error( $consumed ) && 'preview_expired' === $consumed->get_error_code() && 409 === (int) $consumed->get_error_data()['status'], 'consumed preview is rejected with a fresh key' );

$failure_preview = direct_order_response_data( direct_order_call( 'preview', $draft ) );
$orders_before_failure = wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'status' => array_keys( wc_get_order_statuses() ) ) );
$stock_before_failure = (int) wc_get_product( $product_id )->get_stock_quantity();
$fail_save = static function ( $order ) { if ( 'epic-admin' === $order->get_created_via() && $order->get_id() && $order->get_item_count( 'line_item' ) > 0 ) { throw new RuntimeException( 'forced disposable persistence failure' ); } };
add_action( 'woocommerce_before_order_object_save', $fail_save, 10, 1 );
$failed_apply = direct_order_call( 'apply', array( 'preview_token' => $failure_preview['preview_token'] ), wp_generate_uuid4() );
remove_action( 'woocommerce_before_order_object_save', $fail_save, 10 );
$orders_after_failure = wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'status' => array_keys( wc_get_order_statuses() ) ) );
direct_order_check( is_wp_error( $failed_apply ) && 'order_save_failed' === $failed_apply->get_error_code(), 'forced order persistence failure returns order_save_failed' );
direct_order_check( count( $orders_before_failure ) === count( $orders_after_failure ) && $stock_before_failure === (int) wc_get_product( $product_id )->get_stock_quantity(), 'persistence rollback leaves no order and does not reduce stock' );

$paid_at = new WC_DateTime( '2024-01-01 12:00:00', new DateTimeZone( 'UTC' ) );
$order->set_date_paid( $paid_at ); $order->save();
$detail_request = new WP_REST_Request( 'GET', '/epic-admin/v1/records/orders/' . $order_id );
$detail_request->set_url_params( array( 'resource' => 'orders', 'id' => $order_id ) );
$detail = direct_order_response_data( Epic_Admin_Dashboard::record( $detail_request ) );
$edit = $draft; $edit['action'] = 'update'; $edit['order_id'] = $order_id; $edit['expected_revision'] = $detail['revision'];
$edit_preview = direct_order_response_data( direct_order_call( 'preview', $edit ) );
$edited = direct_order_response_data( direct_order_call( 'apply', array( 'preview_token' => $edit_preview['preview_token'] ), wp_generate_uuid4() ) );
direct_order_check( ! empty( $edited['ok'] ) && $paid_at->getTimestamp() === wc_get_order( $order_id )->get_date_paid()->getTimestamp(), 'edit preserves existing paid date' );
direct_order_check( 'QA' === wc_get_order( $order_id )->get_billing_first_name() && 'Phuong Sai Gon' === wc_get_order( $order_id )->get_shipping_address_2(), 'edit preserves customer and structured address' );

$concurrent_preview = direct_order_response_data( direct_order_call( 'preview', $draft ) );
$concurrent_key = wp_generate_uuid4();
$stock_before_concurrent = direct_order_fresh_stock( $product_id );
$barrier = sys_get_temp_dir() . '/epic-direct-order-start-' . wp_generate_uuid4();
$workers = array();
for ( $worker = 0; $worker < 2; $worker++ ) {
	$command = array( PHP_BINARY, __FILE__, '--apply-worker', $concurrent_preview['preview_token'], $concurrent_key, $barrier );
	$descriptors = array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
	$process = proc_open( $command, $descriptors, $pipes, null, null, array( 'bypass_shell' => true ) );
	if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Unable to start concurrent disposable apply worker.' ); }
	fclose( $pipes[0] ); $workers[] = array( 'process' => $process, 'pipes' => $pipes );
}
touch( $barrier ); // Release both already-started PHP workers together.
$worker_results = array();
foreach ( $workers as $worker ) {
	$stdout = stream_get_contents( $worker['pipes'][1] ); $stderr = stream_get_contents( $worker['pipes'][2] );
	fclose( $worker['pipes'][1] ); fclose( $worker['pipes'][2] );
	$exit_code = proc_close( $worker['process'] );
	if ( 0 !== $exit_code || '' !== trim( $stderr ) ) { throw new RuntimeException( 'Concurrent apply worker failed: ' . trim( $stderr ) ); }
	$worker_results[] = json_decode( $stdout, true );
}
@unlink( $barrier );
$successful_worker_ids = array_values( array_unique( array_map( static function ( $result ) { return ! empty( $result['ok'] ) ? (int) $result['id'] : 0; }, $worker_results ) ) );
$successful_worker_ids = array_values( array_filter( $successful_worker_ids ) );
$valid_worker_results = 2 === count( $worker_results ) && 1 === count( $successful_worker_ids );
foreach ( $worker_results as $worker_result ) { if ( empty( $worker_result['ok'] ) && ! in_array( $worker_result['code'], array( 'mutation_reconcile' ), true ) ) { $valid_worker_results = false; } }
$concurrent_order_id = $successful_worker_ids[0] ?? 0;
$valid_worker_results = $valid_worker_results && $concurrent_order_id > 0 && wc_get_order( $concurrent_order_id );
if ( ! $valid_worker_results ) { echo 'Concurrent worker results: ' . wp_json_encode( $worker_results ) . "\n"; }
direct_order_check( $valid_worker_results, 'concurrent same-key applies resolve to one order or one replay plus reconciliation' );
clean_post_cache( $product_id );
wp_cache_delete( $product_id, 'products' );
$product = wc_get_product( $product_id );
$stock_after_concurrent = direct_order_fresh_stock( $product_id );
direct_order_check( ( $stock_before_concurrent - 1 ) === $stock_after_concurrent, 'concurrent same-key applies reduce stock once (' . $stock_before_concurrent . ' to ' . $stock_after_concurrent . ')' );

// Remove the disposable record and restore the disposable product inventory.
$order = wc_get_order( $order_id );
if ( $order ) { $order->update_status( 'cancelled', 'Disposable integration test cleanup.' ); wc_increase_stock_levels( $order_id ); $order->delete( true ); }
$concurrent_order = $concurrent_order_id ? wc_get_order( $concurrent_order_id ) : false;
if ( $concurrent_order ) { $concurrent_order->update_status( 'cancelled', 'Disposable integration test cleanup.' ); wc_increase_stock_levels( $concurrent_order_id ); $concurrent_order->delete( true ); }
wp_delete_post( $product_id, true );
echo "WooCommerce 11.0.1 direct-order integration checks passed.\n";
