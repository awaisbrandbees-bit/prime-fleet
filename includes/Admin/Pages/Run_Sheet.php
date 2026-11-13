<?php
/**
 * Printable day run sheet — assigned jobs only (HTML + print CSS).
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Admin\Pages;

use Prime_Fleet\Admin\View;
use Prime_Fleet\Database\Schema;
use Prime_Fleet\Install\Capabilities;

defined( 'ABSPATH' ) || exit;

final class Run_Sheet {

	/**
	 * Render run sheet for one day.
	 */
	public static function render() {
		if ( ! Capabilities::current_user_can( Capabilities::DISPATCH ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'prime-fleet' ) );
		}

		global $wpdb;

		$day = isset( $_GET['day'] ) ? sanitize_text_field( wp_unslash( $_GET['day'] ) ) : current_time( 'Y-m-d' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
			$day = current_time( 'Y-m-d' );
		}

		$prev_day = gmdate( 'Y-m-d', strtotime( $day . ' -1 day' ) );
		$next_day = gmdate( 'Y-m-d', strtotime( $day . ' +1 day' ) );
		$today    = current_time( 'Y-m-d' );
		$start    = $day . ' 00:00:00';
		$end      = $day . ' 23:59:59';

		$bookings = Schema::table( 'bookings' );
		$services = Schema::table( 'services' );
		$vehicles = Schema::table( 'vehicles' );
		$chauffeurs = Schema::table( 'chauffeurs' );
		$legs     = Schema::table( 'booking_legs' );
		$zones    = Schema::table( 'zones' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.id, b.reference, b.status, b.customer_name, b.customer_phone, b.customer_whatsapp,
					b.passenger_count, b.pickup_datetime, b.internal_notes, b.fare_total, b.currency,
					s.name AS service_name,
					COALESCE(NULLIF(v.customer_display_name,''), v.name, '') AS vehicle_label,
					c.name AS chauffeur_name
				FROM {$bookings} b
				LEFT JOIN {$services} s ON s.id = b.service_id
				LEFT JOIN {$vehicles} v ON v.id = b.assigned_vehicle_id
				LEFT JOIN {$chauffeurs} c ON c.id = b.assigned_chauffeur_id
				WHERE b.pickup_datetime >= %s AND b.pickup_datetime <= %s
				AND b.status IN ('pending_confirmation','confirmed','completed')
				AND (b.assigned_vehicle_id > 0 OR b.assigned_chauffeur_id > 0)
				ORDER BY b.pickup_datetime ASC, b.id ASC",
				$start,
				$end
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$leg_map = array();
		if ( $rows ) {
			$ids = array_map(
				static function ( $r ) {
					return (int) $r['id'];
				},
				$rows
			);
			$ids = array_filter( $ids );
			if ( $ids ) {
				$in   = implode( ',', array_map( 'absint', $ids ) );
				$leg_rows = $wpdb->get_results(
					"SELECT l.booking_id, l.leg_type, l.pickup_detail, l.dest_detail,
						pz.name AS pickup_zone, dz.name AS dest_zone
					FROM {$legs} l
					LEFT JOIN {$zones} pz ON pz.id = l.pickup_zone_id
					LEFT JOIN {$zones} dz ON dz.id = l.dest_zone_id
					WHERE l.booking_id IN ({$in})
					ORDER BY l.id ASC",
					ARRAY_A
				); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				foreach ( (array) $leg_rows as $leg ) {
					$bid = (int) $leg['booking_id'];
					if ( ! isset( $leg_map[ $bid ] ) ) {
						$leg_map[ $bid ] = $leg;
					}
				}
			}
		}

		$day_label = date_i18n( get_option( 'date_format' ) ? get_option( 'date_format' ) : 'M j, Y', strtotime( $day . ' 12:00:00' ) );
		$count     = count( $rows );

		$print_btn = '<button type="button" class="prime-fleet-btn prime-fleet-run-sheet__print-btn" onclick="window.print()">' . esc_html__( 'Print', 'prime-fleet' ) . '</button>';
		$back_btn  = '<button type="button" class="prime-fleet-btn prime-fleet-btn--ghost" data-pf-panel="dispatch" data-pf-query="' . esc_attr( wp_json_encode( array( 'day' => $day ) ) ) . '">' . esc_html__( '← Dispatch', 'prime-fleet' ) . '</button>';

		ob_start();

		echo '<div class="prime-fleet-run-sheet" data-pf-run-sheet="1">';

		/* Same day-bar pattern as Dispatch — avoids WP tablenav float collapse. */
		echo '<form class="prime-fleet-dispatch-bar prime-fleet-run-sheet__bar tablenav top prime-fleet-no-print" data-pf-table-filter="1" data-pf-panel="run_sheet">';
		echo '<div class="prime-fleet-dispatch-bar__day">';
		echo '<label class="prime-fleet-dispatch-bar__label" for="pf-run-day">' . esc_html__( 'Run sheet date', 'prime-fleet' ) . '</label>';
		echo '<div class="prime-fleet-dispatch-bar__controls">';
		echo '<button type="button" class="button prime-fleet-dispatch-bar__nav" data-pf-panel="run_sheet" data-pf-query="' . esc_attr( wp_json_encode( array( 'day' => $prev_day ) ) ) . '" aria-label="' . esc_attr__( 'Previous day', 'prime-fleet' ) . '">' . View::chevron( 'prev' ) . '</button>';
		echo '<input type="date" id="pf-run-day" class="prime-fleet-dispatch-bar__input" name="day" value="' . esc_attr( $day ) . '" />';
		echo '<button type="button" class="button prime-fleet-dispatch-bar__nav" data-pf-panel="run_sheet" data-pf-query="' . esc_attr( wp_json_encode( array( 'day' => $next_day ) ) ) . '" aria-label="' . esc_attr__( 'Next day', 'prime-fleet' ) . '">' . View::chevron( 'next' ) . '</button>';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Load day', 'prime-fleet' ) . '</button>';
		if ( $day !== $today ) {
			echo '<button type="button" class="button" data-pf-panel="run_sheet" data-pf-query="' . esc_attr( wp_json_encode( array( 'day' => $today ) ) ) . '">' . esc_html__( 'Today', 'prime-fleet' ) . '</button>';
		}
		echo '</div></div>';
		echo '<div class="prime-fleet-dispatch-bar__meta">';
		echo '<strong>' . esc_html( $day_label ) . '</strong>';
		echo '<span class="prime-fleet-muted">' . esc_html(
			sprintf(
				/* translators: %d: assigned job count */
				_n( '%d assigned job', '%d assigned jobs', $count, 'prime-fleet' ),
				$count
			)
		) . '</span>';
		echo '</div></form>';

		/* Print-only document header (screen uses shell title + day bar). */
		echo '<header class="prime-fleet-run-sheet__head prime-fleet-print-only">';
		echo '<div class="prime-fleet-run-sheet__brand">';
		echo '<p class="prime-fleet-run-sheet__eyebrow">' . esc_html__( 'Prime Fleet', 'prime-fleet' ) . '</p>';
		echo '<h2 class="prime-fleet-run-sheet__title">' . esc_html__( 'Run sheet', 'prime-fleet' ) . '</h2>';
		echo '<p class="prime-fleet-run-sheet__meta">' . esc_html( $day_label );
		echo ' · ' . esc_html(
			sprintf(
				/* translators: %d: assigned job count */
				_n( '%d assigned job', '%d assigned jobs', $count, 'prime-fleet' ),
				$count
			)
		) . '</p>';
		echo '</div>';
		echo '<p class="prime-fleet-run-sheet__printed">' . esc_html(
			sprintf(
				/* translators: %s: print timestamp */
				__( 'Printed %s', 'prime-fleet' ),
				date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
			)
		) . '</p>';
		echo '</header>';

		if ( empty( $rows ) ) {
			echo '<div class="prime-fleet-no-print">';
			echo View::empty_state(
				__( 'No assigned jobs', 'prime-fleet' ),
				__( 'Assigned vehicle or chauffeur jobs for this date will appear here. Open Dispatch to assign fleet first.', 'prime-fleet' ),
				'<button type="button" class="prime-fleet-btn" data-pf-panel="dispatch" data-pf-query="' . esc_attr( wp_json_encode( array( 'day' => $day ) ) ) . '">' . esc_html__( 'Open Dispatch', 'prime-fleet' ) . '</button>'
			);
			echo '</div>';
			echo '<p class="prime-fleet-print-only prime-fleet-run-sheet__empty-print">' . esc_html__( 'No assigned jobs for this date.', 'prime-fleet' ) . '</p>';
		} else {
			echo '<div class="prime-fleet-table-wrap prime-fleet-run-sheet__table-wrap">';
			echo '<table class="prime-fleet-table prime-fleet-run-sheet__table">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Time', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Ref', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Guest', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Route', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Vehicle', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Chauffeur', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Notes', 'prime-fleet' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $rows as $r ) {
				$bid   = (int) $r['id'];
				$time  = $r['pickup_datetime'] ? date_i18n( 'g:i A', strtotime( $r['pickup_datetime'] ) ) : '—';
				$phone = $r['customer_phone'] ? (string) $r['customer_phone'] : ( $r['customer_whatsapp'] ? (string) $r['customer_whatsapp'] : '' );
				$leg   = isset( $leg_map[ $bid ] ) ? $leg_map[ $bid ] : null;
				$from  = '—';
				$to    = '—';
				if ( $leg ) {
					$from = $leg['pickup_detail'] ? (string) $leg['pickup_detail'] : ( $leg['pickup_zone'] ? (string) $leg['pickup_zone'] : '—' );
					$to   = $leg['dest_detail'] ? (string) $leg['dest_detail'] : ( $leg['dest_zone'] ? (string) $leg['dest_zone'] : '—' );
				}
				$notes = isset( $r['internal_notes'] ) ? wp_strip_all_tags( (string) $r['internal_notes'] ) : '';
				if ( strlen( $notes ) > 160 ) {
					$notes = substr( $notes, 0, 157 ) . '…';
				}

				echo '<tr>';
				echo '<td class="prime-fleet-run-sheet__time"><strong>' . esc_html( $time ) . '</strong>';
				echo '<span class="prime-fleet-run-sheet__status">' . esc_html( self::status_label( (string) $r['status'] ) ) . '</span></td>';
				echo '<td class="prime-fleet-table__mono">';
				echo '<button type="button" class="prime-fleet-row-link prime-fleet-no-print" data-pf-panel="bookings" data-pf-query="' . esc_attr( wp_json_encode( array( 'id' => $bid ) ) ) . '">' . esc_html( (string) $r['reference'] ) . '</button>';
				echo '<span class="prime-fleet-print-only">' . esc_html( (string) $r['reference'] ) . '</span>';
				if ( ! empty( $r['service_name'] ) ) {
					echo '<span class="prime-fleet-run-sheet__svc">' . esc_html( (string) $r['service_name'] ) . '</span>';
				}
				echo '</td>';
				echo '<td><strong>' . esc_html( (string) $r['customer_name'] ) . '</strong>';
				echo '<span class="prime-fleet-run-sheet__sub">' . esc_html( (string) (int) $r['passenger_count'] ) . ' pax';
				if ( $phone ) {
					echo ' · ' . esc_html( $phone );
				}
				echo '</span></td>';
				echo '<td class="prime-fleet-run-sheet__route"><span>' . esc_html( $from ) . '</span>';
				echo '<span class="prime-fleet-run-sheet__arrow" aria-hidden="true">→</span>';
				echo '<span>' . esc_html( $to ) . '</span></td>';
				echo '<td>' . esc_html( $r['vehicle_label'] ? (string) $r['vehicle_label'] : '—' ) . '</td>';
				echo '<td>' . esc_html( $r['chauffeur_name'] ? (string) $r['chauffeur_name'] : '—' ) . '</td>';
				echo '<td class="prime-fleet-run-sheet__notes">' . esc_html( $notes ? $notes : '—' ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table></div>';
		}

		echo '</div>';

		View::shell(
			__( 'Run sheet', 'prime-fleet' ),
			ob_get_clean(),
			array( $back_btn, $print_btn )
		);
	}

	/**
	 * @param string $status Status key.
	 * @return string
	 */
	private static function status_label( $status ) {
		$map = array(
			'pending_confirmation' => __( 'Pending', 'prime-fleet' ),
			'confirmed'            => __( 'Confirmed', 'prime-fleet' ),
			'completed'            => __( 'Completed', 'prime-fleet' ),
		);
		return isset( $map[ $status ] ) ? $map[ $status ] : $status;
	}
}
