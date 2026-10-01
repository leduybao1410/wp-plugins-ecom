<?php
/**
 * REST route the Next.js website's `src/app/api/contact/route.ts` calls
 * (via `src/lib/contact.ts`). Gated behind a shared secret (see
 * class-settings.php) sent as an `X-Epic-Secret` header — same pattern as
 * epic-wholesale-inquiries/includes/class-rest-api.php. This route never
 * trusts an unauthenticated caller to trigger an outbound email.
 *
 * On a valid, well-formed request this first persists the request via
 * `Epic_Contact_Store::insert()` (class-store.php) — so it's recorded in
 * wp-admin regardless of what happens to the notification email — then
 * fires the `epic_contact_request_received` action with a sanitized data
 * array (including the new row's id).
 * `Epic_Email_Contact_Request::trigger()` (class-email-contact-request.php)
 * listens for that action and sends the actual notification email via
 * WooCommerce's WC_Email system, then reports the delivery result back onto
 * the same row via `Epic_Contact_Store::mark_email_status()`.
 *
 * Address fields are only meaningful when the lead requested a direct
 * (in-person) consultation, which is offered in Ho Chi Minh City only — so
 * `directConsult` being truthy makes province/ward/street required, and the
 * province is rejected unless it resolves to HCMC.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Contact_Rest_Api {

	const NAMESPACE = 'epic-contact/v1';

	const MAX_FIELD_LENGTH = 2000;

	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/request',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'submit_request' ),
				'permission_callback' => array( __CLASS__, 'check_secret' ),
			)
		);
	}

	/** Constant-time comparison against the secret configured in WooCommerce → Contact Requests. */
	public static function check_secret( \WP_REST_Request $request ) {
		$configured = Epic_Contact_Settings::get_shared_secret();
		if ( empty( $configured ) ) {
			return new \WP_Error( 'epic_contact_not_configured', 'Shared secret not configured.', array( 'status' => 500 ) );
		}
		$provided = $request->get_header( 'x-epic-secret' );
		if ( empty( $provided ) || ! hash_equals( $configured, $provided ) ) {
			return new \WP_Error( 'epic_contact_forbidden', 'Invalid or missing X-Epic-Secret header.', array( 'status' => 403 ) );
		}
		return true;
	}

	public static function submit_request( \WP_REST_Request $request ) {
		$name           = self::clean_string( $request->get_param( 'name' ) );
		$email          = self::clean_string( $request->get_param( 'email' ) );
		$phone          = self::clean_string( $request->get_param( 'phone' ) );
		$direct_consult = self::to_bool( $request->get_param( 'directConsult' ) );
		$province       = self::clean_string( $request->get_param( 'province' ) );
		$ward           = self::clean_string( $request->get_param( 'ward' ) );
		$street         = self::clean_string( $request->get_param( 'street' ) );
		$message        = self::clean_string( $request->get_param( 'message' ), true );
		$locale         = self::clean_string( $request->get_param( 'locale' ) );

		if ( '' === $name || '' === $phone ) {
			return new \WP_Error( 'epic_contact_bad_request', 'name and phone are required.', array( 'status' => 400 ) );
		}

		// The address only applies to a direct consultation, which is offered
		// in Ho Chi Minh City only — so when the lead opts in, the address is
		// mandatory and the province must resolve to HCMC.
		if ( $direct_consult ) {
			if ( '' === $province || '' === $ward || '' === $street ) {
				return new \WP_Error( 'epic_contact_bad_request', 'A direct consultation requires province, ward and street.', array( 'status' => 400 ) );
			}
			if ( ! self::is_ho_chi_minh( $province ) ) {
				return new \WP_Error( 'epic_contact_bad_request', 'Direct consultations are available in Ho Chi Minh City only.', array( 'status' => 400 ) );
			}
		} else {
			// Drop any stray address values a non-consultation submission
			// might still carry, so the stored row can't imply an address that
			// wasn't actually requested.
			$province = '';
			$ward     = '';
			$street   = '';
		}

		if (
			strlen( $name ) > self::MAX_FIELD_LENGTH ||
			strlen( $email ) > self::MAX_FIELD_LENGTH ||
			strlen( $phone ) > self::MAX_FIELD_LENGTH ||
			strlen( $province ) > self::MAX_FIELD_LENGTH ||
			strlen( $ward ) > self::MAX_FIELD_LENGTH ||
			strlen( $street ) > self::MAX_FIELD_LENGTH ||
			strlen( $message ) > self::MAX_FIELD_LENGTH
		) {
			return new \WP_Error( 'epic_contact_bad_request', 'One or more fields are too long.', array( 'status' => 400 ) );
		}

		$record = array(
			'name'           => $name,
			'email'          => $email,
			'phone'          => $phone,
			'direct_consult' => $direct_consult ? 1 : 0,
			'province'       => $province,
			'ward'           => $ward,
			'street'         => $street,
			'message'        => $message,
			'locale'         => '' !== $locale ? $locale : 'unknown',
			'submitted_at'   => current_time( 'mysql' ),
		);

		// Persist first — a submission is recorded in wp-admin (WooCommerce →
		// Contact Requests) even if the notification email below fails, is
		// disabled, or nobody's configured a recipient yet.
		$record['id'] = Epic_Contact_Store::insert( $record );

		do_action( 'epic_contact_request_received', $record );

		return new \WP_REST_Response( array( 'ok' => true ), 201 );
	}

	/** Accepts true/false, 1/0, "1"/"0", "true"/"false" — the website sends a JSON boolean. */
	private static function to_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes' ), true );
		}
		return (bool) $value;
	}

	/**
	 * True when a province/city name resolves to Ho Chi Minh City, accent- and
	 * case-insensitively (e.g. "Thành phố Hồ Chí Minh" or "TP. HCM").
	 * `remove_accents()` folds Vietnamese diacritics (including đ) to ASCII.
	 */
	public static function is_ho_chi_minh( $province ) {
		$normalized = strtolower( remove_accents( (string) $province ) );
		return false !== strpos( $normalized, 'ho chi minh' );
	}

	/**
	 * @param mixed $value
	 * @param bool  $multiline Use sanitize_textarea_field() instead of sanitize_text_field() — preserves the lead's line breaks in the "message" field.
	 */
	private static function clean_string( $value, $multiline = false ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		return $multiline ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
	}
}
