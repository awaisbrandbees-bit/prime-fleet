<?php
/**
 * Send templated booking emails.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Messaging;

use Prime_Fleet\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class Mailer {

	/**
	 * Send a template for a booking (optional extra vars).
	 *
	 * @param string $template_key Key.
	 * @param int    $booking_id Booking.
	 * @param array  $extra Extra merge vars.
	 * @return true|\WP_Error
	 */
	public static function send_template( $template_key, $booking_id, array $extra = array() ) {
		global $wpdb;

		$tpl = Templates::get( $template_key );
		if ( ! $tpl || ! (int) $tpl['is_enabled'] || ! (int) $tpl['email_enabled'] ) {
			return new \WP_Error( 'prime_fleet_mail_tpl', 'Template disabled or missing: ' . $template_key );
		}

		$ctx = Merge_Vars::for_booking( $booking_id, $extra );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}

		$booking = $ctx['booking'];
		$vars    = $ctx['vars'];

		if ( ! is_email( $booking['customer_email'] ) ) {
			return new \WP_Error( 'prime_fleet_mail_to', 'Invalid booking recipient.' );
		}

		$subject = Templates::merge( $tpl['subject'], $vars );
		$heading = Templates::merge( $tpl['heading'], $vars );
		$inner   = Templates::merge( (string) $tpl['body_html'], $vars );
		$html    = Templates::wrap( $heading, $inner );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		$wpdb->insert(
			Schema::table( 'communications' ),
			array(
				'booking_id'   => (int) $booking['id'],
				'channel'      => 'email',
				'template_key' => $template_key,
				'recipient'    => $booking['customer_email'],
				'status'       => 'sending',
				'body_preview' => wp_strip_all_tags( $subject . ' — ' . $heading ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		$comm_id = (int) $wpdb->insert_id;

		$sent = wp_mail( $booking['customer_email'], $subject, $html, $headers );

		$wpdb->update(
			Schema::table( 'communications' ),
			array(
				'status' => $sent ? 'sent' : 'failed',
			),
			array( 'id' => $comm_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( ! $sent ) {
			return new \WP_Error( 'prime_fleet_mail_fail', 'wp_mail failed for ' . $template_key );
		}
		return true;
	}
}
