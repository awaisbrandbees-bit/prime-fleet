<?php
/**
 * Slot offers list (staff).
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Admin\Pages;

use Prime_Fleet\Admin\Table_List;
use Prime_Fleet\Admin\View;
use Prime_Fleet\Database\Schema;
use Prime_Fleet\Install\Capabilities;

defined( 'ABSPATH' ) || exit;

final class Slot_Offers_Page {

	/**
	 * Render.
	 */
	public static function render() {
		if ( ! Capabilities::current_user_can( Capabilities::DISPATCH ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'prime-fleet' ) );
		}

		global $wpdb;
		$list = Table_List::fetch(
			'slot_offers',
			array(
				'search_cols'  => array( 'token', 'status' ),
				'status_col'   => 'status',
				'status_exact' => true,
				'orderby'      => 'id DESC',
			)
		);

		ob_start();
		echo '<div class="prime-fleet-card">';
		echo Table_List::toolbar(
			'slot_offers',
			$list['args'],
			array(
				'placeholder' => __( 'Search offers…', 'prime-fleet' ),
				'status'      => true,
				'statuses'    => array(
					'sent'     => __( 'Sent', 'prime-fleet' ),
					'accepted' => __( 'Accepted', 'prime-fleet' ),
					'draft'    => __( 'Draft', 'prime-fleet' ),
					'expired'  => __( 'Expired', 'prime-fleet' ),
				),
			)
		);

		if ( empty( $list['rows'] ) ) {
			echo View::empty_state(
				__( 'No slot offers yet', 'prime-fleet' ),
				__( 'Send an offer from a booking detail when a requested time is full.', 'prime-fleet' )
			);
		} else {
			echo '<div class="prime-fleet-table-wrap"><table class="prime-fleet-table"><thead><tr>';
			echo '<th>' . esc_html__( 'Booking', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Proposed', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Expires', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Guest link', 'prime-fleet' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $list['rows'] as $r ) {
				$ref = $wpdb->get_var(
					$wpdb->prepare(
						'SELECT reference FROM ' . Schema::table( 'bookings' ) . ' WHERE id = %d',
						(int) $r['booking_id']
					)
				);
				$url = home_url( '/?prime_fleet_offer=' . rawurlencode( $r['token'] ) );
				echo '<tr>';
				echo '<td><button type="button" class="prime-fleet-btn prime-fleet-btn--ghost" data-pf-panel="bookings" data-pf-query="' . esc_attr( wp_json_encode( array( 'id' => (int) $r['booking_id'] ) ) ) . '">' . esc_html( $ref ? $ref : '#' . $r['booking_id'] ) . '</button></td>';
				echo '<td>' . esc_html( $r['proposed_datetime'] ) . '</td>';
				echo '<td>' . esc_html( $r['status'] ) . '</td>';
				echo '<td>' . esc_html( $r['expires_at'] ? $r['expires_at'] : '—' ) . '</td>';
				echo '<td><code style="font-size:11px;word-break:break-all">' . esc_html( $url ) . '</code></td>';
				echo '</tr>';
			}
			echo '</tbody></table></div>';
			echo Table_List::pagination( 'slot_offers', (int) $list['total'], $list['args'] );
		}
		echo '</div>';

		View::shell( __( 'Slot Offers', 'prime-fleet' ), ob_get_clean() );
	}
}
