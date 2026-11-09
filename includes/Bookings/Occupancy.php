<?php
/**
 * Vehicle occupancy windows (not Amelia clinic slots).
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Bookings;

use Prime_Fleet\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class Occupancy {

	/**
	 * Default trip length when service does not specify hours.
	 */
	const DEFAULT_DURATION_MIN = 120;

	/**
	 * Turnaround buffer between jobs.
	 */
	const DEFAULT_BUFFER_MIN = 30;

	/**
	 * Check if a pickup window is free.
	 *
	 * @param string $pickup_datetime Y-m-d H:i:s.
	 * @param array  $args vehicle_id, duration_min, exclude_booking_id, service_id, hours.
	 * @return array
	 */
	public static function check( $pickup_datetime, array $args = array() ) {
		$pickup = self::normalize_dt( $pickup_datetime );
		if ( ! $pickup ) {
			return array(
				'available'     => false,
				'message'       => __( 'Pickup date and time are required.', 'prime-fleet' ),
				'alternatives'  => array(),
				'vehicle_id'    => 0,
				'busy_vehicles' => array(),
			);
		}

		$vehicle_id = absint( $args['vehicle_id'] ?? 0 );
		$exclude    = absint( $args['exclude_booking_id'] ?? 0 );
		$duration   = self::resolve_duration( $args );
		$buffer     = self::buffer_minutes();
		$span       = max( 30, $duration + $buffer );

		$vehicles = self::active_vehicles();
		if ( ! $vehicles ) {
			return array(
				'available'     => true,
				'message'       => __( 'No fleet capacity configured — staff will confirm manually.', 'prime-fleet' ),
				'alternatives'  => array(),
				'vehicle_id'    => $vehicle_id,
				'busy_vehicles' => array(),
				'manual'        => true,
			);
		}

		$busy_map = self::busy_vehicle_ids( $pickup, $span, $exclude );
		$free     = array();
		foreach ( $vehicles as $v ) {
			$vid = (int) $v['id'];
			if ( ! isset( $busy_map[ $vid ] ) ) {
				$free[] = $vid;
			}
		}

		if ( $vehicle_id ) {
			$ok = in_array( $vehicle_id, $free, true );
			$out = array(
				'available'     => $ok,
				'conflict'      => $ok ? '' : 'vehicle',
				'message'       => $ok
					? __( 'Vehicle available for this window.', 'prime-fleet' )
					: __( 'Selected vehicle is busy in this window.', 'prime-fleet' ),
				'alternatives'  => array(),
				'vehicle_id'    => $vehicle_id,
				'busy_vehicles' => array_keys( $busy_map ),
				'duration_min'  => $duration,
				'buffer_min'    => $buffer,
			);
			if ( ! $ok ) {
				$out['alternatives'] = self::alternatives( $pickup, $span, $exclude, $vehicle_id );
			}
			return $out;
		}

		$ok = ! empty( $free );
		$out = array(
			'available'     => $ok,
			'conflict'      => $ok ? '' : 'slot',
			'message'       => $ok
				? __( 'A vehicle is available for this window.', 'prime-fleet' )
				: __( 'All vehicles are busy in this window.', 'prime-fleet' ),
			'alternatives'  => array(),
			'vehicle_id'    => $ok ? (int) $free[0] : 0,
			'free_vehicles' => $free,
			'busy_vehicles' => array_keys( $busy_map ),
			'duration_min'  => $duration,
			'buffer_min'    => $buffer,
		);
		if ( ! $ok ) {
			$out['alternatives'] = self::alternatives( $pickup, $span, $exclude, 0 );
		}
		return $out;
	}

	/**
	 * @param array $args Args.
	 * @return int
	 */
	private static function resolve_duration( array $args ) {
		if ( ! empty( $args['duration_min'] ) ) {
			return max( 30, (int) $args['duration_min'] );
		}
		if ( ! empty( $args['hours'] ) ) {
			return max( 30, (int) round( (float) $args['hours'] * 60 ) );
		}
		return self::DEFAULT_DURATION_MIN;
	}

	/**
	 * @return int
	 */
	private static function buffer_minutes() {
		$settings = get_option( 'prime_fleet_settings', array() );
		if ( isset( $settings['occupancy_buffer_minutes'] ) && '' !== $settings['occupancy_buffer_minutes'] ) {
			return max( 0, (int) $settings['occupancy_buffer_minutes'] );
		}
		return self::DEFAULT_BUFFER_MIN;
	}

	/**
	 * @return array
	 */
	private static function active_vehicles() {
		global $wpdb;
		$rows = $wpdb->get_results(
			'SELECT id, name FROM ' . Schema::table( 'vehicles' ) . '
			WHERE is_active = 1 AND available_for_booking = 1
			ORDER BY display_order ASC, id ASC',
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Map of vehicle_id => conflicting reference.
	 *
	 * @param string $pickup Pickup.
	 * @param int    $span Minutes.
	 * @param int    $exclude Booking id.
	 * @return array<int,string>
	 */
	private static function busy_vehicle_ids( $pickup, $span, $exclude ) {
		global $wpdb;
		$ts    = strtotime( $pickup );
		$start = date( 'Y-m-d H:i:s', $ts - ( $span * 60 ) );
		$end   = date( 'Y-m-d H:i:s', $ts + ( $span * 60 ) );

		$sql = 'SELECT id, reference, assigned_vehicle_id FROM ' . Schema::table( 'bookings' ) . '
			WHERE status IN (\'pending_confirmation\',\'confirmed\')
			AND assigned_vehicle_id > 0
			AND pickup_datetime IS NOT NULL
			AND pickup_datetime >= %s AND pickup_datetime <= %s';
		$params = array( $start, $end );
		if ( $exclude ) {
			$sql     .= ' AND id <> %d';
			$params[] = $exclude;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$busy = array();
		foreach ( (array) $rows as $row ) {
			$vid = (int) $row['assigned_vehicle_id'];
			if ( $vid && ! isset( $busy[ $vid ] ) ) {
				$busy[ $vid ] = (string) $row['reference'];
			}
		}

		// Availability blocks on vehicles.
		$blocks = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT resource_id, reason FROM ' . Schema::table( 'availability_blocks' ) . '
				WHERE resource_type = %s
				AND start_datetime < %s AND end_datetime > %s',
				'vehicle',
				$end,
				$start
			),
			ARRAY_A
		);
		foreach ( (array) $blocks as $b ) {
			$vid = (int) $b['resource_id'];
			if ( $vid && ! isset( $busy[ $vid ] ) ) {
				$busy[ $vid ] = 'block:' . (string) $b['reason'];
			}
		}

		return $busy;
	}

	/**
	 * Nearby free windows.
	 *
	 * @param string $pickup Pickup.
	 * @param int    $span Span.
	 * @param int    $exclude Exclude.
	 * @param int    $vehicle_id Specific vehicle or 0.
	 * @return array
	 */
	private static function alternatives( $pickup, $span, $exclude, $vehicle_id ) {
		$settings = get_option( 'prime_fleet_settings', array() );
		$step     = isset( $settings['slot_increment'] ) ? (int) $settings['slot_increment'] : 30;
		if ( ! in_array( $step, array( 15, 30, 60 ), true ) ) {
			$step = 30;
		}
		$offsets = array( $step, $step * 2, $step * 3, $step * 4, -$step, -$step * 2, $step * 6, $step * 8 );
		$out     = array();
		$base    = strtotime( $pickup );
		foreach ( $offsets as $mins ) {
			$candidate = date( 'Y-m-d H:i:s', $base + ( $mins * 60 ) );
			if ( $candidate < date( 'Y-m-d H:i:s' ) ) {
				continue;
			}
			$busy = self::busy_vehicle_ids( $candidate, $span, $exclude );
			$ok   = false;
			if ( $vehicle_id ) {
				$ok = ! isset( $busy[ $vehicle_id ] );
			} else {
				$vehicles = self::active_vehicles();
				foreach ( $vehicles as $v ) {
					if ( ! isset( $busy[ (int) $v['id'] ] ) ) {
						$ok = true;
						break;
					}
				}
			}
			if ( $ok ) {
				$out[] = array(
					'datetime' => $candidate,
					'label'    => date( 'D, M j · g:i A', strtotime( $candidate ) ),
				);
			}
			if ( count( $out ) >= 4 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * @param string $dt Datetime.
	 * @return string|null
	 */
	private static function normalize_dt( $dt ) {
		$dt = str_replace( 'T', ' ', trim( (string) $dt ) );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}/', $dt ) ) {
			if ( 16 === strlen( $dt ) ) {
				$dt .= ':00';
			}
			return substr( $dt, 0, 19 );
		}
		return null;
	}
}
