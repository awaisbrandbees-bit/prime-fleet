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
