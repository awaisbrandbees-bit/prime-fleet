<?php
/**
 * Temporary OpenWA test driver (unofficial). Not for client/production.
 *
 * Hardened: only loopback hosts, and only when WP_DEBUG (or PRIME_FLEET_ALLOW_OPENWA).
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Messaging\WhatsApp;

defined( 'ABSPATH' ) || exit;

final class OpenWA_Channel implements Channel_Interface {

	const DEFAULT_BASE = 'http://127.0.0.1:2785';

	/**
	 * Dev-only gate — production / staging without debug never hits OpenWA HTTP.
	 *
	 * @return bool
	 */
	public static function is_dev_allowed() {
		if ( defined( 'PRIME_FLEET_ALLOW_OPENWA' ) && PRIME_FLEET_ALLOW_OPENWA ) {
			return true;
		}
		return defined( 'WP_DEBUG' ) && WP_DEBUG;
	}

	/**
	 * Whether host is loopback only (SSRF guard).
	 *
	 * @param string $host Host.
	 * @return bool
	 */
	public static function is_loopback_host( $host ) {
		$host = strtolower( trim( (string) $host ) );
		/* wp_parse_url may keep brackets for IPv6 literals: [::1]. */
		if ( strlen( $host ) >= 2 && '[' === $host[0] && ']' === substr( $host, -1 ) ) {
			$host = substr( $host, 1, -1 );
		}
		return in_array( $host, array( '127.0.0.1', 'localhost', '::1' ), true );
	}

	/**
	 * Force a safe OpenWA base URL (loopback http/https only). Rejects LAN/cloud SSRF targets.
	 *
	 * @param string $url Raw URL from settings.
	 * @return string
	 */
	public static function sanitize_base_url( $url ) {
		$url = untrailingslashit( trim( (string) $url ) );
		if ( '' === $url ) {
			return self::DEFAULT_BASE;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return self::DEFAULT_BASE;
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return self::DEFAULT_BASE;
		}

		$host = strtolower( (string) $parts['host'] );
		if ( ! self::is_loopback_host( $host ) ) {
			return self::DEFAULT_BASE;
		}

		/* No userinfo — blocks http://127.0.0.1@evil/ style parsers. */
		if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
			return self::DEFAULT_BASE;
		}

		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		/* Drop query/fragment — not needed for OpenWA API root. */
		return untrailingslashit( $scheme . '://' . $host . $port . $path );
	}

	/**
	 * {@inheritdoc}
	 */
	public function send( $to, $body, array $context = array() ) {
		if ( ! self::is_dev_allowed() ) {
			return new \WP_Error(
				'prime_fleet_openwa_blocked',
				__( 'OpenWA is blocked outside WP_DEBUG. Use Stub/Meta, or set PRIME_FLEET_ALLOW_OPENWA in wp-config for local tests.', 'prime-fleet' )
			);
		}

		$settings = get_option( 'prime_fleet_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$base_raw = isset( $settings['whatsapp_openwa_base_url'] ) ? (string) $settings['whatsapp_openwa_base_url'] : '';
		$base     = self::sanitize_base_url( $base_raw );
		$host     = strtolower( (string) wp_parse_url( $base, PHP_URL_HOST ) );
		if ( ! self::is_loopback_host( $host ) ) {
			return new \WP_Error(
				'prime_fleet_openwa_host',
				__( 'OpenWA base URL must be localhost / 127.0.0.1 only.', 'prime-fleet' )
			);
		}

		$session = isset( $settings['whatsapp_openwa_session_id'] ) ? trim( (string) $settings['whatsapp_openwa_session_id'] ) : '';
		if ( '' === $session ) {
			return new \WP_Error( 'prime_fleet_openwa_session', __( 'OpenWA session ID is missing (Settings → WhatsApp).', 'prime-fleet' ) );
		}

		$key = '';
		if ( defined( 'PRIME_FLEET_OPENWA_API_KEY' ) && PRIME_FLEET_OPENWA_API_KEY ) {
			$key = (string) PRIME_FLEET_OPENWA_API_KEY;
		} elseif ( ! empty( $settings['whatsapp_openwa_api_key'] ) ) {
			$key = (string) $settings['whatsapp_openwa_api_key'];
		}
		if ( '' === $key ) {
			return new \WP_Error(
				'prime_fleet_openwa_key',
				__( 'OpenWA API key missing. Prefer PRIME_FLEET_OPENWA_API_KEY in wp-config.php.', 'prime-fleet' )
			);
		}

		$chat_id = self::to_chat_id( $to );
		if ( is_wp_error( $chat_id ) ) {
			return $chat_id;
		}

		$url = $base . '/api/sessions/' . rawurlencode( $session ) . '/messages/send-text';

		/* WP blocks private hosts by default — allow only this validated loopback host. */
		$allow_local = static function ( $is_external, $req_host ) use ( $host ) {
			$req_host = strtolower( (string) $req_host );
			if ( $req_host === $host && OpenWA_Channel::is_loopback_host( $req_host ) ) {
				return true;
			}
			return $is_external;
		};
		add_filter( 'http_request_host_is_external', $allow_local, 10, 2 );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type' => 'application/json',
					'X-API-Key'    => $key,
				),
				'body'    => wp_json_encode(
					array(
						'chatId' => $chat_id,
						'text'   => (string) $body,
					)
				),
			)
		);

		remove_filter( 'http_request_host_is_external', $allow_local, 10 );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'prime_fleet_openwa_http',
				sprintf(
					/* translators: %s: transport error */
					__( 'OpenWA request failed: %s', 'prime-fleet' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $data ) && ! empty( $data['message'] ) ? (string) $data['message'] : $raw;
			return new \WP_Error(
				'prime_fleet_openwa_api',
				sprintf(
					/* translators: 1: HTTP status, 2: short body */
					__( 'OpenWA HTTP %1$d: %2$s', 'prime-fleet' ),
					$code,
					mb_substr( $msg, 0, 300 )
				)
			);
		}

		$provider_id = '';
		if ( is_array( $data ) ) {
			if ( ! empty( $data['id'] ) ) {
				$provider_id = (string) $data['id'];
			} elseif ( ! empty( $data['messageId'] ) ) {
				$provider_id = (string) $data['messageId'];
			} elseif ( ! empty( $data['data']['id'] ) ) {
				$provider_id = (string) $data['data']['id'];
			}
		}
		if ( '' === $provider_id ) {
			$provider_id = 'openwa-' . gmdate( 'YmdHis' );
		}

		return array(
			'provider_id' => $provider_id,
			'status'      => 'sent',
			'preview'     => mb_substr( (string) $body, 0, 500 ),
		);
	}

	/**
	 * Normalize phone / WhatsApp to OpenWA chatId (digits@c.us).
	 *
	 * @param string $to Recipient.
	 * @return string|\WP_Error
	 */
	private static function to_chat_id( $to ) {
		$to = trim( (string) $to );
		if ( '' === $to ) {
			return new \WP_Error( 'prime_fleet_openwa_to', __( 'Empty WhatsApp recipient.', 'prime-fleet' ) );
		}
		if ( false !== strpos( $to, '@' ) ) {
			return $to;
		}
		$digits = preg_replace( '/\D+/', '', $to );
		if ( ! $digits || strlen( $digits ) < 8 ) {
			return new \WP_Error( 'prime_fleet_openwa_to', __( 'Invalid WhatsApp number for OpenWA.', 'prime-fleet' ) );
		}
		return $digits . '@c.us';
	}
}
