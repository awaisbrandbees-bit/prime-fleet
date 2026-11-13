<?php
/**
 * Post-job fare amendments (extra hours / wait / stops / manual).
 *
 * Appends booking_adjustments rows; preserves original base in fare_snapshot_json;
 * fare_total = original_fare_total + sum(adjustments).
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Bookings;

use Prime_Fleet\Database\Schema;
use Prime_Fleet\Messaging\Queue;

defined( 'ABSPATH' ) || exit;

final class Amendments {

	const TYPES = array( 'extra_hours', 'waiting', 'extra_stop', 'manual' );

	/**
	 * Human labels for adjustment types.
	 *
	 * @return array<string,string>
	 */
	public static function type_labels() {
		return array(
			'extra_hours' => __( 'Extra hours', 'prime-fleet' ),
			'waiting'     => __( 'Waiting time', 'prime-fleet' ),
			'extra_stop'  => __( 'Extra stop', 'prime-fleet' ),
			'manual'      => __( 'Manual adjustment', 'prime-fleet' ),
		);
	}

	/**
	 * @param int $booking_id Booking ID.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_booking( $booking_id ) {
		global $wpdb;
		$booking_id = absint( $booking_id );
		if ( ! $booking_id ) {
			return array();
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table( 'booking_adjustments' ) . ' WHERE booking_id = %d ORDER BY id ASC',
				$booking_id
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Suggest amount from catalog rates (staff can override).
	 *
	 * @param array  $booking Booking row.
	 * @param string $type    Adjustment type.
	 * @param float  $qty     Quantity (hours / intervals / stops).
	 * @return float
	 */
	public static function suggest_amount( array $booking, $type, $qty ) {
		$qty  = max( 0, (float) $qty );
		$type = sanitize_key( (string) $type );

		if ( 'manual' === $type || $qty <= 0 ) {
			return 0.0;
		}

		if ( 'extra_hours' === $type ) {
			$rate = self::hourly_rate_for_booking( $booking );
			return round( $rate * $qty, 2 );
		}

		if ( 'waiting' === $type ) {
			// Qty = billable 30-minute intervals (after complimentary wait handled by staff).
			$unit = self::addon_unit_price( 'Waiting Time', 50.0 );
			return round( $unit * $qty, 2 );
		}

		if ( 'extra_stop' === $type ) {
			$unit = self::addon_unit_price( 'Additional Stop', 30.0 );
			return round( $unit * $qty, 2 );
		}

		return 0.0;
	}

	/**
	 * Add an amendment. Does not reopen booking status.
	 *
	 * @param int   $booking_id Booking ID.
	 * @param array $args       type, qty, amount (optional), reason, notify, actor_id.
	 * @return array|\WP_Error Result with amount, fare_total, original_fare_total.
	 */
	public static function add( $booking_id, array $args ) {
		global $wpdb;

		$booking_id = absint( $booking_id );
		if ( ! $booking_id ) {
			return new \WP_Error( 'prime_fleet_invalid_booking', __( 'Booking not found.', 'prime-fleet' ) );
		}

		$booking = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'bookings' ) . ' WHERE id = %d', $booking_id ),
			ARRAY_A
		);
		if ( ! $booking ) {
			return new \WP_Error( 'prime_fleet_invalid_booking', __( 'Booking not found.', 'prime-fleet' ) );
		}

		$status = (string) $booking['status'];
		if ( ! in_array( $status, array( 'confirmed', 'completed', 'no_show' ), true ) ) {
			return new \WP_Error(
				'prime_fleet_amend_status',
				__( 'Amendments are only allowed on confirmed, completed, or no-show bookings.', 'prime-fleet' )
			);
		}

		$type = sanitize_key( (string) ( $args['type'] ?? 'manual' ) );
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return new \WP_Error( 'prime_fleet_amend_type', __( 'Unknown amendment type.', 'prime-fleet' ) );
		}

		$qty = isset( $args['qty'] ) ? (float) $args['qty'] : 1.0;
		if ( $qty <= 0 ) {
			return new \WP_Error( 'prime_fleet_amend_qty', __( 'Quantity must be greater than zero.', 'prime-fleet' ) );
		}
		if ( $qty > 999 ) {
			return new \WP_Error( 'prime_fleet_amend_qty', __( 'Quantity is too large.', 'prime-fleet' ) );
		}

		$amount_raw = array_key_exists( 'amount', $args ) ? $args['amount'] : null;
		if ( null === $amount_raw || '' === $amount_raw ) {
			$amount = self::suggest_amount( $booking, $type, $qty );
		} else {
			$amount = round( (float) $amount_raw, 2 );
		}

		if ( $amount < 0 ) {
			return new \WP_Error( 'prime_fleet_amend_amount', __( 'Amount cannot be negative.', 'prime-fleet' ) );
		}
		if ( $amount > 1000000 ) {
			return new \WP_Error( 'prime_fleet_amend_amount', __( 'Amount is too large.', 'prime-fleet' ) );
		}
		if ( 'manual' === $type && $amount <= 0 ) {
			return new \WP_Error( 'prime_fleet_amend_amount', __( 'Enter a manual amount greater than zero.', 'prime-fleet' ) );
		}
		if ( $amount <= 0 ) {
			return new \WP_Error(
				'prime_fleet_amend_amount',
				__( 'Could not price this amendment — enter an amount manually.', 'prime-fleet' )
			);
		}

		$reason = isset( $args['reason'] ) ? sanitize_textarea_field( (string) $args['reason'] ) : '';
		if ( strlen( $reason ) > 2000 ) {
			$reason = substr( $reason, 0, 2000 );
		}

		$actor_id = isset( $args['actor_id'] ) ? absint( $args['actor_id'] ) : get_current_user_id();
		$notify   = ! empty( $args['notify'] );

		$snapshot = json_decode( (string) ( $booking['fare_snapshot_json'] ?? '' ), true );
		if ( ! is_array( $snapshot ) ) {
			$snapshot = array();
		}

		$current_total = (float) $booking['fare_total'];
		if ( ! isset( $snapshot['original_fare_total'] ) ) {
			$snapshot['original_fare_total'] = $current_total;
			if ( ! isset( $snapshot['base'] ) && isset( $snapshot['total'] ) ) {
				// Keep published quote base visible; do not overwrite.
			}
		}
		$original = (float) $snapshot['original_fare_total'];

		$adj_row = array(
			'booking_id'      => $booking_id,
			'adjustment_type' => $type,
			'qty'             => $qty,
			'amount'          => $amount,
			'reason'          => $reason,
			'user_id'         => $actor_id,
		);

		$ok = $wpdb->insert(
			Schema::table( 'booking_adjustments' ),
			$adj_row,
			array( '%d', '%s', '%f', '%f', '%s', '%d' )
		);
		if ( ! $ok ) {
			return new \WP_Error( 'prime_fleet_amend_db', __( 'Could not save amendment.', 'prime-fleet' ) );
		}
		$adj_id = (int) $wpdb->insert_id;

		$entry = array(
			'id'         => $adj_id,
			'type'       => $type,
			'qty'        => $qty,
			'amount'     => $amount,
			'reason'     => $reason,
			'user_id'    => $actor_id,
			'created_at' => current_time( 'mysql' ),
		);
		if ( empty( $snapshot['adjustments'] ) || ! is_array( $snapshot['adjustments'] ) ) {
			$snapshot['adjustments'] = array();
		}
		$snapshot['adjustments'][] = $entry;

		$labels = self::type_labels();
		$type_label = isset( $labels[ $type ] ) ? $labels[ $type ] : $type;
		$currency   = (string) ( $booking['currency'] ?: 'USD' );
		$line       = sprintf(
			/* translators: 1: type label, 2: qty, 3: money */
			__( 'Amendment — %1$s × %2$s: +%3$s', 'prime-fleet' ),
			$type_label,
			rtrim( rtrim( number_format( $qty, 2, '.', '' ), '0' ), '.' ),
			$currency . ' ' . number_format( $amount, 2 )
		);
		if ( empty( $snapshot['explanation'] ) || ! is_array( $snapshot['explanation'] ) ) {
			$snapshot['explanation'] = array();
		}
		$snapshot['explanation'][] = $line;

		$existing = self::for_booking( $booking_id );
		$sum_adj  = 0.0;
		foreach ( $existing as $row ) {
			$sum_adj += (float) $row['amount'];
		}
		$new_total = round( $original + $sum_adj, 2 );
		$snapshot['total']             = $new_total;
		$snapshot['adjustments_total'] = round( $sum_adj, 2 );

		$wpdb->update(
			Schema::table( 'bookings' ),
			array(
				'fare_total'         => $new_total,
				'fare_snapshot_json' => wp_json_encode( $snapshot ),
			),
			array( 'id' => $booking_id ),
			array( '%f', '%s' ),
			array( '%d' )
		);

		$wpdb->insert(
			Schema::table( 'booking_events' ),
			array(
				'booking_id' => $booking_id,
				'event_type' => 'amendment',
				'actor_id'   => $actor_id,
				'message'    => $line,
				'meta_json'  => wp_json_encode(
					array(
						'adjustment_id'       => $adj_id,
						'type'                => $type,
						'qty'                 => $qty,
						'amount'              => $amount,
						'original_fare_total' => $original,
						'fare_total'          => $new_total,
						'notify'              => $notify,
					)
				),
			),
			array( '%d', '%s', '%d', '%s', '%s' )
		);

		if ( $notify ) {
			Queue::on_updated( $booking_id, false );
		}

		return array(
			'adjustment_id'       => $adj_id,
			'amount'              => $amount,
			'original_fare_total' => $original,
			'fare_total'          => $new_total,
			'notified'            => $notify,
		);
	}

	/**
	 * @param array $booking Booking row.
	 * @return float
	 */
	private static function hourly_rate_for_booking( array $booking ) {
		$snapshot = json_decode( (string) ( $booking['fare_snapshot_json'] ?? '' ), true );
		if ( is_array( $snapshot ) && ! empty( $snapshot['breakdown']['hourly_rate'] ) ) {
			return (float) $snapshot['breakdown']['hourly_rate'];
		}

		global $wpdb;
		$service_id = absint( $booking['service_id'] ?? 0 );
		if ( ! $service_id ) {
			return 0.0;
		}
		$rate = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT hourly_rate FROM ' . Schema::table( 'hourly_rates' ) . ' WHERE service_id = %d AND is_active = 1 ORDER BY id ASC LIMIT 1',
				$service_id
			)
		);
		if ( null !== $rate && '' !== $rate ) {
			return (float) $rate;
		}

		// Fallback: any active hourly rate (route jobs amended with extra hours).
		$any = $wpdb->get_var(
			'SELECT hourly_rate FROM ' . Schema::table( 'hourly_rates' ) . ' WHERE is_active = 1 ORDER BY id ASC LIMIT 1'
		);
		return null !== $any && '' !== $any ? (float) $any : 0.0;
	}

	/**
	 * @param string $name Exact addon name.
	 * @param float  $fallback Fallback unit price.
	 * @return float
	 */
	private static function addon_unit_price( $name, $fallback ) {
		global $wpdb;
		$price = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT price FROM ' . Schema::table( 'addons' ) . ' WHERE name = %s ORDER BY id ASC LIMIT 1',
				$name
			)
		);
		if ( null !== $price && '' !== $price ) {
			return (float) $price;
		}
		return (float) $fallback;
	}
}
