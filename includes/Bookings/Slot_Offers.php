<?php
/**
 * Slot offer create / accept.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Bookings;

use Prime_Fleet\Database\Schema;
use Prime_Fleet\Messaging\Queue;

defined( 'ABSPATH' ) || exit;

final class Slot_Offers {

	/**
	 * Create an offer and queue notify job.
	 *
	 * @param int    $booking_id Booking.
	 * @param string $proposed_datetime Datetime.
	 * @param int    $vehicle_id Vehicle.
	 * @return array|\WP_Error
	 */
	public static function create( $booking_id, $proposed_datetime, $vehicle_id = 0 ) {
		global $wpdb;
		$booking_id = absint( $booking_id );
		$vehicle_id = absint( $vehicle_id );
		$proposed   = str_replace( 'T', ' ', trim( (string) $proposed_datetime ) );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}$/', $proposed ) ) {
			$proposed .= ':00';
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$/', $proposed ) ) {
			return new \WP_Error( 'prime_fleet_offer_dt', __( 'Invalid proposed datetime.', 'prime-fleet' ), array( 'status' => 400 ) );
		}

		$booking = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'bookings' ) . ' WHERE id = %d', $booking_id ),
			ARRAY_A
		);
		if ( ! $booking ) {
			return new \WP_Error( 'prime_fleet_offer', __( 'Booking not found.', 'prime-fleet' ), array( 'status' => 404 ) );
		}

		$occ = Occupancy::check(
			$proposed,
			array(
				'vehicle_id'         => $vehicle_id,
				'exclude_booking_id' => $booking_id,
			)
		);
		if ( empty( $occ['available'] ) && empty( $occ['manual'] ) ) {
			return new \WP_Error( 'prime_fleet_offer_busy', __( 'That window is not free.', 'prime-fleet' ), array( 'status' => 409 ) );
		}
		if ( ! $vehicle_id && ! empty( $occ['vehicle_id'] ) ) {
			$vehicle_id = (int) $occ['vehicle_id'];
		}

		$token         = bin2hex( random_bytes( 20 ) );
		$expires_local = date( 'Y-m-d H:i:s', time() + ( 3 * DAY_IN_SECONDS ) );

		$ok = $wpdb->insert(
			Schema::table( 'slot_offers' ),
			array(
				'booking_id'        => $booking_id,
				'proposed_datetime' => $proposed,
				'vehicle_id'        => $vehicle_id,
				'status'            => 'sent',
				'token'             => $token,
				'expires_at'        => $expires_local,
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s' )
		);
		if ( ! $ok ) {
			return new \WP_Error( 'prime_fleet_offer', __( 'Could not create offer.', 'prime-fleet' ), array( 'status' => 500 ) );
		}
		$offer_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			Schema::table( 'booking_events' ),
			array(
				'booking_id' => $booking_id,
				'event_type' => 'slot_offer_sent',
				'actor_id'   => get_current_user_id(),
				'message'    => 'Slot offer sent: ' . $proposed,
				'meta_json'  => wp_json_encode( array( 'offer_id' => $offer_id, 'token' => $token ) ),
			),
			array( '%d', '%s', '%d', '%s', '%s' )
		);

		$wpdb->insert(
			Schema::table( 'jobs' ),
			array(
				'job_type'     => 'slot_offer_notify',
				'payload_json' => wp_json_encode(
					array(
						'booking_id' => $booking_id,
						'offer_id'   => $offer_id,
						'token'      => $token,
					)
				),
				'status'       => 'pending',
				'run_at'       => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s' )
		);

		$accept_url = home_url( '/?prime_fleet_offer=' . rawurlencode( $token ) );

		return array(
			'offer_id'   => $offer_id,
			'token'      => $token,
			'accept_url' => $accept_url,
			'expires_at' => $expires_local,
			'proposed'   => $proposed,
		);
	}

	/**
	 * @param string $token Token.
	 * @return array|\WP_Error
	 */
	public static function get_by_token( $token ) {
		global $wpdb;
		$token = sanitize_text_field( (string) $token );
		if ( strlen( $token ) < 20 ) {
			return new \WP_Error( 'prime_fleet_offer', __( 'Offer not found.', 'prime-fleet' ), array( 'status' => 404 ) );
		}
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'slot_offers' ) . ' WHERE token = %s LIMIT 1', $token ),
			ARRAY_A
		);
		if ( ! $row ) {
			return new \WP_Error( 'prime_fleet_offer', __( 'Offer not found.', 'prime-fleet' ), array( 'status' => 404 ) );
		}
		if ( 'accepted' === $row['status'] ) {
			return new \WP_Error( 'prime_fleet_offer', __( 'This offer was already accepted.', 'prime-fleet' ), array( 'status' => 409 ) );
		}
		if ( ! empty( $row['expires_at'] ) && $row['expires_at'] < current_time( 'mysql' ) ) {
			return new \WP_Error( 'prime_fleet_offer', __( 'This offer has expired.', 'prime-fleet' ), array( 'status' => 410 ) );
		}
		if ( ! in_array( $row['status'], array( 'sent', 'draft' ), true ) ) {
			return new \WP_Error( 'prime_fleet_offer', __( 'This offer is no longer available.', 'prime-fleet' ), array( 'status' => 410 ) );
		}

		$booking = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT reference, customer_name, pickup_datetime, status FROM ' . Schema::table( 'bookings' ) . ' WHERE id = %d',
				(int) $row['booking_id']
			),
			ARRAY_A
		);

		return array(
			'token'              => $row['token'],
			'proposed_datetime'  => $row['proposed_datetime'],
			'expires_at'         => $row['expires_at'],
			'reference'          => $booking ? $booking['reference'] : '',
			'current_pickup'     => $booking ? $booking['pickup_datetime'] : '',
			'customer_name'      => $booking ? $booking['customer_name'] : '',
		);
	}

	/**
	 * Accept offer — update booking pickup + vehicle.
	 *
	 * @param string $token Token.
	 * @return array|\WP_Error
	 */
	public static function accept( $token ) {
		global $wpdb;
		$offer = self::get_by_token( $token );
		if ( is_wp_error( $offer ) ) {
			return $offer;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'slot_offers' ) . ' WHERE token = %s LIMIT 1', sanitize_text_field( $token ) ),
			ARRAY_A
		);

		$wpdb->query( 'START TRANSACTION' );
		try {
			$wpdb->update(
				Schema::table( 'slot_offers' ),
				array( 'status' => 'accepted' ),
				array( 'id' => (int) $row['id'] ),
				array( '%s' ),
				array( '%d' )
			);
			$update = array(
				'pickup_datetime' => $row['proposed_datetime'],
			);
			if ( (int) $row['vehicle_id'] > 0 ) {
				$update['assigned_vehicle_id'] = (int) $row['vehicle_id'];
			}
			$wpdb->update(
				Schema::table( 'bookings' ),
				$update,
				array( 'id' => (int) $row['booking_id'] ),
				null,
				array( '%d' )
			);
			$wpdb->update(
				Schema::table( 'booking_legs' ),
				array( 'scheduled_datetime' => $row['proposed_datetime'] ),
				array(
					'booking_id' => (int) $row['booking_id'],
					'leg_type'   => 'outbound',
				),
				array( '%s' ),
				array( '%d', '%s' )
			);
			$wpdb->insert(
				Schema::table( 'booking_events' ),
				array(
					'booking_id' => (int) $row['booking_id'],
					'event_type' => 'slot_offer_accepted',
					'actor_id'   => 0,
					'message'    => 'Guest accepted slot offer: ' . $row['proposed_datetime'],
					'meta_json'  => wp_json_encode( array( 'offer_id' => (int) $row['id'] ) ),
				),
				array( '%d', '%s', '%d', '%s', '%s' )
			);
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'prime_fleet_offer', __( 'Could not accept offer.', 'prime-fleet' ), array( 'status' => 500 ) );
		}

		Queue::on_updated( (int) $row['booking_id'], true );

		$booking = $wpdb->get_row(
			$wpdb->prepare( 'SELECT reference FROM ' . Schema::table( 'bookings' ) . ' WHERE id = %d', (int) $row['booking_id'] ),
			ARRAY_A
		);

		return array(
			'reference'         => $booking ? $booking['reference'] : '',
			'pickup_datetime'   => $row['proposed_datetime'],
			'message'           => __( 'Thank you — your new pickup time is confirmed pending Prime Fleet final review.', 'prime-fleet' ),
		);
	}
}
