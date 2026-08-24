<?php
/**
 * Delivers a bulk campaign in small background batches instead of one long
 * synchronous loop over every recipient — a synchronous loop is what would
 * time out the request past roughly 50-100 recipients and burst-send fast
 * enough to trip most hosts'/providers' outbound rate limits.
 *
 * Built on Action Scheduler, which ships bundled with WooCommerce (no new
 * dependency this plugin has to add) — `as_schedule_single_action()` queues
 * one run of process_batch() per BATCH_SIZE recipients, BATCH_INTERVAL
 * seconds apart, each run re-scheduling the next one until the campaign's
 * recipient rows are all sent/failed. If Action Scheduler's functions are
 * somehow unavailable even with WooCommerce active (shouldn't normally
 * happen), falls back to plain WP-Cron single events — slower to fire
 * precisely on time, but functionally equivalent.
 *
 * Each batch's actual sending is fully synchronous within that one batch
 * (BATCH_SIZE is small enough that this comfortably fits inside a normal
 * PHP execution time limit) — only the overall campaign is asynchronous.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Newsletter_Broadcast_Sender {

	/** Recipients emailed per background run. Small on purpose — see this class's docblock. */
	const BATCH_SIZE = 20;

	/**
	 * Seconds between batches. A conservative default for ordinary shared
	 * hosting; sites with a real transactional provider (SES, Postmark,
	 * Brevo, etc.) configured can safely lower this via the
	 * `epic_newsletter_broadcast_batch_interval` filter.
	 */
	const BATCH_INTERVAL = 60;

	const AS_HOOK  = 'epic_newsletter_process_campaign_batch';
	const AS_GROUP = 'epic-newsletter-subscription';

	/**
	 * Entry point from class-broadcast-admin.php's "Send now" handler.
	 * Atomically flips the campaign from draft → sending (via
	 * Epic_Newsletter_Campaign_Store::mark_sending()'s WHERE-status guard)
	 * and queues the first batch. Returns false without doing anything if
	 * the campaign wasn't a draft — e.g. a double form submission, or a
	 * stale "Send now" link reused after the campaign already went out.
	 *
	 * @return bool
	 */
	public static function start( $campaign_id ) {
		if ( ! Epic_Newsletter_Campaign_Store::mark_sending( $campaign_id ) ) {
			return false;
		}
		self::schedule_next_batch( (int) $campaign_id, 0 );
		return true;
	}

	private static function schedule_next_batch( $campaign_id, $delay_seconds ) {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			// Guards against ever double-queuing the same campaign's next
			// batch — process_batch() calling this again re-entrantly (it
			// shouldn't, but this is cheap insurance) would otherwise send
			// each remaining recipient twice.
			if ( function_exists( 'as_has_scheduled_action' )
				&& as_has_scheduled_action( self::AS_HOOK, array( 'campaign_id' => $campaign_id ), self::AS_GROUP ) ) {
				return;
			}
			as_schedule_single_action(
				time() + $delay_seconds,
				self::AS_HOOK,
				array( 'campaign_id' => $campaign_id ),
				self::AS_GROUP
			);
			return;
		}

		// WP-Cron fallback — see this class's docblock.
		if ( ! wp_next_scheduled( self::AS_HOOK, array( $campaign_id ) ) ) {
			wp_schedule_single_event( time() + $delay_seconds, self::AS_HOOK, array( $campaign_id ) );
		}
	}

	/**
	 * Runs one batch and re-schedules itself if recipients remain.
	 * Registered against self::AS_HOOK for both the Action Scheduler path
	 * (which calls it with the `array('campaign_id' => $id)` shape used
	 * above) and the WP-Cron fallback path (which calls it with a bare int
	 * positional arg) — accepts either.
	 */
	public static function process_batch( $campaign_id ) {
		$campaign_id = is_array( $campaign_id ) ? (int) ( $campaign_id['campaign_id'] ?? 0 ) : (int) $campaign_id;
		if ( ! $campaign_id ) {
			return;
		}

		$campaign = Epic_Newsletter_Campaign_Store::get( $campaign_id );
		if ( ! $campaign ) {
			return; // deleted mid-flight — nothing to do.
		}

		$batch = Epic_Newsletter_Campaign_Store::get_pending_batch( $campaign_id, self::BATCH_SIZE );

		if ( empty( $batch ) ) {
			Epic_Newsletter_Campaign_Store::mark_finished( $campaign_id );
			return;
		}

		foreach ( $batch as $recipient ) {
			$sent = self::send_one( $campaign, $recipient['email'], $recipient['locale'] );
			Epic_Newsletter_Campaign_Store::mark_recipient_result( $recipient['id'], $campaign_id, $sent );
		}

		if ( Epic_Newsletter_Campaign_Store::count_pending( $campaign_id ) > 0 ) {
			$interval = (int) apply_filters( 'epic_newsletter_broadcast_batch_interval', self::BATCH_INTERVAL );
			self::schedule_next_batch( $campaign_id, max( 5, $interval ) );
		} else {
			Epic_Newsletter_Campaign_Store::mark_finished( $campaign_id );
		}
	}

	/**
	 * Sends the campaign's content, in whichever language bucket $locale
	 * falls into, to a single address. Shared by process_batch() (real
	 * sends) and send_test() (the composer's "Send test" button) so a test
	 * send renders through the exact same code path as a real one.
	 *
	 * @param array  $campaign Row shape from Epic_Newsletter_Campaign_Store::get() — or, for send_test(), the composer's not-yet-saved field values in the same shape.
	 * @return bool
	 */
	private static function send_one( array $campaign, $to_email, $locale ) {
		$is_english = 'en' === $locale;

		// Falls back to the Vietnamese subject/body whenever the English
		// field was left blank — same "Vietnamese is the default, English
		// is the opt-in extra" convention every other EPIC email uses, so a
		// campaign composed VI-only still sends something coherent to an
		// 'en' recipient instead of an empty email.
		$subject = ( $is_english && '' !== trim( (string) $campaign['subject_en'] ) ) ? $campaign['subject_en'] : $campaign['subject_vi'];
		$body    = ( $is_english && '' !== trim( (string) $campaign['body_en'] ) ) ? $campaign['body_en'] : $campaign['body_vi'];

		require_once EPIC_NEWSLETTER_SUBSCRIPTION_DIR . 'includes/class-email-newsletter-broadcast.php';

		$email                    = new Epic_Email_Newsletter_Broadcast();
		$email->broadcast_subject = $subject;
		$email->broadcast_body    = $body;
		$email->broadcast_locale  = $is_english ? 'en' : 'vi';
		$email->recipient         = $to_email;

		$sent = $email->send( $to_email, $subject, $email->get_content(), $email->get_headers(), $email->get_attachments() );

		if ( ! $sent && function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error(
				sprintf( 'Newsletter broadcast (campaign #%d) failed to send to "%s".', (int) ( $campaign['id'] ?? 0 ), $to_email ),
				array( 'source' => 'epic-newsletter-subscription' )
			);
		}

		return (bool) $sent;
	}

	/**
	 * Sends one immediate, un-queued preview — used by the composer's
	 * "Send test" button, before a real campaign (and its recipient
	 * snapshot) exists. Bypasses Action Scheduler entirely: it's a single
	 * email, not a batch.
	 *
	 * @param array  $campaign_fields Same shape as a campaign row's subject_vi/subject_en/body_vi/body_en (id may be 0/absent — only used for the failure log line above).
	 * @return bool
	 */
	public static function send_test( array $campaign_fields, $to_email, $locale ) {
		return self::send_one( $campaign_fields, $to_email, $locale );
	}
}
