<?php
/**
 * Media → Optimize Product Images — a one-click way to shrink product
 * photos that are already uploaded, without changing their filename/URL
 * (so the WooCommerce REST API, the Next.js storefront, and anything else
 * already pointing at them keeps working unchanged).
 *
 * Defaults to a read-only "Scan" showing current file sizes/dimensions.
 * Nothing is touched on disk until "Optimize now" is clicked, and every
 * original is copied to wp-content/uploads/epic-image-optimize-backup/
 * (mirroring its usual uploads/ path) before it's overwritten, the first
 * time this runs on that file — so the original is always recoverable.
 *
 * Scoped to WooCommerce product images specifically (featured image +
 * gallery, across every published product) rather than the whole Media
 * Library, since that's the confirmed slow path — see the plugin's main
 * file for why.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Image_Optimize_Bulk_Optimizer {

	const CAPABILITY = 'manage_woocommerce';
	const NONCE_ACTION = 'epic_image_optimize_run';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
	}

	public static function register_page() {
		add_submenu_page(
			'upload.php',
			__( 'Optimize Product Images', 'epic-image-optimize' ),
			__( 'Optimize Product Images', 'epic-image-optimize' ),
			self::CAPABILITY,
			'epic-image-optimize',
			array( __CLASS__, 'render_page' )
		);
	}

	/** Every attachment ID used as a product's featured image or gallery
	 *  photo, across every published product. */
	private static function get_product_image_attachment_ids(): array {
		$ids = array();

		$product_ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $product_ids as $product_id ) {
			$thumbnail_id = get_post_thumbnail_id( $product_id );
			if ( $thumbnail_id ) {
				$ids[] = (int) $thumbnail_id;
			}

			$gallery = get_post_meta( $product_id, '_product_image_gallery', true );
			if ( $gallery ) {
				foreach ( explode( ',', $gallery ) as $gallery_id ) {
					$gallery_id = (int) trim( $gallery_id );
					if ( $gallery_id ) {
						$ids[] = $gallery_id;
					}
				}
			}
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	private static function backup_dir(): string {
		$upload_dir = wp_upload_dir();
		$dir        = trailingslashit( $upload_dir['basedir'] ) . 'epic-image-optimize-backup';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	/** Copies $file into the backup dir (mirroring its uploads/-relative
	 *  path) the first time it's touched — a no-op on every later run, so
	 *  the backup always holds the ORIGINAL upload, never an
	 *  already-optimized copy. */
	private static function backup_original( string $file, string $upload_basedir ): void {
		$relative    = ltrim( str_replace( $upload_basedir, '', $file ), '/\\' );
		$backup_path = trailingslashit( self::backup_dir() ) . $relative;

		if ( file_exists( $backup_path ) ) {
			return;
		}

		wp_mkdir_p( dirname( $backup_path ) );
		copy( $file, $backup_path );
	}

	private static function optimize_attachment( int $attachment_id ): ?array {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return null;
		}

		$before_bytes = filesize( $file );

		$upload_dir = wp_upload_dir();
		self::backup_original( $file, $upload_dir['basedir'] );

		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return array(
				'id'    => $attachment_id,
				'file'  => basename( $file ),
				'error' => $editor->get_error_message(),
			);
		}

		$size = $editor->get_size();
		if ( $size && ( $size['width'] > EPIC_IMAGE_OPTIMIZE_MAX_DIMENSION || $size['height'] > EPIC_IMAGE_OPTIMIZE_MAX_DIMENSION ) ) {
			// $crop = false: fit within the box, keep aspect ratio — never
			// crops or distorts the photo.
			$editor->resize( EPIC_IMAGE_OPTIMIZE_MAX_DIMENSION, EPIC_IMAGE_OPTIMIZE_MAX_DIMENSION, false );
		}
		$editor->set_quality( EPIC_IMAGE_OPTIMIZE_QUALITY );

		$saved = $editor->save( $file );
		if ( is_wp_error( $saved ) ) {
			return array(
				'id'    => $attachment_id,
				'file'  => basename( $file ),
				'error' => $saved->get_error_message(),
			);
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$metadata = wp_generate_attachment_metadata( $attachment_id, $file );
		if ( $metadata ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		update_post_meta( $attachment_id, '_epic_image_optimized', EPIC_IMAGE_OPTIMIZE_VERSION );

		clearstatcache( true, $file );

		return array(
			'id'     => $attachment_id,
			'file'   => basename( $file ),
			'before' => $before_bytes,
			'after'  => filesize( $file ),
		);
	}

	private static function format_kb( $bytes ): string {
		return number_format( $bytes / 1024, 0 ) . ' KB';
	}

	public static function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'epic-image-optimize' ) );
		}

		$did_run = false;
		$results = array();
		$force   = ! empty( $_POST['epic_force'] );

		if ( isset( $_POST['epic_image_optimize_action'] ) && 'optimize' === $_POST['epic_image_optimize_action'] ) {
			check_admin_referer( self::NONCE_ACTION );
			set_time_limit( 300 );

			$did_run = true;
			$ids     = self::get_product_image_attachment_ids();

			foreach ( $ids as $attachment_id ) {
				$already_done = get_post_meta( $attachment_id, '_epic_image_optimized', true );
				if ( $already_done === EPIC_IMAGE_OPTIMIZE_VERSION && ! $force ) {
					continue;
				}
				$result = self::optimize_attachment( $attachment_id );
				if ( $result ) {
					$results[] = $result;
				}
			}
		}

		$ids           = self::get_product_image_attachment_ids();
		$total_bytes   = 0;
		$already_count = 0;
		$rows          = array();

		foreach ( $ids as $attachment_id ) {
			$file = get_attached_file( $attachment_id );
			if ( ! $file || ! file_exists( $file ) ) {
				continue;
			}
			$bytes        = filesize( $file );
			$total_bytes += $bytes;
			$editor       = wp_get_image_editor( $file );
			$dimensions   = '?';
			if ( ! is_wp_error( $editor ) ) {
				$size       = $editor->get_size();
				$dimensions = $size ? "{$size['width']}×{$size['height']}" : '?';
			}
			$done = get_post_meta( $attachment_id, '_epic_image_optimized', true );
			if ( $done === EPIC_IMAGE_OPTIMIZE_VERSION ) {
				++$already_count;
			}
			$rows[] = array(
				'id'         => $attachment_id,
				'file'       => basename( $file ),
				'bytes'      => $bytes,
				'dimensions' => $dimensions,
				'optimized'  => $done === EPIC_IMAGE_OPTIMIZE_VERSION,
			);
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Optimize Product Images', 'epic-image-optimize' ) . '</h1>';
		echo '<p>' . esc_html__( 'Shrinks WooCommerce product photos in place (same filename/URL — nothing else on the site needs to change). Each original is backed up to wp-content/uploads/epic-image-optimize-backup/ before it is touched, the first time this runs on that file.', 'epic-image-optimize' ) . '</p>';

		if ( $did_run ) {
			echo '<div class="notice notice-success"><p>' . sprintf(
				/* translators: %d: number of images processed */
				esc_html__( 'Processed %d image(s).', 'epic-image-optimize' ),
				count( $results )
			) . '</p></div>';

			if ( $results ) {
				echo '<table class="widefat striped"><thead><tr><th>' .
					esc_html__( 'File', 'epic-image-optimize' ) . '</th><th>' .
					esc_html__( 'Before', 'epic-image-optimize' ) . '</th><th>' .
					esc_html__( 'After', 'epic-image-optimize' ) . '</th><th>' .
					esc_html__( 'Saved', 'epic-image-optimize' ) . '</th></tr></thead><tbody>';
				foreach ( $results as $r ) {
					if ( isset( $r['error'] ) ) {
						echo '<tr><td>' . esc_html( $r['file'] ) . '</td><td colspan="3">' .
							esc_html__( 'Error: ', 'epic-image-optimize' ) . esc_html( $r['error'] ) . '</td></tr>';
						continue;
					}
					$saved_pct = $r['before'] > 0 ? round( ( 1 - $r['after'] / $r['before'] ) * 100 ) : 0;
					echo '<tr><td>' . esc_html( $r['file'] ) . '</td><td>' .
						esc_html( self::format_kb( $r['before'] ) ) . '</td><td>' .
						esc_html( self::format_kb( $r['after'] ) ) . '</td><td>' .
						esc_html( $saved_pct . '%' ) . '</td></tr>';
				}
				echo '</tbody></table>';
			}
		}

		echo '<h2>' . esc_html__( 'Current state', 'epic-image-optimize' ) . '</h2>';
		echo '<p>' . sprintf(
			/* translators: 1: total product images, 2: already-optimized count, 3: total size in KB */
			esc_html__( '%1$d product image(s) found, %2$d already optimized, %3$s total on disk.', 'epic-image-optimize' ),
			count( $rows ),
			$already_count,
			esc_html( self::format_kb( $total_bytes ) )
		) . '</p>';

		if ( $rows ) {
			echo '<table class="widefat striped"><thead><tr><th>' .
				esc_html__( 'File', 'epic-image-optimize' ) . '</th><th>' .
				esc_html__( 'Dimensions', 'epic-image-optimize' ) . '</th><th>' .
				esc_html__( 'Size', 'epic-image-optimize' ) . '</th><th>' .
				esc_html__( 'Status', 'epic-image-optimize' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				echo '<tr><td>' . esc_html( $row['file'] ) . '</td><td>' .
					esc_html( $row['dimensions'] ) . '</td><td>' .
					esc_html( self::format_kb( $row['bytes'] ) ) . '</td><td>' .
					( $row['optimized']
						? '<span style="color:#2271b1;">' . esc_html__( 'Optimized', 'epic-image-optimize' ) . '</span>'
						: esc_html__( 'Not yet optimized', 'epic-image-optimize' ) ) .
					'</td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '<form method="post" style="margin-top:16px;">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<input type="hidden" name="epic_image_optimize_action" value="optimize" />';
		echo '<label><input type="checkbox" name="epic_force" value="1" /> ' .
			esc_html__( 'Re-process images already marked as optimized', 'epic-image-optimize' ) . '</label><br /><br />';
		submit_button( __( 'Optimize now', 'epic-image-optimize' ), 'primary' );
		echo '</form>';

		echo '</div>';
	}
}
