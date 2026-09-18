<?php
/**
 * REST route the Next.js website's `src/app/api/sample/route.ts` calls (via
 * `src/lib/sample.ts`). Gated behind a shared secret (see
 * class-settings.php) sent as an `X-Epic-Secret` header — same pattern as
 * epic-wholesale-inquiries. This route never trusts an unauthenticated
 * caller to trigger an outbound email.
 *
 * On a valid, well-formed request this first persists the request via
 * `Epic_Sample_Store::insert()` (class-store.php) — so it's recorded in
 * wp-admin regardless of what happens to the notification email — then
 * fires the `epic_sample_request_received` action with a sanitized data
 * array (including the new row's id). `Epic_Email_Sample_Request::trigger()`
 * listens for that action and sends the actual notification email via
 * WooCommerce's WC_Email system, then reports the delivery result back onto
 * the same row via `Epic_Sample_Store::mark_email_status()`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Sample_Rest_Api {

	const NAMESPACE = 'epic-sample/v1';

	/** Keep aligned with SAMPLE_TASTE_OPTIONS in the website's src/lib/data.ts. */
	const VALID_TASTES = array( 'robusta_natural', 'robusta_honey', 'arabica_vn' );

	/** Keep aligned with SAMPLE_BREW_OPTIONS in the website's src/lib/data.ts. */
	const VALID_BREWS = array( 'phin', 'espresso', 'coldbrew', 'pourover' );

	const MAX_SHORT_LENGTH = 255;
	const MAX_EMAIL_LENGTH = 191;
	const MAX_ADDRESS_LENGTH = 512;

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

	/** Constant-time comparison against the secret configured in WooCommerce → Sample Requests. */
	public static function check_secret( \WP_REST_Request $request ) {
		$configured = Epic_Sample_Settings::get_shared_secret();
		if ( empty( $configured ) ) {
			return new \WP_Error( 'epic_sample_not_configured', 'Shared secret not configured.', array( 'status' => 500 ) );
		}
		$provided = $request->get_header( 'x-epic-secret' );
		if ( empty( $provided ) || ! hash_equals( $configured, $provided ) ) {
			return new \WP_Error( 'epic_sample_forbidden', 'Invalid or missing X-Epic-Secret header.', array( 'status' => 403 ) );
		}
		return true;
	}

	public static function submit_request( \WP_REST_Request $request ) {
		$name     = self::clean_string( $request->get_param( 'name' ) );
		$email    = self::clean_email( $request->get_param( 'email' ) );
		// Strip the separator characters people type between digit groups so
		// "0901 234 567" is accepted and stored as "0901234567".
		$phone    = preg_replace( '/[\s.\-]/', '', self::clean_string( $request->get_param( 'phone' ) ) );
		$province = self::clean_string( $request->get_param( 'province' ) );
		$ward     = self::clean_string( $request->get_param( 'ward' ) );
		$street   = self::clean_string( $request->get_param( 'street' ) );
		$taste    = self::clean_string( $request->get_param( 'taste' ) );
		$taste_label_vi = self::clean_string( $request->get_param( 'tasteLabelVi' ) );
		$brew     = self::clean_string( $request->get_param( 'brew' ) );
		$brew_label_vi = self::clean_string( $request->get_param( 'brewLabelVi' ) );
		$locale   = self::clean_string( $request->get_param( 'locale' ) );

		// Required: name + a full delivery address (province/ward/street) + phone.
		// Email, taste and brew style are optional.
		if ( '' === $name || '' === $phone || '' === $province || '' === $ward || '' === $street ) {
			return new \WP_Error( 'epic_sample_bad_request', 'name, phone, province, ward and street are required.', array( 'status' => 400 ) );
		}
		if ( '' !== $email && ( ! is_email( $email ) || strlen( $email ) > self::MAX_EMAIL_LENGTH ) ) {
			return new \WP_Error( 'epic_sample_bad_request', 'email must be a valid email address.', array( 'status' => 400 ) );
		}
		// Vietnam mobile number: exactly 10 digits, starting with 0.
		if ( ! preg_match( '/^0[0-9]{9}$/', $phone ) ) {
			return new \WP_Error( 'epic_sample_bad_request', 'phone must be 10 digits starting with 0.', array( 'status' => 400 ) );
		}
		// Optional fields are only validated when actually provided.
		if ( '' !== $taste && ! in_array( $taste, self::VALID_TASTES, true ) ) {
			return new \WP_Error( 'epic_sample_bad_request', 'taste must be one of: ' . implode( ', ', self::VALID_TASTES ) . '.', array( 'status' => 400 ) );
		}
		if ( '' !== $brew && ! in_array( $brew, self::VALID_BREWS, true ) ) {
			return new \WP_Error( 'epic_sample_bad_request', 'brew must be one of: ' . implode( ', ', self::VALID_BREWS ) . '.', array( 'status' => 400 ) );
		}
		if (
			strlen( $name ) > self::MAX_SHORT_LENGTH ||
			strlen( $phone ) > self::MAX_SHORT_LENGTH ||
			strlen( $province ) > self::MAX_SHORT_LENGTH ||
			strlen( $ward ) > self::MAX_SHORT_LENGTH ||
			strlen( $street ) > self::MAX_SHORT_LENGTH
		) {
			return new \WP_Error( 'epic_sample_bad_request', 'One or more fields are too long.', array( 'status' => 400 ) );
		}

		// The website already computes tasteLabelVi (see SAMPLE_TASTE_LABELS_VI
		// in src/lib/data.ts) — fall back to the raw taste value here only if
		// that's ever missing, so a malformed/older caller still produces a
		// readable email instead of a blank line.
		if ( '' === $taste_label_vi ) {
			$taste_label_vi = $taste;
		}
		if ( '' === $brew_label_vi ) {
			$brew_label_vi = $brew;
		}

		// One-line delivery address assembled from the structured parts, so
		// the wp-admin list table and the notification email have a single
		// ready-to-copy field as well as the individual ones.
		$address = $street . ', ' . $ward . ', ' . $province;
		if ( strlen( $address ) > self::MAX_ADDRESS_LENGTH ) {
			$address = substr( $address, 0, self::MAX_ADDRESS_LENGTH );
		}

		$record = array(
			'name'           => $name,
			'email'          => $email,
			'phone'          => $phone,
			'province'       => $province,
			'ward'           => $ward,
			'street'         => $street,
			'address'        => $address,
			'taste'          => $taste,
			'taste_label_vi' => $taste_label_vi,
			'brew'           => $brew,
			'brew_label_vi'  => $brew_label_vi,
			'locale'         => '' !== $locale ? $locale : 'unknown',
			'submitted_at'   => current_time( 'mysql' ),
		);

		// Persist first — a submission is recorded in wp-admin (WooCommerce →
		// Sample Requests) even if the notification email below fails, is
		// disabled, or nobody's configured a recipient yet.
		$record['id'] = Epic_Sample_Store::insert( $record );

		do_action( 'epic_sample_request_received', $record );

		return new \WP_REST_Response( array( 'ok' => true ), 201 );
	}

	/**
	 * @param mixed $value
	 */
	private static function clean_string( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		return sanitize_text_field( $value );
	}

	/** Lowercased, sanitized email — is_email() validation happens in submit_request() so the error is a proper 400. */
	private static function clean_email( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		return strtolower( sanitize_email( $value ) );
	}
}
