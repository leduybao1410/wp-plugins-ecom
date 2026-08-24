<?php
/**
 * EPIC REST URL Fix (opt-in module of EPIC WooCommerce Suite)
 *
 * Rewrites only the REST API's own base URL (rest_url()) from the public
 * storefront domain to the WordPress admin domain — home_url() /
 * get_bloginfo('url') is left untouched everywhere else on the site.
 *
 * Why this exists:
 *
 * This install's "Site Address (URL)" (the `home` option) is set to the
 * public storefront domain (e.g. https://epicroastery.coffee), which is
 * CORRECT: home_url() is what points things like the WooCommerce order-email
 * header's brand-name link at the real storefront. The one place this causes
 * a problem is the REST API's own base URL: WordPress computes rest_url()
 * from home_url() by default, so every browser-side save in wp-admin (most
 * visibly clicking "Update" on a post) POSTs to
 * https://storefront.example/wp-json/..., a domain that isn't wired to reach
 * WordPress — the request fails the browser's CORS preflight and the edit
 * silently fails.
 *
 * The fix below is intentionally narrow: it only rewrites the REST base via
 * the `rest_url` filter so wpApiSettings.root (and every other REST caller)
 * resolves to the admin domain, while home_url() keeps pointing at the real
 * storefront for everything else.
 *
 * IMPORTANT — opt-in only. The standalone mu-plugin version of this file
 * hardcoded this site's two domains. A bundled plugin must not ship another
 * site's domains, so this module does NOTHING unless you define both constants
 * in wp-config.php before this file loads:
 *
 *   define( 'EPIC_REST_URL_FIX_FROM', 'https://epicroastery.coffee/' );
 *   define( 'EPIC_REST_URL_FIX_TO',   'https://admin.epicroastery.coffee/' );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

if ( defined( 'EPIC_REST_URL_FIX_FROM' ) && defined( 'EPIC_REST_URL_FIX_TO' ) ) {
	add_filter(
		'rest_url',
		function ( $url ) {
			if ( 0 === strpos( $url, EPIC_REST_URL_FIX_FROM ) ) {
				return EPIC_REST_URL_FIX_TO . substr( $url, strlen( EPIC_REST_URL_FIX_FROM ) );
			}
			return $url;
		}
	);
}
