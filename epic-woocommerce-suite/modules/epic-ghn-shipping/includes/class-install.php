<?php
/**
 * Activation / schema install.
 *
 * Phase 1 doesn't use the bundles table yet (bundling ships in Phase 2), but
 * creating it now means Phase 2 is a plain code update with no separate
 * "please reactivate the plugin" migration step for the store owner.
 *
 * @package Epic_GHN_Shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_GHN_Install {

	const DB_VERSION = '1.0';

	public static function activate() {
		self::create_tables();
		update_option( 'epic_ghn_db_version', self::DB_VERSION );

		// add_option() only writes if the row doesn't already exist, so
		// reactivating the plugin (or updating it) never clobbers settings
		// a store owner has already filled in.
		$defaults = array(
			'epic_ghn_environment'           => 'sandbox',
			'epic_ghn_token'                 => '',
			'epic_ghn_shop_id'               => '',
			'epic_ghn_from_name'             => '',
			'epic_ghn_from_phone'            => '',
			'epic_ghn_from_province_id'      => '',
			'epic_ghn_from_province_name'    => '',
			'epic_ghn_from_district_id'      => '',
			'epic_ghn_from_district_name'    => '',
			'epic_ghn_from_ward_code'        => '',
			'epic_ghn_from_ward_name'        => '',
			'epic_ghn_from_address'          => '',
			'epic_ghn_service_type_id'       => 2,
			'epic_ghn_default_length_cm'     => 20,
			'epic_ghn_default_width_cm'      => 15,
			'epic_ghn_default_height_cm'     => 10,
			'epic_ghn_default_item_weight_g' => 250,
		);

		foreach ( $defaults as $option => $value ) {
			add_option( $option, $value );
		}
	}

	/**
	 * Bundle table — unused by any Phase 1 code path, present only so the
	 * schema already exists when Phase 2 (bundling) lands.
	 */
	private static function create_tables() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'epic_ghn_bundles';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ghn_order_code VARCHAR(64) NULL,
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
