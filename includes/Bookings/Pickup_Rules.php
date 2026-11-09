<?php
/**
 * Guest pickup date/time rules (timezone + min advance).
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Bookings;

defined( 'ABSPATH' ) || exit;

final class Pickup_Rules {

	const DEFAULT_MIN_ADVANCE_HOURS = 2.0;

	/**
	 * Plugin booking timezone.
	 *
	 * @return \DateTimeZone
	 */
	public static function timezone() {
		$settings = get_option( 'prime_fleet_settings', array() );
		$name     = isset( $settings['timezone'] ) ? (string) $settings['timezone'] : 'UTC';
		try {
			return new \DateTimeZone( $name );
		} catch ( \Exception $e ) {
			return new \DateTimeZone( 'UTC' );
		}
	}

	/**
	 * Min advance hours (default 2 when unset/empty).
	 *
	 * @return float
	 */
	public static function min_advance_hours() {
		$settings = get_option( 'prime_fleet_settings', array() );
		if ( ! isset( $settings['min_advance_hours'] ) || '' === $settings['min_advance_hours'] ) {
			return self::DEFAULT_MIN_ADVANCE_HOURS;
		}
		$hours = (float) $settings['min_advance_hours'];
		return $hours > 0 ? $hours : self::DEFAULT_MIN_ADVANCE_HOURS;
	}

	/**
	 * Whether same-day bookings are allowed.
	 *
	 * @return bool
	 */
	public static function same_day_allowed() {
		$settings = get_option( 'prime_fleet_settings', array() );
		if ( ! array_key_exists( 'same_day_allowed', $settings ) ) {
			return true;
		}
		return ! empty( $settings['same_day_allowed'] );
	}

	/**
	 * Slot increment minutes.
	 *
	 * @return int
	 */
	public static function slot_increment() {
		$settings = get_option( 'prime_fleet_settings', array() );
		$slot     = isset( $settings['slot_increment'] ) ? (int) $settings['slot_increment'] : 30;
		return in_array( $slot, array( 15, 30, 60 ), true ) ? $slot : 30;
	}

	/**
	 * Now in booking timezone.
	 *
	 * @return \DateTimeImmutable
	 */
	public static function now() {
		return new \DateTimeImmutable( 'now', self::timezone() );
	}

	/**
	 * Earliest selectable pickup (now + min advance, seconds cleared).
	 *
	 * @return \DateTimeImmutable
	 */
	public static function earliest_pickup() {
		$minutes = (int) ceil( self::min_advance_hours() * 60 );
		$earliest = self::now()->modify( '+' . max( 1, $minutes ) . ' minutes' );
		return $earliest->setTime( (int) $earliest->format( 'H' ), (int) $earliest->format( 'i' ), 0 );
	}

	/**
	 * Guest-safe live fields (merged at request time; not cached).
	 *
	 * @return array
	 */
	public static function public_rules() {
		$now      = self::now();
		$earliest = self::earliest_pickup();
		$min_date = $earliest->format( 'Y-m-d' );
		if ( ! self::same_day_allowed() && $min_date === $now->format( 'Y-m-d' ) ) {
			$min_date = $now->modify( '+1 day' )->format( 'Y-m-d' );
		}
		return array(
			'min_advance_hours' => self::min_advance_hours(),
			'same_day_allowed'  => self::same_day_allowed(),
			'slot_increment'    => self::slot_increment(),
			'timezone'          => self::timezone()->getName(),
			'today'             => $now->format( 'Y-m-d' ),
			'now'               => $now->format( 'Y-m-d H:i:s' ),
			'earliest_pickup'   => $earliest->format( 'Y-m-d H:i' ),
			'min_date'          => $min_date,
		);
	}

	/**
	 * Validate a pickup date + time (H:i).
	 *
	 * @param string $date Y-m-d.
	 * @param string $time H:i.
	 * @return true|\WP_Error
	 */
	public static function validate( $date, $time ) {
		$date = (string) $date;
		$time = substr( (string) $time, 0, 5 );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
			return new \WP_Error( 'prime_fleet_pickup', __( 'Pickup date and time are required.', 'prime-fleet' ), array( 'status' => 400 ) );
		}

		$tz     = self::timezone();
		$pickup = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $date . ' ' . $time, $tz );
		if ( ! $pickup || $pickup->format( 'Y-m-d H:i' ) !== $date . ' ' . $time ) {
			return new \WP_Error( 'prime_fleet_pickup', __( 'Invalid pickup date or time.', 'prime-fleet' ), array( 'status' => 400 ) );
		}

		$now      = self::now();
		$earliest = self::earliest_pickup();

		if ( $date < $now->format( 'Y-m-d' ) ) {
			return new \WP_Error( 'prime_fleet_pickup_past', __( 'Pickup date cannot be in the past.', 'prime-fleet' ), array( 'status' => 400 ) );
		}

		if ( ! self::same_day_allowed() && $date === $now->format( 'Y-m-d' ) ) {
			return new \WP_Error(
				'prime_fleet_pickup_same_day',
				__( 'Same-day bookings are not available. Please choose a later date.', 'prime-fleet' ),
				array( 'status' => 400 )
			);
		}

		if ( $pickup < $earliest ) {
			$hours = self::min_advance_hours();
			return new \WP_Error(
				'prime_fleet_pickup_advance',
				sprintf(
					/* translators: %s: hours */
					__( 'Pickup must be at least %s hours from now.', 'prime-fleet' ),
					rtrim( rtrim( number_format( $hours, 1 ), '0' ), '.' )
				),
				array( 'status' => 400 )
			);
		}

		return true;
	}
}
