<?php
/**
 * One-time historical import of the T09 tab from the old "BÁO CÁO BH.xlsx" report.
 *
 * v1 scope: T09 only (its "TỔNG HỢP ĐƠN" order section). T08 is deliberately NOT imported yet — the
 * user wasn't sure the THỰC NHẬN-as-revenue assumption is right for the Shopee tab, so that's deferred.
 *
 * Reads the .xlsx with a minimal hand-rolled reader (ZipArchive + SimpleXML) — no PhpSpreadsheet
 * dependency, matching this project's existing no-dependency habit for OOXML files.
 *
 * @package Epic_Distributor_Profit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Distributor_Profit_Import_Legacy {

	const TARGET_SHEET = 'T09';

	public static function init() {
		add_action( 'admin_post_epic_dp_import_legacy', array( __CLASS__, 'handle_import' ) );
	}

	public static function render_import_box() {
		?>
		<h2><?php esc_html_e( 'Historical import (T09)', 'epic-distributor-profit' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Upload the old "BÁO CÁO BH.xlsx" report to import its T09 tab as one historical entry, assigned to a new "Legacy / Unassigned" distributor at 0% commission. T08 (Shopee) is not imported yet.', 'epic-distributor-profit' ); ?>
		</p>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'epic_dp_import_legacy', 'epic_dp_import_legacy_nonce' ); ?>
			<input type="hidden" name="action" value="epic_dp_import_legacy" />
			<input type="file" name="report_file" accept=".xlsx" required />
			<?php submit_button( __( 'Import T09', 'epic-distributor-profit' ), 'secondary', '', false ); ?>
		</form>
		<?php
	}

	public static function handle_import() {
		if ( ! current_user_can( EPIC_DISTRIBUTOR_PROFIT_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'epic-distributor-profit' ) );
		}
		check_admin_referer( 'epic_dp_import_legacy', 'epic_dp_import_legacy_nonce' );

		$redirect_base = admin_url( 'admin.php?page=epic-distributor-profit' );

		if ( empty( $_FILES['report_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['report_file']['tmp_name'] ) ) {
			wp_safe_redirect( add_query_arg( 'import_error', rawurlencode( __( 'No file uploaded.', 'epic-distributor-profit' ) ), $redirect_base ) );
			exit;
		}

		try {
			$orders = self::parse_t09( $_FILES['report_file']['tmp_name'] ); // phpcs:ignore
		} catch ( Exception $e ) {
			wp_safe_redirect( add_query_arg( 'import_error', rawurlencode( $e->getMessage() ), $redirect_base ) );
			exit;
		}

		if ( empty( $orders ) ) {
			wp_safe_redirect( add_query_arg( 'import_error', rawurlencode( __( 'Could not find any order rows in the T09 tab.', 'epic-distributor-profit' ) ), $redirect_base ) );
			exit;
		}

		$distributor_id = Epic_Distributor_Profit_Store::get_or_create_legacy_distributor();
		$created        = 0;

		foreach ( $orders as $order ) {
			Epic_Distributor_Profit_Store::save_entry(
				array(
					'entry_date'        => $order['entry_date'],
					'wc_order_id'       => 0,
					'order_code'        => $order['order_code'],
					'channel'           => 'Web',
					'distributor_id'    => $distributor_id,
					'product_name'      => $order['product_name'],
					'quantity'          => '',
					'cost'              => $order['cost'],
					'revenue'           => $order['revenue'],
					'shipping_cost'     => $order['shipping_cost'],
					'other_cost_label'  => $order['other_cost_label'],
					'other_cost_amount' => $order['other_cost_amount'],
					'source'            => 'legacy_import',
				)
			);
			$created++;
		}

		wp_safe_redirect( add_query_arg( 'imported', $created, $redirect_base ) );
		exit;
	}

	// ---------------------------------------------------------------------
	// Minimal .xlsx reader
	// ---------------------------------------------------------------------

	private static function col_to_index( $ref ) {
		preg_match( '/^([A-Z]+)/', $ref, $m );
		$letters = $m[1];
		$index   = 0;
		for ( $i = 0; $i < strlen( $letters ); $i++ ) {
			$index = $index * 26 + ( ord( $letters[ $i ] ) - 64 );
		}
		return $index - 1; // zero-based.
	}

	private static function load_shared_strings( ZipArchive $zip ) {
		$xml = $zip->getFromName( 'xl/sharedStrings.xml' );
		if ( false === $xml ) {
			return array();
		}
		$sx  = simplexml_load_string( $xml );
		$out = array();
		foreach ( $sx->si as $si ) {
			if ( isset( $si->t ) ) {
				$out[] = (string) $si->t;
			} else {
				$text = '';
				foreach ( $si->r as $r ) {
					$text .= (string) $r->t;
				}
				$out[] = $text;
			}
		}
		return $out;
	}

	private static function find_sheet_target( ZipArchive $zip, $sheet_name ) {
		$workbook_xml = $zip->getFromName( 'xl/workbook.xml' );
		if ( false === $workbook_xml ) {
			throw new Exception( __( 'Not a valid .xlsx file (missing workbook.xml).', 'epic-distributor-profit' ) );
		}
		$wb  = simplexml_load_string( $workbook_xml );
		$rid = null;
		foreach ( $wb->sheets->sheet as $sheet ) {
			if ( (string) $sheet['name'] === $sheet_name ) {
				$attrs = $sheet->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' );
				$rid   = (string) $attrs['id'];
				break;
			}
		}
		if ( ! $rid ) {
			throw new Exception(
				sprintf(
					/* translators: %s: sheet name */
					__( 'Could not find a "%s" tab in the uploaded file.', 'epic-distributor-profit' ),
					$sheet_name
				)
			);
		}

		$rels_xml = $zip->getFromName( 'xl/_rels/workbook.xml.rels' );
		$rels     = simplexml_load_string( $rels_xml );
		foreach ( $rels->Relationship as $rel ) {
			if ( (string) $rel['Id'] === $rid ) {
				return 'xl/' . ltrim( (string) $rel['Target'], '/' );
			}
		}
		throw new Exception( __( 'Could not resolve the sheet location inside the .xlsx file.', 'epic-distributor-profit' ) );
	}

	/**
	 * Returns a 2D sparse array: $rows[row_number][col_index] = string|float value.
	 */
	private static function parse_sheet( ZipArchive $zip, $target, $shared_strings ) {
		$xml = $zip->getFromName( $target );
		if ( false === $xml ) {
			throw new Exception( __( 'Could not read the sheet data inside the .xlsx file.', 'epic-distributor-profit' ) );
		}
		$sx   = simplexml_load_string( $xml );
		$rows = array();

		foreach ( $sx->sheetData->row as $row ) {
			$row_num = (int) $row['r'];
			foreach ( $row->c as $cell ) {
				$ref  = (string) $cell['r'];
				$col  = self::col_to_index( $ref );
				$type = (string) $cell['t'];

				if ( 'inlineStr' === $type ) {
					$value = isset( $cell->is->t ) ? (string) $cell->is->t : '';
				} elseif ( 's' === $type ) {
					$idx   = isset( $cell->v ) ? (int) $cell->v : -1;
					$value = isset( $shared_strings[ $idx ] ) ? $shared_strings[ $idx ] : '';
				} elseif ( isset( $cell->v ) ) {
					$raw   = (string) $cell->v;
					$value = is_numeric( $raw ) ? $raw + 0 : $raw;
				} else {
					$value = '';
				}

				$rows[ $row_num ][ $col ] = $value;
			}
		}
		return $rows;
	}

	/**
	 * Walks the "TỔNG HỢP ĐƠN" section of T09 and returns a list of orders, each:
	 * order_code, product_name (joined), revenue, cost, shipping_cost, other_cost_label, other_cost_amount.
	 */
	private static function parse_t09( $file_path ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $file_path ) ) {
			throw new Exception( __( 'Could not open the uploaded file as .xlsx.', 'epic-distributor-profit' ) );
		}

		$shared_strings = self::load_shared_strings( $zip );
		$target         = self::find_sheet_target( $zip, self::TARGET_SHEET );
		$rows           = self::parse_sheet( $zip, $target, $shared_strings );
		$zip->close();

		// Find the "TỔNG HỢP ĐƠN" section header row, then its column-header row directly below.
		$section_row = null;
		foreach ( $rows as $row_num => $cols ) {
			if ( isset( $cols[0] ) && false !== stripos( (string) $cols[0], 'TỔNG HỢP ĐƠN' ) ) {
				$section_row = $row_num;
				break;
			}
		}
		if ( null === $section_row ) {
			throw new Exception( __( 'Could not find the "TỔNG HỢP ĐƠN" section in the T09 tab.', 'epic-distributor-profit' ) );
		}

		$header_row_num = $section_row + 1;
		if ( ! isset( $rows[ $header_row_num ] ) ) {
			throw new Exception( __( 'Could not find the order table header row under "TỔNG HỢP ĐƠN".', 'epic-distributor-profit' ) );
		}

		// Map column index -> normalized header label.
		$header_map = array();
		foreach ( $rows[ $header_row_num ] as $col => $label ) {
			$header_map[ $col ] = self::normalize_header( (string) $label );
		}
		$col_for = array_flip( $header_map ); // label -> col (last one wins if duplicated, fine here).

		$orders     = array();
		$current    = null;
		$row_nums   = array_keys( $rows );
		sort( $row_nums );
		foreach ( $row_nums as $row_num ) {
			if ( $row_num <= $header_row_num ) {
				continue;
			}
			$cols = $rows[ $row_num ];

			$stt         = isset( $cols[ $col_for['stt'] ?? -1 ] ) ? $cols[ $col_for['stt'] ] : '';
			$order_code  = isset( $col_for['ma_don'], $cols[ $col_for['ma_don'] ] ) ? $cols[ $col_for['ma_don'] ] : '';
			$product     = isset( $col_for['san_pham'], $cols[ $col_for['san_pham'] ] ) ? $cols[ $col_for['san_pham'] ] : '';
			$tong_don    = isset( $col_for['tong_don'], $cols[ $col_for['tong_don'] ] ) ? $cols[ $col_for['tong_don'] ] : '';
			$thuc_nhan   = isset( $col_for['thuc_nhan'], $cols[ $col_for['thuc_nhan'] ] ) ? $cols[ $col_for['thuc_nhan'] ] : '';
			$gia_von     = isset( $col_for['gia_von'], $cols[ $col_for['gia_von'] ] ) ? $cols[ $col_for['gia_von'] ] : '';
			$other_label = isset( $col_for['chi_phi_tru'], $cols[ $col_for['chi_phi_tru'] ] ) ? $cols[ $col_for['chi_phi_tru'] ] : '';
			$other_amount_col = ( $col_for['chi_phi_tru'] ?? -1 ) + 1;
			$other_amount     = isset( $cols[ $other_amount_col ] ) ? $cols[ $other_amount_col ] : '';

			$row_is_totally_blank = ( '' === $stt && '' === $order_code && '' === $product && '' === $tong_don );
			if ( $row_is_totally_blank ) {
				continue;
			}

			// A totals row repeats TỔNG ĐƠN/etc. but has no STT, no MÃ ĐƠN and no SẢN PHẨM — stop there.
			if ( '' === $stt && '' === $order_code && '' === $product && '' !== $tong_don ) {
				break;
			}

			if ( '' !== $stt || '' !== $order_code ) {
				// New order row.
				if ( $current ) {
					$orders[] = self::finalize_order( $current );
				}
				$current = array(
					'order_code'       => (string) $order_code,
					'products'         => array_filter( array( (string) $product ) ),
					'revenue'          => $thuc_nhan,
					'cost'             => $gia_von,
					'other_label'      => (string) $other_label,
					'other_amount'     => $other_amount,
				);
			} elseif ( $current && '' !== $product ) {
				// Continuation row: another product on the same order.
				$current['products'][] = (string) $product;
			}
		}
		if ( $current ) {
			$orders[] = self::finalize_order( $current );
		}

		return $orders;
	}

	private static function finalize_order( $current ) {
		$is_shipping     = false !== stripos( $current['other_label'], 'ship' );
		$other_amount    = is_numeric( $current['other_amount'] ) ? (float) $current['other_amount'] : 0;

		return array(
			'entry_date'        => gmdate( 'Y-09-01' ), // T09 = tháng 09; exact day unknown, defaults to the 1st — edit after import if the real date matters.
			'order_code'        => $current['order_code'],
			'product_name'      => implode( ', ', $current['products'] ),
			'revenue'           => is_numeric( $current['revenue'] ) ? (float) $current['revenue'] : 0,
			'cost'              => is_numeric( $current['cost'] ) ? (float) $current['cost'] : 0,
			'shipping_cost'     => $is_shipping ? $other_amount : 0,
			'other_cost_label'  => $is_shipping ? '' : $current['other_label'],
			'other_cost_amount' => $is_shipping ? 0 : $other_amount,
		);
	}

	/**
	 * Normalize a Vietnamese header label to a stable ASCII key, tolerant of the diacritics/casing
	 * in the actual sheet (STT, MÃ ĐƠN, SẢN PHẨM, TỔNG ĐƠN, THỰC NHẬN, GIÁ VỐN, LỢI NHUẬN, CHI PHÍ TRỪ).
	 */
	private static function normalize_header( $label ) {
		$label = mb_strtolower( trim( $label ) );
		$map   = array(
			'stt'            => 'stt',
			'mã đơn'         => 'ma_don',
			'sản phẩm'       => 'san_pham',
			'tổng đơn'       => 'tong_don',
			'thực nhận'      => 'thuc_nhan',
			'giá vốn'        => 'gia_von',
			'lợi nhuận'      => 'loi_nhuan',
			'chi phí trừ'    => 'chi_phi_tru',
		);
		return isset( $map[ $label ] ) ? $map[ $label ] : $label;
	}
}
