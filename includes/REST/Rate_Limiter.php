<?php
/**
 * Simple IP rate limiter for public REST endpoints.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\REST;

defined( 'ABSPATH' ) || exit;

final class Rate_Limiter {

	/**
	 * Allow using Settings → Security rate-limit overrides when present.
	 *
	 * @param string $bucket Endpoint key (bookings|coupon|quote|…).
	 * @return true|\WP_Error
	 */
	public static function allow_configured( $bucket ) {
		$defaults = array(
			'bookings' => array( 20, 60 ),
			'coupon'   => array( 40, 60 ),
			'quote'    => array( 60, 60 ),
			'lookup'   => array( 30, 60 ),
			'config'   => array( 120, 60 ),
		);
		$max    = isset( $defaults[ $bucket ] ) ? $defaults[ $bucket ][0] : 60;
		$window = isset( $defaults[ $bucket ] ) ? $defaults[ $bucket ][1] : 60;

		$settings = get_option( 'prime_fleet_settings', array() );
		if ( is_array( $settings ) ) {
			$map = array(
				'bookings' => 'rl_bookings_per_min',
				'coupon'   => 'rl_coupon_per_min',
				'quote'    => 'rl_quote_per_min',
				'lookup'   => 'rl_lookup_per_min',
			);
			if ( isset( $map[ $bucket ] ) && isset( $settings[ $map[ $bucket ] ] ) && '' !== $settings[ $map[ $bucket ] ] ) {
				$max = max( 1, min( 500, absint( $settings[ $map[ $bucket ] ] ) ) );
			}
		}

		return self::allow( $bucket, $max, $window );
	}

	/**
	 * @param string $bucket Endpoint key.
	 * @param int    $max Max hits.
	 * @param int    $window_seconds Window.
	 * @return true|\WP_Error
	 */
	public static function allow( $bucket, $max = 60, $window_seconds = 60 ) {
		$ip = self::client_ip();
		$key = 'prime_fleet_rl_' . md5( $bucket . '|' . $ip );
		$data = get_transient( $key );
		if ( ! is_array( $data ) ) {
			$data = array(
				'count' => 0,
				'start' => time(),
			);
		}

		$elapsed = time() - (int) $data['start'];
		if ( $elapsed >= $window_seconds ) {
			$data = array(
				'count' => 0,
				'start' => time(),
			);
		}

		$data['count'] = (int) $data['count'] + 1;
		$remaining     = max( 1, $window_seconds - ( time() - (int) $data['start'] ) );
		set_transient( $key, $data, $window_seconds );

		if ( $data['count'] > $max ) {
			/* Throttle log spam — one row per IP+bucket per window. */
			$log_key = 'prime_fleet_rl_logged_' . md5( $bucket . '|' . $ip );
			if ( false === get_transient( $log_key ) ) {
				set_transient( $log_key, 1, $window_seconds );
				\Prime_Fleet\Logging\Logger::warning(
					'rate_limit',
					sprintf( 'Rate limit 429 on %s', sanitize_key( (string) $bucket ) ),
					array(
						'bucket' => sanitize_key( (string) $bucket ),
						'ip'     => $ip,
						'max'    => (int) $max,
						'count'  => (int) $data['count'],
					)
				);
			}
			return new \WP_Error(
				'prime_fleet_rate_limited',
				__( 'Too many requests. Please wait and try again.', 'prime-fleet' ),
				array(
					'status'      => 429,
					'retry_after' => $remaining,
				)
			);
		}

		return true;
	}

	/**
	 * @return string
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '0.0.0.0'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// Do not trust X-Forwarded-For by default (spoofable). Hosts behind trusted proxies can filter later.
		return preg_replace( '/[^0-9a-fA-F:\.]/', '', $ip ) ?: '0.0.0.0';
	}
}
