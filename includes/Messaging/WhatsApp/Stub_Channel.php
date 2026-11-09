<?php
/**
 * Stub WhatsApp channel — logs to communications / outbox until Meta is ready.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Messaging\WhatsApp;

defined( 'ABSPATH' ) || exit;

final class Stub_Channel implements Channel_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function send( $to, $body, array $context = array() ) {
		$to = trim( (string) $to );
		if ( '' === $to ) {
			return new \WP_Error( 'prime_fleet_wa_to', 'Missing WhatsApp recipient.' );
		}

		$body = trim( (string) $body );
		if ( '' === $body ) {
			return new \WP_Error( 'prime_fleet_wa_body', 'Empty WhatsApp body.' );
		}

		$id = 'stub-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 6, false, false );

		return array(
			'provider_id' => $id,
			'status'      => 'stubbed',
			'preview'     => mb_substr( $body, 0, 500 ),
		);
	}
}
