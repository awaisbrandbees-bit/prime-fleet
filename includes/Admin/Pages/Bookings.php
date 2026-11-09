<?php
/**
 * Bookings queue + detail (staff).
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Admin\Pages;

use Prime_Fleet\Admin\Table_List;
use Prime_Fleet\Admin\View;
use Prime_Fleet\Bookings\Slot_Offers;
use Prime_Fleet\Database\Schema;
use Prime_Fleet\Install\Capabilities;
use Prime_Fleet\Messaging\Queue;
use Prime_Fleet\Payments\Payment_Service;

defined( 'ABSPATH' ) || exit;

final class Bookings {

	const NONCE = 'prime_fleet_bookings';

	/**
	 * Admin hooks.
	 */
	public static function hooks() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_export_csv' ) );
	}

	/**
	 * CSV via full admin request (not AJAX panel).
	 */
	public static function maybe_export_csv() {
		if ( empty( $_GET['prime_fleet_csv'] ) || empty( $_GET['page'] ) || 'prime-fleet' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! Capabilities::current_user_can( Capabilities::DISPATCH ) ) {
			wp_die( esc_html__( 'Forbidden.', 'prime-fleet' ) );
		}
		self::export_csv();
	}

	/**
	 * Render list or detail.
	 */
	public static function render() {
		if ( ! Capabilities::current_user_can( Capabilities::DISPATCH ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'prime-fleet' ) );
		}

		$id = absint( $_GET['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $id ) {
			self::render_detail( $id );
			return;
		}

		self::render_list();
	}

	/**
	 * CSV download of filtered bookings.
	 */
	private static function export_csv() {
		global $wpdb;
		$list = Table_List::fetch(
			'bookings',
			array(
				'search_cols'  => array( 'reference', 'customer_name', 'customer_email', 'customer_phone' ),
				'status_col'   => 'status',
				'status_exact' => true,
				'payment_col'  => 'payment_status',
				'orderby'      => 'id DESC',
				'args'         => array_merge(
					Table_List::args(),
					array(
						'per_page' => 5000,
						'paged'    => 1,
					)
				),
			)
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=prime-fleets-' . gmdate( 'Ymd-His' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fputcsv(
			$out,
			array(
				'reference',
				'status',
				'payment_status',
				'customer_name',
				'customer_email',
				'customer_phone',
				'pickup_datetime',
				'fare_total',
				'currency',
				'coupon_code',
				'manual_review',
				'created_at',
			)
		);
		foreach ( $list['rows'] as $r ) {
			fputcsv(
				$out,
				array(
					$r['reference'],
					$r['status'],
					$r['payment_status'],
					$r['customer_name'],
					$r['customer_email'],
					$r['customer_phone'],
					$r['pickup_datetime'],
					$r['fare_total'],
					$r['currency'],
					$r['coupon_code'],
					$r['manual_review'],
					$r['created_at'],
				)
			);
		}
		fclose( $out );
		exit;
	}

	/**
	 * List queue.
	 */
	private static function render_list() {
		$list = Table_List::fetch(
			'bookings',
			array(
				'search_cols'  => array( 'reference', 'customer_name', 'customer_email', 'customer_phone' ),
				'status_col'   => 'status',
				'status_exact' => true,
				'payment_col'  => 'payment_status',
				'orderby'      => 'id DESC',
			)
		);
		$rows = $list['rows'];
		$args = $list['args'];

		ob_start();
		echo '<div class="prime-fleet-card">';
		echo Table_List::toolbar(
			'bookings',
			$args,
			array(
				'placeholder' => __( 'Search reference, name, email…', 'prime-fleet' ),
				'status'      => true,
				'statuses'    => array(
					'pending_confirmation' => __( 'Pending Confirmation', 'prime-fleet' ),
					'confirmed'            => __( 'Confirmed', 'prime-fleet' ),
					'cancelled'            => __( 'Cancelled', 'prime-fleet' ),
					'completed'            => __( 'Completed', 'prime-fleet' ),
				),
				'payment'     => true,
				'payments'    => array(
					'unpaid'    => __( 'Unpaid', 'prime-fleet' ),
					'link_sent' => __( 'Link sent', 'prime-fleet' ),
					'paid'      => __( 'Paid', 'prime-fleet' ),
					'refunded'  => __( 'Refunded', 'prime-fleet' ),
				),
			)
		);

		if ( empty( $rows ) ) {
			$filtered = '' !== (string) ( $args['s'] ?? '' ) || '' !== (string) ( $args['status'] ?? '' ) || '' !== (string) ( $args['payment'] ?? '' );
			echo View::empty_state(
				$filtered ? __( 'No matching bookings', 'prime-fleet' ) : __( 'No bookings yet', 'prime-fleet' ),
				$filtered ? __( 'Try another search or reset filters.', 'prime-fleet' ) : __( 'Guest submissions will appear here with PF- references.', 'prime-fleet' )
			);
		} else {
			echo '<div class="prime-fleet-table-wrap"><table class="prime-fleet-table"><thead><tr>';
			echo '<th>' . esc_html__( 'Reference', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Customer', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Pickup', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Fare', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Payment', 'prime-fleet' ) . '</th>';
			echo '<th></th>';
			echo '</tr></thead><tbody>';
			foreach ( $rows as $r ) {
				$fare = esc_html( self::fare_display( $r ) );
				echo '<tr>';
				echo '<td><strong>' . esc_html( $r['reference'] ) . '</strong></td>';
				echo '<td>' . esc_html( $r['customer_name'] ) . '<br><span class="prime-fleet-muted">' . esc_html( $r['customer_email'] ) . '</span></td>';
				echo '<td>' . esc_html( $r['pickup_datetime'] ? $r['pickup_datetime'] : '—' ) . '</td>';
				echo '<td>' . $fare . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<td>' . esc_html( self::status_label( $r['status'] ) ) . '</td>';
				echo '<td>' . esc_html( self::pay_label( (string) $r['payment_status'] ) ) . '</td>';
				echo '<td><button type="button" class="prime-fleet-btn prime-fleet-btn--ghost" data-pf-panel="bookings" data-pf-query="' . esc_attr( wp_json_encode( array( 'id' => (int) $r['id'] ) ) ) . '">' . esc_html__( 'View', 'prime-fleet' ) . '</button></td>';
				echo '</tr>';
			}
			echo '</tbody></table></div>';
			echo Table_List::pagination( 'bookings', (int) $list['total'], $args );
		}
		echo '</div>';

		$csv_q = Table_List::query_params( $args );
		$csv_q['prime_fleet_csv'] = '1';
		$csv_url = admin_url( 'admin.php?page=prime-fleet&panel=bookings&' . http_build_query( $csv_q ) );

		View::shell(
			__( 'Bookings', 'prime-fleet' ),
			ob_get_clean(),
			array(
				'<a class="prime-fleet-btn prime-fleet-btn--ghost" href="' . esc_url( $csv_url ) . '">' . esc_html__( 'Export CSV', 'prime-fleet' ) . '</a>',
			)
		);
	}

	/**
	 * @param int $id Booking ID.
	 */
	private static function render_detail( $id ) {
		global $wpdb;

		$notice = '';
		if ( ! empty( $_POST['prime_fleet_booking_action'] ) && check_admin_referer( self::NONCE ) ) {
			$action = sanitize_key( wp_unslash( $_POST['prime_fleet_booking_action'] ) );
			$current = $wpdb->get_row(
				$wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'bookings' ) . ' WHERE id = %d', $id ),
				ARRAY_A
			);

			if ( 'confirm' === $action && $current ) {
				$wpdb->update(
					Schema::table( 'bookings' ),
					array( 'status' => 'confirmed' ),
					array( 'id' => $id ),
					array( '%s' ),
					array( '%d' )
				);
				$wpdb->insert(
					Schema::table( 'booking_events' ),
					array(
						'booking_id' => $id,
						'event_type' => 'status_changed',
						'actor_id'   => get_current_user_id(),
						'message'    => 'Confirmed by staff',
						'meta_json'  => wp_json_encode( array( 'status' => 'confirmed' ) ),
					),
					array( '%d', '%s', '%d', '%s', '%s' )
				);
				Queue::on_confirmed( $id );
				do_action( 'prime_fleet_booking_status_changed', $id, 'confirmed', $current['status'] );
				$notice = View::notice( __( 'Booking confirmed. Reminders scheduled.', 'prime-fleet' ), 'success' );
			} elseif ( 'cancel' === $action && $current && 'cancelled' !== $current['status'] ) {
				$wpdb->update(
					Schema::table( 'bookings' ),
					array( 'status' => 'cancelled' ),
					array( 'id' => $id ),
					array( '%s' ),
					array( '%d' )
				);
				$wpdb->insert(
					Schema::table( 'booking_events' ),
					array(
						'booking_id' => $id,
						'event_type' => 'status_changed',
						'actor_id'   => get_current_user_id(),
						'message'    => 'Cancelled by staff',
						'meta_json'  => wp_json_encode( array( 'status' => 'cancelled' ) ),
					),
					array( '%d', '%s', '%d', '%s', '%s' )
				);
				Queue::on_cancelled( $id );
				do_action( 'prime_fleet_booking_status_changed', $id, 'cancelled', $current['status'] );
				$notice = View::notice( __( 'Booking cancelled. Guest notified.', 'prime-fleet' ), 'success' );
			} elseif ( 'update_pickup' === $action && $current && 'cancelled' !== $current['status'] ) {
				$pickup = sanitize_text_field( wp_unslash( $_POST['pickup_datetime'] ?? '' ) );
				$pickup = str_replace( 'T', ' ', $pickup );
				if ( 16 === strlen( $pickup ) ) {
					$pickup .= ':00';
				}
				if ( ! $pickup || ! strtotime( $pickup ) ) {
					$notice = View::notice( __( 'Invalid pickup datetime.', 'prime-fleet' ), 'error' );
				} else {
					$wpdb->update(
						Schema::table( 'bookings' ),
						array( 'pickup_datetime' => $pickup ),
						array( 'id' => $id ),
						array( '%s' ),
						array( '%d' )
					);
					$wpdb->update(
						Schema::table( 'booking_legs' ),
						array( 'scheduled_datetime' => $pickup ),
						array(
							'booking_id' => $id,
							'leg_type'   => 'outbound',
						),
						array( '%s' ),
						array( '%d', '%s' )
					);
					$wpdb->insert(
						Schema::table( 'booking_events' ),
						array(
							'booking_id' => $id,
							'event_type' => 'booking_updated',
							'actor_id'   => get_current_user_id(),
							'message'    => 'Pickup updated to ' . $pickup,
							'meta_json'  => wp_json_encode( array( 'pickup_datetime' => $pickup ) ),
						),
						array( '%d', '%s', '%d', '%s', '%s' )
					);
					Queue::on_updated( $id, true );
					$notice = View::notice( __( 'Pickup updated. Guest notified.', 'prime-fleet' ), 'success' );
				}
			} elseif ( 'issue_payment' === $action && $current ) {
				$method_key = sanitize_key( wp_unslash( $_POST['payment_method'] ?? '' ) );
				$custom_url = esc_url_raw( wp_unslash( $_POST['custom_url'] ?? '' ) );
				$result     = Payment_Service::issue(
					$id,
					$method_key,
					array(
						'custom_url' => $custom_url,
						'actor_id'   => get_current_user_id(),
					)
				);
				if ( is_wp_error( $result ) ) {
					$notice = View::notice( $result->get_error_message(), 'error' );
				} else {
					$notice = View::notice(
						sprintf(
							/* translators: %s guest pay URL */
							__( 'Payment link issued and queued. Guest link: %s', 'prime-fleet' ),
							$result['guest_url']
						),
						'success'
					);
				}
			} elseif ( 'mark_paid' === $action && $current ) {
				$result = Payment_Service::mark_paid(
					$id,
					array(
						'source'   => 'staff',
						'actor_id' => get_current_user_id(),
					)
				);
				if ( is_wp_error( $result ) ) {
					$notice = View::notice( $result->get_error_message(), 'error' );
				} elseif ( ! empty( $result['already_paid'] ) ) {
					$notice = View::notice( __( 'Booking was already marked paid.', 'prime-fleet' ), 'success' );
				} else {
					$notice = View::notice( __( 'Marked paid. Payment confirmed message queued.', 'prime-fleet' ), 'success' );
				}
			} elseif ( 'mark_refunded' === $action && $current ) {
				$result = Payment_Service::mark_refunded(
					$id,
					array(
						'source'   => 'staff',
						'actor_id' => get_current_user_id(),
					)
				);
				if ( is_wp_error( $result ) ) {
					$notice = View::notice( $result->get_error_message(), 'error' );
				} elseif ( ! empty( $result['already_refunded'] ) ) {
					$notice = View::notice( __( 'Booking was already marked refunded.', 'prime-fleet' ), 'success' );
				} else {
					$notice = View::notice( __( 'Marked refunded.', 'prime-fleet' ), 'success' );
				}
			} elseif ( 'regenerate_payment' === $action && $current ) {
				$method_key = sanitize_key( wp_unslash( $_POST['payment_method'] ?? '' ) );
				$custom_url = esc_url_raw( wp_unslash( $_POST['custom_url'] ?? '' ) );
				$result     = Payment_Service::regenerate(
					$id,
					array(
						'method_key' => $method_key,
						'custom_url' => $custom_url,
						'actor_id'   => get_current_user_id(),
					)
				);
				if ( is_wp_error( $result ) ) {
					$notice = View::notice( $result->get_error_message(), 'error' );
				} else {
					$notice = View::notice(
						sprintf(
							/* translators: %s guest pay URL */
							__( 'New payment link generated and queued. Guest link: %s', 'prime-fleet' ),
							$result['guest_url']
						),
						'success'
					);
				}
			} elseif ( 'send_slot_offer' === $action ) {
				$proposed = sanitize_text_field( wp_unslash( $_POST['proposed_datetime'] ?? '' ) );
				$proposed = str_replace( 'T', ' ', $proposed );
				if ( 16 === strlen( $proposed ) ) {
					$proposed .= ':00';
				}
				$vehicle = absint( $_POST['offer_vehicle_id'] ?? 0 );
				$result  = Slot_Offers::create( $id, $proposed, $vehicle );
				if ( is_wp_error( $result ) ) {
					$notice = View::notice( $result->get_error_message(), 'error' );
				} else {
					$notice = View::notice(
						sprintf(
							/* translators: %s accept URL */
							__( 'Slot offer created. Guest link: %s', 'prime-fleet' ),
							$result['accept_url']
						),
						'success'
					);
				}
			}
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'bookings' ) . ' WHERE id = %d', $id ),
			ARRAY_A
		);
		if ( ! $row ) {
			View::shell( __( 'Bookings', 'prime-fleet' ), View::notice( __( 'Booking not found.', 'prime-fleet' ), 'error' ) );
			return;
		}

		$service = $wpdb->get_var(
			$wpdb->prepare( 'SELECT name FROM ' . Schema::table( 'services' ) . ' WHERE id = %d', (int) $row['service_id'] )
		);
		$legs = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'booking_legs' ) . ' WHERE booking_id = %d ORDER BY id ASC', $id ),
			ARRAY_A
		);
		$items = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'booking_items' ) . ' WHERE booking_id = %d ORDER BY id ASC', $id ),
			ARRAY_A
		);
		$events = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'booking_events' ) . ' WHERE booking_id = %d ORDER BY id DESC LIMIT 20', $id ),
			ARRAY_A
		);
		$comms = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table( 'communications' ) . ' WHERE booking_id = %d ORDER BY id DESC LIMIT 40',
				$id
			),
			ARRAY_A
		);
		$snapshot = json_decode( (string) $row['fare_snapshot_json'], true );
		if ( ! is_array( $snapshot ) ) {
			$snapshot = array();
		}

		$zone_names = array();
		$zones      = $wpdb->get_results( 'SELECT id, name, customer_label FROM ' . Schema::table( 'zones' ), ARRAY_A );
		foreach ( (array) $zones as $z ) {
			$zone_names[ (int) $z['id'] ] = $z['customer_label'] ? $z['customer_label'] : $z['name'];
		}

		ob_start();
		if ( $notice ) {
			echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		$status_mod = 'pending_confirmation' === $row['status'] ? 'is-pending' : ( 'confirmed' === $row['status'] ? 'is-confirmed' : ( 'cancelled' === $row['status'] ? 'is-cancelled' : '' ) );
		$pay_mod    = 'paid' === $row['payment_status'] ? 'is-paid' : ( 'unpaid' === $row['payment_status'] ? 'is-unpaid' : '' );
		$fare_label = self::fare_display( $row );
		$needs_review = (int) $row['manual_review'] && (float) $row['fare_total'] > 0;
		$avail        = ( isset( $snapshot['availability'] ) && is_array( $snapshot['availability'] ) ) ? $snapshot['availability'] : array();
		$avail_msg    = ! empty( $avail['message'] ) ? (string) $avail['message'] : '';
		$avail_alts   = ( ! empty( $avail['alternatives'] ) && is_array( $avail['alternatives'] ) ) ? $avail['alternatives'] : array();
		$conflict     = ! empty( $avail['conflict'] ) ? sanitize_key( (string) $avail['conflict'] ) : '';
		if ( '' === $conflict && $needs_review ) {
			$conflict = ! empty( $avail['vehicle_id'] ) ? 'vehicle' : 'slot';
		}
		$conflict_vid = ! empty( $avail['vehicle_id'] ) ? (int) $avail['vehicle_id'] : (int) ( $row['assigned_vehicle_id'] ?? 0 );
		$is_true_quote = (int) $row['manual_review'] && (float) $row['fare_total'] <= 0;
		$pickup_day  = $row['pickup_datetime'] ? date_i18n( 'D, M j', strtotime( $row['pickup_datetime'] ) ) : '—';
		$pickup_time = $row['pickup_datetime'] ? date_i18n( 'g:i A', strtotime( $row['pickup_datetime'] ) ) : '';
		$guest_name  = (string) $row['customer_name'];
		$initials    = '';
		foreach ( preg_split( '/\s+/', trim( $guest_name ) ) as $part ) {
			if ( '' !== $part ) {
				$initials .= strtoupper( substr( $part, 0, 1 ) );
			}
			if ( strlen( $initials ) >= 2 ) {
				break;
			}
		}
		if ( '' === $initials ) {
			$initials = '?';
		}

		$vehicles = $wpdb->get_results(
			'SELECT id, name, customer_display_name FROM ' . Schema::table( 'vehicles' ) . ' WHERE is_active = 1 ORDER BY display_order ASC, id ASC',
			ARRAY_A
		);
		$vehicle_labels = array();
		foreach ( (array) $vehicles as $v ) {
			$vehicle_labels[ (int) $v['id'] ] = $v['customer_display_name'] ? $v['customer_display_name'] : $v['name'];
		}
		$conflict_vehicle = ( $conflict_vid && isset( $vehicle_labels[ $conflict_vid ] ) ) ? $vehicle_labels[ $conflict_vid ] : '';
		$offer_rendered = false;

		echo '<div class="prime-fleet-bd">';

		/* —— Header —— */
		echo '<header class="prime-fleet-bd__header">';
		echo '<div class="prime-fleet-bd__header-main">';
		echo '<span class="prime-fleet-bd__badge prime-fleet-bd__badge--' . esc_attr( $status_mod ? str_replace( 'is-', '', $status_mod ) : 'pending' ) . '">' . esc_html( self::status_label( $row['status'] ) ) . '</span>';
		echo '<h2 class="prime-fleet-bd__ref">' . esc_html( $row['reference'] ) . '</h2>';
		echo '<p class="prime-fleet-bd__service">' . esc_html( $service ? $service : ( '#' . $row['service_id'] ) ) . '</p>';
		echo '</div>';
		echo '<div class="prime-fleet-bd__when">';
		echo '<span class="prime-fleet-bd__when-day">' . esc_html( $pickup_day ) . '</span>';
		if ( $pickup_time ) {
			echo '<span class="prime-fleet-bd__when-time">' . esc_html( $pickup_time ) . '</span>';
		}
		echo '</div>';
		if ( 'pending_confirmation' === $row['status'] ) {
			echo '<form method="post" class="prime-fleet-bd__confirm">';
			wp_nonce_field( self::NONCE );
			echo '<input type="hidden" name="prime_fleet_booking_action" value="confirm" />';
			echo '<button type="submit" class="prime-fleet-btn">' . esc_html__( 'Confirm booking', 'prime-fleet' ) . '</button>';
			echo '</form>';
		}
		if ( 'cancelled' !== $row['status'] ) {
			echo '<form method="post" class="prime-fleet-bd__confirm">';
			wp_nonce_field( self::NONCE );
			echo '<input type="hidden" name="prime_fleet_booking_action" value="cancel" />';
			echo '<button type="submit" class="prime-fleet-btn prime-fleet-btn--ghost" data-pf-confirm-title="' . esc_attr__( 'Cancel booking', 'prime-fleet' ) . '" data-pf-confirm-ok="' . esc_attr__( 'Cancel booking', 'prime-fleet' ) . '" data-pf-confirm="' . esc_attr__( 'Cancel this booking and notify the guest?', 'prime-fleet' ) . '">' . esc_html__( 'Cancel booking', 'prime-fleet' ) . '</button>';
			echo '</form>';
		}
		echo '</header>';

		/* —— KPI strip —— */
		$coupon_code = ! empty( $row['coupon_code'] ) ? (string) $row['coupon_code'] : '';
		$coupon_disc = '';
		if ( $coupon_code && ! empty( $snapshot['coupon']['discount'] ) ) {
			$coupon_disc = '−' . $row['currency'] . ' ' . number_format( (float) $snapshot['coupon']['discount'], 2 );
		} elseif ( $coupon_code && ! empty( $snapshot['coupon']['fare_before'] ) ) {
			$before = (float) $snapshot['coupon']['fare_before'];
			$coupon_disc = '−' . $row['currency'] . ' ' . number_format( max( 0, $before - (float) $row['fare_total'] ), 2 );
		}
		echo '<div class="prime-fleet-bd__kpis' . ( $coupon_code ? ' prime-fleet-bd__kpis--4' : '' ) . '" role="group" aria-label="' . esc_attr__( 'Booking summary', 'prime-fleet' ) . '">';
		echo '<div class="prime-fleet-bd__kpi"><span class="prime-fleet-bd__kpi-label">' . esc_html__( 'Fare', 'prime-fleet' ) . '</span><strong class="prime-fleet-bd__kpi-value">' . esc_html( $fare_label ) . '</strong></div>';
		echo '<div class="prime-fleet-bd__kpi"><span class="prime-fleet-bd__kpi-label">' . esc_html__( 'Payment', 'prime-fleet' ) . '</span><strong class="prime-fleet-bd__kpi-value prime-fleet-bd__kpi-value--' . esc_attr( $pay_mod ? str_replace( 'is-', '', $pay_mod ) : 'other' ) . '">' . esc_html( self::pay_label( $row['payment_status'] ) ) . '</strong></div>';
		echo '<div class="prime-fleet-bd__kpi"><span class="prime-fleet-bd__kpi-label">' . esc_html__( 'Passengers', 'prime-fleet' ) . '</span><strong class="prime-fleet-bd__kpi-value">' . esc_html( (string) (int) $row['passenger_count'] ) . '</strong></div>';
		if ( $coupon_code ) {
			echo '<div class="prime-fleet-bd__kpi"><span class="prime-fleet-bd__kpi-label">' . esc_html__( 'Coupon', 'prime-fleet' ) . '</span><strong class="prime-fleet-bd__kpi-value">' . esc_html( $coupon_code );
			if ( $coupon_disc ) {
				echo '<span class="prime-fleet-bd__kpi-sub">' . esc_html( $coupon_disc ) . '</span>';
			}
			echo '</strong></div>';
		}
		echo '</div>';

		if ( $needs_review || $is_true_quote ) {
			self::render_review_alert(
				array(
					'needs_review'      => $needs_review,
					'is_quote'          => $is_true_quote,
					'conflict'          => $conflict,
					'conflict_vehicle'  => $conflict_vehicle,
					'avail_alts'        => $avail_alts,
					'status'            => (string) $row['status'],
				)
			);
		}

		if ( $needs_review && 'cancelled' !== $row['status'] ) {
			self::render_slot_offer_panel( $vehicles );
			$offer_rendered = true;
		}

		echo '<div class="prime-fleet-bd__layout">';

		/* —— Guest (top left) —— */
		echo '<section class="prime-fleet-bd__panel prime-fleet-bd__panel--guest">';
		echo '<h3 class="prime-fleet-bd__panel-title">' . esc_html__( 'Guest', 'prime-fleet' ) . '</h3>';
		echo '<div class="prime-fleet-bd__guest">';
		echo '<div class="prime-fleet-bd__avatar" aria-hidden="true">' . esc_html( $initials ) . '</div>';
		echo '<div class="prime-fleet-bd__guest-body">';
		echo '<p class="prime-fleet-bd__guest-name">' . esc_html( $guest_name ) . '</p>';
		echo '<div class="prime-fleet-bd__contact">';
		self::contact_row( __( 'Email', 'prime-fleet' ), $row['customer_email'] );
		self::contact_row( __( 'Phone', 'prime-fleet' ), $row['customer_phone'] );
		self::contact_row( __( 'WhatsApp', 'prime-fleet' ), $row['customer_whatsapp'] ? $row['customer_whatsapp'] : '—' );
		echo '</div></div></div></section>';

		/* —— Trip (top right) —— */
		echo '<section class="prime-fleet-bd__panel prime-fleet-bd__panel--trip">';
		echo '<h3 class="prime-fleet-bd__panel-title">' . esc_html__( 'Trip', 'prime-fleet' ) . '</h3>';
		if ( $legs ) {
			echo '<div class="prime-fleet-bd__legs">';
			foreach ( $legs as $leg ) {
				$from      = $zone_names[ (int) $leg['pickup_zone_id'] ] ?? ( '#' . $leg['pickup_zone_id'] );
				$to        = $zone_names[ (int) $leg['dest_zone_id'] ] ?? ( '#' . $leg['dest_zone_id'] );
				$leg_key   = sanitize_key( (string) $leg['leg_type'] );
				$leg_label = self::leg_label( $leg_key );
				echo '<article class="prime-fleet-bd__leg">';
				echo '<div class="prime-fleet-bd__leg-top">';
				echo '<span class="prime-fleet-bd__leg-chip">' . esc_html( $leg_label ) . '</span>';
				if ( ! empty( $leg['scheduled_datetime'] ) ) {
					echo '<time class="prime-fleet-bd__leg-time" datetime="' . esc_attr( $leg['scheduled_datetime'] ) . '">' . esc_html( date_i18n( 'M j · g:i A', strtotime( $leg['scheduled_datetime'] ) ) ) . '</time>';
				}
				echo '</div>';
				echo '<div class="prime-fleet-bd__route">';
				echo '<span class="prime-fleet-bd__route-place">' . esc_html( $from ) . '</span>';
				echo '<span class="prime-fleet-bd__route-arrow" aria-hidden="true">→</span>';
				echo '<span class="prime-fleet-bd__route-place">' . esc_html( $to ) . '</span>';
				echo '</div>';
				echo '</article>';
			}
			echo '</div>';
		} else {
			echo '<p class="prime-fleet-bd__empty">' . esc_html__( 'No legs on this booking.', 'prime-fleet' ) . '</p>';
		}

		if ( $items ) {
			echo '<div class="prime-fleet-bd__addons">';
			echo '<h4 class="prime-fleet-bd__subhead">' . esc_html__( 'Add-ons', 'prime-fleet' ) . '</h4>';
			echo '<ul class="prime-fleet-bd__addon-list">';
			foreach ( $items as $item ) {
				echo '<li><span>' . esc_html( $item['label'] ) . ' × ' . esc_html( (string) $item['qty'] ) . '</span><span>' . esc_html( number_format( (float) $item['line_total'], 2 ) ) . '</span></li>';
			}
			echo '</ul></div>';
		}

		$has_fare_lines = ! empty( $snapshot['explanation'] ) && is_array( $snapshot['explanation'] );
		$has_coupon_snap = $coupon_code && ( ! empty( $snapshot['coupon'] ) || $coupon_disc );
		if ( $has_fare_lines || $has_coupon_snap ) {
			echo '<div class="prime-fleet-bd__fare-note">';
			echo '<h4 class="prime-fleet-bd__subhead">' . esc_html__( 'Fare breakdown', 'prime-fleet' ) . '</h4>';
			echo '<ul class="prime-fleet-bd__fare-list">';
			if ( $has_fare_lines ) {
				foreach ( $snapshot['explanation'] as $line ) {
					echo '<li>' . esc_html( (string) $line ) . '</li>';
				}
			}
			if ( $has_coupon_snap ) {
				$coupon_line = sprintf(
					/* translators: 1: coupon code, 2: discount amount */
					__( 'Coupon %1$s applied%2$s', 'prime-fleet' ),
					$coupon_code,
					$coupon_disc ? ' (' . $coupon_disc . ')' : ''
				);
				echo '<li class="prime-fleet-bd__fare-coupon">' . esc_html( $coupon_line ) . '</li>';
			}
			echo '</ul></div>';
		}
		echo '</section>';

		/* —— Communications (full width) —— */
		echo '<section class="prime-fleet-bd__panel prime-fleet-bd__panel--comms">';
		echo '<h3 class="prime-fleet-bd__panel-title">' . esc_html__( 'Communications', 'prime-fleet' ) . '</h3>';
		if ( $comms ) {
			echo '<div class="prime-fleet-table-wrap"><table class="prime-fleet-table prime-fleet-table--compact"><thead><tr>';
			echo '<th>' . esc_html__( 'When', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Channel', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Template', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'To', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Preview', 'prime-fleet' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $comms as $c ) {
				echo '<tr>';
				echo '<td>' . esc_html( date_i18n( 'M j · g:i A', strtotime( $c['created_at'] ) ) ) . '</td>';
				echo '<td><span class="prime-fleet-badge">' . esc_html( $c['channel'] ) . '</span></td>';
				echo '<td><code>' . esc_html( $c['template_key'] ) . '</code></td>';
				echo '<td>' . esc_html( $c['recipient'] ) . '</td>';
				echo '<td>' . esc_html( $c['status'] ) . '</td>';
				echo '<td>' . esc_html( mb_substr( (string) $c['body_preview'], 0, 80 ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table></div>';
		} else {
			echo '<p class="prime-fleet-bd__empty">' . esc_html__( 'No emails or WhatsApp messages logged yet.', 'prime-fleet' ) . '</p>';
		}
		echo '</section>';

		/* —— Activity (full width) —— */
		echo '<section class="prime-fleet-bd__panel prime-fleet-bd__panel--activity">';
		echo '<h3 class="prime-fleet-bd__panel-title">' . esc_html__( 'Activity', 'prime-fleet' ) . '</h3>';
		if ( $events ) {
			echo '<div class="prime-fleet-bd__feed">';
			foreach ( $events as $ev ) {
				$type_label = self::event_label( (string) $ev['event_type'] );
				$msg        = $ev['message'] ? (string) $ev['message'] : $type_label;
				echo '<div class="prime-fleet-bd__feed-item">';
				echo '<span class="prime-fleet-bd__feed-marker" aria-hidden="true"></span>';
				echo '<div class="prime-fleet-bd__feed-body">';
				echo '<p class="prime-fleet-bd__feed-title">' . esc_html( $msg ) . '</p>';
				echo '<p class="prime-fleet-bd__feed-meta">' . esc_html( $type_label ) . ' · ' . esc_html( date_i18n( 'M j, Y · g:i A', strtotime( $ev['created_at'] ) ) ) . '</p>';
				echo '</div></div>';
			}
			echo '</div>';
		} else {
			echo '<p class="prime-fleet-bd__empty">' . esc_html__( 'No activity yet.', 'prime-fleet' ) . '</p>';
		}
		echo '</section>';

		/* —— Slot offer (full width) —— */
		if ( ! $offer_rendered ) {
			self::render_slot_offer_panel( $vehicles );
		}

		if ( 'cancelled' !== $row['status'] ) {
			/* —— Update pickup —— */
			$pickup_val = '';
			if ( ! empty( $row['pickup_datetime'] ) ) {
				$pickup_val = gmdate( 'Y-m-d\TH:i', strtotime( $row['pickup_datetime'] ) );
			}
			echo '<section class="prime-fleet-bd__panel prime-fleet-bd__panel--ops">';
			echo '<div class="prime-fleet-bd__offer-head">';
			echo '<h3 class="prime-fleet-bd__panel-title">' . esc_html__( 'Update pickup', 'prime-fleet' ) . '</h3>';
			echo '<p class="prime-fleet-bd__offer-help">' . esc_html__( 'Changes notify the guest and reschedule reminders if confirmed.', 'prime-fleet' ) . '</p>';
			echo '</div>';
			echo '<form method="post" class="prime-fleet-bd__offer-form">';
			wp_nonce_field( self::NONCE );
			echo '<input type="hidden" name="prime_fleet_booking_action" value="update_pickup" />';
			echo '<div class="prime-fleet-field"><label for="pf-bd-pickup">' . esc_html__( 'Pickup', 'prime-fleet' ) . '</label><input id="pf-bd-pickup" type="datetime-local" name="pickup_datetime" value="' . esc_attr( $pickup_val ) . '" required /></div>';
			echo '<div class="prime-fleet-bd__offer-action"><button type="submit" class="prime-fleet-btn prime-fleet-btn--ghost">' . esc_html__( 'Save & notify', 'prime-fleet' ) . '</button></div>';
			echo '</form></section>';

			/* —— Payment (M4) —— */
			$pay_methods = Payment_Service::issueable_methods();
			$latest_pay  = Payment_Service::latest_for_booking( $id );
			$guest_pay   = $latest_pay && ! empty( $latest_pay['token'] )
				? Payment_Service::guest_url( $latest_pay['token'] )
				: '';
			$pay_status  = (string) $row['payment_status'];
			$selected_m  = '';
			$prefill_url = '';
			if ( $latest_pay && ! empty( $latest_pay['method_id'] ) ) {
				$selected_m = (string) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT method_key FROM ' . Schema::table( 'payment_methods' ) . ' WHERE id = %d',
						(int) $latest_pay['method_id']
					)
				);
				$prefill_url = ! empty( $latest_pay['custom_url'] ) ? (string) $latest_pay['custom_url'] : '';
			}

			echo '<section class="prime-fleet-bd__panel prime-fleet-bd__panel--pay">';
			echo '<div class="prime-fleet-bd__pay-top">';
			echo '<div class="prime-fleet-bd__pay-intro">';
			echo '<h3 class="prime-fleet-bd__panel-title">' . esc_html__( 'Payment', 'prime-fleet' ) . '</h3>';
			echo '<p class="prime-fleet-bd__offer-help">' . esc_html__( 'Issue or re-send a tracked guest pay link (instructions, custom link, or Stripe). Regenerate invalidates the old token.', 'prime-fleet' ) . '</p>';
			echo '</div>';
			echo '<div class="prime-fleet-bd__pay-badge prime-fleet-bd__pay-badge--' . esc_attr( sanitize_html_class( $pay_status ) ) . '">';
			echo '<span class="prime-fleet-bd__pay-badge-label">' . esc_html__( 'Status', 'prime-fleet' ) . '</span>';
			echo '<strong>' . esc_html( self::pay_label( $pay_status ) ) . '</strong>';
			echo '</div>';
			echo '</div>';

			if ( $latest_pay && ! empty( $latest_pay['external_id'] ) ) {
				echo '<p class="prime-fleet-bd__offer-help">' . esc_html__( 'Gateway ref:', 'prime-fleet' ) . ' <code>' . esc_html( (string) $latest_pay['external_id'] ) . '</code>';
				if ( ! empty( $latest_pay['failure_code'] ) ) {
					echo ' · ' . esc_html__( 'Note:', 'prime-fleet' ) . ' ' . esc_html( (string) $latest_pay['failure_code'] );
				}
				echo '</p>';
			}

			if ( $guest_pay && ! in_array( $pay_status, array( 'refunded' ), true ) ) {
				echo '<div class="prime-fleet-bd__pay-link">';
				echo '<span class="prime-fleet-bd__pay-link-label">' . esc_html__( 'Guest link', 'prime-fleet' ) . '</span>';
				echo '<a href="' . esc_url( $guest_pay ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $guest_pay ) . '</a>';
				if ( $latest_pay && Payment_Service::is_expired( $latest_pay ) && in_array( (string) $latest_pay['status'], array( 'unpaid', 'link_sent', 'expired' ), true ) ) {
					echo '<span class="prime-fleet-bd__pay-expired">' . esc_html__( 'This link is expired — regenerate to send a fresh one.', 'prime-fleet' ) . '</span>';
				}
				echo '</div>';
			}

			if ( $pay_methods && 'refunded' !== $pay_status ) {
				echo '<form method="post" class="prime-fleet-bd__pay-form">';
				wp_nonce_field( self::NONCE );
				echo '<input type="hidden" name="prime_fleet_booking_action" value="issue_payment" />';
				echo '<div class="prime-fleet-field prime-fleet-bd__pay-method"><label for="pf-bd-paymethod">' . esc_html__( 'Method', 'prime-fleet' ) . '</label><select id="pf-bd-paymethod" name="payment_method">';
				foreach ( $pay_methods as $mid => $pm ) {
					echo '<option value="' . esc_attr( $mid ) . '"' . selected( $selected_m, $mid, false ) . '>' . esc_html( $pm->label() ) . '</option>';
				}
				echo '</select></div>';
				echo '<div class="prime-fleet-field prime-fleet-bd__pay-url"><label for="pf-bd-customurl">' . esc_html__( 'Override URL', 'prime-fleet' ) . ' <span class="prime-fleet-bd__pay-optional">' . esc_html__( '(custom link)', 'prime-fleet' ) . '</span></label><input id="pf-bd-customurl" type="url" name="custom_url" value="' . esc_attr( $prefill_url ) . '" placeholder="https://" /></div>';
				echo '<div class="prime-fleet-bd__pay-submit"><button type="submit" class="prime-fleet-btn">' . esc_html( $guest_pay ? __( 'Re-send payment link', 'prime-fleet' ) : __( 'Send payment link', 'prime-fleet' ) ) . '</button></div>';
				echo '</form>';
			} elseif ( ! $pay_methods ) {
				echo '<p class="prime-fleet-bd__offer-help">' . esc_html__( 'Enable Bank/instructions or Custom payment link under Prime Fleet → Payments.', 'prime-fleet' ) . '</p>';
			}

			echo '<div class="prime-fleet-bd__pay-secondary">';
			if ( 'paid' !== $pay_status && 'refunded' !== $pay_status ) {
				echo '<form method="post">';
				wp_nonce_field( self::NONCE );
				echo '<input type="hidden" name="prime_fleet_booking_action" value="mark_paid" />';
				echo '<button type="submit" class="prime-fleet-btn prime-fleet-btn--ghost" data-pf-confirm="' . esc_attr__( 'Mark this booking as paid and notify the guest?', 'prime-fleet' ) . '" data-pf-confirm-title="' . esc_attr__( 'Mark paid', 'prime-fleet' ) . '" data-pf-confirm-ok="' . esc_attr__( 'Mark paid', 'prime-fleet' ) . '">' . esc_html__( 'Mark paid', 'prime-fleet' ) . '</button>';
				echo '</form>';
			}
			if ( $guest_pay && in_array( $pay_status, array( 'unpaid', 'link_sent' ), true ) && $pay_methods ) {
				$regen_method = $selected_m;
				if ( '' === $regen_method ) {
					$keys         = array_keys( $pay_methods );
					$regen_method = (string) $keys[0];
				}
				echo '<form method="post">';
				wp_nonce_field( self::NONCE );
				echo '<input type="hidden" name="prime_fleet_booking_action" value="regenerate_payment" />';
				echo '<input type="hidden" name="payment_method" value="' . esc_attr( $regen_method ) . '" />';
				echo '<input type="hidden" name="custom_url" value="' . esc_attr( $prefill_url ) . '" />';
				echo '<button type="submit" class="prime-fleet-btn prime-fleet-btn--ghost" data-pf-confirm="' . esc_attr__( 'Invalidate the current guest link and email/WhatsApp a new one?', 'prime-fleet' ) . '" data-pf-confirm-title="' . esc_attr__( 'Regenerate link', 'prime-fleet' ) . '" data-pf-confirm-ok="' . esc_attr__( 'Regenerate', 'prime-fleet' ) . '">' . esc_html__( 'Regenerate link', 'prime-fleet' ) . '</button>';
				echo '</form>';
			}
			if ( 'paid' === $pay_status ) {
				echo '<form method="post">';
				wp_nonce_field( self::NONCE );
				echo '<input type="hidden" name="prime_fleet_booking_action" value="mark_refunded" />';
				echo '<button type="submit" class="prime-fleet-btn prime-fleet-btn--ghost" data-pf-confirm="' . esc_attr__( 'Mark this payment as refunded?', 'prime-fleet' ) . '" data-pf-confirm-title="' . esc_attr__( 'Mark refunded', 'prime-fleet' ) . '" data-pf-confirm-ok="' . esc_attr__( 'Mark refunded', 'prime-fleet' ) . '">' . esc_html__( 'Mark refunded', 'prime-fleet' ) . '</button>';
				echo '</form>';
			}
			echo '</div>';

			if ( $latest_pay ) {
				$pay_events = Payment_Service::events_for_payment( (int) $latest_pay['id'], 20 );
				if ( $pay_events ) {
					echo '<div class="prime-fleet-bd__pay-events">';
					echo '<h4 class="prime-fleet-bd__pay-events-title">' . esc_html__( 'Payment timeline', 'prime-fleet' ) . '</h4>';
					echo '<div class="prime-fleet-bd__pay-timeline" role="list">';
					foreach ( $pay_events as $pev ) {
						$type = (string) $pev['event_type'];
						$mod  = self::pay_event_mod( $type );
						$payload = json_decode( (string) ( $pev['payload_json'] ?? '' ), true );
						$detail  = self::pay_event_detail( $type, is_array( $payload ) ? $payload : array() );
						echo '<div class="prime-fleet-bd__pay-tl-item is-' . esc_attr( $mod ) . '" role="listitem">';
						echo '<span class="prime-fleet-bd__pay-tl-marker" aria-hidden="true"></span>';
						echo '<div class="prime-fleet-bd__pay-tl-body">';
						echo '<p class="prime-fleet-bd__pay-tl-title">' . esc_html( self::pay_event_label( $type ) ) . '</p>';
						if ( $detail ) {
							echo '<p class="prime-fleet-bd__pay-tl-detail">' . esc_html( $detail ) . '</p>';
						}
						echo '<p class="prime-fleet-bd__pay-tl-meta"><time datetime="' . esc_attr( (string) $pev['created_at'] ) . '">' . esc_html( date_i18n( 'M j, Y · g:i A', strtotime( (string) $pev['created_at'] ) ) ) . '</time></p>';
						echo '</div></div>';
					}
					echo '</div></div>';
				}
			}
			echo '</section>';
		}

		echo '</div>'; /* layout */
		echo '</div>'; /* bd */

		$back = '<button type="button" class="prime-fleet-btn prime-fleet-btn--ghost" data-pf-panel="bookings">' . esc_html__( '← Back to bookings', 'prime-fleet' ) . '</button>';
		View::shell( __( 'Booking detail', 'prime-fleet' ), ob_get_clean(), array( $back ) );
	}

	/**
	 * @param string $label Label.
	 * @param string $value Value.
	 */
	private static function contact_row( $label, $value ) {
		echo '<div class="prime-fleet-bd__contact-row">';
		echo '<span class="prime-fleet-bd__contact-label">' . esc_html( $label ) . '</span>';
		echo '<span class="prime-fleet-bd__contact-value">' . esc_html( (string) $value ) . '</span>';
		echo '</div>';
	}

	/**
	 * @param string $label Label.
	 * @param string $value Value.
	 */
	private static function dl( $label, $value ) {
		self::contact_row( $label, $value );
	}

	/**
	 * @param string $label Label.
	 * @param string $value Value.
	 */
	private static function kv( $label, $value ) {
		self::contact_row( $label, $value );
	}

	/**
	 * @param string $type Leg type key.
	 * @return string
	 */
	private static function leg_label( $type ) {
		$map = array(
			'outbound' => __( 'Outbound', 'prime-fleet' ),
			'return'   => __( 'Return', 'prime-fleet' ),
			'single'   => __( 'One way', 'prime-fleet' ),
		);
		return isset( $map[ $type ] ) ? $map[ $type ] : ucfirst( str_replace( '_', ' ', $type ) );
	}

	/**
	 * @param string $type Event type key.
	 * @return string
	 */
	private static function event_label( $type ) {
		$map = array(
			'created'                  => __( 'Booking created', 'prime-fleet' ),
			'status_changed'           => __( 'Status updated', 'prime-fleet' ),
			'slot_offer_sent'          => __( 'Slot offer sent', 'prime-fleet' ),
			'slot_offer_accepted'      => __( 'Slot offer accepted', 'prime-fleet' ),
			'assigned'                 => __( 'Assignment updated', 'prime-fleet' ),
			'booking_updated'          => __( 'Booking updated', 'prime-fleet' ),
			'payment_link_sent'        => __( 'Payment link issued', 'prime-fleet' ),
			'payment_confirmed_notify' => __( 'Payment confirmed', 'prime-fleet' ),
			'payment_refunded'         => __( 'Payment refunded', 'prime-fleet' ),
			'payment_refunded_notify'  => __( 'Refund notice sent', 'prime-fleet' ),
			'payment_pending_verification' => __( 'Guest payment notice', 'prime-fleet' ),
			'note'                     => __( 'Note', 'prime-fleet' ),
		);
		return isset( $map[ $type ] ) ? $map[ $type ] : ucwords( str_replace( '_', ' ', $type ) );
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

	/**
	 * Compact review banner for admins.
	 *
	 * @param array $args Context.
	 */
	private static function render_review_alert( array $args ) {
		$needs     = ! empty( $args['needs_review'] );
		$conflict  = isset( $args['conflict'] ) ? sanitize_key( (string) $args['conflict'] ) : 'slot';
		$vname     = isset( $args['conflict_vehicle'] ) ? (string) $args['conflict_vehicle'] : '';
		$alts      = isset( $args['avail_alts'] ) && is_array( $args['avail_alts'] ) ? $args['avail_alts'] : array();
		$status    = isset( $args['status'] ) ? (string) $args['status'] : '';
		$alt_label = '';
		if ( $alts ) {
			$first = $alts[0];
			$alt_label = ! empty( $first['label'] ) ? (string) $first['label'] : (string) ( $first['datetime'] ?? '' );
		}

		if ( ! $needs ) {
			echo '<aside class="prime-fleet-bd__alert prime-fleet-bd__alert--is-quote" role="status">';
			echo '<div class="prime-fleet-bd__alert-top">';
			echo '<span class="prime-fleet-bd__alert-pill">' . esc_html__( 'Custom quote', 'prime-fleet' ) . '</span>';
			echo '<p class="prime-fleet-bd__alert-title">' . esc_html__( 'No published fare — agree a price, then Mark paid or send a payment link.', 'prime-fleet' ) . '</p>';
			echo '</div></aside>';
			return;
		}

		$is_vehicle = ( 'vehicle' === $conflict );
		$class      = $is_vehicle ? 'is-vehicle' : 'is-slot';
		$pill       = $is_vehicle ? __( 'Vehicle busy', 'prime-fleet' ) : __( 'Slot full', 'prime-fleet' );
		if ( $is_vehicle ) {
			$title = $vname
				? sprintf(
					/* translators: %s vehicle name */
					__( '%s is already booked for this pickup time.', 'prime-fleet' ),
					$vname
				)
				: __( 'The selected vehicle is already booked for this pickup time.', 'prime-fleet' );
			$next = __( 'Assign another vehicle in Dispatch, or send a slot offer below.', 'prime-fleet' );
		} else {
			$title = __( 'No vehicles are free at this pickup time.', 'prime-fleet' );
			$next  = __( 'Send a slot offer below, or change the pickup time.', 'prime-fleet' );
		}
		if ( 'pending_confirmation' === $status ) {
			$next .= ' ' . __( 'Confirm once the schedule is clear.', 'prime-fleet' );
		}

		echo '<aside class="prime-fleet-bd__alert prime-fleet-bd__alert--' . esc_attr( $class ) . '" role="status">';
		echo '<div class="prime-fleet-bd__alert-top">';
		echo '<span class="prime-fleet-bd__alert-pill">' . esc_html( $pill ) . '</span>';
		echo '<p class="prime-fleet-bd__alert-title">' . esc_html( $title ) . '</p>';
		echo '</div>';
		echo '<p class="prime-fleet-bd__alert-copy">';
		if ( $alt_label ) {
			echo esc_html(
				sprintf(
					/* translators: %s suggested datetime label */
					__( 'Suggested free window: %s.', 'prime-fleet' ),
					$alt_label
				)
			);
			echo ' ';
		}
		echo esc_html( $next );
		echo '</p>';
		echo '</aside>';
	}

	/**
	 * Slot offer form panel.
	 *
	 * @param array $vehicles Vehicle rows.
	 */
	private static function render_slot_offer_panel( array $vehicles ) {
		echo '<section class="prime-fleet-bd__panel prime-fleet-bd__panel--offer" id="prime-fleet-bd-slot-offer">';
		echo '<div class="prime-fleet-bd__offer-head">';
		echo '<h3 class="prime-fleet-bd__panel-title">' . esc_html__( 'Send slot offer', 'prime-fleet' ) . '</h3>';
		echo '<p class="prime-fleet-bd__offer-help">' . esc_html__( 'Propose an alternative pickup when the requested window is full. Guest receives a secure accept link.', 'prime-fleet' ) . '</p>';
		echo '</div>';
		echo '<form method="post" class="prime-fleet-bd__offer-form">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="prime_fleet_booking_action" value="send_slot_offer" />';
		echo '<div class="prime-fleet-field"><label for="pf-bd-proposed">' . esc_html__( 'Proposed pickup', 'prime-fleet' ) . '</label><input id="pf-bd-proposed" type="datetime-local" name="proposed_datetime" required /></div>';
		echo '<div class="prime-fleet-field"><label for="pf-bd-vehicle">' . esc_html__( 'Vehicle', 'prime-fleet' ) . '</label><select id="pf-bd-vehicle" name="offer_vehicle_id"><option value="0">' . esc_html__( 'Any free vehicle', 'prime-fleet' ) . '</option>';
		foreach ( $vehicles as $v ) {
			$label = $v['customer_display_name'] ? $v['customer_display_name'] : $v['name'];
			echo '<option value="' . esc_attr( (string) (int) $v['id'] ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select></div>';
		echo '<div class="prime-fleet-bd__offer-action"><button type="submit" class="prime-fleet-btn">' . esc_html__( 'Send offer', 'prime-fleet' ) . '</button></div>';
		echo '</form></section>';
	}

	/**
	 * @param string $type Payment event type.
	 * @return string
	 */
	private static function pay_event_label( $type ) {
		$map = array(
			'payment_issued'   => __( 'Payment link issued', 'prime-fleet' ),
			'payment_paid'     => __( 'Marked paid', 'prime-fleet' ),
			'payment_refunded' => __( 'Marked refunded', 'prime-fleet' ),
			'payment_expired'  => __( 'Link expired / regenerated', 'prime-fleet' ),
			'guest_ack'        => __( 'Guest reported payment sent', 'prime-fleet' ),
			'stripe_refunded'  => __( 'Stripe refund webhook', 'prime-fleet' ),
			'stripe_paid'      => __( 'Stripe Checkout paid', 'prime-fleet' ),
		);
		return isset( $map[ $type ] ) ? $map[ $type ] : ucwords( str_replace( '_', ' ', (string) $type ) );
	}

	/**
	 * @param string $type Event type.
	 * @return string CSS modifier.
	 */
	private static function pay_event_mod( $type ) {
		if ( false !== strpos( $type, 'refund' ) ) {
			return 'refunded';
		}
		if ( false !== strpos( $type, 'paid' ) || 'guest_ack' === $type ) {
			return 'paid';
		}
		if ( false !== strpos( $type, 'issued' ) ) {
			return 'issued';
		}
		if ( false !== strpos( $type, 'expir' ) ) {
			return 'expired';
		}
		return 'neutral';
	}

	/**
	 * @param string $type Event type.
	 * @param array  $payload Payload.
	 * @return string
	 */
	private static function pay_event_detail( $type, array $payload ) {
		$bits = array();
		if ( ! empty( $payload['source'] ) ) {
			$bits[] = sprintf(
				/* translators: %s source key */
				__( 'Source: %s', 'prime-fleet' ),
				str_replace( '_', ' ', (string) $payload['source'] )
			);
		}
		if ( ! empty( $payload['method_key'] ) ) {
			$bits[] = sprintf(
				/* translators: %s method key */
				__( 'Method: %s', 'prime-fleet' ),
				(string) $payload['method_key']
			);
		}
		if ( ! empty( $payload['reused'] ) ) {
			$bits[] = __( 'Existing link re-sent', 'prime-fleet' );
		}
		if ( ! empty( $payload['reason'] ) ) {
			$bits[] = (string) $payload['reason'];
		}
		if ( ! empty( $payload['event_id'] ) ) {
			$bits[] = 'Stripe ' . (string) $payload['event_id'];
		}
		unset( $type );
		return implode( ' · ', $bits );
	}

	/**
	 * Fare label for admin UI.
	 * "Custom quote" only when there is no published total.
	 * Occupancy / slot conflicts set manual_review but still keep priced fare.
	 *
	 * @param array $row Booking row.
	 * @return string
	 */
	private static function fare_display( array $row ) {
		$fare = (float) ( $row['fare_total'] ?? 0 );
		$cur  = ! empty( $row['currency'] ) ? (string) $row['currency'] : 'USD';
		if ( $fare > 0 ) {
			return $cur . ' ' . number_format( $fare, 2 );
		}
		if ( ! empty( $row['manual_review'] ) ) {
			return __( 'Custom quote', 'prime-fleet' );
		}
		return $cur . ' ' . number_format( 0, 2 );
	}

	/**
	 * @param string $status Payment status.
	 * @return string
	 */
	private static function pay_label( $status ) {
		$map = array(
			'unpaid'               => __( 'Unpaid', 'prime-fleet' ),
			'link_sent'            => __( 'Link sent', 'prime-fleet' ),
			'paid'                 => __( 'Paid', 'prime-fleet' ),
			'refunded'             => __( 'Refunded', 'prime-fleet' ),
			'pending_verification' => __( 'Pending verification', 'prime-fleet' ),
		);
		return isset( $map[ $status ] ) ? $map[ $status ] : $status;
	}
}
