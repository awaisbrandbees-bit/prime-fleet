<?php
/**
 * One-time submit tokens for guest bookings.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Bookings;

use Prime_Fleet\REST\Rate_Limiter;

defined( 'ABSPATH' ) || exit;

final class Submit_Token {

	const TTL           = 45 * MINUTE_IN_SECONDS;
	const MIN_AGE       = 2; // Seconds between issue and consume (bots).
	const PREFIX        = 'prime_fleet_bt_';

	/**
	 * Issue a new one-time token.
	 *
	 * @return array{token:string,expires_in:int}|\WP_Error
	 */
	public static function issue() {
		$limit = Rate_Limiter::allow( 'booking_token', 30, 60 );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}

		$token = bin2hex( random_bytes( 24 ) );
		$key   = self::PREFIX . hash( 'sha256', $token );
		$data  = array(
			'issued_at' => time(),
			'ip'        => Rate_Limiter::client_ip(),
			'used'      => false,
		);
		set_transient( $key, $data, self::TTL );

		return array(
			'token'      => $token,
			'expires_in' => self::TTL,
		);
	}

	/**
	 * Consume a token (single use). Returns true or WP_Error.
	 *
	 * @param string $token Token.
	 * @return true|\WP_Error
	 */
	public static function consume( $token ) {
		$token = is_string( $token ) ? trim( $token ) : '';
		if ( '' === $token || strlen( $token ) < 32 ) {
			return new \WP_Error( 'prime_fleet_bad_token', __( 'Invalid booking session. Please reload and try again.', 'prime-fleet' ), array( 'status' => 403 ) );
		}

		$key  = self::PREFIX . hash( 'sha256', $token );
		$data = get_transient( $key );
		if ( ! is_array( $data ) || empty( $data['issued_at'] ) ) {
			return new \WP_Error( 'prime_fleet_bad_token', __( 'Your booking session expired. Please reload and try again.', 'prime-fleet' ), array( 'status' => 403 ) );
		}
		if ( ! empty( $data['used'] ) ) {
			return new \WP_Error( 'prime_fleet_token_replay', __( 'This booking was already submitted.', 'prime-fleet' ), array( 'status' => 409 ) );
		}

		$age = time() - (int) $data['issued_at'];
		if ( $age < self::MIN_AGE ) {
			return new \WP_Error( 'prime_fleet_too_fast', __( 'Please wait a moment and try again.', 'prime-fleet' ), array( 'status' => 429, 'retry_after' => self::MIN_AGE ) );
		}

		// Mark used before returning success (one-shot).
		$data['used'] = true;
		set_transient( $key, $data, 5 * MINUTE_IN_SECONDS );

		return true;
	}
}
