<?php
/**
 * Fan-out email + WhatsApp for a template key.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Messaging;

use Prime_Fleet\Database\Schema;
use Prime_Fleet\Messaging\WhatsApp\Channel_Factory;

defined( 'ABSPATH' ) || exit;

final class Notifier {

	/**
	 * Send enabled channels for a booking template.
	 *
	 * @param string $template_key Template key.
	 * @param int    $booking_id Booking ID.
	 * @param array  $extra Extra merge vars.
	 * @param array  $progress In/out: email_done, wa_done (avoid double-send on retry).
	 * @return true|\WP_Error
	 */
	public static function send( $template_key, $booking_id, array $extra = array(), array &$progress = array() ) {
		$tpl = Templates::get( $template_key );
		if ( ! $tpl || ! (int) $tpl['is_enabled'] ) {
			return new \WP_Error( 'prime_fleet_notify_tpl', 'Template disabled or missing: ' . $template_key );
		}

		$want_email = (int) $tpl['email_enabled'];
		$want_wa    = (int) $tpl['whatsapp_enabled'];

		if ( ! $want_email && ! $want_wa ) {
			return true; // Nothing to send.
		}

		$errors = array();

		if ( $want_email && empty( $progress['email_done'] ) ) {
			$result = Mailer::send_template( $template_key, $booking_id, $extra );
			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
			} else {
				$progress['email_done'] = true;
			}
		}

		if ( $want_wa && empty( $progress['wa_done'] ) ) {
			$result = self::send_whatsapp( $template_key, $booking_id, $extra, $tpl );
			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
			} else {
				$progress['wa_done'] = true;
			}
		}

		if ( $errors ) {
			return new \WP_Error( 'prime_fleet_notify_partial', implode( ' | ', $errors ) );
		}

		return true;
	}

	/**
	 * @param string $template_key Key.
	 * @param int    $booking_id Booking.
	 * @param array  $extra Extra.
	 * @param array  $tpl Template row.
	 * @return true|\WP_Error
	 */
	private static function send_whatsapp( $template_key, $booking_id, array $extra, array $tpl ) {
		global $wpdb;

		$channel = Channel_Factory::make();
		if ( ! $channel ) {
			return true; // Globally disabled — treat as success (do not retry forever).
		}

		$ctx = Merge_Vars::for_booking( $booking_id, $extra );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}

		$booking = $ctx['booking'];
		$vars    = $ctx['vars'];

		$to = trim( (string) ( $booking['customer_whatsapp'] ? $booking['customer_whatsapp'] : $booking['customer_phone'] ) );
		if ( '' === $to ) {
			return new \WP_Error( 'prime_fleet_wa_to', 'No WhatsApp/phone on booking.' );
		}

		$heading = Templates::merge( (string) $tpl['heading'], $vars );
		$inner   = Templates::merge( (string) $tpl['body_html'], $vars );
		$body    = $heading . "\n\n" . Merge_Vars::plain_from_html( $inner );

		$wpdb->insert(
			Schema::table( 'communications' ),
			array(
				'booking_id'   => (int) $booking['id'],
				'channel'      => 'whatsapp',
				'template_key' => $template_key,
				'recipient'    => $to,
				'status'       => 'sending',
				'body_preview' => mb_substr( $body, 0, 280 ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		$comm_id = (int) $wpdb->insert_id;

		$result = $channel->send(
			$to,
			$body,
			array(
				'template_key'         => $template_key,
				'booking_id'           => (int) $booking['id'],
				'whatsapp_template_id' => (string) ( $tpl['whatsapp_template_id'] ?? '' ),
				'vars'                 => $vars,
			)
		);

		if ( is_wp_error( $result ) ) {
			if ( $comm_id ) {
				$wpdb->update(
					Schema::table( 'communications' ),
					array(
						'status' => 'failed',
					),
					array( 'id' => $comm_id ),
					array( '%s' ),
					array( '%d' )
				);
			}
			return $result;
		}

		$status  = isset( $result['status'] ) ? (string) $result['status'] : 'sent';
		$preview = isset( $result['preview'] ) ? (string) $result['preview'] : mb_substr( $body, 0, 280 );
		$pid     = isset( $result['provider_id'] ) ? (string) $result['provider_id'] : '';

		if ( $comm_id ) {
			$wpdb->update(
				Schema::table( 'communications' ),
				array(
					'status'       => $status,
					'provider_id'  => $pid,
					'body_preview' => mb_substr( $preview, 0, 500 ),
				),
				array( 'id' => $comm_id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
		}

		return true;
	}
}
