<?php
/**
 * File-download endpoints for this plugin — two independent exports sharing
 * one pair of generic CSV/XLSX writers:
 *
 *  - The "Export CSV/XLSX" buttons on WooCommerce → Newsletter Subscribers
 *    (`?page=epic-newsletter-subscription&epic_newsletter_export=csv|xlsx`)
 *    — the full subscriber list, for handing to a bulk-email tool or a
 *    one-off mailing outside WordPress.
 *  - The delivery-log export on a sent campaign's detail screen under
 *    WooCommerce → Send Newsletter
 *    (`?page=epic-newsletter-broadcast&epic_newsletter_campaign_export=csv|xlsx&campaign_id=N`)
 *    — per-recipient send status for that one campaign
 *    (class-campaign-store.php).
 *
 * Both are hooked on `admin_init` rather than handled inline in their
 * respective render_page() methods because a file download must send its
 * Content-Type/Content-Disposition headers and exit before wp-admin's own
 * page chrome starts printing — admin_init runs early enough for that, the
 * same reason WooCommerce's own CSV exporters (orders, coupons) hook there
 * instead of rendering inline.
 *
 * XLSX is written here as a minimal OOXML spreadsheet built by hand with
 * ZipArchive — deliberately not a dependency on PhpSpreadsheet (or any
 * Composer package) for one plain, unstyled sheet. Falls back to CSV
 * automatically if the `zip` PHP extension isn't available on the host.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Newsletter_Export {

	const NONCE_ACTION          = 'epic_newsletter_export';
	const CAMPAIGN_NONCE_ACTION = 'epic_newsletter_campaign_export';

	/** Column order for the subscriber export — (row key => header label). */
	private static function subscriber_columns() {
		return array(
			'email'          => __( 'Email', 'epic-newsletter-subscription' ),
			'subscribed_at'  => __( 'Subscribed', 'epic-newsletter-subscription' ),
			'locale'         => __( 'Locale', 'epic-newsletter-subscription' ),
			'email_status'   => __( 'Team email', 'epic-newsletter-subscription' ),
			'confirm_status' => __( 'Confirmation', 'epic-newsletter-subscription' ),
		);
	}

	/** Plain-text label for a subscriber row's status value — same wording the list table's colored badges use, minus the color. Also covers campaign-recipient statuses, which reuse the same three string values (pending/sent/failed). */
	private static function status_label( $status ) {
		$labels = array(
			Epic_Newsletter_Store::STATUS_SENT     => __( 'Sent', 'epic-newsletter-subscription' ),
			Epic_Newsletter_Store::STATUS_FAILED   => __( 'Failed', 'epic-newsletter-subscription' ),
			Epic_Newsletter_Store::STATUS_DISABLED => __( 'Email disabled', 'epic-newsletter-subscription' ),
			Epic_Newsletter_Store::STATUS_PENDING  => __( 'Pending', 'epic-newsletter-subscription' ),
			Epic_Newsletter_Store::STATUS_EXISTS   => __( 'Already subscribed', 'epic-newsletter-subscription' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( (string) $status );
	}

	/**
	 * Dispatches to whichever export this admin_init request is actually
	 * for. Does nothing (no output) for every other admin request, so it's
	 * safe to hook unconditionally.
	 */
	public static function handle() {
		if ( ! empty( $_GET['epic_newsletter_export'] ) && ! empty( $_GET['page'] ) && 'epic-newsletter-subscription' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce is checked via check_admin_referer() inside each handler once we know the request is actually for us.
			self::handle_subscriber_export();
			return;
		}

		if ( ! empty( $_GET['epic_newsletter_campaign_export'] ) && ! empty( $_GET['page'] ) && 'epic-newsletter-broadcast' === $_GET['page'] && ! empty( $_GET['campaign_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
			self::handle_campaign_export();
			return;
		}
	}

	private static function handle_subscriber_export() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to export the newsletter subscriber list.', 'epic-newsletter-subscription' ) );
		}
		check_admin_referer( self::NONCE_ACTION );

		$format  = sanitize_key( wp_unslash( $_GET['epic_newsletter_export'] ) );
		$columns = self::subscriber_columns();
		$headers = array_values( $columns );

		$rows = array();
		foreach ( Epic_Newsletter_Store::get_all_for_export() as $row ) {
			$line = array();
			foreach ( array_keys( $columns ) as $key ) {
				$line[] = self::format_subscriber_cell( $key, $row );
			}
			$rows[] = $line;
		}

		self::output( $format, $headers, $rows, 'epic-newsletter-subscribers-' . gmdate( 'Y-m-d' ) );
	}

	private static function handle_campaign_export() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to export this campaign\'s delivery log.', 'epic-newsletter-subscription' ) );
		}

		$campaign_id = (int) $_GET['campaign_id'];
		check_admin_referer( self::CAMPAIGN_NONCE_ACTION . '_' . $campaign_id );

		require_once EPIC_NEWSLETTER_SUBSCRIPTION_DIR . 'includes/class-campaign-store.php';

		$format  = sanitize_key( wp_unslash( $_GET['epic_newsletter_campaign_export'] ) );
		$headers = array(
			__( 'Email', 'epic-newsletter-subscription' ),
			__( 'Locale', 'epic-newsletter-subscription' ),
			__( 'Status', 'epic-newsletter-subscription' ),
			__( 'Sent at', 'epic-newsletter-subscription' ),
		);

		$rows = array();
		foreach ( Epic_Newsletter_Campaign_Store::get_recipients_for_export( $campaign_id ) as $row ) {
			$rows[] = array(
				(string) $row['email'],
				(string) $row['locale'],
				self::status_label( $row['status'] ),
				$row['sent_at'] ? mysql2date( 'Y-m-d H:i', $row['sent_at'] ) : '',
			);
		}

		self::output( $format, $headers, $rows, 'epic-newsletter-campaign-' . $campaign_id . '-' . gmdate( 'Y-m-d' ) );
	}

	/** Shared value formatting for the subscriber export. */
	private static function format_subscriber_cell( $key, array $row ) {
		switch ( $key ) {
			case 'subscribed_at':
				return isset( $row['subscribed_at'] ) ? mysql2date( 'Y-m-d H:i', $row['subscribed_at'] ) : '';
			case 'email_status':
			case 'confirm_status':
				return self::status_label( isset( $row[ $key ] ) ? $row[ $key ] : '' );
			default:
				return isset( $row[ $key ] ) ? (string) $row[ $key ] : '';
		}
	}

	/**
	 * @param string   $format   'csv' or 'xlsx'.
	 * @param string[] $headers  Column header labels, in order.
	 * @param string[][] $rows   Each entry a flat list of already-formatted cell strings, same order/count as $headers.
	 */
	private static function output( $format, array $headers, array $rows, $basename ) {
		if ( 'xlsx' === $format && class_exists( 'ZipArchive' ) ) {
			self::write_xlsx( $headers, $rows, $basename . '.xlsx' );
		} else {
			// Also the fallback when xlsx was requested but ZipArchive isn't
			// available on this host — the download still succeeds (opens
			// fine in Excel/Sheets) instead of a fatal error.
			self::write_csv( $headers, $rows, $basename . '.csv' );
		}
		exit;
	}

	private static function write_csv( array $headers, array $rows, $filename ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fputcsv writes directly to the response stream, not into HTML.
		$out = fopen( 'php://output', 'w' );

		// UTF-8 BOM so Excel on Windows (which otherwise guesses the system
		// codepage) renders Vietnamese/diacritic values correctly instead of
		// as mojibake.
		fwrite( $out, "\xEF\xBB\xBF" );

		fputcsv( $out, $headers );
		foreach ( $rows as $row ) {
			fputcsv( $out, $row );
		}

		fclose( $out );
	}

	/**
	 * Minimal single-sheet OOXML (.xlsx) writer — every cell is written as
	 * an inline string (`t="inlineStr"`), so there's no shared-strings table
	 * to maintain and no numeric/date cell typing to get right. Good enough
	 * for "hand this list to Mailchimp/Brevo" or "check who a campaign
	 * reached"; not intended as a general spreadsheet library.
	 */
	private static function write_xlsx( array $headers, array $rows, $filename ) {
		$sheet_xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
		$sheet_xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
		$sheet_xml .= self::xlsx_row( 1, $headers );

		$row_number = 2;
		foreach ( $rows as $row ) {
			$sheet_xml .= self::xlsx_row( $row_number, $row );
			++$row_number;
		}

		$sheet_xml .= '</sheetData></worksheet>';

		$content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
			. '</Types>';

		$root_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '</Relationships>';

		$workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheets><sheet name="Export" sheetId="1" r:id="rId1"/></sheets>'
			. '</workbook>';

		$workbook_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
			. '</Relationships>';

		$tmp_file = wp_tempnam( 'epic-newsletter-export' );
		$zip      = new ZipArchive();

		if ( true !== $zip->open( $tmp_file, ZipArchive::OVERWRITE ) ) {
			// Extremely unlikely (tempnam just succeeded), but fail safe to
			// CSV rather than serve a broken/empty file.
			wp_delete_file( $tmp_file );
			self::write_csv( $headers, $rows, str_replace( '.xlsx', '.csv', $filename ) );
			return;
		}

		$zip->addEmptyDir( '_rels' );
		$zip->addEmptyDir( 'xl' );
		$zip->addEmptyDir( 'xl/_rels' );
		$zip->addEmptyDir( 'xl/worksheets' );
		$zip->addFromString( '[Content_Types].xml', $content_types );
		$zip->addFromString( '_rels/.rels', $root_rels );
		$zip->addFromString( 'xl/workbook.xml', $workbook );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', $workbook_rels );
		$zip->addFromString( 'xl/worksheets/sheet1.xml', $sheet_xml );
		$zip->close();

		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Content-Length: ' . filesize( $tmp_file ) );

		readfile( $tmp_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile -- streaming a just-built temp zip straight to the download response; WP_Filesystem has no streaming read primitive for this.
		wp_delete_file( $tmp_file );
	}

	/** @param int $row_number 1-based spreadsheet row. @param string[] $values */
	private static function xlsx_row( $row_number, array $values ) {
		$xml = '<row r="' . (int) $row_number . '">';
		foreach ( $values as $i => $value ) {
			$cell_ref = self::column_letter( $i ) . $row_number;
			$xml     .= '<c r="' . $cell_ref . '" t="inlineStr"><is><t xml:space="preserve">' . self::xml_escape( $value ) . '</t></is></c>';
		}
		return $xml . '</row>';
	}

	/** 0-based column index → spreadsheet column letter(s) (0 => A, 25 => Z, 26 => AA, ...). */
	private static function column_letter( $index ) {
		$letter = '';
		++$index;
		while ( $index > 0 ) {
			$remainder = ( $index - 1 ) % 26;
			$letter    = chr( 65 + $remainder ) . $letter;
			$index     = (int) ( ( $index - $remainder ) / 26 );
		}
		return $letter;
	}

	/** esc_xml() is only available on WP 5.5+ (this plugin's floor is 6.0), used here rather than htmlspecialchars() to also strip control characters XML forbids. */
	private static function xml_escape( $value ) {
		return esc_xml( (string) $value );
	}
}
