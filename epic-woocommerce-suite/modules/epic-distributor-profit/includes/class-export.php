<?php
/**
 * CSV / XLSX export of the (filtered) ledger. Hand-built minimal OOXML writer — no PhpSpreadsheet
 * dependency, same approach already used in epic-newsletter-subscription.
 *
 * @package Epic_Distributor_Profit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Distributor_Profit_Export {

	public static function init() {
		add_action( 'admin_post_epic_dp_export', array( __CLASS__, 'handle_export' ) );
	}

	private static function columns() {
		return array(
			'entry_date'         => __( 'Date', 'epic-distributor-profit' ),
			'order_code'         => __( 'Order', 'epic-distributor-profit' ),
			'product_name'       => __( 'Product', 'epic-distributor-profit' ),
			'channel'            => __( 'Channel', 'epic-distributor-profit' ),
			'quantity'           => __( 'Qty', 'epic-distributor-profit' ),
			'revenue'            => __( 'Revenue', 'epic-distributor-profit' ),
			'cost'               => __( 'Cost', 'epic-distributor-profit' ),
			'shipping_cost'      => __( 'Shipping', 'epic-distributor-profit' ),
			'other_cost_amount'  => __( 'Other Cost', 'epic-distributor-profit' ),
			'gross_profit'       => __( 'Gross Profit', 'epic-distributor-profit' ),
			'distributor_name'   => __( 'Distributor', 'epic-distributor-profit' ),
			'commission_percent_snapshot' => __( 'Commission %', 'epic-distributor-profit' ),
			'commission_amount'  => __( 'Commission', 'epic-distributor-profit' ),
			'net_profit'         => __( 'Net Profit', 'epic-distributor-profit' ),
		);
	}

	private static function get_filter_args_from_request() {
		return array(
			'date_from'      => isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '',
			'date_to'        => isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '',
			'distributor_id' => isset( $_GET['distributor_id'] ) ? (int) $_GET['distributor_id'] : 0,
			'channel'        => isset( $_GET['channel'] ) ? sanitize_text_field( wp_unslash( $_GET['channel'] ) ) : '',
		);
	}

	public static function handle_export() {
		if ( ! current_user_can( EPIC_DISTRIBUTOR_PROFIT_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'epic-distributor-profit' ) );
		}
		check_admin_referer( 'epic_dp_export' );

		$format      = isset( $_GET['format'] ) && 'xlsx' === $_GET['format'] ? 'xlsx' : 'csv';
		$filter_args = self::get_filter_args_from_request();
		$rows        = Epic_Distributor_Profit_Store::query_entries( $filter_args ); // no per_page => all rows.
		$columns     = self::columns();

		$filename = 'distributor-profit-' . gmdate( 'Y-m-d' );

		if ( 'xlsx' === $format ) {
			self::output_xlsx( $rows, $columns, $filename );
		} else {
			self::output_csv( $rows, $columns, $filename );
		}
		exit;
	}

	private static function row_values( $row, $columns ) {
		$values = array();
		foreach ( array_keys( $columns ) as $key ) {
			$values[] = isset( $row->$key ) ? $row->$key : '';
		}
		return $values;
	}

	private static function output_csv( $rows, $columns, $filename ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '.csv"' );

		$handle = fopen( 'php://output', 'w' );
		// UTF-8 BOM so Excel opens Vietnamese diacritics correctly.
		fwrite( $handle, "\xEF\xBB\xBF" );
		fputcsv( $handle, array_values( $columns ) );
		foreach ( $rows as $row ) {
			fputcsv( $handle, self::row_values( $row, $columns ) );
		}
		fclose( $handle );
	}

	/**
	 * Minimal single-sheet XLSX writer using inline strings (no sharedStrings.xml needed).
	 */
	private static function output_xlsx( $rows, $columns, $filename ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			// Fall back to CSV if the server has no zip extension — same fallback the newsletter
			// plugin uses, so an export request never dies with a fatal.
			self::output_csv( $rows, $columns, $filename );
			return;
		}

		$tmp = wp_tempnam( 'epic-dp-export' );
		$zip = new ZipArchive();
		$zip->open( $tmp, ZipArchive::OVERWRITE );

		$zip->addFromString( '[Content_Types].xml', self::content_types_xml() );
		$zip->addFromString( '_rels/.rels', self::rels_xml() );
		$zip->addFromString( 'xl/workbook.xml', self::workbook_xml() );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', self::workbook_rels_xml() );
		$zip->addFromString( 'xl/worksheets/sheet1.xml', self::sheet_xml( $rows, $columns ) );

		$zip->close();

		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '.xlsx"' );
		header( 'Content-Length: ' . filesize( $tmp ) );
		readfile( $tmp ); // phpcs:ignore
		unlink( $tmp ); // phpcs:ignore
	}

	private static function content_types_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
			'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
			'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
			'<Default Extension="xml" ContentType="application/xml"/>' .
			'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
			'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
			'</Types>';
	}

	private static function rels_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
			'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
			'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
			'</Relationships>';
	}

	private static function workbook_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
			'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
			'<sheets><sheet name="Distributor Profit" sheetId="1" r:id="rId1"/></sheets>' .
			'</workbook>';
	}

	private static function workbook_rels_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
			'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
			'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
			'</Relationships>';
	}

	private static function col_letter( $index ) {
		$letters = '';
		$index++;
		while ( $index > 0 ) {
			$mod     = ( $index - 1 ) % 26;
			$letters = chr( 65 + $mod ) . $letters;
			$index   = (int) ( ( $index - $mod ) / 26 );
		}
		return $letters;
	}

	private static function cell_xml( $col_index, $row_index, $value ) {
		$ref = self::col_letter( $col_index ) . $row_index;
		if ( is_numeric( $value ) && '' !== $value ) {
			return '<c r="' . $ref . '"><v>' . esc_html( (string) $value ) . '</v></c>';
		}
		$escaped = esc_html( (string) $value );
		return '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $escaped . '</t></is></c>';
	}

	private static function sheet_xml( $rows, $columns ) {
		$xml       = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
		$xml      .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
		$xml      .= '<sheetData>';
		$row_index = 1;

		$xml .= '<row r="' . $row_index . '">';
		$col  = 0;
		foreach ( array_values( $columns ) as $header ) {
			$xml .= self::cell_xml( $col, $row_index, $header );
			$col++;
		}
		$xml .= '</row>';
		$row_index++;

		foreach ( $rows as $row ) {
			$xml .= '<row r="' . $row_index . '">';
			$col  = 0;
			foreach ( self::row_values( $row, $columns ) as $value ) {
				$xml .= self::cell_xml( $col, $row_index, $value );
				$col++;
			}
			$xml .= '</row>';
			$row_index++;
		}

		$xml .= '</sheetData></worksheet>';
		return $xml;
	}
}
