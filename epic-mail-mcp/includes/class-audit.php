<?php
/**
 * Audit table for every send attempt, including denied ones.
 *
 * The table records who sent what to whom and whether it worked. It never
 * stores the rendered body, credentials or Application Passwords.
 *
 * @package Epic_Mail_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Mail_MCP_Audit {

	const DB_VERSION_OPTION = 'epic_mail_mcp_db_version';
	const STATUSES          = array( 'sent', 'failed', 'denied' );

	/**
	 * Fully qualified audit table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'epic_mail_log';
	}

	/**
	 * Create or upgrade the audit table. Safe to run repeatedly (dbDelta).
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			recipient varchar(200) NOT NULL DEFAULT '',
			subject varchar(255) NOT NULL DEFAULT '',
			template varchar(64) DEFAULT NULL,
			status varchar(16) NOT NULL DEFAULT '',
			error text,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY status (status)
		) {$charset};";

		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, EPIC_MAIL_MCP_VERSION, false );
	}

	/**
	 * Install the table when the plugin was deployed without activation hooks.
	 */
	public static function maybe_install() {
		if ( get_option( self::DB_VERSION_OPTION ) === EPIC_MAIL_MCP_VERSION ) {
			return;
		}

		self::install();
	}

	/**
	 * Record one attempt.
	 *
	 * @param string      $recipient Recipient address as supplied by the caller.
	 * @param string      $subject   Subject line that was attempted.
	 * @param string|null $template  Template slug, or null for a direct send.
	 * @param string      $status    One of sent, failed, denied.
	 * @param string      $error     Error detail for failed and denied attempts.
	 * @return int Inserted row id, 0 on failure.
	 */
	public static function record( $recipient, $subject, $template, $status, $error = '' ) {
		global $wpdb;

		$status = in_array( $status, self::STATUSES, true ) ? $status : 'failed';

		$wpdb->insert(
			self::table_name(),
			array(
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'user_id'    => get_current_user_id(),
				'recipient'  => substr( (string) $recipient, 0, 200 ),
				'subject'    => substr( (string) $subject, 0, 255 ),
				'template'   => ( null === $template || '' === $template ) ? null : substr( (string) $template, 0, 64 ),
				'status'     => $status,
				'error'      => (string) $error,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Read audit rows, newest first.
	 *
	 * @param int    $limit  Page size, clamped to 1..200.
	 * @param int    $offset Row offset.
	 * @param string $status Optional status filter.
	 * @return array
	 */
	public static function query( $limit = 20, $offset = 0, $status = '' ) {
		global $wpdb;

		$table  = self::table_name();
		$limit  = min( 200, max( 1, (int) $limit ) );
		$offset = max( 0, (int) $offset );
		$status = in_array( $status, self::STATUSES, true ) ? $status : '';

		if ( '' !== $status ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status )
			);
			$rows  = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d OFFSET %d", $status, $limit, $offset ),
				ARRAY_A
			);
		} else {
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
			$rows  = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ),
				ARRAY_A
			);
		}

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = array(
				'id'         => (int) $row['id'],
				'created_at' => (string) $row['created_at'],
				'user_id'    => (int) $row['user_id'],
				'recipient'  => (string) $row['recipient'],
				'subject'    => (string) $row['subject'],
				'template'   => $row['template'],
				'status'     => (string) $row['status'],
				'error'      => (string) $row['error'],
			);
		}

		return array(
			'items'  => $items,
			'total'  => $total,
			'limit'  => $limit,
			'offset' => $offset,
			'status' => '' === $status ? null : $status,
		);
	}
}
