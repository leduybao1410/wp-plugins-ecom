<?php
/**
 * Plugin Name: EPIC REST URL Fix (must-use)
 * Description: Rewrites only the REST API's own base URL (rest_url()) from the public storefront domain to the WordPress admin domain — home_url()/get_bloginfo('url') itself is left untouched everywhere else on the site.
 * Version:     1.0.0
 * Author:      EPIC Coffee Roaster
 *
 * Why this exists:
 *
 * This install's "Site Address (URL)" (the `home` option) is set to the
 * public storefront domain, https://epicroastery.coffee — and that is
 * CORRECT: home_url() / get_bloginfo('url') is what points things like the
 * WooCommerce order-email header's brand-name link at the real storefront,
 * not the WordPress admin backend. Changing that setting to "fix" anything
 * would break every one of those links.
 *
 * The one place this causes a real problem is the REST API's own base URL:
 * WordPress computes rest_url() from home_url() by default
 * (wp-includes/rest-api.php, get_rest_url()). The block editor reads that
 * computed URL into `wpApiSettings.root` and uses it for every save. Since
 * WordPress itself actually runs on admin.epicroastery.coffee, every
 * browser-side save in wp-admin — most visibly clicking "Update" on a post —
 * was trying to POST to https://epicroastery.coffee/wp-json/..., a domain
 * that isn't wired to reach WordPress. That request either 503s or 308s,
 * and either way fails the browser's CORS preflight before ever reaching
 * this server, so the edit silently fails ("Cập nhật không thành công" /
 * "Update failed").
 *
 * The fix below is intentionally narrow: it only rewrites the REST base via
 * the `rest_url` filter, so `wpApiSettings.root` (and every other REST
 * caller, in-admin or otherwise) resolves to admin.epicroastery.coffee,
 * while home_url()/get_bloginfo('url') keep pointing at the real storefront
 * for everything else — emails, feeds, canonical links, etc.
 *
 * Installed as a must-use plugin (wp-content/mu-plugins/) rather than a
 * regular plugin so it loads unconditionally and can't be accidentally
 * deactivated from the Plugins screen — this is infrastructure, not an
 * optional feature.
 *
 * To point this at different domains without editing this file, define
 * EPIC_REST_URL_FIX_FROM / EPIC_REST_URL_FIX_TO in wp-config.php before this
 * file loads (mu-plugins load in filename-alphabetical order).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

if ( ! defined( 'EPIC_REST_URL_FIX_FROM' ) ) {
	define( 'EPIC_REST_URL_FIX_FROM', 'https://epicroastery.coffee/' );
}

if ( ! defined( 'EPIC_REST_URL_FIX_TO' ) ) {
	define( 'EPIC_REST_URL_FIX_TO', 'https://admin.epicroastery.coffee/' );
}

add_filter(
	'rest_url',
	function ( $url ) {
		if ( 0 === strpos( $url, EPIC_REST_URL_FIX_FROM ) ) {
			return EPIC_REST_URL_FIX_TO . substr( $url, strlen( EPIC_REST_URL_FIX_FROM ) );
		}
		return $url;
	}
);
