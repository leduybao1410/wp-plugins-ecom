<?php
/**
 * REST route the Next.js website's `src/app/api/wholesale/route.ts` calls
 * (via `src/lib/wholesale.ts`). Gated behind a shared secret (see
 * class-settings.php) sent as an `X-Epic-Secret` header — same pattern as
 * epic-payment-store/includes/class-rest-api.php. This route never trusts
 * an unauthenticated caller to trigger an outbound email.
 *
 * On a valid, well-formed request this first persists the inquiry via
 * `Epic_Wholesale_Store::insert()` (class-store.php) — so it's recorded in
 * wp-admin regardless of what happens to the notification email — then
 * fires the `epic_wholesale_inquiry_received` action with a sanitized data
 * array (including the new row's id).
 * `Epic_Email_Wholesale_Inquiry::trigger()` (class-email-wholesale-inquiry.php)
 * listens for that action and sends the actual notification email via
 * WooCommerce's WC_Email system, then reports the delivery result back onto
 * the same row via `Epic_Wholesale_Store::mark_email_status()`. The action
 * itself stays a generic extension point (same decoupling
 * epic-ghn-shipping's `epic_ghn_shipment_booked` uses for epic-order-emails)
 * — the REST layer doesn't need to know anything about how the email is
 * built, only that storage happens before it fires.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Wholesale_Rest_Api {

	const NAMESPACE = 'epic-wholesale/v1';

	/** Keep aligned with WHOLESALE_TOPIC_OPTIONS in the website's src/lib/data.ts. */
	const VALID_TOPICS = array( 'wholesale', 'oem', 'setup', 'other' );

	const MAX_FIELD_LENGTH = 2000;

	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/inquiry',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'submit_inquiry' ),
				'permission_callback' => array( __CLASS__, 'check_secret' ),
			)
		);
	}

	/** Constant-time comparison against the secret configured in WooCommerce → Wholesale Inquiries. */
	public static function check_secret( \WP_REST_Request $request ) {
		$configured = Epic_Wholesale_Settings::get_shared_secret();
		if ( empty( $configured ) ) {
			return new \WP_Error( 'epic_wholesale_not_configured', 'Shared secret not configured.', array( 'status' => 500 ) );
		}
		$provided = $request->get_header( 'x-epic-secret' );
		if ( empty( $provided ) || ! hash_equals( $configured, $provided ) ) {
			return new \WP_Error( 'epic_wholesale_forbidden', 'Invalid or missing X-Epic-Secret header.', array( 'status' => 403 ) );
		}
		return true;
	}

	public static function submit_inquiry( \WP_REST_Request $request ) {
		$business_name  = self::clean_string( $request->get_param( 'businessName' ) );
		$phone          = self::clean_string( $request->get_param( 'phone' ) );
		$contact        = self::clean_string( $request->get_param( 'contact' ) );
		$topic          = self::clean_string( $request->get_param( 'topic' ) );
		$topic_label_vi = self::clean_string( $request->get_param( 'topicLabelVi' ) );
		$details        = self::clean_string( $request->get_param( 'details' ), true );
		$locale         = self::clean_string( $request->get_param( 'locale' ) );

		if ( '' === $business_name || '' === $phone || '' === $contact ) {
			return new \WP_Error( 'epic_wholesale_bad_request', 'businessName, phone and contact are required.', array( 'status' => 400 ) );
		}
		if ( ! in_array( $topic, self::VALID_TOPICS, true ) ) {
			return new \WP_Error( 'epic_wholesale_bad_request', 'topic must be one of: ' . implode( ', ', self::VALID_TOPICS ) . '.', array( 'status' => 400 ) );
		}
		if (
			strlen( $business_name ) > self::MAX_FIELD_LENGTH ||
			strlen( $phone ) > self::MAX_FIELD_LENGTH ||
			strlen( $contact ) > self::MAX_FIELD_LENGTH ||
			strlen( $details ) > self::MAX_FIELD_LENGTH
		) {
			return new \WP_Error( 'epic_wholesale_bad_request', 'One or more fields are too long.', array( 'status' => 400 ) );
		}

		// The website already computes topicLabelVi (see WHOLESALE_TOPIC_LABELS_VI
		// in src/lib/data.ts) — fall back to the raw topic value here only if
		// that's ever missing, so a malformed/older caller still produces a
		// readable email instead of a blank line.
		if ( '' === $topic_label_vi ) {
			$topic_label_vi = $topic;
		}

		$record = array(
			'business_name'  => $business_name,
			'phone'          => $phone,
			'contact'        => $contact,
			'topic'          => $topic,
			'topic_label_vi' => $topic_label_vi,
			'details'        => $details,
			'locale'         => '' !== $locale ? $locale : 'unknown',
			'submitted_at'   => current_time( 'mysql' ),
		);

		// Persist first — a submission is recorded in wp-admin (WooCommerce →
		// Wholesale Inquiries) even if the notification email below fails,
		// is disabled, or nobody's configured a recipient yet. insert()
		// returns 0 on failure rather than throwing, and 0 is treated as
		// "couldn't persist this one" downstream (mark_email_status() no-ops
		// on a falsy id) — a storage hiccup must never block the email.
		$record['id'] = Epic_Wholesale_Store::insert( $record );

		do_action( 'epic_wholesale_inquiry_received', $record );

		return new \WP_REST_Response( array( 'ok' => true ), 201 );
	}

	/**
	 * @param mixed $value
	 * @param bool  $multiline Use sanitize_textarea_field() instead of sanitize_text_field() — preserves the lead's line breaks in the "details" field.
	 */
	private static function clean_string( $value, $multiline = false ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		return $multiline ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
	}
}
