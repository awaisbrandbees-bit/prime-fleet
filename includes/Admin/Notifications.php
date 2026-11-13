<?php
/**
 * Admin header notifications (pending bookings / payment attention).
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Admin;

use Prime_Fleet\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class Notifications {

	/**
	 * @return array{count:int,items:array<int,array<string,mixed>>}
	 */
	public static function summary() {
		global $wpdb;

		$bookings = Schema::table( 'bookings' );
		$services = Schema::table( 'services' );
		$exists   = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bookings ) );

		$empty = array(
			'count' => 0,
			'items' => array(),
		);

		if ( ! $exists ) {
			return $empty;
		}

		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$bookings}
			WHERE status = 'pending_confirmation'
			   OR (payment_status IN ('unpaid','link_sent','pending_verification') AND status NOT IN ('cancelled'))"
		);

		$rows = $wpdb->get_results(
			"SELECT b.id, b.reference, b.status, b.payment_status, b.customer_name, b.pickup_datetime, s.name AS service_name
			FROM {$bookings} b
			LEFT JOIN {$services} s ON s.id = b.service_id
			WHERE b.status = 'pending_confirmation'
			   OR (b.payment_status IN ('unpaid','link_sent','pending_verification') AND b.status NOT IN ('cancelled'))
			ORDER BY
				CASE WHEN b.status = 'pending_confirmation' THEN 0 ELSE 1 END,
				b.pickup_datetime ASC
			LIMIT 8",
			ARRAY_A
		);

		$items = array();
		foreach ( (array) $rows as $row ) {
			$pending = ( 'pending_confirmation' === $row['status'] );
			$reason  = $pending
				? __( 'Needs confirmation', 'prime-fleet' )
				: __( 'Payment attention', 'prime-fleet' );
			$when = ! empty( $row['pickup_datetime'] )
				? date_i18n( 'M j · g:i A', strtotime( $row['pickup_datetime'] ) )
				: '';

			$items[] = array(
				'id'        => (int) $row['id'],
				'reference' => (string) $row['reference'],
				'customer'  => (string) $row['customer_name'],
				'service'   => (string) ( $row['service_name'] ? $row['service_name'] : '' ),
				'reason'    => $reason,
				'when'      => $when,
				'tone'      => $pending ? 'warn' : 'pay',
			);
		}

		return array(
			'count' => $count,
			'items' => $items,
		);
	}
}
