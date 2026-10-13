<?php
// Run only in the disposable epic-security-release-wp container.
if ( getenv( 'WORDPRESS_DB_PASSWORD' ) !== 'isolated-security-test' ) { exit( 'Wrong test environment' ); }
$_SERVER['HTTP_HOST'] = 'localhost:8097';
$_SERVER['REQUEST_URI'] = '/';
require '/var/www/html/wp-load.php';
if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'Epic_Admin_Dashboard' ) || ! class_exists( 'Epic_VTP_Client' ) || ! class_exists( 'Epic_VTP_Order_Meta_Box' ) ) { exit( 'Required test plugins are not active' ); }
Epic_Admin_Dashboard::init();
function verify_shipment_link( $condition, $message ) { if ( ! $condition ) { throw new Exception( $message ); } echo "PASS: $message\n"; }
function shipment_link_order() {
	$order = wc_create_order( array( 'status' => 'processing' ) );
	if ( is_wp_error( $order ) ) { throw new Exception( $order->get_error_message() ); }
	$order->set_billing_first_name( 'Shipment' ); $order->set_billing_last_name( 'Fixture' ); $order->set_billing_phone( '0900000000' );
	$order->set_shipping_first_name( 'Shipment' ); $order->set_shipping_last_name( 'Fixture' ); $order->set_payment_method( 'cod' ); $order->set_total( 150000 ); $order->save();
	return wc_get_order( $order->get_id() );
}
function shipment_link_request( $order_id, $body, $key = 'shipment-link-key-0001' ) {
	$request = new WP_REST_Request( 'POST', '/epic-admin/v1/shipments/' . $order_id . '/action' );
	$request->set_param( 'order_id', $order_id ); $request->set_header( 'content-type', 'application/json' ); $request->set_header( 'idempotency-key', $key ); $request->set_body( wp_json_encode( $body ) );
	return Epic_Admin_Dashboard::shipment_action( $request );
}
function shipment_link_error_code( $response ) { return is_wp_error( $response ) ? $response->get_error_code() : ''; }

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $admins ) { throw new Exception( 'No administrator fixture exists' ); }
wp_set_current_user( $admins[0]->ID );
global $wpdb;
$orders = array(); $email_events = 0; $status_events = 0;
add_action( 'epic_vtp_shipment_booked', static function () use ( &$email_events ) { $email_events++; } );
add_action( 'epic_vtp_status_changed', static function () use ( &$status_events ) { $status_events++; } );

$order = shipment_link_order(); $orders[] = $order->get_id(); $waybill = '154456463720';
$invalid = shipment_link_request( $order->get_id(), array( 'action' => 'link', 'tracking' => 'bad', 'confirmed_match' => true ) );
verify_shipment_link( shipment_link_error_code( $invalid ) === 'tracking_invalid', 'invalid waybill is rejected before writing' );
$unconfirmed = shipment_link_request( $order->get_id(), array( 'action' => 'link', 'tracking' => $waybill ) );
verify_shipment_link( shipment_link_error_code( $unconfirmed ) === 'shipment_confirmation_required', 'missing shipment match confirmation is rejected' );

$key = 'shipment-link-key-success-0001';
$linked = shipment_link_request( $order->get_id(), array( 'action' => 'link', 'tracking' => $waybill, 'confirmed_match' => true, 'status' => '102' ), $key );
verify_shipment_link( ! is_wp_error( $linked ) && $linked->get_data()['trackingCode'] === $waybill, 'existing waybill links to WooCommerce order' );
$saved = wc_get_order( $order->get_id() );
verify_shipment_link( $saved->get_meta( Epic_VTP_Order_Meta_Box::META_ORDER_NUMBER ) === $waybill, 'waybill persists through WooCommerce CRUD' );
verify_shipment_link( $saved->get_meta( Epic_VTP_Order_Meta_Box::META_STATUS ) === '102' && (float) $saved->get_meta( Epic_VTP_Order_Meta_Box::META_COD_AMOUNT ) === 150000.0, 'confirmed status and order COD total persist' );
verify_shipment_link( $saved->get_meta( Epic_VTP_Order_Meta_Box::META_LAST_SYNCED ) === '', 'manual link does not claim a courier synchronization time' );
$detail_request = new WP_REST_Request( 'GET', '/epic-admin/v1/records/shipments/' . $order->get_id() );
$detail_request->set_param( 'resource', 'shipments' ); $detail_request->set_param( 'id', $order->get_id() );
$detail = Epic_Admin_Dashboard::record( $detail_request );
verify_shipment_link( ! is_wp_error( $detail ) && $detail->get_data()['tracking'] === $waybill, 'shipment detail can be read directly by order ID' );
$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
$mapping_notes = array_filter( $notes, static function ( $note ) use ( $waybill ) { return false !== strpos( $note->content, 'manually linked' ) && false !== strpos( $note->content, $waybill ); } );
verify_shipment_link( count( $mapping_notes ) === 1, 'one internal mapping note is recorded' );
$replay = shipment_link_request( $order->get_id(), array( 'action' => 'link', 'tracking' => $waybill, 'confirmed_match' => true, 'status' => '102' ), $key );
verify_shipment_link( ! is_wp_error( $replay ) && count( array_filter( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), static function ( $note ) use ( $waybill ) { return false !== strpos( $note->content, 'manually linked' ) && false !== strpos( $note->content, $waybill ); } ) ) === 1, 'same idempotency request replays without a duplicate note' );
$conflict = shipment_link_request( $order->get_id(), array( 'action' => 'link', 'tracking' => '154456463721', 'confirmed_match' => true ), $key );
verify_shipment_link( shipment_link_error_code( $conflict ) === 'idempotency_conflict', 'idempotency key cannot be reused with a different payload' );
verify_shipment_link( $email_events === 0 && $status_events === 0, 'manual mapping fires no shipment or status notification hooks' );

$duplicate = shipment_link_order(); $orders[] = $duplicate->get_id();
$duplicate_result = shipment_link_request( $duplicate->get_id(), array( 'action' => 'link', 'tracking' => $waybill, 'confirmed_match' => true ) );
verify_shipment_link( shipment_link_error_code( $duplicate_result ) === 'tracking_conflict', 'a waybill already linked to another order is rejected' );

$uncertain = shipment_link_order(); $orders[] = $uncertain->get_id();
$receipts = $wpdb->prefix . 'epic_admin_receipts';
$wpdb->insert( $receipts, array( 'receipt_key' => hash( 'sha256', 'shipment-link-test-uncertain-book' ), 'user_id' => get_current_user_id(), 'action' => 'shipment-book:' . $uncertain->get_id(), 'outcome' => 'uncertain', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$reconciled = shipment_link_request( $uncertain->get_id(), array( 'action' => 'link', 'tracking' => '154456463722', 'confirmed_match' => true ) );
$booking = $wpdb->get_row( $wpdb->prepare( "SELECT outcome FROM $receipts WHERE action = %s", 'shipment-book:' . $uncertain->get_id() ) );
verify_shipment_link( ! is_wp_error( $reconciled ) && $booking && $booking->outcome === 'success', 'manual link resolves one uncertain booking' );

$cancel = shipment_link_order(); $orders[] = $cancel->get_id();
$wpdb->insert( $receipts, array( 'receipt_key' => hash( 'sha256', 'shipment-link-test-uncertain-cancel' ), 'user_id' => get_current_user_id(), 'action' => 'shipment-cancel:' . $cancel->get_id(), 'outcome' => 'uncertain', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$blocked = shipment_link_request( $cancel->get_id(), array( 'action' => 'link', 'tracking' => '154456463723', 'confirmed_match' => true ) );
verify_shipment_link( shipment_link_error_code( $blocked ) === 'shipment_reconcile', 'uncertain cancellation blocks manual linking' );

$active = shipment_link_order(); $orders[] = $active->get_id();
$wpdb->insert( $receipts, array( 'receipt_key' => hash( 'sha256', 'shipment-link-test-pending-book' ), 'user_id' => get_current_user_id(), 'action' => 'shipment-book:' . $active->get_id(), 'outcome' => 'pending', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$active_result = shipment_link_request( $active->get_id(), array( 'action' => 'link', 'tracking' => '154456463725', 'confirmed_match' => true ) );
verify_shipment_link( shipment_link_error_code( $active_result ) === 'shipment_busy', 'active courier request blocks manual linking' );

$multiple = shipment_link_order(); $orders[] = $multiple->get_id();
foreach ( array( 'shipment-book:', 'shipment-cancel:' ) as $index => $action ) { $wpdb->insert( $receipts, array( 'receipt_key' => hash( 'sha256', 'shipment-link-test-multiple-' . $index ), 'user_id' => get_current_user_id(), 'action' => $action . $multiple->get_id(), 'outcome' => 'uncertain', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) ); }
$multiple_result = shipment_link_request( $multiple->get_id(), array( 'action' => 'link', 'tracking' => '154456463726', 'confirmed_match' => true ) );
verify_shipment_link( shipment_link_error_code( $multiple_result ) === 'shipment_reconcile', 'multiple unresolved courier actions block manual linking' );

$failed_save = shipment_link_order(); $orders[] = $failed_save->get_id();
$throw_on_save = static function ( $object ) { if ( $object instanceof WC_Order ) { throw new RuntimeException( 'Injected disposable save failure' ); } };
add_action( 'woocommerce_before_order_object_save', $throw_on_save, 10, 2 );
$save_result = shipment_link_request( $failed_save->get_id(), array( 'action' => 'link', 'tracking' => '154456463727', 'confirmed_match' => true ) );
remove_action( 'woocommerce_before_order_object_save', $throw_on_save, 10 );
verify_shipment_link( shipment_link_error_code( $save_result ) === 'shipment_link_save_failed' && ! wc_get_order( $failed_save->get_id() )->get_meta( Epic_VTP_Order_Meta_Box::META_ORDER_NUMBER ), 'failed persistence returns an error and does not report a link' );

$permission_order = shipment_link_order(); $orders[] = $permission_order->get_id();
$subscriber = get_users( array( 'role' => 'subscriber', 'number' => 1 ) );
if ( $subscriber ) {
	wp_set_current_user( $subscriber[0]->ID );
	$denied = shipment_link_request( $permission_order->get_id(), array( 'action' => 'link', 'tracking' => '154456463724', 'confirmed_match' => true ) );
	verify_shipment_link( shipment_link_error_code( $denied ) === 'forbidden', 'users without order capability cannot link shipments' );
}
wp_set_current_user( $admins[0]->ID );

foreach ( $orders as $id ) { $order = wc_get_order( $id ); if ( $order ) { $order->delete( true ); } }
echo "Shipment link integration checks passed.\n";
