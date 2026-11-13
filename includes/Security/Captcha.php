<?php
/**
 * Guest booking CAPTCHA (Cloudflare Turnstile / reCAPTCHA v2).
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Security;

defined( 'ABSPATH' ) || exit;

final class Captcha {

	/**
	 * @return array{enabled:bool,provider:string,site_key:string}
	 */
	public static function public_config() {
		$s = self::settings();
		$enabled = ! empty( $s['captcha_enabled'] )
			&& in_array( $s['captcha_provider'], array( 'turnstile', 'recaptcha_v2' ), true )
			&& '' !== $s['captcha_site_key']
			&& '' !== self::secret();

		return array(
			'enabled'  => $enabled,
			'provider' => $enabled ? $s['captcha_provider'] : '',
			'site_key' => $enabled ? $s['captcha_site_key'] : '',
		);
	}

	/**
	 * Whether verification is required on booking create.
	 *
	 * @return bool
	 */
	public static function is_required() {
		return ! empty( self::public_config()['enabled'] );
	}

	/**
	 * Admin checked "enabled" but keys/provider incomplete — treat as hard fail
	 * so a half-configured CAPTCHA cannot silently disable protection.
	 *
	 * @return bool
	 */
	public static function is_misconfigured() {
		$s = self::settings();
		if ( empty( $s['captcha_enabled'] ) ) {
			return false;
		}
		return empty( self::public_config()['enabled'] );
	}

	/**
	 * Verify token from guest request body.
	 *
	 * @param array<string,mixed> $raw Request body.
	 * @return true|\WP_Error
	 */
	public static function verify_request( array $raw ) {
		if ( self::is_misconfigured() ) {
			return new \WP_Error(
				'prime_fleet_captcha_config',
				__( 'Booking security is misconfigured. Please contact the site administrator.', 'prime-fleet' ),
				array( 'status' => 503 )
			);
		}
		if ( ! self::is_required() ) {
			return true;
		}

		$token = '';
		foreach ( array( 'captcha_token', 'cf-turnstile-response', 'g-recaptcha-response' ) as $key ) {
			if ( ! empty( $raw[ $key ] ) && is_string( $raw[ $key ] ) ) {
				$token = trim( $raw[ $key ] );
				break;
			}
		}
		if ( '' === $token ) {
			return new \WP_Error(
				'prime_fleet_captcha',
				__( 'Please complete the security check and try again.', 'prime-fleet' ),
				array( 'status' => 403 )
			);
		}

		$s        = self::settings();
		$provider = $s['captcha_provider'];
		$secret   = self::secret();
		$endpoint = 'turnstile' === $provider
			? 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
			: 'https://www.google.com/recaptcha/api/siteverify';

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 12,
				'body'    => array(
					'secret'   => $secret,
					'response' => $token,
					'remoteip' => isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'prime_fleet_captcha',
				__( 'Security check temporarily unavailable. Please try again.', 'prime-fleet' ),
				array( 'status' => 503 )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) || empty( $data['success'] ) ) {
			return new \WP_Error(
				'prime_fleet_captcha',
				__( 'Security check failed. Please try again.', 'prime-fleet' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function settings() {
		$raw = get_option( 'prime_fleet_settings', array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		$provider = isset( $raw['captcha_provider'] ) ? sanitize_key( (string) $raw['captcha_provider'] ) : 'none';
		if ( ! in_array( $provider, array( 'none', 'turnstile', 'recaptcha_v2' ), true ) ) {
			$provider = 'none';
		}
		return array(
			'captcha_enabled'  => ! empty( $raw['captcha_enabled'] ) ? 1 : 0,
			'captcha_provider' => $provider,
			'captcha_site_key' => isset( $raw['captcha_site_key'] ) ? sanitize_text_field( (string) $raw['captcha_site_key'] ) : '',
			'captcha_secret'   => isset( $raw['captcha_secret'] ) ? (string) $raw['captcha_secret'] : '',
		);
	}

	/**
	 * Prefer wp-config constant so secrets stay out of the DB.
	 *
	 * @return string
	 */
	private static function secret() {
		if ( defined( 'PRIME_FLEET_CAPTCHA_SECRET' ) && PRIME_FLEET_CAPTCHA_SECRET ) {
			return (string) PRIME_FLEET_CAPTCHA_SECRET;
		}
		$s = self::settings();
		return trim( (string) $s['captcha_secret'] );
	}
}
