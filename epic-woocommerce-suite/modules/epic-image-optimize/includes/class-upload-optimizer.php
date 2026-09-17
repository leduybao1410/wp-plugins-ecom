<?php
/**
 * Caps every FUTURE image upload at EPIC_IMAGE_OPTIMIZE_MAX_DIMENSION /
 * EPIC_IMAGE_OPTIMIZE_QUALITY, using WordPress's own built-in hooks —
 * doesn't touch anything already uploaded (that's class-bulk-optimizer.php).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Image_Optimize_Upload_Optimizer {

	public static function init() {
		// WordPress auto-downscales (and renames with a "-scaled" suffix)
		// any upload wider/taller than this threshold, generating that
		// scaled copy as the "full" size everything else (including the
		// WooCommerce REST API's images[].src) reads from. Default is
		// 2560px — well above our product photos' native 1500px, so it
		// never fires today. Lowering it to our own cap makes WordPress
		// do the resize itself, the same way it already knows how to.
		add_filter( 'big_image_size_threshold', array( __CLASS__, 'max_dimension' ) );

		// Quality used whenever WordPress (re)encodes a JPEG — the
		// "-scaled" original included. WordPress's own default is 82;
		// this brings it in line with the rest of the site's photography.
		add_filter( 'wp_editor_set_quality', array( __CLASS__, 'quality' ), 10, 2 );
	}

	public static function max_dimension() {
		return EPIC_IMAGE_OPTIMIZE_MAX_DIMENSION;
	}

	public static function quality( $quality, $mime_type ) {
		if ( 'image/jpeg' === $mime_type ) {
			return EPIC_IMAGE_OPTIMIZE_QUALITY;
		}
		return $quality;
	}
}
