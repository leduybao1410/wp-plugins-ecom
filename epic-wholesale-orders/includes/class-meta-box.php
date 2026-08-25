<?php
/**
 * The order-detail metabox on the wholesale order's CPT edit screen. Shows the
 * line items, totals, and the customer's note, plus the two admin controls:
 * order status (pending/done/cancelled) and payment status. Saves go through
 * Epic_Wholesale_Orders_Store::apply_order_status() so the order-status →
 * payment-status workflow (see class-store.php) is always respected.
 *
 * Cancelling/unapproving requires a reason — the save is rejected (the status
 * is left unchanged) and an admin notice is shown if the reason is empty.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Wholesale_Order_Meta_Box {

	/** Errors collected during save, rendered via admin_notices. */
	private static $errors = array();

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_' . Epic_Wholesale_Orders_Store::POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'print_errors' ) );
	}

	public static function add_meta_box() {
		add_meta_box(
			'epic_wholesale_order_details',
			__( 'Wholesale Order', 'epic-wholesale-orders' ),
			array( __CLASS__, 'render' ),
			Epic_Wholesale_Orders_Store::POST_TYPE,
			'normal',
			'high'
		);
	}

	public static function render( $post ) {
		$order = Epic_Wholesale_Orders_Store::get_order( $post->ID );
		if ( ! $order ) {
			echo '<p>' . esc_html__( 'Order data is missing.', 'epic-wholesale-orders' ) . '</p>';
			return;
		}

		wp_nonce_field( 'epic_wholesale_order_details_' . $post->ID, 'epic_wholesale_order_details_nonce' );

		$cancel_reason = $order['cancel_reason'];
		$is_cancelled  = Epic_Wholesale_Orders_Store::STATUS_CANCELLED === $order['order_status'];
		?>
		<style>
			.epic-wo-items { width: 100%; border-collapse: collapse; }
			.epic-wo-items th, .epic-wo-items td { padding: 8px; text-align: left; border-bottom: 1px solid #e0e0e0; }
			.epic-wo-items th { background: #f7f7f7; }
			.epic-wo-total { font-weight: 700; }
		</style>

		<h3><?php esc_html_e( 'Items', 'epic-wholesale-orders' ); ?></h3>
		<table class="widefat epic-wo-items">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Product', 'epic-wholesale-orders' ); ?></th>
					<th><?php esc_html_e( 'SKU', 'epic-wholesale-orders' ); ?></th>
					<th><?php esc_html_e( 'Qty', 'epic-wholesale-orders' ); ?></th>
					<th><?php esc_html_e( 'Unit price', 'epic-wholesale-orders' ); ?></th>
					<th><?php esc_html_e( 'Line total', 'epic-wholesale-orders' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $order['items'] ) ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No items.', 'epic-wholesale-orders' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $order['items'] as $item ) : ?>
						<tr>
							<td><?php echo esc_html( $item['name'] ); ?></td>
							<td><?php echo esc_html( $item['sku'] ); ?></td>
							<td><?php echo esc_html( $item['quantity'] ); ?></td>
							<td><?php echo wp_kses_post( wc_price( $item['unit_price'] ) ); ?></td>
							<td><?php echo wp_kses_post( wc_price( $item['line_total'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<p>
			<strong><?php esc_html_e( 'Total', 'epic-wholesale-orders' ); ?>:</strong>
			<span class="epic-wo-total"><?php echo wp_kses_post( wc_price( $order['total'] ) ); ?></span>
		</p>

		<?php if ( $order['note'] ) : ?>
			<h3><?php esc_html_e( 'Note', 'epic-wholesale-orders' ); ?></h3>
			<blockquote style="border-left:4px solid #ddd; margin:8px 0; padding:4px 12px;"><?php echo esc_html( $order['note'] ); ?></blockquote>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Status', 'epic-wholesale-orders' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="epic_wo_order_status"><?php esc_html_e( 'Order status', 'epic-wholesale-orders' ); ?></label></th>
				<td>
					<select id="epic_wo_order_status" name="epic_wo_order_status">
						<?php foreach ( Epic_Wholesale_Orders_Store::order_statuses() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $order['order_status'], $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php esc_html_e( 'Marking Done sets payment to "Waiting for payment" (unless already Paid). Cancelling requires a reason and sets payment to "Canceled".', 'epic-wholesale-orders' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="epic_wo_payment_status"><?php esc_html_e( 'Payment status', 'epic-wholesale-orders' ); ?></label></th>
				<td>
					<select id="epic_wo_payment_status" name="epic_wo_payment_status">
						<?php foreach ( Epic_Wholesale_Orders_Store::payment_statuses() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $order['payment_status'], $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="epic_wo_cancel_reason"><?php esc_html_e( 'Cancel / unapprove reason', 'epic-wholesale-orders' ); ?></label></th>
				<td>
					<textarea
						id="epic_wo_cancel_reason"
						name="epic_wo_cancel_reason"
						rows="3"
						class="large-text"
						placeholder="<?php esc_attr_e( 'Required when cancelling or unapproving this order.', 'epic-wholesale-orders' ); ?>"
					><?php echo esc_textarea( $cancel_reason ); ?></textarea>
					<?php if ( $is_cancelled ) : ?>
						<p class="description"><?php esc_html_e( 'This order is cancelled. Re-saving the reason above updates it.', 'epic-wholesale-orders' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * @param int     $post_id
	 * @param \WP_Post $post
	 */
	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['epic_wholesale_order_details_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['epic_wholesale_order_details_nonce'] ) ), 'epic_wholesale_order_details_' . $post_id ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$current_status = get_post_status( $post_id );
		$posted_status  = isset( $_POST['epic_wo_order_status'] ) ? sanitize_key( wp_unslash( $_POST['epic_wo_order_status'] ) ) : $current_status;

		$valid_statuses = array_keys( Epic_Wholesale_Orders_Store::order_statuses() );
		if ( ! in_array( $posted_status, $valid_statuses, true ) ) {
			$posted_status = $current_status;
		}

		$cancel_reason = isset( $_POST['epic_wo_cancel_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['epic_wo_cancel_reason'] ) ) : '';
		$cancel_reason = trim( $cancel_reason );

		// Cancelling/unapproving requires a reason — reject the whole save.
		if ( Epic_Wholesale_Orders_Store::STATUS_CANCELLED === $posted_status && '' === $cancel_reason ) {
			self::$errors[] = __( 'To cancel or unapprove this order you must provide a reason.', 'epic-wholesale-orders' );
			return;
		}

		// Persist the reason whenever one exists (keeps history on cancel).
		if ( '' !== $cancel_reason ) {
			update_post_meta( $post_id, Epic_Wholesale_Orders_Store::META_CANCEL_REASON, $cancel_reason );
		}

		// Actual status transition: apply the payment auto-rules.
		if ( $posted_status !== $current_status ) {
			Epic_Wholesale_Orders_Store::apply_order_status( $post_id, $posted_status );
			return; // apply_order_status() already wrote the payment status.
		}

		// Status unchanged: respect a direct payment-status edit.
		$posted_payment = isset( $_POST['epic_wo_payment_status'] ) ? sanitize_key( wp_unslash( $_POST['epic_wo_payment_status'] ) ) : '';
		if ( in_array( $posted_payment, Epic_Wholesale_Orders_Store::PAYMENT_STATUSES, true ) ) {
			update_post_meta( $post_id, Epic_Wholesale_Orders_Store::META_PAYMENT_STATUS, $posted_payment );
		}
	}

	public static function print_errors() {
		if ( empty( self::$errors ) ) {
			return;
		}
		foreach ( self::$errors as $error ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $error ) . '</p></div>';
		}
	}
}
