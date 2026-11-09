<?php
/**
 * Guest booking status lookup (reference + email).
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Bookings;

use Prime_Fleet\Database\Schema;
use Prime_Fleet\REST\Rate_Limiter;

defined( 'ABSPATH' ) || exit;

final class Lookup {

	/**
	 * @param string $reference Ref.
	 * @param string $email Email.
	 * @return array|\WP_Error
	 */
	public static function find( $reference, $email ) {
		$limit = Rate_Limiter::allow( 'lookup', 20, 60 );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}

		$reference = strtoupper( trim( (string) $reference ) );
		$email     = strtolower( sanitize_email( (string) $email ) );

		// Same generic error for all failures (no email enumeration).
		$generic = new \WP_Error(
			'prime_fleet_lookup',
			__( 'We could not find a booking with those details.', 'prime-fleet' ),
			array( 'status' => 404 )
		);

		if ( ! $reference || ! is_email( $email ) ) {
			return $generic;
		}

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT reference, status, payment_status, pickup_datetime, fare_total, currency, manual_review, customer_name
				FROM ' . Schema::table( 'bookings' ) . '
				WHERE reference = %s AND LOWER(customer_email) = %s
				LIMIT 1',
				$reference,
				$email
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return $generic;
		}

		$manual = (int) $row['manual_review'] === 1 && (float) $row['fare_total'] <= 0;
		return array(
			'reference'      => $row['reference'],
			'status'         => $row['status'],
			'payment_status' => $row['payment_status'],
			'pickup_datetime'=> $row['pickup_datetime'],
			'fare_total'     => $manual ? null : (float) $row['fare_total'],
			'currency'       => $row['currency'],
			'manual_quote'   => $manual,
			'customer_name'  => $row['customer_name'],
		);
	}
}
