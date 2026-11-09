<?php
/**
 * Resolve WhatsApp channel from settings.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Messaging\WhatsApp;

defined( 'ABSPATH' ) || exit;

final class Channel_Factory {

	/**
	 * @return Channel_Interface|null Null when WhatsApp channel is globally off.
	 */
	public static function make() {
		$settings = get_option( 'prime_fleet_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		// Default ON when the setting was never saved (M3 Phase A).
		$enabled = array_key_exists( 'whatsapp_channel_enabled', $settings )
			? ! empty( $settings['whatsapp_channel_enabled'] )
			: true;
		if ( ! $enabled ) {
			return null;
		}

		$driver = isset( $settings['whatsapp_driver'] ) ? sanitize_key( (string) $settings['whatsapp_driver'] ) : 'stub';

		if ( 'openwa' === $driver ) {
			// Temporary local testing only — never the client/production path.
			return new OpenWA_Channel();
		}

		if ( 'meta' === $driver ) {
			// Meta Cloud driver lands in Phase D; fall back to stub until implemented.
			return new Stub_Channel();
		}

		return new Stub_Channel();
	}
}
