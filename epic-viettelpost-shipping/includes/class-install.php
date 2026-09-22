<?php
/**
 * Activation / schema install.
 *
 * Seeds the plugin's default options and creates the bundles table ahead of a
 * later bundling phase so that phase is a plain code update with no separate
 * "please reactivate the plugin" migration step for the store owner.
 *
 * @package Epic_ViettelPost_Shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_VTP_Install {

	const DB_VERSION = '1.0';

	public static function activate() {
		self::create_tables();
		update_option( 'epic_vtp_db_version', self::DB_VERSION );

		// add_option() only writes if the row doesn't already exist, so
		// reactivating/updating never clobbers settings a store owner has
		// already filled in.
		$defaults = array(
			'epic_vtp_environment'           => 'sandbox',
			'epic_vtp_token'                 => '',
			'epic_vtp_username'              => '',
			'epic_vtp_password'              => '',
			'epic_vtp_from_name'             => '',
			'epic_vtp_from_phone'            => '',
			'epic_vtp_from_address'          => '',
			'epic_vtp_from_province_id'      => '',
			'epic_vtp_from_province_name'    => '',
			'epic_vtp_from_ward_id'          => '',
			'epic_vtp_from_ward_name'        => '',
			'epic_vtp_default_service'       => '',
			'epic_vtp_default_length_cm'     => 20,
			'epic_vtp_default_width_cm'      => 15,
			'epic_vtp_default_height_cm'     => 10,
			'epic_vtp_default_item_weight_g' => 250,
			'epic_vtp_webhook_secret'        => '',
			'epic_vtp_free_shipping_min_subtotal' => 500000,
		);

		foreach ( $defaults as $option => $value ) {
			add_option( $option, $value );
		}
	}

	/**
	 * Bundle table — unused by the current single-order code paths, present
	 * only so the schema already exists when bundling lands.
	 */
	private static function create_tables() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'epic_vtp_bundles';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			vtp_order_number VARCHAR(64) NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'draft',
			order_ids TEXT NOT NULL,
			recipient_name VARCHAR(191) NULL,
			recipient_phone VARCHAR(32) NULL,
			recipient_address TEXT NULL,
			total_weight_g INT NULL,
			package_length INT NULL,
			package_width INT NULL,
			package_height INT NULL,
			items_subtotal BIGINT NULL,
			shipping_fee BIGINT NULL,
			cod_amount BIGINT NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NULL,
			updated_at DATETIME NULL,
			error_message TEXT NULL,
			PRIMARY KEY  (id),
			KEY status (status)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
