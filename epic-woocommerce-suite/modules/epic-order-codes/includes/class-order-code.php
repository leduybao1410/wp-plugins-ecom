<?php
/**
 * Deterministic, reversible order-code generator.
 *
 * Converts a WooCommerce order ID (a sequential integer) into a short code
 * mixing letters and digits that is impossible to scan or guess, e.g.
 * `EPIC-7K3M9X`. Consecutive order IDs produce completely unrelated-looking
 * codes, yet each code maps back to exactly one order ID — so the code stays
 * unique, needs no storage/migration, works for every existing order, and can
 * be decoded for admin lookups and the admin search box.
 *
 * Algorithm: a keyed 8-round Feistel network over a 30-bit space (2^30 ≈ 1.07B
 * order IDs), rendered as 6 characters from a 32-symbol alphabet. Feistel is a
 * bijection, so encode is a permutation: no two order IDs ever share a code.
 * The round function is HMAC-SHA256 keyed by a per-site secret, so without the
 * key the code cannot be reversed or related to other codes.
 *
 * The alphabet deliberately excludes visually ambiguous characters (0/O, 1/I)
 * so codes are safe to read over the phone and type from a printed slip.
 *
 * @see https://en.wikipedia.org/wiki/Feistel_cipher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Order_Code {

	/** 32 unambiguous symbols: A–Z minus I and O, plus digits 2–9. */
	const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

	const CODE_LEN = 6;            // 6 chars × 5 bits = 30-bit code space.
	const ROUNDS   = 8;            // Feistel rounds — 8 is ample for 30-bit.
	const HALF     = 15;           // 30-bit value split into two 15-bit halves.
	const MASK     = 0x7FFF;       // low 15 bits.
	const SPACE    = 1073741824;   // 2^30 — capacity ≈ 1.07 billion orders.
	const PREFIX   = 'EPIC-';
	const KEY_OPTION = 'epic_order_codes_key';

	/**
	 * Register everything the plugin does.
	 */
	public static function init() {
		add_filter( 'woocommerce_order_number', array( __CLASS__, 'filter_order_number' ), 10, 2 );

		// Admin order search, on both storage backends.
		add_action( 'pre_get_posts', array( __CLASS__, 'pre_get_posts_search' ) );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( __CLASS__, 'hpos_search_args' ) );
		add_filter( 'woocommerce_shop_order_list_table_prepare_items_query_args', array( __CLASS__, 'hpos_search_args' ) );
	}

	/**
	 * Generate (and persist) the site's Feistel key if none exists yet.
	 *
	 * Called on plugins_loaded so the key is stable across requests and never
	 * changes after activation — if it did, every previously-issued order code
	 * would change. Also honor a hard-coded key via define() for environments
	 * that want the key outside the database.
	 */
	public static function maybe_generate_key() {
		if ( defined( 'EPIC_ORDER_CODES_KEY' ) && EPIC_ORDER_CODES_KEY ) {
			return;
		}
		if ( get_option( self::KEY_OPTION ) ) {
			return;
		}
		update_option( self::KEY_OPTION, self::random_key(), true );
	}

	/** The site's Feistel key — define()d value wins, else the stored option. */
	public static function get_key() {
		if ( defined( 'EPIC_ORDER_CODES_KEY' ) && EPIC_ORDER_CODES_KEY ) {
			return (string) EPIC_ORDER_CODES_KEY;
		}
		$key = (string) get_option( self::KEY_OPTION, '' );
		if ( '' === $key ) {
			$key = self::random_key();
			update_option( self::KEY_OPTION, $key, true );
		}
		return $key;
	}

	/** Cryptographically random 64-byte key (base64 — fine as an HMAC key). */
	private static function random_key() {
		if ( function_exists( 'wp_generate_password' ) ) {
			return wp_generate_password( 64, true, true );
		}
		$bytes = '';
		while ( strlen( $bytes ) < 64 ) {
			$bytes .= hash( 'sha256', (string) random_bytes( 32 ), true );
		}
		return base64_encode( substr( $bytes, 0, 64 ) );
	}

	/**
	 * The `woocommerce_order_number` filter — the single point of truth for
	 * every display of an order number (admin, emails, REST API `number`
	 * field, order notes). Returning our code here means the Next.js website,
	 * which reads `order.number` from the REST API, picks it up automatically.
	 *
	 * @param string   $number Original order number (the numeric order ID).
	 * @param WC_Order $order  Order object.
	 * @return string
	 */
	public static function filter_order_number( $number, $order ) {
		if ( ! $order instanceof WC_Abstract_Order ) {
			return $number;
		}
		$id = (int) $order->get_id();
		if ( $id < 1 ) {
			return $number;
		}
		return self::encode( $id );
	}

	/**
	 * Encode an order ID into its code.
	 *
	 * @param int $order_id Numeric WooCommerce order ID.
	 * @return string Code like `EPIC-7K3M9X`.
	 */
	public static function encode( $order_id ) {
		$id = (int) $order_id;
		if ( $id < 1 ) {
			return (string) $order_id;
		}
		// Beyond the 30-bit code space — vanishingly unlikely; fall back to the
		// raw ID rather than silently produce a colliding/incorrect code.
		if ( $id >= self::SPACE ) {
			return (string) $order_id;
		}

		$mix = self::feistel( $id );

		$code = '';
		for ( $i = self::CODE_LEN - 1; $i >= 0; $i-- ) {
			$code .= self::ALPHABET[ ( $mix >> ( 5 * $i ) ) & 0x1F ];
		}
		return self::PREFIX . $code;
	}

	/**
	 * Decode a code back to its order ID. Returns 0 for anything that isn't a
	 * valid code of this plugin's format.
	 *
	 * @param string $code Code like `EPIC-7K3M9X` (prefix optional).
	 * @return int Order ID, or 0 if the code is invalid.
	 */
	public static function decode( $code ) {
		$code = strtoupper( trim( (string) $code ) );
		if ( 0 === strpos( $code, self::PREFIX ) ) {
			$code = substr( $code, strlen( self::PREFIX ) );
		}
		if ( strlen( $code ) !== self::CODE_LEN ) {
			return 0;
		}

		$mix = 0;
		for ( $i = 0; $i < self::CODE_LEN; $i++ ) {
			$pos = strpos( self::ALPHABET, $code[ $i ] );
			if ( false === $pos ) {
				return 0;
			}
			$mix = ( $mix << 5 ) | $pos;
		}

		$id = self::inverse_feistel( $mix );
		if ( $id < 1 ) {
			return 0;
		}
		return (int) $id;
	}

	/**
	 * Keyed Feistel permutation of a 30-bit integer (bijective).
	 *
	 * @param int $n Plain value in [0, 2^30).
	 * @return int Permuted value in [0, 2^30).
	 */
	private static function feistel( $n ) {
		$l = ( $n >> self::HALF ) & self::MASK;
		$r = $n & self::MASK;
		for ( $round = 1; $round <= self::ROUNDS; $round++ ) {
			$f = self::round_fn( $round, $r );
			$new_l = $r;
			$new_r = $l ^ $f;
			$l = $new_l;
			$r = $new_r;
		}
		return ( $l << self::HALF ) | $r;
	}

	/**
	 * Inverse of feistel() — same structure, rounds run in reverse (Feistel is
	 * its own inverse given the same round function).
	 *
	 * @param int $n Permuted value in [0, 2^30).
	 * @return int Plain value in [0, 2^30).
	 */
	private static function inverse_feistel( $n ) {
		$l = ( $n >> self::HALF ) & self::MASK;
		$r = $n & self::MASK;
		for ( $round = self::ROUNDS; $round >= 1; $round-- ) {
			$f = self::round_fn( $round, $l );
			$new_r = $l;
			$new_l = $r ^ $f;
			$l = $new_l;
			$r = $new_r;
		}
		return ( $l << self::HALF ) | $r;
	}

	/**
	 * One Feistel round function: 15-bit keyed digest of (round, right-half).
	 *
	 * @param int $round Round number.
	 * @param int $r     Right half (15 bits).
	 * @return int 15-bit pseudo-random value.
	 */
	private static function round_fn( $round, $r ) {
		$h = hash_hmac( 'sha256', 'epic-order-code:' . $round . ':' . $r, self::get_key() );
		return ( hexdec( substr( $h, 0, 4 ) ) ) & self::MASK;
	}

	/**
	 * Legacy (posts-backed) admin order search: if the search term is a valid
	 * code, decode it to the order ID and search by that ID instead.
	 *
	 * @param WP_Query $query The main admin query.
	 */
	public static function pre_get_posts_search( $query ) {
		if ( ! $query->is_main_query() || ! is_admin() ) {
			return;
		}
		$post_type = $query->get( 'post_type' );
		if ( 'shop_order' !== $post_type ) {
			return;
		}
		$search = trim( (string) $query->get( 's' ) );
		if ( '' === $search || ! self::looks_like_code( $search ) ) {
			return;
		}
		$id = self::decode( $search );
		if ( $id ) {
			$query->set( 's', $id );
		}
	}

	/**
	 * HPOS (custom order tables) admin order search: same decode-to-ID rewrite,
	 * applied to the args WooCommerce passes to wc_get_orders().
	 *
	 * @param array $query_args Query args.
	 * @return array
	 */
	public static function hpos_search_args( $query_args ) {
		if ( empty( $query_args['s'] ) || ! self::looks_like_code( (string) $query_args['s'] ) ) {
			return $query_args;
		}
		$id = self::decode( (string) $query_args['s'] );
		if ( $id ) {
			$query_args['s'] = $id;
		}
		return $query_args;
	}

	/**
	 * Whether a search term is plausibly one of our codes. Requires the
	 * `EPIC-` prefix so an unrelated 6-character search (a customer name, a
	 * random string) is never hijacked into a wrong order ID.
	 *
	 * @param string $term Search term.
	 * @return bool
	 */
	private static function looks_like_code( $term ) {
		$term = strtoupper( trim( $term ) );
		return 0 === strpos( $term, self::PREFIX );
	}
}
