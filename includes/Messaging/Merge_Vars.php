<?php
/**
 * Build merge variables for a booking.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Messaging;

use Prime_Fleet\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class Merge_Vars {

	/**
	 * @param int   $booking_id Booking ID.
	 * @param array $extra Extra vars.
	 * @return array{booking:array,vars:array}|\WP_Error
	 */
	public static function for_booking( $booking_id, array $extra = array() ) {
		global $wpdb;

		$booking = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'bookings' ) . ' WHERE id = %d', absint( $booking_id ) ),
			ARRAY_A
		);
		if ( ! $booking ) {
			return new \WP_Error( 'prime_fleet_merge_booking', 'Booking not found.' );
		}

		$service = $wpdb->get_var(
			$wpdb->prepare( 'SELECT name FROM ' . Schema::table( 'services' ) . ' WHERE id = %d', (int) $booking['service_id'] )
		);

		$fare = ( (float) $booking['fare_total'] > 0 )
			? trim( $booking['currency'] . ' ' . number_format( (float) $booking['fare_total'], 2 ) )
			: ( (int) $booking['manual_review'] ? __( 'Custom quote', 'prime-fleet' ) : trim( $booking['currency'] . ' ' . number_format( 0, 2 ) ) );

		$snapshot = json_decode( (string) ( $booking['fare_snapshot_json'] ?? '' ), true );
		if ( ! is_array( $snapshot ) ) {
			$snapshot = array();
		}
		$coupon_code     = ! empty( $booking['coupon_code'] ) ? (string) $booking['coupon_code'] : '';
		$coupon_discount = '';
		$coupon_rows     = '';
		$coupon_note     = '';
		if ( $coupon_code ) {
			$disc = 0.0;
			if ( ! empty( $snapshot['coupon']['discount'] ) ) {
				$disc = (float) $snapshot['coupon']['discount'];
			}
			$coupon_discount = trim( $booking['currency'] . ' ' . number_format( $disc, 2 ) );
			$coupon_rows     = '<tr><td style="padding:4px 0;color:#6b778c;">Coupon</td><td style="padding:4px 0;text-align:right;font-weight:700;">'
				. esc_html( $coupon_code ) . '</td></tr>'
				. '<tr><td style="padding:4px 0;color:#6b778c;">Discount</td><td style="padding:4px 0;text-align:right;font-weight:700;">−'
				. esc_html( $coupon_discount ) . '</td></tr>';
			$coupon_note = '<p style="margin:10px 0 0;font-size:14px;color:#0b1d39;">Coupon <strong>'
				. esc_html( $coupon_code ) . '</strong> · discount −' . esc_html( $coupon_discount ) . '</p>';
		}

		$vehicle_name    = '';
		$chauffeur_name  = '';
		$chauffeur_phone = '';
		if ( ! empty( $booking['assigned_vehicle_id'] ) ) {
			$vrow = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT name, customer_display_name FROM ' . Schema::table( 'vehicles' ) . ' WHERE id = %d',
					(int) $booking['assigned_vehicle_id']
				),
				ARRAY_A
			);
			if ( $vrow ) {
				$vehicle_name = $vrow['customer_display_name'] ? $vrow['customer_display_name'] : $vrow['name'];
			}
		}
		if ( ! empty( $booking['assigned_chauffeur_id'] ) ) {
			$crow = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT name, phone, whatsapp FROM ' . Schema::table( 'chauffeurs' ) . ' WHERE id = %d',
					(int) $booking['assigned_chauffeur_id']
				),
				ARRAY_A
			);
			if ( $crow ) {
				$chauffeur_name  = (string) $crow['name'];
				$chauffeur_phone = $crow['phone'] ? (string) $crow['phone'] : (string) $crow['whatsapp'];
			}
		}

		$vars = array_merge(
			array(
				'customer_name'     => $booking['customer_name'],
				'customer_email'    => $booking['customer_email'],
				'customer_phone'    => $booking['customer_phone'],
				'customer_whatsapp' => $booking['customer_whatsapp'] ? $booking['customer_whatsapp'] : $booking['customer_phone'],
				'booking_reference' => $booking['reference'],
				'service_name'      => $service ? $service : '',
				'pickup_time'       => $booking['pickup_datetime']
					? date_i18n( 'D, M j, Y · g:i A', strtotime( $booking['pickup_datetime'] ) )
					: '—',
				'passenger_count'   => (string) (int) $booking['passenger_count'],
				'fare_total'        => $fare,
				'site_name'         => get_bloginfo( 'name' ),
				'vehicle_name'      => $vehicle_name ? $vehicle_name : '—',
				'chauffeur_name'    => $chauffeur_name ? $chauffeur_name : '—',
				'chauffeur_phone'   => $chauffeur_phone ? $chauffeur_phone : '—',
				'coupon_code'       => $coupon_code,
				'coupon_discount'   => $coupon_discount,
				'coupon_rows'       => $coupon_rows,
				'coupon_note'       => $coupon_note,
				'payment_link'      => '',
				'offer_datetime'    => '',
				'offer_accept_url'  => '',
				'offer_expires'     => '',
			),
			$extra
		);

		return array(
			'booking' => $booking,
			'vars'    => $vars,
		);
	}

	/**
	 * Plain-text body for WhatsApp (from email HTML).
	 *
	 * Table label/value cells become "Label: Value" on their own lines.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function plain_from_html( $html ) {
		$html = (string) $html;

		// Soft breaks.
		$html = preg_replace( '/<br\s*\/?>/i', "\n", $html );

		// Keep hrefs for WhatsApp (strip_tags would drop them). Button → "Label\nURL".
		$html = preg_replace_callback(
			'/<a\s[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is',
			static function ( $m ) {
				$url   = html_entity_decode( trim( (string) $m[2] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$label = trim( wp_strip_all_tags( html_entity_decode( (string) $m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
				if ( '' === $url ) {
					return $label;
				}
				if ( '' === $label || strcasecmp( $label, $url ) === 0 ) {
					return $url;
				}
				return $label . "\n" . $url;
			},
			$html
		);

		// Email table rows: Label cell + Value cell → "Label: Value".
		$html = preg_replace( '/<\/t[dh]>\s*<t[dh][^>]*>/i', ': ', $html );

		// Block / row ends → newline.
		$html = preg_replace( '/<\/(p|div|tr|h[1-6]|li|blockquote|section|article|header|footer)>/i', "\n", $html );

		// Leftover cell closes.
		$html = preg_replace( '/<\/t[dh]>/i', "\n", $html );

		$text = wp_strip_all_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Normalize spaces per line; keep newlines.
		$lines     = preg_split( "/\R/u", $text );
		$out       = array();
		$seen_urls = array();
		foreach ( (array) $lines as $line ) {
			$line = trim( preg_replace( '/[ \t]+/', ' ', (string) $line ) );
			if ( '' === $line ) {
				$out[] = '';
				continue;
			}
			// Email button + fallback URL → one link for WhatsApp.
			if ( preg_match( '/^https?:\/\/\S+$/i', $line ) ) {
				$key = strtolower( $line );
				if ( isset( $seen_urls[ $key ] ) ) {
					continue;
				}
				$seen_urls[ $key ] = true;
			}
			// Drop email-only “if the button…” wording; keep expiry if present.
			$line = preg_replace( '/\s*If the button does not work,?\s*open:?\s*/i', '', $line );
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			// "Label:Value" → "Label: Value". Do not touch https:// or clock times (5:30).
			$line  = preg_replace( '/([A-Za-z][A-Za-z0-9 \/&]*):(?!\/\/)\s*(?=\S)/', '$1: ', $line );
			$out[] = $line;
		}
		$text = implode( "\n", $out );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( (string) $text );
	}
}
