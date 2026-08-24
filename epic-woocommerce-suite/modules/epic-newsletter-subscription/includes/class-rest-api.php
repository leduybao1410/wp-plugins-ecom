<?php
/**
 * REST route the Next.js website's `src/app/api/subscribe/route.ts` calls
 * (via `src/lib/subscription.ts`). Gated behind a shared secret (see
 * class-settings.php) sent as an `X-Epic-Secret` header — same pattern as
 * epic-wholesale-inquiries/includes/class-rest-api.php. This route never
 * trusts an unauthenticated caller to trigger an outbound email.
 *
 * On a valid, well-formed request this first checks whether the email is
 * already subscribed (idempotent — a repeated submission returns success
 * without firing a duplicate notification or confirmation email), then
 * persists the subscription via `Epic_Newsletter_Store::insert()`
 * (class-store.php) — so it's recorded in wp-admin regardless of what
 * happens to the notification email — then fires the
 * `epic_newsletter_subscription_received` action with a sanitized data
 * array (including the new row's id).
 * `Epic_Email_Newsletter_Subscription::trigger()` (priority 10,
 * class-email-newsletter-subscription.php) listens for that action and
 * sends the admin notification email via WooCommerce's WC_Email system,
 * then reports the delivery result back onto the same row via
 * `Epic_Newsletter_Store::mark_email_status()`.
 * `Epic_Email_Newsletter_Confirmation::trigger()` (priority 20,
 * class-email-newsletter-confirmation.php) listens for the same action and
 * sends the thank-you confirmation email back to the subscriber's own
 * address, reporting via `Epic_Newsletter_Store::mark_confirm_status()`.
 * The action itself stays a generic extension point (same decoupling
 * epic-wholesale-inquiries uses) — the REST layer doesn't need to know
 * anything about how the emails are built, only that storage happens before
 * they fire.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Newsletter_Rest_Api {

	const NAMESPACE = 'epic-newsletter/v1';

	const MAX_EMAIL_LENGTH = 191;

	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/subscribe',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'subscribe' ),
				'permission_callback' => array( __CLASS__, 'check_secret' ),
			)
		);
	}

	/** Constant-time comparison against the secret configured in WooCommerce → Newsletter Subscribers. */
	public static function check_secret( \WP_REST_Request $request ) {
		$configured = Epic_Newsletter_Settings::get_shared_secret();
		if ( empty( $configured ) ) {
			return new \WP_Error( 'epic_newsletter_not_configured', 'Shared secret not configured.', array( 'status' => 500 ) );
		}
		$provided = $request->get_header( 'x-epic-secret' );
		if ( empty( $provided ) || ! hash_equals( $configured, $provided ) ) {
			return new \WP_Error( 'epic_newsletter_forbidden', 'Invalid or missing X-Epic-Secret header.', array( 'status' => 403 ) );
		}
		return true;
	}

	public static function subscribe( \WP_REST_Request $request ) {
		$email  = sanitize_email( $request->get_param( 'email' ) );
		$locale = sanitize_text_field( (string) $request->get_param( 'locale' ) );

		if ( '' === $email ) {
			return new \WP_Error( 'epic_newsletter_bad_request', 'A valid email is required.', array( 'status' => 400 ) );
		}
		if ( strlen( $email ) > self::MAX_EMAIL_LENGTH ) {
			return new \WP_Error( 'epic_newsletter_bad_request', 'Email is too long.', array( 'status' => 400 ) );
		}

		// Idempotency: an already-subscribed address is not an error — the
		// footer form just says "thanks, you're on the list" again. Skip the
		// insert and the notification email entirely; the existing row is
		// left untouched (original subscribed_at preserved).
		if ( Epic_Newsletter_Store::find_by_email( $email ) ) {
			return new \WP_REST_Response( array( 'ok' => true, 'already' => true ), 200 );
		}

		$record = array(
			'email'         => $email,
			'locale'        => '' !== $locale ? $locale : 'unknown',
			'subscribed_at' => current_time( 'mysql' ),
		);

		// Persist first — a subscription is recorded in wp-admin (WooCommerce
		// → Newsletter Subscribers) even if the notification email below
		// fails, is disabled, or nobody's configured a recipient yet.
		// insert() returns 0 on failure rather than throwing, and 0 is
		// treated as "couldn't persist this one" downstream
		// (mark_email_status() no-ops on a falsy id) — a storage hiccup
		// must never block the email.
		$record['id'] = Epic_Newsletter_Store::insert( $record );

		do_action( 'epic_newsletter_subscription_received', $record );

		return new \WP_REST_Response( array( 'ok' => true ), 201 );
	}
}
