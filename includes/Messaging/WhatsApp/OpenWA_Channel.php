<?php
/**
 * Temporary OpenWA test driver (unofficial). Not for client/production.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Messaging\WhatsApp;

defined( 'ABSPATH' ) || exit;

final class OpenWA_Channel implements Channel_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function send( $to, $body, array $context = array() ) {
		$settings = get_option( 'prime_fleet_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$base = isset( $settings['whatsapp_openwa_base_url'] ) ? untrailingslashit( trim( (string) $settings['whatsapp_openwa_base_url'] ) ) : '';
		if ( '' === $base ) {
			$base = 'http://127.0.0.1:2785';
		}

		$session = isset( $settings['whatsapp_openwa_session_id'] ) ? trim( (string) $settings['whatsapp_openwa_session_id'] ) : '';
		if ( '' === $session ) {
			return new \WP_Error( 'prime_fleet_openwa_session', 'OpenWA session ID is missing (Settings → WhatsApp).' );
		}

		$key = '';
		if ( defined( 'PRIME_FLEET_OPENWA_API_KEY' ) && PRIME_FLEET_OPENWA_API_KEY ) {
			$key = (string) PRIME_FLEET_OPENWA_API_KEY;
		} elseif ( ! empty( $settings['whatsapp_openwa_api_key'] ) ) {
			$key = (string) $settings['whatsapp_openwa_api_key'];
		}
		if ( '' === $key ) {
			return new \WP_Error( 'prime_fleet_openwa_key', 'OpenWA API key missing. Prefer PRIME_FLEET_OPENWA_API_KEY in wp-config.php.' );
		}

		$chat_id = self::to_chat_id( $to );
		if ( is_wp_error( $chat_id ) ) {
			return $chat_id;
		}

		$url = $base . '/api/sessions/' . rawurlencode( $session ) . '/messages/send-text';

		// WP blocks private/local hosts by default — allow only for this OpenWA call.
		$allow_local = static function ( $is_external, $host ) {
			$host = strtolower( (string) $host );
			if ( in_array( $host, array( '127.0.0.1', 'localhost', '::1' ), true ) ) {
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
				'OpenWA request failed: ' . $response->get_error_message()
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $data ) && ! empty( $data['message'] ) ? (string) $data['message'] : $raw;
			return new \WP_Error( 'prime_fleet_openwa_api', 'OpenWA HTTP ' . $code . ': ' . mb_substr( $msg, 0, 300 ) );
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
			return new \WP_Error( 'prime_fleet_openwa_to', 'Empty WhatsApp recipient.' );
		}
		if ( false !== strpos( $to, '@' ) ) {
			return $to;
		}
		$digits = preg_replace( '/\D+/', '', $to );
		if ( ! $digits || strlen( $digits ) < 8 ) {
			return new \WP_Error( 'prime_fleet_openwa_to', 'Invalid WhatsApp number for OpenWA.' );
		}
		return $digits . '@c.us';
	}
}
