<?php
/**
 * Enqueue lifecycle notification jobs.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Messaging;

use Prime_Fleet\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class Queue {

	/**
	 * @param string     $job_type Job type.
	 * @param int        $booking_id Booking ID.
	 * @param array      $extra Extra payload fields.
	 * @param string|null $run_at MySQL datetime (site local) or null = now.
	 * @return int Job ID (0 on failure).
	 */
	public static function enqueue( $job_type, $booking_id, array $extra = array(), $run_at = null ) {
		global $wpdb;

		$booking_id = absint( $booking_id );
		if ( ! $booking_id || ! $job_type ) {
			return 0;
		}

		$payload = array_merge(
			array( 'booking_id' => $booking_id ),
			$extra
		);

		$ok = $wpdb->insert(
			Schema::table( 'jobs' ),
			array(
				'job_type'     => sanitize_key( $job_type ),
				'payload_json' => wp_json_encode( $payload ),
				'status'       => 'pending',
				'run_at'       => $run_at ? $run_at : current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * After staff confirms a booking.
	 *
	 * @param int $booking_id Booking ID.
	 */
	public static function on_confirmed( $booking_id ) {
		self::enqueue( 'booking_confirmed_notify', $booking_id );
		self::schedule_reminders( $booking_id );
	}

	/**
	 * After booking cancelled.
	 *
	 * @param int $booking_id Booking ID.
	 */
	public static function on_cancelled( $booking_id ) {
		self::cancel_pending_reminders( $booking_id );
		self::enqueue( 'booking_cancelled_notify', $booking_id );
	}

	/**
	 * After pickup / material booking change.
	 *
	 * @param int  $booking_id Booking ID.
	 * @param bool $reschedule_reminders Reschedule if still confirmed.
	 */
	public static function on_updated( $booking_id, $reschedule_reminders = true ) {
		self::enqueue( 'booking_updated_notify', $booking_id );
		if ( $reschedule_reminders ) {
			self::schedule_reminders( $booking_id );
		}
	}

	/**
	 * Payment link issued (M4 or admin test).
	 *
	 * @param int    $booking_id Booking ID.
	 * @param string $payment_link URL.
	 */
	public static function on_payment_link( $booking_id, $payment_link = '' ) {
		self::enqueue(
			'payment_link_notify',
			$booking_id,
			array( 'payment_link' => (string) $payment_link )
		);
	}

	/**
	 * Payment confirmed (M4 or admin test).
	 *
	 * @param int $booking_id Booking ID.
	 */
	public static function on_payment_confirmed( $booking_id ) {
		self::enqueue( 'payment_confirmed_notify', $booking_id );
	}

	/**
	 * Payment refunded (staff Mark refunded or Stripe webhook).
	 *
	 * @param int $booking_id Booking ID.
	 */
	public static function on_payment_refunded( $booking_id ) {
		self::enqueue( 'payment_refunded_notify', $booking_id );
	}

	/**
	 * Cancel pending reminder jobs for a booking.
	 *
	 * @param int $booking_id Booking ID.
	 */
	public static function cancel_pending_reminders( $booking_id ) {
		global $wpdb;
		$booking_id = absint( $booking_id );
		$table      = Schema::table( 'jobs' );
		$types      = array( 'reminder_24h_notify', 'reminder_2h_notify' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT id, payload_json FROM {$table} WHERE status = 'pending' AND job_type IN ('reminder_24h_notify','reminder_2h_notify')",
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$payload = json_decode( (string) $row['payload_json'], true );
			if ( ! is_array( $payload ) || (int) ( $payload['booking_id'] ?? 0 ) !== $booking_id ) {
				continue;
			}
			$wpdb->update(
				$table,
				array(
					'status'     => 'cancelled',
					'last_error' => 'superseded',
				),
				array( 'id' => (int) $row['id'] ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}
		unset( $types );
	}

	/**
	 * Schedule 24h / 2h reminders from pickup time (confirmed bookings only).
	 *
	 * @param int $booking_id Booking ID.
	 */
	public static function schedule_reminders( $booking_id ) {
		global $wpdb;
		$booking_id = absint( $booking_id );

		self::cancel_pending_reminders( $booking_id );

		$booking = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, status, pickup_datetime FROM ' . Schema::table( 'bookings' ) . ' WHERE id = %d',
				$booking_id
			),
			ARRAY_A
		);
		if ( ! $booking || 'confirmed' !== $booking['status'] || empty( $booking['pickup_datetime'] ) ) {
			return;
		}

		$pickup_ts = strtotime( $booking['pickup_datetime'] );
		if ( ! $pickup_ts ) {
			return;
		}

		$settings = get_option( 'prime_fleet_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$map = array(
			'reminder_24h_notify' => array(
				'key'     => 'reminder_24h',
				'setting' => 'reminder_24h',
				'default' => 1440,
			),
			'reminder_2h_notify'  => array(
				'key'     => 'reminder_2h',
				'setting' => 'reminder_2h',
				'default' => 120,
			),
		);

		$now = time();
		foreach ( $map as $job_type => $meta ) {
			$offset = self::reminder_offset_minutes( $meta['key'], $settings, $meta['setting'], $meta['default'] );
			if ( $offset <= 0 ) {
				continue;
			}
			$run_ts = $pickup_ts - ( $offset * MINUTE_IN_SECONDS );
			// Skip if the reminder window already passed.
			if ( $run_ts <= $now || $run_ts >= $pickup_ts ) {
				continue;
			}
			$run_at = wp_date( 'Y-m-d H:i:s', $run_ts );
			if ( ! $run_at ) {
				$run_at = gmdate( 'Y-m-d H:i:s', $run_ts + ( (int) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
			}
			self::enqueue( $job_type, $booking_id, array( 'offset_minutes' => $offset ), $run_at );
		}
	}

	/**
	 * @param string $template_key Template key.
	 * @param array  $settings Settings.
	 * @param string $setting_key Settings key.
	 * @param int    $default Default minutes.
	 * @return int
	 */
	private static function reminder_offset_minutes( $template_key, array $settings, $setting_key, $default ) {
		$tpl = Templates::get( $template_key );
		if ( $tpl && isset( $tpl['reminder_offset_minutes'] ) && '' !== $tpl['reminder_offset_minutes'] && null !== $tpl['reminder_offset_minutes'] ) {
			$mins = (int) $tpl['reminder_offset_minutes'];
			if ( $mins > 0 ) {
				return $mins;
			}
		}
		if ( isset( $settings[ $setting_key ] ) ) {
			return max( 0, (int) $settings[ $setting_key ] );
		}
		return (int) $default;
	}
}
