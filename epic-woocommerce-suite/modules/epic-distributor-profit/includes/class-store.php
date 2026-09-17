<?php
/**
 * Data layer: table creation + CRUD + the cost/profit/commission math.
 *
 * @package Epic_Distributor_Profit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Distributor_Profit_Store {

	/**
	 * Name of the "no real distributor known" placeholder created by the T09 historical import.
	 */
	const LEGACY_DISTRIBUTOR_NAME = 'Legacy / Unassigned';

	public static function distributors_table() {
		global $wpdb;
		return $wpdb->prefix . 'epic_distributor_profit_distributors';
	}

	public static function entries_table() {
		global $wpdb;
		return $wpdb->prefix . 'epic_distributor_profit_entries';
	}

	/**
	 * Create/upgrade both tables. Called on activation, and defensively on admin_init in case the
	 * plugin was ever activated without WooCommerce present (activation hook still ran, but let's
	 * not assume the table exists everywhere else).
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$distributors    = self::distributors_table();
		$entries         = self::entries_table();

		$sql = "CREATE TABLE {$distributors} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL,
			contact VARCHAR(255) NULL,
			commission_percent DECIMAL(6,3) NOT NULL DEFAULT 0,
			active TINYINT(1) NOT NULL DEFAULT 1,
			notes TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY active (active)
		) {$charset_collate};

		CREATE TABLE {$entries} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			entry_date DATE NOT NULL,
			wc_order_id BIGINT UNSIGNED NULL,
			order_code VARCHAR(191) NULL,
			channel VARCHAR(100) NULL,
			distributor_id BIGINT UNSIGNED NOT NULL,
			product_name VARCHAR(255) NOT NULL,
			quantity INT NULL,
			cost DECIMAL(14,2) NOT NULL DEFAULT 0,
			revenue DECIMAL(14,2) NOT NULL DEFAULT 0,
			shipping_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
			other_cost_label VARCHAR(191) NULL,
			other_cost_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
			gross_profit DECIMAL(14,2) NOT NULL DEFAULT 0,
			commission_percent_snapshot DECIMAL(6,3) NOT NULL DEFAULT 0,
			commission_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
			net_profit DECIMAL(14,2) NOT NULL DEFAULT 0,
			source VARCHAR(20) NOT NULL DEFAULT 'manual',
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY entry_date (entry_date),
			KEY distributor_id (distributor_id),
			KEY channel (channel),
			KEY wc_order_id (wc_order_id)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Core math, kept in one place so the admin form, the AJAX preview, and the importer all agree.
	 *
	 * @param float $revenue            Amount actually received for this entry.
	 * @param float $cost               Manually entered cost for this entry.
	 * @param float $shipping_cost      Shipping cost charged against this entry.
	 * @param float $other_cost_amount  Any other deduction (e.g. a platform wallet top-up).
	 * @param float $commission_percent The distributor's commission percentage (0-100).
	 * @return array{gross_profit:float,commission_amount:float,net_profit:float}
	 */
	public static function compute( $revenue, $cost, $shipping_cost, $other_cost_amount, $commission_percent ) {
		$revenue            = (float) $revenue;
		$cost               = (float) $cost;
		$shipping_cost      = (float) $shipping_cost;
		$other_cost_amount  = (float) $other_cost_amount;
		$commission_percent = (float) $commission_percent;

		$gross_profit      = $revenue - $cost - $shipping_cost - $other_cost_amount;
		$commission_amount = $gross_profit * ( $commission_percent / 100 );
		$net_profit        = $gross_profit - $commission_amount;

		return array(
			'gross_profit'      => round( $gross_profit, 2 ),
			'commission_amount' => round( $commission_amount, 2 ),
			'net_profit'        => round( $net_profit, 2 ),
		);
	}

	// ---------------------------------------------------------------------
	// Distributors
	// ---------------------------------------------------------------------

	public static function get_distributor( $id ) {
		global $wpdb;
		$table = self::distributors_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore
	}

	public static function get_distributor_by_name( $name ) {
		global $wpdb;
		$table = self::distributors_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE name = %s", $name ) ); // phpcs:ignore
	}

	public static function list_distributors( $active_only = false ) {
		global $wpdb;
		$table = self::distributors_table();
		if ( $active_only ) {
			return $wpdb->get_results( "SELECT * FROM {$table} WHERE active = 1 ORDER BY name ASC" ); // phpcs:ignore
		}
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name ASC" ); // phpcs:ignore
	}

	public static function save_distributor( $data, $id = 0 ) {
		global $wpdb;
		$table = self::distributors_table();
		$now   = current_time( 'mysql' );

		$fields = array(
			'name'                => sanitize_text_field( $data['name'] ),
			'contact'             => isset( $data['contact'] ) ? sanitize_text_field( $data['contact'] ) : '',
			'commission_percent'  => isset( $data['commission_percent'] ) ? (float) $data['commission_percent'] : 0,
			'active'              => empty( $data['active'] ) ? 0 : 1,
			'notes'               => isset( $data['notes'] ) ? sanitize_textarea_field( $data['notes'] ) : '',
			'updated_at'          => $now,
		);

		if ( $id ) {
			$wpdb->update( $table, $fields, array( 'id' => $id ) ); // phpcs:ignore
			return $id;
		}

		$fields['created_at'] = $now;
		$wpdb->insert( $table, $fields ); // phpcs:ignore
		return $wpdb->insert_id;
	}

	public static function delete_distributor( $id ) {
		global $wpdb;
		$table = self::distributors_table();
		$wpdb->delete( $table, array( 'id' => $id ) ); // phpcs:ignore
	}

	/**
	 * Find-or-create the "Legacy / Unassigned" distributor used by the T09 historical import.
	 */
	public static function get_or_create_legacy_distributor() {
		$existing = self::get_distributor_by_name( self::LEGACY_DISTRIBUTOR_NAME );
		if ( $existing ) {
			return $existing->id;
		}
		return self::save_distributor(
			array(
				'name'               => self::LEGACY_DISTRIBUTOR_NAME,
				'contact'            => '',
				'commission_percent' => 0,
				'active'             => 1,
				'notes'              => 'Auto-created by the historical import. Orders imported before distributor tracking existed have no known distributor, so commission is 0%.',
			)
		);
	}

	// ---------------------------------------------------------------------
	// Entries
	// ---------------------------------------------------------------------

	public static function get_entry( $id ) {
		global $wpdb;
		$table = self::entries_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore
	}

	/**
	 * Save an entry, computing gross_profit/commission_amount/net_profit server-side from the
	 * submitted revenue/cost/shipping/other_cost and the chosen distributor's *current* commission
	 * rate (snapshotted into commission_percent_snapshot so later rate edits don't rewrite history).
	 */
	public static function save_entry( $data, $id = 0 ) {
		global $wpdb;
		$table = self::entries_table();
		$now   = current_time( 'mysql' );

		$distributor = self::get_distributor( (int) $data['distributor_id'] );
		$commission_percent = $distributor ? (float) $distributor->commission_percent : 0;

		$revenue           = isset( $data['revenue'] ) ? (float) $data['revenue'] : 0;
		$cost              = isset( $data['cost'] ) ? (float) $data['cost'] : 0;
		$shipping_cost     = isset( $data['shipping_cost'] ) ? (float) $data['shipping_cost'] : 0;
		$other_cost_amount = isset( $data['other_cost_amount'] ) ? (float) $data['other_cost_amount'] : 0;

		$computed = self::compute( $revenue, $cost, $shipping_cost, $other_cost_amount, $commission_percent );

		$fields = array(
			'entry_date'                  => sanitize_text_field( $data['entry_date'] ),
			'wc_order_id'                 => ! empty( $data['wc_order_id'] ) ? (int) $data['wc_order_id'] : null,
			'order_code'                  => isset( $data['order_code'] ) ? sanitize_text_field( $data['order_code'] ) : '',
			'channel'                     => isset( $data['channel'] ) ? sanitize_text_field( $data['channel'] ) : '',
			'distributor_id'              => (int) $data['distributor_id'],
			'product_name'                => sanitize_text_field( $data['product_name'] ),
			'quantity'                    => isset( $data['quantity'] ) && '' !== $data['quantity'] ? (int) $data['quantity'] : null,
			'cost'                        => $cost,
			'revenue'                     => $revenue,
			'shipping_cost'               => $shipping_cost,
			'other_cost_label'            => isset( $data['other_cost_label'] ) ? sanitize_text_field( $data['other_cost_label'] ) : '',
			'other_cost_amount'           => $other_cost_amount,
			'gross_profit'                => $computed['gross_profit'],
			'commission_percent_snapshot' => $commission_percent,
			'commission_amount'           => $computed['commission_amount'],
			'net_profit'                  => $computed['net_profit'],
			'source'                      => isset( $data['source'] ) ? sanitize_key( $data['source'] ) : 'manual',
			'updated_at'                  => $now,
		);

		if ( $id ) {
			$wpdb->update( $table, $fields, array( 'id' => $id ) ); // phpcs:ignore
			return $id;
		}

		$fields['created_by'] = get_current_user_id();
		$fields['created_at'] = $now;
		$wpdb->insert( $table, $fields ); // phpcs:ignore
		return $wpdb->insert_id;
	}

	public static function delete_entry( $id ) {
		global $wpdb;
		$table = self::entries_table();
		$wpdb->delete( $table, array( 'id' => $id ) ); // phpcs:ignore
	}

	/**
	 * Query entries with optional filters, used by both the list table and the exporter so they
	 * never drift apart.
	 *
	 * @param array $args {
	 *   @type string $date_from   Y-m-d, inclusive.
	 *   @type string $date_to     Y-m-d, inclusive.
	 *   @type int    $distributor_id
	 *   @type string $channel
	 *   @type string $orderby
	 *   @type string $order       ASC|DESC
	 *   @type int    $per_page    0 = no limit.
	 *   @type int    $page        1-indexed.
	 * }
	 */
	public static function query_entries( $args = array() ) {
		global $wpdb;
		$table         = self::entries_table();
		$distributors  = self::distributors_table();

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['date_from'] ) ) {
			$where[]  = 'e.entry_date >= %s';
			$params[] = $args['date_from'];
		}
		if ( ! empty( $args['date_to'] ) ) {
			$where[]  = 'e.entry_date <= %s';
			$params[] = $args['date_to'];
		}
		if ( ! empty( $args['distributor_id'] ) ) {
			$where[]  = 'e.distributor_id = %d';
			$params[] = (int) $args['distributor_id'];
		}
		if ( ! empty( $args['channel'] ) ) {
			$where[]  = 'e.channel = %s';
			$params[] = $args['channel'];
		}

		$orderby_allowed = array( 'entry_date', 'revenue', 'gross_profit', 'commission_amount', 'net_profit' );
		$orderby         = in_array( $args['orderby'] ?? '', $orderby_allowed, true ) ? $args['orderby'] : 'entry_date';
		$order           = strtoupper( $args['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';

		$sql = "SELECT e.*, d.name AS distributor_name
			FROM {$table} e
			LEFT JOIN {$distributors} d ON d.id = e.distributor_id
			WHERE " . implode( ' AND ', $where ) . "
			ORDER BY e.{$orderby} {$order}, e.id DESC";

		if ( ! empty( $args['per_page'] ) ) {
			$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
			$per_page = (int) $args['per_page'];
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = $per_page;
			$params[] = ( $page - 1 ) * $per_page;
		}

		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params ); // phpcs:ignore
		}

		return $wpdb->get_results( $sql ); // phpcs:ignore
	}

	public static function count_entries( $args = array() ) {
		global $wpdb;
		$table = self::entries_table();

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['date_from'] ) ) {
			$where[]  = 'entry_date >= %s';
			$params[] = $args['date_from'];
		}
		if ( ! empty( $args['date_to'] ) ) {
			$where[]  = 'entry_date <= %s';
			$params[] = $args['date_to'];
		}
		if ( ! empty( $args['distributor_id'] ) ) {
			$where[]  = 'distributor_id = %d';
			$params[] = (int) $args['distributor_id'];
		}
		if ( ! empty( $args['channel'] ) ) {
			$where[]  = 'channel = %s';
			$params[] = $args['channel'];
		}

		$sql = "SELECT COUNT(*) FROM {$table} WHERE " . implode( ' AND ', $where );
		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params ); // phpcs:ignore
		}
		return (int) $wpdb->get_var( $sql ); // phpcs:ignore
	}

	/**
	 * Totals across a filtered set — same filters as query_entries(), no pagination.
	 */
	public static function totals( $args = array() ) {
		global $wpdb;
		$table = self::entries_table();

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['date_from'] ) ) {
			$where[]  = 'entry_date >= %s';
			$params[] = $args['date_from'];
		}
		if ( ! empty( $args['date_to'] ) ) {
			$where[]  = 'entry_date <= %s';
			$params[] = $args['date_to'];
		}
		if ( ! empty( $args['distributor_id'] ) ) {
			$where[]  = 'distributor_id = %d';
			$params[] = (int) $args['distributor_id'];
		}
		if ( ! empty( $args['channel'] ) ) {
			$where[]  = 'channel = %s';
			$params[] = $args['channel'];
		}

		$sql = "SELECT
				COUNT(*) AS entry_count,
				COALESCE(SUM(revenue),0) AS revenue,
				COALESCE(SUM(cost),0) AS cost,
				COALESCE(SUM(shipping_cost),0) AS shipping_cost,
				COALESCE(SUM(other_cost_amount),0) AS other_cost_amount,
				COALESCE(SUM(gross_profit),0) AS gross_profit,
				COALESCE(SUM(commission_amount),0) AS commission_amount,
				COALESCE(SUM(net_profit),0) AS net_profit
			FROM {$table} WHERE " . implode( ' AND ', $where );

		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params ); // phpcs:ignore
		}
		return $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore
	}

	/**
	 * Distinct channel values seen so far, for the filter dropdown.
	 */
	public static function distinct_channels() {
		global $wpdb;
		$table = self::entries_table();
		return $wpdb->get_col( "SELECT DISTINCT channel FROM {$table} WHERE channel != '' ORDER BY channel ASC" ); // phpcs:ignore
	}
}
