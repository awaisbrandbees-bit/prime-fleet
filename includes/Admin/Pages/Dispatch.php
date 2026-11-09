<?php
/**
 * Dispatch day board (staff).
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Admin\Pages;

use Prime_Fleet\Admin\View;
use Prime_Fleet\Bookings\Occupancy;
use Prime_Fleet\Database\Schema;
use Prime_Fleet\Install\Capabilities;
use Prime_Fleet\Messaging\Queue;

defined( 'ABSPATH' ) || exit;

final class Dispatch {

	const NONCE = 'prime_fleet_dispatch';

	/**
	 * Render day board.
	 */
	public static function render() {
		if ( ! Capabilities::current_user_can( Capabilities::DISPATCH ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'prime-fleet' ) );
		}

		global $wpdb;

		$notice = '';
		if ( ! empty( $_POST['prime_fleet_dispatch_action'] ) && check_admin_referer( self::NONCE ) ) {
			$notice = self::handle_action();
		}

		$day = isset( $_GET['day'] ) ? sanitize_text_field( wp_unslash( $_GET['day'] ) ) : current_time( 'Y-m-d' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
			$day = current_time( 'Y-m-d' );
		}

		$prev_day = gmdate( 'Y-m-d', strtotime( $day . ' -1 day' ) );
		$next_day = gmdate( 'Y-m-d', strtotime( $day . ' +1 day' ) );
		$today    = current_time( 'Y-m-d' );

		$start = $day . ' 00:00:00';
		$end   = $day . ' 23:59:59';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT b.*, s.name AS service_name
				FROM ' . Schema::table( 'bookings' ) . ' b
				LEFT JOIN ' . Schema::table( 'services' ) . ' s ON s.id = b.service_id
				WHERE b.pickup_datetime >= %s AND b.pickup_datetime <= %s
				AND b.status IN (\'pending_confirmation\',\'confirmed\')
				ORDER BY b.pickup_datetime DESC, b.id DESC',
				$start,
				$end
			),
			ARRAY_A
		);

		$vehicles = $wpdb->get_results(
			'SELECT id, name, customer_display_name FROM ' . Schema::table( 'vehicles' ) . ' WHERE is_active = 1 ORDER BY display_order ASC, id ASC',
			ARRAY_A
		);
		$chauffeurs = $wpdb->get_results(
			'SELECT id, name FROM ' . Schema::table( 'chauffeurs' ) . ' WHERE is_active = 1 ORDER BY name ASC',
			ARRAY_A
		);

		$vehicle_names = array();
		foreach ( (array) $vehicles as $v ) {
			$vehicle_names[ (int) $v['id'] ] = $v['customer_display_name'] ? $v['customer_display_name'] : $v['name'];
		}

		$pending_count = 0;
		foreach ( (array) $rows as $r ) {
			if ( 'pending_confirmation' === $r['status'] ) {
				++$pending_count;
			}
		}

		$day_label = date_i18n( get_option( 'date_format' ) ? get_option( 'date_format' ) : 'M j, Y', strtotime( $day . ' 12:00:00' ) );

		ob_start();
		if ( $notice ) {
			echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '<form class="prime-fleet-dispatch-bar" data-pf-table-filter="1" data-pf-panel="dispatch">';
		echo '<div class="prime-fleet-dispatch-bar__day">';
		echo '<label class="prime-fleet-dispatch-bar__label" for="pf-dispatch-day">' . esc_html__( 'Board date', 'prime-fleet' ) . '</label>';
		echo '<div class="prime-fleet-dispatch-bar__controls">';
		echo '<button type="button" class="prime-fleet-btn prime-fleet-btn--ghost prime-fleet-dispatch-bar__nav" data-pf-panel="dispatch" data-pf-query="' . esc_attr( wp_json_encode( array( 'day' => $prev_day ) ) ) . '" aria-label="' . esc_attr__( 'Previous day', 'prime-fleet' ) . '">‹</button>';
		echo '<input type="date" id="pf-dispatch-day" class="prime-fleet-dispatch-bar__input" name="day" value="' . esc_attr( $day ) . '" />';
		echo '<button type="button" class="prime-fleet-btn prime-fleet-btn--ghost prime-fleet-dispatch-bar__nav" data-pf-panel="dispatch" data-pf-query="' . esc_attr( wp_json_encode( array( 'day' => $next_day ) ) ) . '" aria-label="' . esc_attr__( 'Next day', 'prime-fleet' ) . '">›</button>';
		echo '<button type="submit" class="prime-fleet-btn">' . esc_html__( 'Load day', 'prime-fleet' ) . '</button>';
		if ( $day !== $today ) {
			echo '<button type="button" class="prime-fleet-btn prime-fleet-btn--ghost" data-pf-panel="dispatch" data-pf-query="' . esc_attr( wp_json_encode( array( 'day' => $today ) ) ) . '">' . esc_html__( 'Today', 'prime-fleet' ) . '</button>';
		}
		echo '</div></div>';
		echo '<div class="prime-fleet-dispatch-bar__meta">';
		echo '<strong>' . esc_html( $day_label ) . '</strong>';
		echo '<span class="prime-fleet-muted">' . esc_html( sprintf( /* translators: %d count */ _n( '%d job', '%d jobs', count( (array) $rows ), 'prime-fleet' ), count( (array) $rows ) ) ) . '</span>';
		if ( $pending_count ) {
			echo '<span class="prime-fleet-dispatch-pill">' . esc_html( sprintf( /* translators: %d count */ _n( '%d pending', '%d pending', $pending_count, 'prime-fleet' ), $pending_count ) ) . '</span>';
		}
		echo '</div></form>';

		echo '<div class="prime-fleet-card prime-fleet-dispatch-board">';
		echo '<div class="prime-fleet-dispatch-board__head">';
		echo '<h3 class="prime-fleet-dispatch-board__title">' . esc_html__( 'Day board', 'prime-fleet' ) . '</h3>';
		echo '<p class="prime-fleet-muted" style="margin:0">' . esc_html__( 'Assign fleet and confirm pending pickups for this day.', 'prime-fleet' ) . '</p>';
		echo '</div>';

		if ( empty( $rows ) ) {
			echo View::empty_state(
				__( 'No jobs this day', 'prime-fleet' ),
				__( 'Pending and confirmed bookings with a pickup on this date will appear here.', 'prime-fleet' )
			);
		} else {
			echo '<div class="prime-fleet-dispatch-timeline" role="list">';
			foreach ( $rows as $r ) {
				$conflict = false;
				if ( (int) $r['assigned_vehicle_id'] && ! empty( $r['pickup_datetime'] ) ) {
					$occ = Occupancy::check(
						$r['pickup_datetime'],
						array(
							'vehicle_id'         => (int) $r['assigned_vehicle_id'],
							'exclude_booking_id' => (int) $r['id'],
						)
					);
					if ( empty( $occ['available'] ) ) {
						$conflict = true;
					}
				}

				$time         = $r['pickup_datetime'] ? date_i18n( 'g:i A', strtotime( $r['pickup_datetime'] ) ) : '—';
				$status_class = 'pending_confirmation' === $r['status'] ? 'is-pending' : 'is-confirmed';
				$card_class   = 'prime-fleet-dispatch-job' . ( $conflict ? ' has-conflict' : '' );

				echo '<article class="' . esc_attr( $card_class ) . '" role="listitem">';
				echo '<div class="prime-fleet-dispatch-job__rail" aria-hidden="true"><span class="prime-fleet-dispatch-job__dot"></span></div>';
				echo '<div class="prime-fleet-dispatch-job__body">';
				echo '<header class="prime-fleet-dispatch-job__head">';
				echo '<div class="prime-fleet-dispatch-job__when"><span class="prime-fleet-dispatch-job__time">' . esc_html( $time ) . '</span></div>';
				echo '<span class="prime-fleet-dispatch-status ' . esc_attr( $status_class ) . '">' . esc_html( self::status_label( $r['status'] ) ) . '</span>';
				echo '</header>';

				echo '<div class="prime-fleet-dispatch-job__main">';
				echo '<button type="button" class="prime-fleet-dispatch-job__ref" data-pf-panel="bookings" data-pf-query="' . esc_attr( wp_json_encode( array( 'id' => (int) $r['id'] ) ) ) . '">' . esc_html( $r['reference'] ) . '</button>';
				echo '<p class="prime-fleet-dispatch-job__service">' . esc_html( $r['service_name'] ? $r['service_name'] : '—' ) . '</p>';
				echo '<p class="prime-fleet-dispatch-job__guest">' . esc_html( $r['customer_name'] );
				echo ' <span class="prime-fleet-muted">· ' . esc_html( (string) (int) $r['passenger_count'] ) . ' pax</span></p>';
				if ( $conflict ) {
					echo '<p class="prime-fleet-dispatch-conflict">' . esc_html__( 'Vehicle conflict in this window', 'prime-fleet' ) . '</p>';
				}
				echo '</div>';

				echo '<form method="post" class="prime-fleet-dispatch-assign">';
				wp_nonce_field( self::NONCE );
				echo '<input type="hidden" name="prime_fleet_dispatch_action" value="assign" />';
				echo '<input type="hidden" name="booking_id" value="' . esc_attr( (string) (int) $r['id'] ) . '" />';
				echo '<input type="hidden" name="day" value="' . esc_attr( $day ) . '" />';
				echo '<div class="prime-fleet-dispatch-assign__fields">';
				echo '<label class="prime-fleet-dispatch-assign__field"><span>' . esc_html__( 'Vehicle', 'prime-fleet' ) . '</span>';
				echo '<select name="assigned_vehicle_id" aria-label="' . esc_attr__( 'Vehicle', 'prime-fleet' ) . '">';
				echo '<option value="0">' . esc_html__( 'Unassigned', 'prime-fleet' ) . '</option>';
				foreach ( (array) $vehicles as $v ) {
					printf(
						'<option value="%1$d" %2$s>%3$s</option>',
						(int) $v['id'],
						selected( (int) $r['assigned_vehicle_id'], (int) $v['id'], false ),
						esc_html( $vehicle_names[ (int) $v['id'] ] )
					);
				}
				echo '</select></label>';
				echo '<label class="prime-fleet-dispatch-assign__field"><span>' . esc_html__( 'Chauffeur', 'prime-fleet' ) . '</span>';
				echo '<select name="assigned_chauffeur_id" aria-label="' . esc_attr__( 'Chauffeur', 'prime-fleet' ) . '">';
				echo '<option value="0">' . esc_html__( 'Unassigned', 'prime-fleet' ) . '</option>';
				foreach ( (array) $chauffeurs as $c ) {
					printf(
						'<option value="%1$d" %2$s>%3$s</option>',
						(int) $c['id'],
						selected( (int) $r['assigned_chauffeur_id'], (int) $c['id'], false ),
						esc_html( $c['name'] )
					);
				}
				echo '</select></label>';
				echo '</div>';
				echo '<div class="prime-fleet-dispatch-assign__actions">';
				echo '<button type="submit" class="prime-fleet-btn prime-fleet-btn--ghost">' . esc_html__( 'Save assign', 'prime-fleet' ) . '</button>';
				if ( 'pending_confirmation' === $r['status'] ) {
					echo '<button type="submit" name="prime_fleet_dispatch_action" value="confirm" class="prime-fleet-btn">' . esc_html__( 'Confirm', 'prime-fleet' ) . '</button>';
				}
				echo '</div></form>';

				echo '</div></article>';
			}
			echo '</div>';
		}
		echo '</div>';

		View::shell( __( 'Dispatch', 'prime-fleet' ), ob_get_clean() );
	}

	/**
	 * @return string Notice HTML.
	 */
	private static function handle_action() {
		global $wpdb;
		$action = sanitize_key( wp_unslash( $_POST['prime_fleet_dispatch_action'] ?? '' ) );
		$id     = absint( $_POST['booking_id'] ?? 0 );
		if ( ! $id ) {
			return View::notice( __( 'Missing booking.', 'prime-fleet' ), 'error' );
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'bookings' ) . ' WHERE id = %d', $id ),
			ARRAY_A
		);
		if ( ! $row ) {
			return View::notice( __( 'Booking not found.', 'prime-fleet' ), 'error' );
		}

		if ( 'assign' === $action ) {
			$vehicle   = absint( $_POST['assigned_vehicle_id'] ?? 0 );
			$chauffeur = absint( $_POST['assigned_chauffeur_id'] ?? 0 );
			$warn      = '';
			if ( $vehicle && ! empty( $row['pickup_datetime'] ) ) {
				$occ = Occupancy::check(
					$row['pickup_datetime'],
					array(
						'vehicle_id'         => $vehicle,
						'exclude_booking_id' => $id,
					)
				);
				if ( empty( $occ['available'] ) ) {
					$warn = ' ' . __( 'Warning: vehicle may conflict in this window.', 'prime-fleet' );
				}
			}
			$wpdb->update(
				Schema::table( 'bookings' ),
				array(
					'assigned_vehicle_id'   => $vehicle,
					'assigned_chauffeur_id' => $chauffeur,
				),
				array( 'id' => $id ),
				array( '%d', '%d' ),
				array( '%d' )
			);
			$wpdb->insert(
				Schema::table( 'booking_events' ),
				array(
					'booking_id' => $id,
					'event_type' => 'assigned',
					'actor_id'   => get_current_user_id(),
					'message'    => 'Dispatch assignment updated',
					'meta_json'  => wp_json_encode(
						array(
							'vehicle_id'   => $vehicle,
							'chauffeur_id' => $chauffeur,
						)
					),
				),
				array( '%d', '%s', '%d', '%s', '%s' )
			);
			// Guest email with chauffeur / vehicle details (lifecycle: Chauffeur & Vehicle Info).
			if ( $vehicle || $chauffeur ) {
				Queue::enqueue( 'assignment_notify', $id );
			}
			return View::notice( __( 'Assignment saved.', 'prime-fleet' ) . $warn, $warn ? 'info' : 'success' );
		}

		if ( 'confirm' === $action ) {
			$vehicle   = absint( $_POST['assigned_vehicle_id'] ?? $row['assigned_vehicle_id'] );
			$chauffeur = absint( $_POST['assigned_chauffeur_id'] ?? $row['assigned_chauffeur_id'] );
			$wpdb->update(
				Schema::table( 'bookings' ),
				array(
					'status'                => 'confirmed',
					'assigned_vehicle_id'   => $vehicle,
					'assigned_chauffeur_id' => $chauffeur,
				),
				array( 'id' => $id ),
				array( '%s', '%d', '%d' ),
				array( '%d' )
			);
			$wpdb->insert(
				Schema::table( 'booking_events' ),
				array(
					'booking_id' => $id,
					'event_type' => 'status_changed',
					'actor_id'   => get_current_user_id(),
					'message'    => 'Confirmed from Dispatch',
					'meta_json'  => wp_json_encode( array( 'status' => 'confirmed' ) ),
				),
				array( '%d', '%s', '%d', '%s', '%s' )
			);
			if ( 'confirmed' !== $row['status'] ) {
				Queue::on_confirmed( $id );
			} else {
				Queue::schedule_reminders( $id );
			}
			if ( $vehicle || $chauffeur ) {
				Queue::enqueue( 'assignment_notify', $id );
			}
			do_action( 'prime_fleet_booking_status_changed', $id, 'confirmed', $row['status'] );
			return View::notice( __( 'Booking confirmed.', 'prime-fleet' ), 'success' );
		}

		return '';
	}

	/**
	 * @param string $status Status.
	 * @return string
	 */
	private static function status_label( $status ) {
		$map = array(
			'pending_confirmation' => __( 'Pending Confirmation', 'prime-fleet' ),
			'confirmed'            => __( 'Confirmed', 'prime-fleet' ),
			'cancelled'            => __( 'Cancelled', 'prime-fleet' ),
			'completed'            => __( 'Completed', 'prime-fleet' ),
		);
		return isset( $map[ $status ] ) ? $map[ $status ] : $status;
	}
}
