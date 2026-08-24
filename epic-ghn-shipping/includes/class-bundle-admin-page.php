<?php
/**
 * "Review & confirm bundle" screen (PLAN.md §5.2) — reached only via the
 * orders list bulk action (class-orders-list.php), never linked from any
 * admin menu. Validates the selected orders share one recipient, aggregates
 * their line items/weight/subtotal, previews the ONE combined-parcel fee,
 * and books the shipment through Epic_GHN_Bundle::confirm() on confirm.
 *
 * @package Epic_GHN_Shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_GHN_Bundle_Admin_Page {

	const NONCE_ACTION = 'epic_ghn_bundle_confirm';
	const PAGE_SLUG    = 'epic-ghn-bundle';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
	}

	/**
	 * Registered with a null parent so it's reachable at
	 * admin.php?page=epic-ghn-bundle but doesn't appear in any admin menu —
	 * staff only ever land here via the orders list bulk action redirect.
	 */
	public static function register_page() {
		add_submenu_page(
			null,
			__( 'Bundle & Ship via GHN', 'epic-ghn-shipping' ),
			__( 'Bundle & Ship via GHN', 'epic-ghn-shipping' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function maybe_enqueue() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page identification, not a form submission.
		if ( isset( $_GET['page'] ) && self::PAGE_SLUG === $_GET['page'] ) {
			Epic_GHN_Assets::enqueue();
		}
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'epic-ghn-shipping' ) );
		}

		if ( isset( $_POST['epic_ghn_bundle_confirm'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified explicitly inside handle_confirm() before anything happens.
			self::handle_confirm();
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only initial load, no state change.
		$order_ids = isset( $_GET['orders'] ) ? self::parse_order_ids( wp_unslash( $_GET['orders'] ) ) : array();
		self::render_form( $order_ids );
	}

	private static function parse_order_ids( $raw ) {
		return array_values( array_filter( array_map( 'absint', explode( ',', (string) $raw ) ) ) );
	}

	/**
	 * @param int[] $order_ids
	 * @param array $errors Error strings to show at the top of the form.
	 * @param array $posted Previously-submitted values to re-populate the form with, after a failed confirm.
	 */
	private static function render_form( array $order_ids, array $errors = array(), array $posted = array() ) {
		echo '<div class="wrap epic-ghn-bundle-page">';
		echo '<h1>' . esc_html__( 'Bundle & Ship via GHN', 'epic-ghn-shipping' ) . '</h1>';

		if ( ! Epic_GHN_Client::is_configured() ) {
			echo '<div class="notice notice-error"><p>';
			printf(
				wp_kses_post(
					/* translators: %s: settings page URL */
					__( 'GHN isn\'t configured yet. Add your Token, Shop ID, and pickup address under <a href="%s">WooCommerce → Settings → GHN Shipping</a> first.', 'epic-ghn-shipping' )
				),
				esc_url( admin_url( 'admin.php?page=wc-settings&tab=epic_ghn_shipping' ) )
			);
			echo '</p></div></div>';
			return;
		}

		foreach ( $errors as $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}

		if ( empty( $order_ids ) ) {
			echo '<p>' . esc_html__( 'No orders were selected. Go back to the Orders list, select 2 or more unshipped orders, and choose "Bundle & ship via GHN" from the Bulk actions menu.', 'epic-ghn-shipping' ) . '</p>';
			self::render_back_link();
			echo '</div>';
			return;
		}

		$loaded  = Epic_GHN_Bundle::load_orders( $order_ids );
		$orders  = $loaded['orders'];
		$dropped = $loaded['dropped'];

		if ( ! empty( $dropped ) ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Some selected orders were dropped from this bundle:', 'epic-ghn-shipping' ) . '</strong></p><ul class="epic-ghn-bundle-dropped">';
			foreach ( $dropped as $drop ) {
				printf(
					'<li>%s</li>',
					esc_html(
						sprintf(
							/* translators: 1: order ID, 2: reason */
							__( 'Order #%1$d — %2$s', 'epic-ghn-shipping' ),
							$drop['id'],
							$drop['reason']
						)
					)
				);
			}
			echo '</ul></div>';
		}

		if ( count( $orders ) < 2 ) {
			echo '<p>' . esc_html__( 'Fewer than 2 bookable orders remain in this selection — a bundle needs at least 2. Go back and select a different set of orders.', 'epic-ghn-shipping' ) . '</p>';
			self::render_back_link();
			echo '</div>';
			return;
		}

		$compare   = Epic_GHN_Bundle::compare_recipients( $orders );
		$reference = $compare['snapshots'][ $compare['reference_id'] ];

		$weight_g = Epic_GHN_Bundle::aggregate_weight_g( $orders );
		$subtotal = Epic_GHN_Bundle::aggregate_subtotal( $orders );
		$items    = Epic_GHN_Bundle::aggregate_items( $orders );

		$fee_preview = null;
		$fee_error   = null;
		if ( $reference['resolved']['resolved'] ) {
			$fee_result = Epic_GHN_Client::calculate_fee(
				array(
					'to_district_id'  => $reference['resolved']['district_id'],
					'to_ward_code'    => $reference['resolved']['ward_code'],
					'weight_g'        => $weight_g,
					'insurance_value' => $subtotal,
				)
			);
			if ( is_wp_error( $fee_result ) ) {
				$fee_error = $fee_result->get_error_message();
			} else {
				$fee_preview = Epic_GHN_Bundle::extract_fee_amount( $fee_result );
			}
		}

		self::render_recipient_table( $orders, $compare );

		if ( $compare['has_mismatch'] ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'These orders don\'t all share the same recipient phone and GHN address — see the differences highlighted above. Check the override box below to bundle them anyway (this is logged on every order\'s notes), or go back and select a matching set of orders.', 'epic-ghn-shipping' ) . '</p></div>';
		}

		echo '<h2>' . esc_html__( 'Combined parcel', 'epic-ghn-shipping' ) . '</h2>';
		echo '<div class="epic-ghn-bundle-summary">';
		echo '<p><strong>' . esc_html__( 'Items:', 'epic-ghn-shipping' ) . '</strong> ' . esc_html(
			implode(
				', ',
				array_map(
					function ( $item ) {
						return $item['name'] . ' × ' . $item['quantity'];
					},
					$items
				)
			)
		) . '</p>';
		echo '<p><strong>' . esc_html__( 'Total weight:', 'epic-ghn-shipping' ) . '</strong> ' . esc_html( $weight_g ) . ' g</p>';
		echo '<p><strong>' . esc_html__( 'Items subtotal:', 'epic-ghn-shipping' ) . '</strong> ' . wp_kses_post( wc_price( $subtotal ) ) . '</p>';

		if ( null !== $fee_preview ) {
			echo '<p><strong>' . esc_html__( 'GHN combined shipping fee (quote):', 'epic-ghn-shipping' ) . '</strong> ' . wp_kses_post( wc_price( $fee_preview ) ) . '</p>';
			echo '<p><strong>' . esc_html__( 'COD to collect on delivery:', 'epic-ghn-shipping' ) . '</strong> ' . esc_html__( '0 — every order in this bundle is already paid online (COD orders can\'t be bundled).', 'epic-ghn-shipping' ) . '</p>';
			echo '<p class="description">' . esc_html__( 'Each order\'s own shipping total is left as-is — only this bundle record tracks the real combined fee, for reconciliation. Confirming re-checks the fee with GHN at booking time; the amount above is a quote and the booked fee may differ slightly if GHN\'s rates changed since this page loaded.', 'epic-ghn-shipping' ) . '</p>';
		} elseif ( $fee_error ) {
			echo '<p class="epic-ghn-bundle-diff">' . esc_html(
				sprintf(
					/* translators: %s: GHN error message */
					__( 'Could not get a fee quote right now: %s. You can still try to confirm — booking will retry the fee check.', 'epic-ghn-shipping' ),
					$fee_error
				)
			) . '</p>';
		} else {
			echo '<p class="epic-ghn-bundle-diff">' . esc_html__( 'No fee quote yet — the reference order\'s address needs to be matched or picked manually below before GHN can quote a fee.', 'epic-ghn-shipping' ) . '</p>';
		}
		echo '</div>';

		$settings = Epic_GHN_Client::get_settings();

		echo '<form method="post" class="epic-ghn-bundle-form" data-has-mismatch="' . ( $compare['has_mismatch'] ? '1' : '' ) . '">';
		wp_nonce_field( self::NONCE_ACTION, 'epic_ghn_bundle_nonce' );
		echo '<input type="hidden" name="epic_ghn_bundle_confirm" value="1" />';
		$order_ids_csv = implode(
			',',
			array_map(
				function ( $order ) {
					return $order->get_id();
				},
				$orders
			)
		);
		echo '<input type="hidden" name="order_ids" value="' . esc_attr( $order_ids_csv ) . '" />';

		if ( ! $reference['resolved']['resolved'] ) {
			echo '<h2>' . esc_html__( 'Ship-to address', 'epic-ghn-shipping' ) . '</h2>';
			echo '<p>' . esc_html__( 'The reference order\'s address couldn\'t be automatically matched to a GHN district/ward. Pick them manually — this one address is used for the whole bundle.', 'epic-ghn-shipping' ) . '</p>';
			echo Epic_GHN_Assets::render_address_group( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped internally.
				'bundle',
				array(
					'province_id'   => isset( $posted['province_id'] ) ? $posted['province_id'] : '',
					'province_name' => isset( $posted['province_name'] ) ? $posted['province_name'] : '',
					'district_id'   => isset( $posted['district_id'] ) ? $posted['district_id'] : '',
					'district_name' => isset( $posted['district_name'] ) ? $posted['district_name'] : '',
					'ward_code'     => isset( $posted['ward_code'] ) ? $posted['ward_code'] : '',
					'ward_name'     => isset( $posted['ward_name'] ) ? $posted['ward_name'] : '',
				)
			);
		}

		echo '<h2>' . esc_html__( 'Package dimensions', 'epic-ghn-shipping' ) . '</h2>';
		echo '<p class="epic-ghn-bundle-dims">';
		echo '<label>' . esc_html__( 'Length (cm)', 'epic-ghn-shipping' ) . ' <input type="number" min="1" name="length_cm" value="' . esc_attr( isset( $posted['length_cm'] ) && $posted['length_cm'] ? $posted['length_cm'] : $settings['default_length_cm'] ) . '" /></label> ';
		echo '<label>' . esc_html__( 'Width (cm)', 'epic-ghn-shipping' ) . ' <input type="number" min="1" name="width_cm" value="' . esc_attr( isset( $posted['width_cm'] ) && $posted['width_cm'] ? $posted['width_cm'] : $settings['default_width_cm'] ) . '" /></label> ';
		echo '<label>' . esc_html__( 'Height (cm)', 'epic-ghn-shipping' ) . ' <input type="number" min="1" name="height_cm" value="' . esc_attr( isset( $posted['height_cm'] ) && $posted['height_cm'] ? $posted['height_cm'] : $settings['default_height_cm'] ) . '" /></label>';
		echo '</p>';

		if ( $compare['has_mismatch'] ) {
			echo '<p><label id="epic-ghn-bundle-override-label"><input type="checkbox" id="epic-ghn-bundle-override" name="override" value="1" ' . checked( ! empty( $posted['override'] ), true, false ) . ' /> ' . esc_html__( 'I\'ve checked these differences and confirm they\'re the same real recipient/address — bundle them anyway.', 'epic-ghn-shipping' ) . '</label></p>';
			echo '<p><label>' . esc_html__( 'Note for the order log (optional)', 'epic-ghn-shipping' ) . '<br /><textarea name="override_reason" rows="2" class="large-text">' . esc_textarea( isset( $posted['override_reason'] ) ? $posted['override_reason'] : '' ) . '</textarea></label></p>';
		}

		echo '<p class="epic-ghn-actions">';
		echo '<button type="submit" class="button button-primary epic-ghn-bundle-confirm">' . esc_html__( 'Confirm & book GHN shipment', 'epic-ghn-shipping' ) . '</button> ';
		self::render_back_link();
		echo '</p>';
		echo '</form>';

		echo '</div>';
	}

	private static function render_back_link() {
		printf(
			'<a class="button" href="%s">%s</a>',
			esc_url( Epic_GHN_Orders_List::orders_list_url() ),
			esc_html__( 'Back to Orders', 'epic-ghn-shipping' )
		);
	}

	private static function render_recipient_table( array $orders, array $compare ) {
		echo '<table class="widefat epic-ghn-bundle-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Order', 'epic-ghn-shipping' ) . '</th>';
		echo '<th>' . esc_html__( 'Recipient', 'epic-ghn-shipping' ) . '</th>';
		echo '<th>' . esc_html__( 'Phone', 'epic-ghn-shipping' ) . '</th>';
		echo '<th>' . esc_html__( 'Address', 'epic-ghn-shipping' ) . '</th>';
		echo '<th>' . esc_html__( 'Items subtotal', 'epic-ghn-shipping' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $orders as $order ) {
			$id       = $order->get_id();
			$snapshot = $compare['snapshots'][ $id ];
			$diffs    = $compare['diffs'][ $id ];
			$is_ref   = ( $id === $compare['reference_id'] );

			echo '<tr class="' . ( $diffs ? 'epic-ghn-bundle-mismatch' : '' ) . '">';
			echo '<td>';
			printf(
				'<a href="%s">#%s</a>',
				esc_url( $order->get_edit_order_url() ),
				esc_html( $order->get_order_number() )
			);
			if ( $is_ref ) {
				echo ' <span class="description">(' . esc_html__( 'reference', 'epic-ghn-shipping' ) . ')</span>';
			}
			echo '</td>';
			echo '<td>' . esc_html( $snapshot['name'] ) . '</td>';
			echo '<td>' . esc_html( $snapshot['phone'] ) . '</td>';
			echo '<td>' . esc_html( trim( $snapshot['address_1'] . ' ' . $snapshot['address_2'] . ', ' . $snapshot['city'] . ', ' . $snapshot['state'], ', ' ) );
			if ( $snapshot['resolved']['resolved'] ) {
				echo '<br /><span class="description">' . esc_html( trim( $snapshot['resolved']['ward_name'] . ', ' . $snapshot['resolved']['district_name'] . ', ' . $snapshot['resolved']['province_name'], ', ' ) ) . '</span>';
			}
			echo '</td>';
			echo '<td>' . wp_kses_post( wc_price( (float) $order->get_subtotal() ) ) . '</td>';
			echo '</tr>';

			if ( $diffs ) {
				echo '<tr class="epic-ghn-bundle-mismatch"><td></td><td colspan="4">';
				foreach ( $diffs as $diff ) {
					echo '<p class="epic-ghn-bundle-diff">' . esc_html( $diff ) . '</p>';
				}
				echo '</td></tr>';
			}
		}

		echo '</tbody></table>';
	}

	private static function handle_confirm() {
		check_admin_referer( self::NONCE_ACTION, 'epic_ghn_bundle_nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'epic-ghn-shipping' ) );
		}

		$order_ids = isset( $_POST['order_ids'] ) ? self::parse_order_ids( wp_unslash( $_POST['order_ids'] ) ) : array();
		$posted    = array(
			'length_cm'       => isset( $_POST['length_cm'] ) ? absint( $_POST['length_cm'] ) : 0,
			'width_cm'        => isset( $_POST['width_cm'] ) ? absint( $_POST['width_cm'] ) : 0,
			'height_cm'       => isset( $_POST['height_cm'] ) ? absint( $_POST['height_cm'] ) : 0,
			'override'        => ! empty( $_POST['override'] ),
			'override_reason' => isset( $_POST['override_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['override_reason'] ) ) : '',
			'district_id'     => isset( $_POST['epic_ghn_bundle_district_id'] ) ? absint( $_POST['epic_ghn_bundle_district_id'] ) : 0,
			'ward_code'       => isset( $_POST['epic_ghn_bundle_ward_code'] ) ? sanitize_text_field( wp_unslash( $_POST['epic_ghn_bundle_ward_code'] ) ) : '',
			'province_id'     => isset( $_POST['epic_ghn_bundle_province_id'] ) ? absint( $_POST['epic_ghn_bundle_province_id'] ) : 0,
			'province_name'   => isset( $_POST['epic_ghn_bundle_province_name'] ) ? sanitize_text_field( wp_unslash( $_POST['epic_ghn_bundle_province_name'] ) ) : '',
			'district_name'   => isset( $_POST['epic_ghn_bundle_district_name'] ) ? sanitize_text_field( wp_unslash( $_POST['epic_ghn_bundle_district_name'] ) ) : '',
			'ward_name'       => isset( $_POST['epic_ghn_bundle_ward_name'] ) ? sanitize_text_field( wp_unslash( $_POST['epic_ghn_bundle_ward_name'] ) ) : '',
		);

		$loaded = Epic_GHN_Bundle::load_orders( $order_ids );
		$orders = $loaded['orders'];

		if ( count( $orders ) < 2 ) {
			self::render_form( $order_ids, array( __( 'Fewer than 2 bookable orders remain — a bundle needs at least 2. Some may have already been shipped since you opened this page.', 'epic-ghn-shipping' ) ), $posted );
			return;
		}

		$compare = Epic_GHN_Bundle::compare_recipients( $orders );

		if ( $compare['has_mismatch'] && ! $posted['override'] ) {
			self::render_form( $order_ids, array( __( 'These orders don\'t share the same recipient — check the override box if you want to bundle them anyway.', 'epic-ghn-shipping' ) ), $posted );
			return;
		}

		$reference = $compare['snapshots'][ $compare['reference_id'] ];

		$to_district_id = $reference['resolved']['resolved'] ? $reference['resolved']['district_id'] : $posted['district_id'];
		$to_ward_code   = $reference['resolved']['resolved'] ? $reference['resolved']['ward_code'] : $posted['ward_code'];

		if ( ! $to_district_id || ! $to_ward_code ) {
			self::render_form( $order_ids, array( __( 'Pick a ship-to district and ward before confirming.', 'epic-ghn-shipping' ) ), $posted );
			return;
		}

		$to_address = trim( $reference['address_1'] . ' ' . $reference['address_2'] );

		$result = Epic_GHN_Bundle::confirm(
			$orders,
			array(
				'to_name'         => $reference['name'],
				'to_phone'        => $reference['phone'],
				'to_address'      => $to_address,
				'to_district_id'  => $to_district_id,
				'to_ward_code'    => $to_ward_code,
				'length_cm'       => $posted['length_cm'],
				'width_cm'        => $posted['width_cm'],
				'height_cm'       => $posted['height_cm'],
				'override'        => $posted['override'],
				'override_reason' => $posted['override_reason'],
				'created_by'      => get_current_user_id(),
			)
		);

		if ( is_wp_error( $result ) ) {
			self::render_form( $order_ids, array( $result->get_error_message() ), $posted );
			return;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'epic_ghn_bundle_success'  => 1,
					'epic_ghn_bundle_id'       => $result['bundle_id'],
					'epic_ghn_bundle_tracking' => rawurlencode( $result['tracking_code'] ),
				),
				Epic_GHN_Orders_List::orders_list_url()
			)
		);
		exit;
	}
}
