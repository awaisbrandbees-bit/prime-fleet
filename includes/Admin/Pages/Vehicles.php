<?php
/**
 * Vehicles / fleet admin (list + modal).
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Admin\Pages;

use Prime_Fleet\Admin\Crud;
use Prime_Fleet\Admin\Table_List;
use Prime_Fleet\Admin\View;
use Prime_Fleet\Install\Capabilities;

defined( 'ABSPATH' ) || exit;

final class Vehicles {

	const NONCE = 'prime_fleet_vehicles';

	public static function render() {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'prime-fleet' ) );
		}

		$notice  = Crud::handle_delete( 'vehicles', self::NONCE );
		$notice .= Crud::handle_save(
			'vehicles',
			array(
				'name'                  => 'text',
				'vehicle_type'          => 'text',
				'customer_display_name' => 'text',
				'description'           => 'textarea',
				'passenger_capacity'    => 'int',
				'checked_bag_capacity'  => 'nullable_int',
				'carry_on_bag_capacity' => 'nullable_int',
				'is_active'             => 'bool',
				'available_for_booking' => 'bool',
				'customer_visible'      => 'bool',
				'display_order'         => 'int',
			),
			self::NONCE
		);

		$list = Table_List::fetch(
			'vehicles',
			array(
				'search_cols' => array( 'name', 'vehicle_type', 'customer_display_name' ),
				'status_col'  => 'is_active',
				'orderby'     => 'display_order ASC, id ASC',
			)
		);
		$rows = $list['rows'];
		$args = $list['args'];
		$filt = Table_List::query_params( $args );

		ob_start();
		if ( $notice ) {
			echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '<div class="prime-fleet-card prime-fleet-card--list">';
		echo Table_List::toolbar(
			'vehicles',
			$args,
			array(
				'placeholder' => __( 'Search vehicles…', 'prime-fleet' ),
				'status'      => true,
			)
		);

		if ( empty( $rows ) ) {
			$filtered = '' !== (string) ( $args['s'] ?? '' ) || '' !== (string) ( $args['status'] ?? '' );
			echo View::empty_state(
				$filtered ? __( 'No matching vehicles', 'prime-fleet' ) : __( 'No vehicles', 'prime-fleet' ),
				$filtered ? __( 'Try another search or reset filters.', 'prime-fleet' ) : __( 'Add fleet units.', 'prime-fleet' )
			);
		} else {
			echo '<div class="prime-fleet-table-wrap"><table class="prime-fleet-table"><thead><tr><th>' . esc_html__( 'Vehicle', 'prime-fleet' ) . '</th><th>' . esc_html__( 'Pax', 'prime-fleet' ) . '</th><th>' . esc_html__( 'Status', 'prime-fleet' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				$record = array(
					'id'                    => (int) $r['id'],
					'name'                  => $r['name'],
					'vehicle_type'          => $r['vehicle_type'],
					'customer_display_name' => $r['customer_display_name'],
					'description'           => $r['description'],
					'passenger_capacity'    => (int) $r['passenger_capacity'],
					'checked_bag_capacity'  => $r['checked_bag_capacity'],
					'carry_on_bag_capacity' => $r['carry_on_bag_capacity'],
					'display_order'         => (int) $r['display_order'],
					'is_active'             => (int) $r['is_active'],
					'available_for_booking' => (int) $r['available_for_booking'],
					'customer_visible'      => (int) $r['customer_visible'],
				);
				$del_q = array_merge(
					$filt,
					array(
						'prime_fleet_delete' => (int) $r['id'],
						'_wpnonce'       => wp_create_nonce( self::NONCE ),
					)
				);
				echo '<tr><td><strong>' . esc_html( $r['name'] ) . '</strong><br><span class="prime-fleet-help">' . esc_html( $r['vehicle_type'] ) . '</span></td>';
				echo '<td>' . (int) $r['passenger_capacity'] . '</td>';
				echo '<td>' . ( $r['is_active'] ? '<span class="prime-fleet-badge prime-fleet-badge--ok">Active</span>' : '<span class="prime-fleet-badge prime-fleet-badge--off">Off</span>' ) . '</td>';
				echo '<td class="prime-fleet-table__actions">';
				echo View::modal_button( __( 'Edit', 'prime-fleet' ), 'prime-fleet-tpl-vehicle', __( 'Edit vehicle', 'prime-fleet' ), 'ghost', $record );
				echo ' <button type="button" class="prime-fleet-btn prime-fleet-btn--danger" data-pf-confirm-title="' . esc_attr__( 'Delete vehicle', 'prime-fleet' ) . '" data-pf-confirm="' . esc_attr__( 'Delete this vehicle from the fleet?', 'prime-fleet' ) . '" data-pf-panel="vehicles" data-pf-query="' . esc_attr( wp_json_encode( $del_q ) ) . '">' . esc_html__( 'Delete', 'prime-fleet' ) . '</button>';
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
			echo Table_List::pagination( 'vehicles', $list['total'], $args );
		}
		echo '</div>';

		echo '<div id="prime-fleet-tpl-vehicle" class="prime-fleet-form-source" hidden><form method="post" data-pf-modal-form="1">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="prime_fleet_crud" value="1" /><input type="hidden" name="id" value="0" data-pf-field="id" />';
		echo '<p class="prime-fleet-help">' . esc_html__( 'Leave bag capacities empty until the client confirms numbers.', 'prime-fleet' ) . '</p>';
		echo '<div class="prime-fleet-form-grid prime-fleet-form-grid--2">';
		self::text( 'name', __( 'Name', 'prime-fleet' ), '' );
		self::text( 'vehicle_type', __( 'Vehicle type', 'prime-fleet' ), '' );
		self::text( 'customer_display_name', __( 'Customer display name', 'prime-fleet' ), '' );
		self::textarea( 'description', __( 'Description', 'prime-fleet' ), '' );
		self::number( 'passenger_capacity', __( 'Passenger capacity', 'prime-fleet' ), 0 );
		self::number( 'checked_bag_capacity', __( 'Checked bag capacity', 'prime-fleet' ), '' );
		self::number( 'carry_on_bag_capacity', __( 'Carry-on bag capacity', 'prime-fleet' ), '' );
		self::number( 'display_order', __( 'Display order', 'prime-fleet' ), 0 );
		echo '</div><div class="prime-fleet-checks">';
		self::checkbox( 'is_active', __( 'Active', 'prime-fleet' ), 1 );
		self::checkbox( 'available_for_booking', __( 'Available for booking', 'prime-fleet' ), 1 );
		self::checkbox( 'customer_visible', __( 'Customer visible', 'prime-fleet' ), 1 );
		echo '</div><div class="prime-fleet-modal__footer"><button type="button" class="prime-fleet-btn prime-fleet-btn--ghost" data-pf-modal-close="1">' . esc_html__( 'Cancel', 'prime-fleet' ) . '</button><button type="submit" class="prime-fleet-btn">' . esc_html__( 'Save vehicle', 'prime-fleet' ) . '</button></div></form></div>';

		View::shell( __( 'Vehicles', 'prime-fleet' ), ob_get_clean(), array( View::modal_button( __( 'Add vehicle', 'prime-fleet' ), 'prime-fleet-tpl-vehicle', __( 'Add vehicle', 'prime-fleet' ) ) ) );
	}

	private static function text( $name, $label, $value ) {
		printf( '<div class="prime-fleet-field"><label for="v_%1$s">%2$s</label><input id="v_%1$s" type="text" name="%1$s" value="%3$s" data-pf-field="%1$s" /></div>', esc_attr( $name ), esc_html( $label ), esc_attr( (string) $value ) );
	}

	private static function number( $name, $label, $value ) {
		printf( '<div class="prime-fleet-field"><label for="v_%1$s">%2$s</label><input id="v_%1$s" type="number" name="%1$s" value="%3$s" data-pf-field="%1$s" /></div>', esc_attr( $name ), esc_html( $label ), esc_attr( (string) $value ) );
	}

	private static function textarea( $name, $label, $value ) {
		printf( '<div class="prime-fleet-field prime-fleet-field--block"><label for="v_%1$s">%2$s</label><textarea id="v_%1$s" name="%1$s" rows="3" data-pf-field="%1$s">%3$s</textarea></div>', esc_attr( $name ), esc_html( $label ), esc_textarea( (string) $value ) );
	}

	private static function checkbox( $name, $label, $value ) {
		printf( '<label class="prime-fleet-check"><input type="checkbox" name="%1$s" value="1" data-pf-field="%1$s" %2$s /> <span>%3$s</span></label>', esc_attr( $name ), checked( (int) $value, 1, false ), esc_html( $label ) );
	}
}
