<?php
/**
 * Durable rate limiter for the public bot API. Counters live in WordPress
 * transients (a fixed window per budget), so they survive across Vercel's
 * ephemeral function instances — which is the whole reason the counter is
 * here and not in the Next.js process.
 *
 * The counter key is a salted hash of (scope + client IP); the client IP is
 * forwarded by the Next.js server as best-effort (see AI_BOT_API_PLAN.md §8).
 * The daily global cap is the real backstop if a single IP is spoofed across
 * many requests.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Epic_Agent_Rate_Limiter {

	const BUCKET_WRITE = 'write';
	const BUCKET_ORDER = 'order';

	/**
	 * @param string $route    e.g. "POST /sample-requests".
	 * @param string $client_ip Best-effort end-user IP.
	 * @param string $user_agent
	 * @param bool   $honeypot
	 * @return array{allow:bool,silent:bool,retryAfterSeconds:int,remaining:int,bucket:string}
	 */
	public static function check( $route, $client_ip, $user_agent, $honeypot ) {
		$limits = Epic_Agent_Settings::get_limits();
		$bucket = self::bucket_for_route( $route );

		// A tripped honeypot never blocks (the route returns a synthetic
		// success anyway) — returning allow=true here keeps the bot from
		// learning that it was detected.
		if ( $honeypot ) {
			self::log( $route, $bucket, $client_ip, $user_agent, true, true );
			return array(
				'allow'             => true,
				'silent'            => true,
				'retryAfterSeconds' => 0,
				'remaining'         => 0,
				'bucket'            => $bucket,
			);
		}

		$allow     = true;
		$retry     = 0;
		$remaining = 0;

		if ( self::BUCKET_ORDER === $bucket ) {
			$result    = self::consume( 'order', $client_ip, (int) $limits['order_per_min'], MINUTE_IN_SECONDS );
			$allow     = $result['allow'];
			$retry     = $result['retry'];
			$remaining = $result['remaining'];
		} else {
			// Two budgets for writes: per-hour and per-day.
			$hourly = self::consume( 'write_hour', $client_ip, (int) $limits['write_per_hour'], HOUR_IN_SECONDS );
			$daily  = self::consume( 'write_day', $client_ip, (int) $limits['write_per_day'], DAY_IN_SECONDS );

			$allow     = $hourly['allow'] && $daily['allow'];
			$retry     = max( $hourly['retry'], $daily['retry'] );
			$remaining = min( $hourly['remaining'], $daily['remaining'] );

			if ( $allow ) {
				$global = self::consume_global( (int) $limits['global_write_per_day'] );
				if ( ! $global['allow'] ) {
					$allow = false;
					$retry = max( $retry, $global['retry'] );
				}
			}
		}

		self::log( $route, $bucket, $client_ip, $user_agent, $allow, false );

		return array(
			'allow'             => $allow,
			'silent'            => false,
			'retryAfterSeconds' => $allow ? 0 : max( 1, $retry ),
			'remaining'         => max( 0, $remaining ),
			'bucket'            => $bucket,
		);
	}

	public static function bucket_for_route( $route ) {
		return 0 === strpos( (string) $route, 'GET /orders' ) ? self::BUCKET_ORDER : self::BUCKET_WRITE;
	}

	/** @return array{allow:bool,retry:int,remaining:int} */
	private static function consume( $scope, $client_ip, $limit, $window ) {
		$key   = self::key( $scope, $client_ip, $window );
		$count = self::hit( $key, $window );
		$allow = $count <= $limit;
		return array(
			'allow'     => $allow,
			'retry'     => $allow ? 0 : self::remaining_ttl( $key ),
			'remaining' => max( 0, $limit - $count ),
		);
	}

	/** Site-wide daily fuse across all IPs. @return array{allow:bool,retry:int} */
	private static function consume_global( $limit ) {
		$key    = 'epic_agent_rl_global_' . gmdate( 'Ymd' );
		$window = self::seconds_until_midnight();
		$count  = self::hit( $key, $window );
		$allow  = $count <= $limit;
		return array(
			'allow' => $allow,
			'retry' => $allow ? 0 : self::remaining_ttl( $key ),
		);
	}

	private static function key( $scope, $client_ip, $window ) {
		$ip = '' === (string) $client_ip ? 'unknown' : (string) $client_ip;
		return 'epic_agent_rl_' . md5( wp_salt( 'auth' ) . '|' . $scope . '|' . $window . '|' . $ip );
	}

	private static function hit( $key, $window ) {
		$count = (int) get_transient( $key );
		$count++;
		set_transient( $key, $count, max( 1, (int) $window ) );
		return $count;
	}

	private static function remaining_ttl( $key ) {
		$timeout = (int) get_option( '_transient_timeout_' . $key, 0 );
		return $timeout > 0 ? max( 1, $timeout - time() ) : 60;
	}

	private static function seconds_until_midnight() {
		$now      = current_datetime();
		$midnight = ( clone $now )->setTime( 0, 0, 0 )->modify( '+1 day' );
		return max( 60, $midnight->getTimestamp() - $now->getTimestamp() );
	}

	private static function log( $route, $bucket, $client_ip, $user_agent, $allowed, $silent ) {
		$ip = '' === (string) $client_ip ? '' : substr( hash( 'sha256', wp_salt( 'auth' ) . '|' . $client_ip ), 0, 64 );
		Epic_Agent_Store::insert(
			array(
				'route'      => $route,
				'bucket'     => $bucket,
				'ip_hash'    => $ip,
				'user_agent' => $user_agent,
				'allowed'    => $allowed,
				'silent'     => $silent,
			)
		);
	}
}
