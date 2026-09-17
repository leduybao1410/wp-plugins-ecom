<?php
/**
 * Plugin Name: EPIC Image Optimize
 * Description: Caps dimensions/quality on future product photo uploads, and gives Media → Optimize Product Images a one-click way to shrink already-uploaded product photos in place (same filename/URL, so nothing else on the site needs to change).
 * Version: 1.0.0
 * Author: EPIC Coffee Roastery
 * Text Domain: epic-image-optimize
 *
 * Why this exists:
 *
 * Product photos land in the Media Library straight from wp-admin uploads —
 * e.g. 1500x1500px, ~370KB each, no resize or compression applied. The
 * headless Next.js storefront (see website/next.config.ts) fetches these
 * originals through Vercel's on-demand image optimizer, which has to
 * download and transform the full-size file on every first request for a
 * given size — measured at 3-5 seconds per image on a cold cache. Smaller,
 * better-compressed originals cut that cold-transform cost directly, the
 * same way compressing website/public/assets/*.jpg already did.
 *
 * Two parts:
 *  1. class-upload-optimizer.php — hooks WordPress's own image pipeline so
 *     every future upload is capped automatically. No admin action needed.
 *  2. class-bulk-optimizer.php — a Media submenu page to re-process photos
 *     that are already live. It backs up each original before touching it
 *     (wp-content/uploads/epic-image-optimize-backup/) and defaults to a
 *     dry-run "Scan" that only reports current sizes — nothing is changed
 *     until you click "Optimize now".
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EPIC_IMAGE_OPTIMIZE_VERSION', '1.0.0' );
define( 'EPIC_IMAGE_OPTIMIZE_FILE', __FILE__ );
define( 'EPIC_IMAGE_OPTIMIZE_DIR', plugin_dir_path( __FILE__ ) );

/** Longest side, in pixels, that any product photo is allowed to be —
 *  applies both to future uploads and to the bulk re-optimize tool. 1600px
 *  is comfortably larger than any on-page use (the biggest is the Next.js
 *  product gallery's main shot) while still being a fraction of 1500x1500
 *  at full/near-full quality. */
define( 'EPIC_IMAGE_OPTIMIZE_MAX_DIMENSION', 1600 );

/** JPEG quality applied on resize/recompress. 78 is the same ballpark as
 *  the quality=82 used for website/public/assets — visually lossless for
 *  photography, meaningfully smaller than an unspecified/default-quality
 *  export. */
define( 'EPIC_IMAGE_OPTIMIZE_QUALITY', 78 );

require_once EPIC_IMAGE_OPTIMIZE_DIR . 'includes/class-upload-optimizer.php';
require_once EPIC_IMAGE_OPTIMIZE_DIR . 'includes/class-bulk-optimizer.php';

add_action(
	'plugins_loaded',
	function () {
		Epic_Image_Optimize_Upload_Optimizer::init();
		Epic_Image_Optimize_Bulk_Optimizer::init();
	}
);
