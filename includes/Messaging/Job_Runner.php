<?php
/**
 * Process queued notification jobs.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Messaging;

use Prime_Fleet\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class Job_Runner {

	/**
	 * Hooks.
	 */
	public function hooks() {
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
		add_action( 'init', array( $this, 'schedule' ) );
		add_action( 'prime_fleet_process_jobs', array( $this, 'run' ) );
		add_action( 'shutdown', array( $this, 'maybe_run_soft' ), 20 );
	}

	/**
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public function cron_schedules( $schedules ) {
		if ( ! isset( $schedules['prime_fleet_five_minutes'] ) ) {
			$schedules['prime_fleet_five_minutes'] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every five minutes (Prime Fleet)', 'prime-fleet' ),
			);
		}
		return $schedules;
	}

	/**
	 * Register WP-Cron (5-minute for timely reminders).
	 */
	public function schedule() {
		$next = wp_next_scheduled( 'prime_fleet_process_jobs' );
		if ( $next ) {
			// Migrate off hourly if still on it.
			$ts = wp_get_scheduled_event( 'prime_fleet_process_jobs' );
			if ( $ts && isset( $ts->schedule ) && 'hourly' === $ts->schedule ) {
				wp_unschedule_event( $next, 'prime_fleet_process_jobs' );
				$next = false;
			}
		}
		if ( ! $next ) {
			wp_schedule_event( time() + 60, 'prime_fleet_five_minutes', 'prime_fleet_process_jobs' );
		}
		Templates::ensure();
	}

	/**
	 * Soft drain a few jobs on request end (admin/front).
	 */
	public function maybe_run_soft() {
		if ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) {
			return;
		}
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		$this->run( 5 );
	}

	/**
	 * @param int $limit Max jobs.
	 */
	public function run( $limit = 10 ) {
		global $wpdb;
		$limit = max( 1, min( 25, (int) $limit ) );
		$table = Schema::table( 'jobs' );
		$now   = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'pending' AND run_at <= %s ORDER BY id ASC LIMIT %d",
				$now,
				$limit
			),
			ARRAY_A
		);
		if ( ! $rows ) {
			return;
		}

		foreach ( $rows as $job ) {
			$claimed = $wpdb->update(
				$table,
				array(
					'status'   => 'processing',
					'attempts' => (int) $job['attempts'] + 1,
				),
				array(
					'id'     => (int) $job['id'],
					'status' => 'pending',
				),
				array( '%s', '%d' ),
				array( '%d', '%s' )
			);
			if ( ! $claimed ) {
				continue;
			}

			$payload  = json_decode( (string) $job['payload_json'], true );
			$payload  = is_array( $payload ) ? $payload : array();
			$progress = isset( $payload['_progress'] ) && is_array( $payload['_progress'] ) ? $payload['_progress'] : array();

			$result = $this->handle( $job, $payload, $progress );

			$payload['_progress'] = $progress;
			$payload_json         = wp_json_encode( $payload );

			if ( is_wp_error( $result ) ) {
				$attempts = (int) $job['attempts'] + 1;
				$wpdb->update(
					$table,
					array(
						'status'       => $attempts >= 5 ? 'failed' : 'pending',
						'last_error'   => $result->get_error_message(),
						'run_at'       => gmdate( 'Y-m-d H:i:s', time() + ( $attempts * 120 ) ),
						'payload_json' => $payload_json,
					),
					array( 'id' => (int) $job['id'] ),
					array( '%s', '%s', '%s', '%s' ),
					array( '%d' )
				);
				continue;
			}

			$wpdb->update(
				$table,
				array(
					'status'       => 'done',
					'last_error'   => '',
					'payload_json' => $payload_json,
				),
				array( 'id' => (int) $job['id'] ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
		}
	}

	/**
	 * @param array $job Job row.
	 * @param array $payload Payload.
	 * @param array $progress Progress (by ref).
	 * @return true|\WP_Error
	 */
	private function handle( array $job, array $payload, array &$progress ) {
		$type       = (string) $job['job_type'];
		$booking_id = (int) ( $payload['booking_id'] ?? 0 );
		$extra      = array();
		if ( ! empty( $payload['payment_link'] ) ) {
			$extra['payment_link'] = (string) $payload['payment_link'];
		}

		$map = array(
			'booking_created_notify'    => 'booking_received',
			'booking_confirmed_notify'  => 'booking_confirmed',
			'booking_updated_notify'    => 'booking_updated',
			'booking_cancelled_notify'  => 'booking_cancelled',
			'assignment_notify'         => 'chauffeur_vehicle',
			'payment_link_notify'       => 'payment_link',
			'payment_confirmed_notify'  => 'payment_confirmed',
			'payment_refunded_notify'   => 'payment_refunded',
			'reminder_24h_notify'       => 'reminder_24h',
			'reminder_2h_notify'        => 'reminder_2h',
		);

		if ( isset( $map[ $type ] ) ) {
			if ( in_array( $type, array( 'reminder_24h_notify', 'reminder_2h_notify' ), true )
				&& ! $this->reminder_is_sendable( $booking_id ) ) {
				return true; // Superseded — mark done without send.
			}
			return Notifier::send( $map[ $type ], $booking_id, $extra, $progress );
		}

		if ( 'slot_offer_notify' === $type ) {
			return $this->slot_offer( $payload, $progress );
		}

		return true; // Unknown job types: mark done so they do not block.
	}

	/**
	 * @param int $booking_id Booking ID.
	 * @return bool
	 */
	private function reminder_is_sendable( $booking_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT status, pickup_datetime FROM ' . Schema::table( 'bookings' ) . ' WHERE id = %d',
				absint( $booking_id )
			),
			ARRAY_A
		);
		if ( ! $row || 'confirmed' !== $row['status'] ) {
			return false;
		}
		$pickup = strtotime( $row['pickup_datetime'] );
		if ( ! $pickup || $pickup <= time() ) {
			return false;
		}
		return true;
	}

	/**
	 * @param array $payload Payload.
	 * @param array $progress Progress.
	 * @return true|\WP_Error
	 */
	private function slot_offer( array $payload, array &$progress ) {
		global $wpdb;
		$booking_id = (int) ( $payload['booking_id'] ?? 0 );
		$offer_id   = (int) ( $payload['offer_id'] ?? 0 );
		$token      = (string) ( $payload['token'] ?? '' );

		$offer = null;
		if ( $offer_id ) {
			$offer = $wpdb->get_row(
				$wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'slot_offers' ) . ' WHERE id = %d', $offer_id ),
				ARRAY_A
			);
		}
		if ( ! $offer && $token ) {
			$offer = $wpdb->get_row(
				$wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'slot_offers' ) . ' WHERE token = %s', $token ),
				ARRAY_A
			);
		}
		if ( ! $offer ) {
			return new \WP_Error( 'prime_fleet_offer_missing', 'Slot offer not found.' );
		}

		$token = $token ? $token : (string) $offer['token'];
		$url   = home_url( '/?prime_fleet_offer=' . rawurlencode( $token ) );

		return Notifier::send(
			'slot_offer',
			$booking_id ? $booking_id : (int) $offer['booking_id'],
			array(
				'offer_datetime'   => date_i18n( 'D, M j, Y · g:i A', strtotime( $offer['proposed_datetime'] ) ),
				'offer_accept_url' => $url,
				'offer_expires'    => ! empty( $offer['expires_at'] )
					? date_i18n( 'M j, Y g:i A', strtotime( $offer['expires_at'] ) )
					: '',
			),
			$progress
		);
	}
}
