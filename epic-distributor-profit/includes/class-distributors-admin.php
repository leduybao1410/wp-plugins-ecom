<?php
/**
 * WooCommerce → Distributors screen: list + add/edit form + delete handling.
 *
 * @package Epic_Distributor_Profit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Distributor_Profit_Distributors_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Distributors', 'epic-distributor-profit' ),
			__( 'Distributors', 'epic-distributor-profit' ),
			EPIC_DISTRIBUTOR_PROFIT_CAP,
			'epic-distributor-profit-distributors',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function enqueue( $hook ) {
		if ( false === strpos( $hook, 'epic-distributor-profit' ) ) {
			return;
		}
		wp_enqueue_style(
			'epic-distributor-profit-admin',
			EPIC_DISTRIBUTOR_PROFIT_URL . 'assets/admin.css',
			array(),
			EPIC_DISTRIBUTOR_PROFIT_VERSION
		);
	}

	/**
	 * Handle save/delete before any output, so we can redirect afterwards (avoids the classic
	 * "headers already sent" trap and a stale list on refresh/resubmit).
	 */
	public static function handle_actions() {
		if ( ! isset( $_GET['page'] ) || 'epic-distributor-profit-distributors' !== $_GET['page'] ) {
			return;
		}
		if ( ! current_user_can( EPIC_DISTRIBUTOR_PROFIT_CAP ) ) {
			return;
		}

		// Delete.
		if ( isset( $_GET['action'], $_GET['id'] ) && 'delete' === $_GET['action'] ) {
			$id = (int) $_GET['id'];
			check_admin_referer( 'epic_dp_delete_distributor_' . $id );
			Epic_Distributor_Profit_Store::delete_distributor( $id );
			wp_safe_redirect( admin_url( 'admin.php?page=epic-distributor-profit-distributors&deleted=1' ) );
			exit;
		}

		// Save (add or edit).
		if ( isset( $_POST['epic_dp_distributor_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['epic_dp_distributor_nonce'] ) ), 'epic_dp_save_distributor' ) ) {
			$id = isset( $_POST['distributor_id'] ) ? (int) $_POST['distributor_id'] : 0;

			$data = array(
				'name'               => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
				'contact'            => isset( $_POST['contact'] ) ? sanitize_text_field( wp_unslash( $_POST['contact'] ) ) : '',
				'commission_percent' => isset( $_POST['commission_percent'] ) ? (float) $_POST['commission_percent'] : 0,
				'active'             => isset( $_POST['active'] ) ? 1 : 0,
				'notes'              => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '',
			);

			if ( '' === $data['name'] ) {
				add_settings_error( 'epic_dp_distributors', 'name_required', __( 'Name is required.', 'epic-distributor-profit' ) );
				return;
			}

			Epic_Distributor_Profit_Store::save_distributor( $data, $id );
			wp_safe_redirect( admin_url( 'admin.php?page=epic-distributor-profit-distributors&saved=1' ) );
			exit;
		}
	}

	public static function render_page() {
		if ( ! current_user_can( EPIC_DISTRIBUTOR_PROFIT_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'epic-distributor-profit' ) );
		}

		$editing = null;
		if ( isset( $_GET['edit'] ) ) {
			$editing = Epic_Distributor_Profit_Store::get_distributor( (int) $_GET['edit'] );
		}

		?>
		<div class="wrap epic-distributor-profit">
			<h1><?php esc_html_e( 'Distributors', 'epic-distributor-profit' ); ?></h1>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Distributor saved.', 'epic-distributor-profit' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['deleted'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Distributor deleted.', 'epic-distributor-profit' ); ?></p></div>
			<?php endif; ?>
			<?php settings_errors( 'epic_dp_distributors' ); ?>

			<div class="epic-dp-columns">
				<div class="epic-dp-col-list">
					<?php
					$table = new Epic_Distributor_Profit_Distributors_List_Table();
					$table->prepare_items();
					$table->display();
					?>
				</div>
				<div class="epic-dp-col-form">
					<h2><?php echo $editing ? esc_html__( 'Edit Distributor', 'epic-distributor-profit' ) : esc_html__( 'Add Distributor', 'epic-distributor-profit' ); ?></h2>
					<form method="post">
						<?php wp_nonce_field( 'epic_dp_save_distributor', 'epic_dp_distributor_nonce' ); ?>
						<input type="hidden" name="distributor_id" value="<?php echo esc_attr( $editing ? $editing->id : 0 ); ?>" />

						<table class="form-table">
							<tr>
								<th><label for="name"><?php esc_html_e( 'Name', 'epic-distributor-profit' ); ?></label></th>
								<td><input type="text" id="name" name="name" class="regular-text" required
									value="<?php echo esc_attr( $editing ? $editing->name : '' ); ?>" /></td>
							</tr>
							<tr>
								<th><label for="contact"><?php esc_html_e( 'Contact', 'epic-distributor-profit' ); ?></label></th>
								<td><input type="text" id="contact" name="contact" class="regular-text"
									value="<?php echo esc_attr( $editing ? $editing->contact : '' ); ?>"
									placeholder="<?php esc_attr_e( 'Phone or email', 'epic-distributor-profit' ); ?>" /></td>
							</tr>
							<tr>
								<th><label for="commission_percent"><?php esc_html_e( 'Commission %', 'epic-distributor-profit' ); ?></label></th>
								<td><input type="number" step="0.01" min="0" max="100" id="commission_percent" name="commission_percent" class="small-text"
									value="<?php echo esc_attr( $editing ? $editing->commission_percent : '0' ); ?>" />
									<p class="description"><?php esc_html_e( 'Applied to gross profit (revenue − cost − shipping − other cost) on new entries.', 'epic-distributor-profit' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="active"><?php esc_html_e( 'Active', 'epic-distributor-profit' ); ?></label></th>
								<td><input type="checkbox" id="active" name="active" value="1"
									<?php checked( $editing ? (bool) $editing->active : true ); ?> />
									<label for="active"><?php esc_html_e( 'Available for selection on new entries', 'epic-distributor-profit' ); ?></label></td>
							</tr>
							<tr>
								<th><label for="notes"><?php esc_html_e( 'Notes', 'epic-distributor-profit' ); ?></label></th>
								<td><textarea id="notes" name="notes" class="large-text" rows="3"><?php echo esc_textarea( $editing ? $editing->notes : '' ); ?></textarea></td>
							</tr>
						</table>

						<?php submit_button( $editing ? __( 'Update Distributor', 'epic-distributor-profit' ) : __( 'Add Distributor', 'epic-distributor-profit' ) ); ?>
						<?php if ( $editing ) : ?>
							<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=epic-distributor-profit-distributors' ) ); ?>"><?php esc_html_e( 'Cancel edit', 'epic-distributor-profit' ); ?></a></p>
						<?php endif; ?>
					</form>
				</div>
			</div>
		</div>
		<?php
	}
}
