<?php
/**
 * Two REST routes the Next.js website calls (via `src/lib/reviews.ts`):
 *
 *  - `GET /wp-json/epic-reviews/v1/reviews?product=<id>` — PUBLIC and
 *    read-only. Returns only APPROVED reviews for a product plus its
 *    aggregate rating. Public on purpose: it only ever exposes moderated,
 *    approved content, and it's what the site's server component fetches to
 *    render the review section and build the Product JSON-LD. No secret on
 *    this path keeps it simple and cacheable; the content is sanitized and
 *    gated behind the moderation flow regardless.
 *
 *  - `POST /wp-json/epic-reviews/v1/reviews` — SHARED-SECRET protected (see
 *    class-settings.php), same `X-Epic-Secret` header pattern as
 *    epic-wholesale-inquiries. Creates a review in `pending` status so a
 *    staff member must approve it under WooCommerce → Product Reviews before
 *    it ever shows up on the site (or in structured data). Never trusts an
 *    unauthenticated caller to write to the table.
 *
 * The GET route is public but the review content is stored sanitized and is
 * only ever returned for approved rows — see Epic_Reviews_Store::get_approved().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Reviews_Rest_Api {

	const NAMESPACE = 'epic-reviews/v1';

	const RATING_MIN = 1;
	const RATING_MAX = 5;

	const MAX_AUTHOR_LENGTH   = 255;
	const MAX_TITLE_LENGTH    = 255;
	const MAX_CONTENT_LENGTH  = 4000;
	const MAX_PRODUCT_ID_DIGITS = 20;

	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/reviews',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_reviews' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'submit_review' ),
					'permission_callback' => array( __CLASS__, 'check_secret' ),
				),
			)
		);
	}

	/** Constant-time comparison against the secret configured in WooCommerce → Product Reviews. */
	public static function check_secret( \WP_REST_Request $request ) {
		$configured = Epic_Reviews_Settings::get_shared_secret();
		if ( empty( $configured ) ) {
			return new \WP_Error( 'epic_reviews_not_configured', 'Shared secret not configured.', array( 'status' => 500 ) );
		}
		$provided = $request->get_header( 'x-epic-secret' );
		if ( empty( $provided ) || ! hash_equals( $configured, $provided ) ) {
			return new \WP_Error( 'epic_reviews_forbidden', 'Invalid or missing X-Epic-Secret header.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * GET /reviews?product=<id> — approved reviews + aggregate for one
	 * product. The website renders exactly this list on the page and feeds
	 * the same numbers into its Product JSON-LD, so the structured data
	 * always reflects visible, moderated reviews (Google's requirement for
	 * review markup).
	 */
	public static function get_reviews( \WP_REST_Request $request ) {
		$product_id = absint( $request->get_param( 'product' ) );
		if ( $product_id < 1 ) {
			return new \WP_Error( 'epic_reviews_bad_request', 'A valid numeric `product` query parameter is required.', array( 'status' => 400 ) );
		}

		$rows      = Epic_Reviews_Store::get_approved( $product_id );
		$aggregate = Epic_Reviews_Store::aggregate( $product_id );

		// camelCase keys to match the website's JSON conventions (see
		// src/lib/reviews.ts) — same shape the rest of this codebase uses
		// for WordPress ↔ Next.js payloads.
		$reviews = array_map(
			function ( $row ) {
				return array(
					'id'        => (int) $row['id'],
					'author'    => (string) $row['author'],
					'rating'    => (int) $row['rating'],
					'title'     => (string) $row['title'],
					'content'   => (string) $row['content'],
					'createdAt' => (string) $row['created_at'],
				);
			},
			$rows
		);

		$aggregate_payload = null;
		if ( $aggregate ) {
			$aggregate_payload = array(
				'ratingValue' => (float) $aggregate['rating_value'],
				'reviewCount' => (int) $aggregate['review_count'],
			);
		}

		return new \WP_REST_Response(
			array(
				'reviews'   => $reviews,
				'aggregate' => $aggregate_payload,
			),
			200
		);
	}

	public static function submit_review( \WP_REST_Request $request ) {
		$product_id = absint( $request->get_param( 'productId' ) );
		$author     = self::clean_string( $request->get_param( 'author' ) );
		$rating     = (int) $request->get_param( 'rating' );
		$title      = self::clean_string( $request->get_param( 'title' ) );
		$content    = self::clean_string( $request->get_param( 'content' ), true );
		$locale     = self::clean_string( $request->get_param( 'locale' ) );

		if ( $product_id < 1 ) {
			return new \WP_Error( 'epic_reviews_bad_request', 'A valid numeric productId is required.', array( 'status' => 400 ) );
		}
		if ( '' === $author || '' === $content ) {
			return new \WP_Error( 'epic_reviews_bad_request', 'author and content are required.', array( 'status' => 400 ) );
		}
		if ( $rating < self::RATING_MIN || $rating > self::RATING_MAX ) {
			return new \WP_Error( 'epic_reviews_bad_request', 'rating must be an integer between 1 and 5.', array( 'status' => 400 ) );
		}
		if (
			strlen( $author ) > self::MAX_AUTHOR_LENGTH ||
			strlen( $title ) > self::MAX_TITLE_LENGTH ||
			strlen( $content ) > self::MAX_CONTENT_LENGTH ||
			strlen( (string) $product_id ) > self::MAX_PRODUCT_ID_DIGITS
		) {
			return new \WP_Error( 'epic_reviews_bad_request', 'One or more fields are too long.', array( 'status' => 400 ) );
		}

		$record = array(
			'product_id' => $product_id,
			'author'     => $author,
			'rating'     => $rating,
			'title'      => $title,
			'content'    => $content,
			'locale'     => '' !== $locale ? $locale : 'unknown',
			'status'     => Epic_Reviews_Store::STATUS_PENDING,
			'created_at' => current_time( 'mysql' ),
		);

		$record['id'] = Epic_Reviews_Store::insert( $record );

		if ( empty( $record['id'] ) ) {
			return new \WP_Error( 'epic_reviews_storage', 'Could not store the review. Please try again.', array( 'status' => 500 ) );
		}

		do_action( 'epic_product_review_received', $record );

		return new \WP_REST_Response(
			array(
				'ok'     => true,
				'review' => array(
					'id'     => (int) $record['id'],
					'status' => Epic_Reviews_Store::STATUS_PENDING,
				),
			),
			201
		);
	}

	/**
	 * @param mixed $value
	 * @param bool  $multiline Use sanitize_textarea_field() instead of sanitize_text_field() — preserves the reviewer's line breaks in the "content" field.
	 */
	private static function clean_string( $value, $multiline = false ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		return $multiline ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
	}
}
