<?php
/**
 * The order-detail metabox on the wholesale order's CPT edit screen. Shows the
 * line items, totals, and the customer's note, plus the two admin controls:
 * order status (pending/done/cancelled) and payment status. Saves go through
 * Epic_Wholesale_Orders_Store::apply_order_status() so the order-status →
 * payment-status workflow (see class-store.php) is always respected.
 *
 * VIP orders (see Store::is_vip()) are recorded without prices; while the order
 * is pending or approved the metabox shows an editable unit-price input per line
 * item so the seller can set a final price for each. A VIP order cannot be
 * Approved until every item is priced (the price the seller enters is final).
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
		$is_cancelled  = in_array( $order['order_status'], array( Epic_Wholesale_Orders_Store::STATUS_UNAPPROVED, Epic_Wholesale_Orders_Store::STATUS_CANCELLED ), true );

		// VIP orders carry no prices at submission — the seller enters a final
		// price per item here while the order is pending/approved. Done and
		// unapproved orders are read-only.
		$is_vip    = ! empty( $order['is_vip'] );
		$priced    = ! empty( $order['priced'] );
		$can_price = $is_vip && in_array( $order['order_status'], array( Epic_Wholesale_Orders_Store::STATUS_PENDING, Epic_Wholesale_Orders_Store::STATUS_APPROVED ), true );
		?>
		<style>
			.epic-wo-items { width: 100%; border-collapse: collapse; }
			.epic-wo-items th, .epic-wo-items td { padding: 8px; text-align: left; border-bottom: 1px solid #e0e0e0; }
			.epic-wo-items th { background: #f7f7f7; }
			.epic-wo-total { font-weight: 700; }
		</style>

		<h3><?php esc_html_e( 'Items', 'epic-wholesale-orders' ); ?></h3>
		<?php if ( $is_vip ) : ?>
			<p class="description">
				<?php if ( $priced ) : ?>
					<?php esc_html_e( 'VIP order — the prices below were set by the seller and are final (no level discount applies).', 'epic-wholesale-orders' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'VIP order — this customer does not see prices. Set a price for every item below; the order cannot be Approved until every item is priced.', 'epic-wholesale-orders' ); ?>
				<?php endif; ?>
			</p>
		<?php endif; ?>
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
					<?php foreach ( $order['items'] as $item ) :
						$item_price = ( isset( $item['unit_price'] ) && '' !== $item['unit_price'] && (float) $item['unit_price'] > 0 ) ? (float) $item['unit_price'] : null;
						$item_total = null !== $item_price ? $item_price * (int) $item['quantity'] : null;
						?>
						<tr>
							<td><?php echo esc_html( $item['name'] ); ?></td>
							<td><?php echo esc_html( $item['sku'] ); ?></td>
							<td><?php echo esc_html( $item['quantity'] ); ?></td>
							<td>
								<?php if ( $can_price ) : ?>
									<input
										type="number"
										name="epic_wo_item_price[<?php echo esc_attr( $item['product_id'] ); ?>]"
										value="<?php echo null !== $item_price ? esc_attr( wc_format_decimal( $item_price ) ) : ''; ?>"
										min="0"
										step="any"
										class="wc_input_price"
										style="width:120px;"
									/>
								<?php elseif ( null !== $item_price ) : ?>
									<?php echo wp_kses_post( wc_price( $item_price ) ); ?>
								<?php else : ?>
									<span style="color:#999;"><?php esc_html_e( 'Not set', 'epic-wholesale-orders' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( null !== $item_total ) : ?>
									<?php echo wp_kses_post( wc_price( $item_total ) ); ?>
								<?php else : ?>
									<span style="color:#999;">&#8212;</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<p>
			<strong><?php esc_html_e( 'Total', 'epic-wholesale-orders' ); ?>:</strong>
			<span class="epic-wo-total">
				<?php if ( $priced ) : ?>
					<?php echo wp_kses_post( wc_price( $order['total'] ) ); ?>
				<?php else : ?>
					<span style="color:#999;"><?php esc_html_e( 'Not set', 'epic-wholesale-orders' ); ?></span>
				<?php endif; ?>
			</span>
			<?php if ( $order['level_name'] && ! $is_vip ) : ?>
				&nbsp;·&nbsp;
				<span>
					<?php
					printf(
						/* translators: %s: pricing level name */
						esc_html__( 'Level: %s', 'epic-wholesale-orders' ),
						esc_html( $order['level_name'] )
					);
					?>
					<?php if ( $order['level_discount'] > 0 ) : ?>
						(<?php echo esc_html( (float) $order['level_discount'] ); ?>%)
					<?php endif; ?>
				</span>
			<?php endif; ?>
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
						<?php esc_html_e( 'Marking Approved sets payment to "Waiting for payment" (unless already Paid). Unapproving requires a reason and sets payment to "Canceled". Done is the final state and does not change payment. VIP orders must be fully priced before they can be Approved.', 'epic-wholesale-orders' ); ?>
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
				<th scope="row"><label for="epic_wo_cancel_reason"><?php esc_html_e( 'Unapprove reason', 'epic-wholesale-orders' ); ?></label></th>
				<td>
					<textarea
						id="epic_wo_cancel_reason"
						name="epic_wo_cancel_reason"
						rows="3"
						class="large-text"
						placeholder="<?php esc_attr_e( 'Required when unapproving this order.', 'epic-wholesale-orders' ); ?>"
					><?php echo esc_textarea( $cancel_reason ); ?></textarea>
					<?php if ( $is_cancelled ) : ?>
						<p class="description"><?php esc_html_e( 'This order is unapproved. Re-saving the reason above updates it.', 'epic-wholesale-orders' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>

		<h3><?php esc_html_e( 'Invoice', 'epic-wholesale-orders' ); ?></h3>
		<?php
		$invoice_id = (int) $order['invoice_attachment_id'];
		if ( $invoice_id ) {
			$invoice_title = get_the_title( $invoice_id );
			echo '<p>' . esc_html__( 'Current invoice:', 'epic-wholesale-orders' ) . ' <a href="' . esc_url( wp_get_attachment_url( $invoice_id ) ) . '" target="_blank" rel="noopener">' . esc_html( $invoice_title ? $invoice_title : basename( (string) get_attached_file( $invoice_id ) ) ) . '</a></p>';
		}
		?>
		<p>
			<input type="file" name="epic_wo_invoice" accept=".pdf,.png,.jpg,.jpeg" />
			<span class="description"><?php esc_html_e( 'PDF, PNG or JPG. One per order — uploading a new file replaces the current invoice.', 'epic-wholesale-orders' ); ?></span>
		</p>
		<?php if ( $invoice_id ) : ?>
			<p>
				<label>
					<input type="checkbox" name="epic_wo_remove_invoice" value="yes" />
					<?php esc_html_e( 'Remove the current invoice', 'epic-wholesale-orders' ); ?>
				</label>
			</p>
		<?php endif; ?>
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

		// Unapproving requires a reason — reject the whole save.
		if ( Epic_Wholesale_Orders_Store::STATUS_UNAPPROVED === $posted_status && '' === $cancel_reason ) {
			self::$errors[] = __( 'To unapprove this order you must provide a reason.', 'epic-wholesale-orders' );
			return;
		}

		// Persist the reason whenever one exists (keeps history on rejection).
		if ( '' !== $cancel_reason ) {
			update_post_meta( $post_id, Epic_Wholesale_Orders_Store::META_CANCEL_REASON, $cancel_reason );
		}

		self::maybe_save_invoice( $post_id );

		// VIP pricing: persist the seller's per-item prices before the status
		// transition so prices and approval can be saved in the same form submit.
		$is_vip = 'yes' === get_post_meta( $post_id, Epic_Wholesale_Orders_Store::META_IS_VIP, true );
		if ( $is_vip ) {
			self::maybe_save_prices( $post_id );
		}

		// A VIP order cannot be Approved until every line item carries a price.
		if ( $is_vip && Epic_Wholesale_Orders_Store::STATUS_APPROVED === $posted_status && ! self::order_priced( $post_id ) ) {
			self::$errors[] = __( 'This VIP order cannot be Approved until a price is set for every item above.', 'epic-wholesale-orders' );
			return;
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

	/**
	 * Saves the seller's per-item prices on a VIP order. Only numeric values
	 * above zero are written; leaving a field blank keeps its previous value
	 * (a blank never unprices an item). Recomputes and stores the order total.
	 *
	 * @param int $post_id
	 */
	private static function maybe_save_prices( $post_id ) {
		$items = get_post_meta( $post_id, Epic_Wholesale_Orders_Store::META_ITEMS, true );
		if ( ! is_array( $items ) || empty( $items ) ) {
			return;
		}
		if ( empty( $_POST['epic_wo_item_price'] ) || ! is_array( $_POST['epic_wo_item_price'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per-field below.
			return;
		}
		$posted = wp_unslash( $_POST['epic_wo_item_price'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per-field below.

		$changed = false;
		foreach ( $items as &$item ) {
			$pid = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			if ( ! $pid || ! isset( $posted[ $pid ] ) ) {
				continue;
			}
			$raw = wc_clean( $posted[ $pid ] );
			if ( ! is_numeric( $raw ) || (float) $raw <= 0 ) {
				continue; // Blank/non-numeric keeps the existing value.
			}
			$unit                  = wc_format_decimal( $raw );
			$item['unit_price']    = (float) $unit;
			$item['line_total']    = (float) ( (float) $unit * (int) $item['quantity'] );
			$changed               = true;
		}
		unset( $item );

		if ( ! $changed ) {
			return;
		}

		update_post_meta( $post_id, Epic_Wholesale_Orders_Store::META_ITEMS, $items );

		$total = 0.0;
		foreach ( $items as $item ) {
			if ( isset( $item['line_total'] ) && is_numeric( $item['line_total'] ) ) {
				$total += (float) $item['line_total'];
			}
		}
		update_post_meta( $post_id, Epic_Wholesale_Orders_Store::META_TOTAL, (string) $total );
	}

	/** True when every line item on the order is priced (VIP approval gate). */
	private static function order_priced( $post_id ) {
		$items = get_post_meta( $post_id, Epic_Wholesale_Orders_Store::META_ITEMS, true );
		return is_array( $items ) && Epic_Wholesale_Orders_Store::items_priced( $items );
	}

	/**
	 * Handles the invoice file on the order: upload (PDF or image, one per
	 * order — re-upload replaces the previous), or remove when flagged.
	 */
	private static function maybe_save_invoice( $post_id ) {
		$current = (int) get_post_meta( $post_id, Epic_Wholesale_Orders_Store::META_INVOICE_ATTACHMENT, true );

		if ( ! empty( $_POST['epic_wo_remove_invoice'] ) ) {
			if ( $current && 'yes' === sanitize_key( wp_unslash( $_POST['epic_wo_remove_invoice'] ) ) ) {
				wp_delete_attachment( $current, true );
				delete_post_meta( $post_id, Epic_Wholesale_Orders_Store::META_INVOICE_ATTACHMENT );
			}
			return;
		}

		if ( empty( $_FILES['epic_wo_invoice']['name'] ) ) {
			return; // No new file — keep whatever is already attached.
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$mimes = array(
			'pdf'  => 'application/pdf',
			'png'  => 'image/png',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
		);

		$attachment_id = media_handle_upload(
			'epic_wo_invoice',
			$post_id,
			array( 'post_title' => 'Invoice ' . Epic_Wholesale_Orders_Store::order_number_for( $post_id ) ),
			array(
				'test_form' => false,
				'mimes'     => $mimes,
			)
		);

		if ( is_wp_error( $attachment_id ) ) {
			self::$errors[] = __( 'Could not upload the invoice — only PDF, PNG, or JPG files are allowed.', 'epic-wholesale-orders' );
			return;
		}

		if ( $current ) {
			wp_delete_attachment( $current, true );
		}
		update_post_meta( $post_id, Epic_Wholesale_Orders_Store::META_INVOICE_ATTACHMENT, (int) $attachment_id );
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
