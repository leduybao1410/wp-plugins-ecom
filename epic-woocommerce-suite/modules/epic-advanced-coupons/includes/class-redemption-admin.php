<?php
/**
 * "Redemptions" admin screen: a filterable, paginated view of the
 * epic_coupon_redemptions log (see class-redemption-log.php), plus a CSV
 * export of the currently-filtered result set.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Adv_Coupons_Redemption_Admin {

	const PAGE_SLUG = 'epic-coupon-redemptions';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_epic_export_redemptions', array( __CLASS__, 'export_csv' ) );
	}

	public static function add_menu() {
		// Hangs off the Coupons post-type's own admin menu (Marketing →
		// Coupons in current WooCommerce, WooCommerce → Coupons in older
		// versions) — WordPress resolves the parent correctly either way
		// since it's the CPT's own menu group, not a hardcoded top-level slug.
		add_submenu_page(
			'edit.php?post_type=shop_coupon',
			__( 'Coupon Redemptions', 'epic-advanced-coupons' ),
			__( 'Redemptions', 'epic-advanced-coupons' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'epic-advanced-coupons' ) );
		}

		if ( ! class_exists( 'WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}
		// __DIR__, not the EPIC_ADV_COUPONS_DIR constant — see the comment
		// at the top of the main plugin file for why.
		require_once __DIR__ . '/class-redemption-list-table.php';

		$list_table = new Epic_Adv_Coupons_Redemption_List_Table();
		$list_table->prepare_items();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Coupon Redemptions', 'epic-advanced-coupons' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Every coupon (including bulk-generated single-use codes) actually used on an order, kept in sync automatically whenever an order is created or updated.', 'epic-advanced-coupons' ); ?>
			</p>
			<form method="get">
				<input type="hidden" name="post_type" value="shop_coupon" />
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<?php
				$list_table->search_box( __( 'Search codes', 'epic-advanced-coupons' ), 'epic-redemption-search' );
				$list_table->display();
				?>
			</form>
		</div>
		<?php
	}

	public static function export_csv() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'epic-advanced-coupons' ) );
		}
		check_admin_referer( 'epic_export_redemptions' );

		$filters = array(
			'search'         => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'email'          => isset( $_GET['epic_email'] ) ? sanitize_email( wp_unslash( $_GET['epic_email'] ) ) : '',
			'generated_from' => isset( $_GET['epic_batch'] ) ? (int) $_GET['epic_batch'] : 0,
			'status'         => isset( $_GET['epic_status'] ) ? sanitize_key( $_GET['epic_status'] ) : '',
			'date_from'      => isset( $_GET['epic_date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['epic_date_from'] ) ) : '',
			'date_to'        => isset( $_GET['epic_date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['epic_date_to'] ) ) : '',
			'per_page'       => 50000, // Safety cap for a single export — comfortably above any realistic batch/campaign size for this store.
			'page'           => 1,
		);

		$rows = Epic_Adv_Coupons_Redemption_Log::query( $filters );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=coupon-redemptions-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv(
			$out,
			array( 'coupon_code', 'batch_template_id', 'order_id', 'order_number', 'discount_amount', 'order_total', 'billing_email', 'billing_phone', 'status', 'redeemed_at' )
		);
		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				array(
					$row['coupon_code'],
					$row['generated_from'],
					$row['order_id'],
					$row['order_number'],
					$row['discount_amount'],
					$row['order_total'],
					$row['billing_email'],
					$row['billing_phone'],
					$row['status'],
					$row['redeemed_at'],
				)
			);
		}
		fclose( $out );
		exit;
	}
}
