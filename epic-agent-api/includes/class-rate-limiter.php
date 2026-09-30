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
	const BUCKET_READ  = 'read';
	const BUCKET_MCP   = 'mcp';

	/**
	 * @param string $route    e.g. "POST /sample-requests".
	 * @param string $client_ip Best-effort end-user IP.
	 * @param string $user_agent
	 * @param bool   $honeypot
	 * @return array{allow:bool,silent:bool,retryAfterSeconds:int,remaining:int,bucket:string}
	 */
	public static function check( $route, $client_ip, $user_agent, $honeypot, $action = '' ) {
		$limits = Epic_Agent_Settings::get_limits();
		$bucket = in_array( $action, array( self::BUCKET_WRITE, self::BUCKET_ORDER, self::BUCKET_READ, self::BUCKET_MCP ), true )
			? $action
			: self::bucket_for_route( $route );

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

		if ( self::BUCKET_MCP === $bucket ) {
			$result    = self::consume( 'mcp_min', $client_ip, (int) $limits['mcp_per_min'], MINUTE_IN_SECONDS );
			$allow     = $result['allow'];
			$retry     = $result['retry'];
			$remaining = $result['remaining'];
		} elseif ( self::BUCKET_READ === $bucket ) {
			$minute = self::consume( 'read_min', $client_ip, (int) $limits['read_per_min'], MINUTE_IN_SECONDS );
			$daily  = self::consume( 'read_day', $client_ip, (int) $limits['read_per_day'], DAY_IN_SECONDS );
			$global = $minute['allow'] && $daily['allow']
				? self::consume_global( 'read_global', (int) $limits['global_read_per_day'] )
				: array( 'allow' => true, 'retry' => 0 );
			$allow     = $minute['allow'] && $daily['allow'] && $global['allow'];
			$retry     = max( $minute['retry'], $daily['retry'], $global['retry'] );
			$remaining = min( $minute['remaining'], $daily['remaining'] );
		} elseif ( self::BUCKET_ORDER === $bucket ) {
			$minute = self::consume( 'order_min', $client_ip, (int) $limits['order_per_min'], MINUTE_IN_SECONDS );
			$daily  = self::consume( 'order_day', $client_ip, (int) $limits['order_per_day'], DAY_IN_SECONDS );
			$allow     = $minute['allow'] && $daily['allow'];
			$retry     = max( $minute['retry'], $daily['retry'] );
			$remaining = min( $minute['remaining'], $daily['remaining'] );
		} else {
			// Two budgets for writes: per-hour and per-day.
			$hourly = self::consume( 'write_hour', $client_ip, (int) $limits['write_per_hour'], HOUR_IN_SECONDS );
			$daily  = self::consume( 'write_day', $client_ip, (int) $limits['write_per_day'], DAY_IN_SECONDS );

			$allow     = $hourly['allow'] && $daily['allow'];
			$retry     = max( $hourly['retry'], $daily['retry'] );
			$remaining = min( $hourly['remaining'], $daily['remaining'] );

			if ( $allow ) {
			$global = self::consume_global( 'write_global', (int) $limits['global_write_per_day'] );
				if ( ! $global['allow'] ) {
					$allow = false;
					$retry = max( $retry, $global['retry'] );
				}
			}
		}

		// Keep successful reads out of the 30-day event log; counters provide aggregate usage.
		if ( ! $allow || self::BUCKET_WRITE === $bucket || self::BUCKET_ORDER === $bucket ) {
			self::log( $route, $bucket, $client_ip, $user_agent, $allow, false );
		}

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
		if ( false === $count ) return array( 'allow' => false, 'retry' => 30, 'remaining' => 0 );
		$allow = $count <= $limit;
		return array(
			'allow'     => $allow,
			'retry'     => $allow ? 0 : max( 1, $window - ( time() % max( 1, (int) $window ) ) ),
			'remaining' => max( 0, $limit - $count ),
		);
	}

	/** Site-wide daily fuse across all IPs. @return array{allow:bool,retry:int} */
	private static function consume_global( $scope, $limit ) {
		$window = DAY_IN_SECONDS;
		$key    = self::key( $scope, 'global', $window );
		$count  = self::hit( $key, $window );
		if ( false === $count ) return array( 'allow' => false, 'retry' => 30 );
		$allow  = $count <= $limit;
		return array(
			'allow' => $allow,
			'retry' => $allow ? 0 : max( 1, $window - ( time() % max( 1, (int) $window ) ) ),
		);
	}

	/**
	 * Canonicalise a forwarded client IP. Anything that is not a valid IP
	 * collapses into one shared "unknown" bucket, so a caller cannot mint a
	 * fresh rate-limit bucket per request by sending an arbitrary string.
	 *
	 * @param string $client_ip
	 * @return string
	 */
	public static function normalize_ip( $client_ip ) {
		$ip = trim( (string) $client_ip );
		if ( '' === $ip ) {
			return 'unknown';
		}
		if ( false !== strpos( $ip, ',' ) ) {
			$ip = trim( explode( ',', $ip )[0] );
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return $ip;
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$packed = @inet_pton( $ip );
			if ( false !== $packed && 16 === strlen( $packed ) ) {
				// Bucket IPv6 by /64 so one host cannot rotate addresses.
				return inet_ntop( substr( $packed, 0, 8 ) . str_repeat( "\0", 8 ) );
			}
			return $ip;
		}
		return 'unknown';
	}

	/**
	 * Fixed-window bucket key. The window *bucket* (not the duration) is part
	 * of the key so counters reset on schedule instead of being re-armed on
	 * every hit.
	 */
	private static function key( $scope, $client_ip, $window ) {
		$ip     = self::normalize_ip( $client_ip );
		$window = max( 1, (int) $window );
		$bucket = (int) floor( time() / $window );
		return hash( 'sha256', wp_salt( 'auth' ) . '|' . $scope . '|' . $window . '|' . $bucket . '|' . $ip );
	}

	/**
	 * Increment a durable database counter using one atomic SQL upsert.
	 */
	private static function hit( $key, $window ) {
		$window    = max( 1, (int) $window );
		$remaining = max( 1, $window - ( time() % $window ) );
		$expires   = gmdate( 'Y-m-d H:i:s', time() + $remaining );
		return Epic_Agent_Store::increment_counter( $key, $expires );
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
