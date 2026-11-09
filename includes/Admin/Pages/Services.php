<?php
/**
 * Services list + add modal.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Admin\Pages;

use Prime_Fleet\Admin\Table_List;
use Prime_Fleet\Admin\View;
use Prime_Fleet\Database\Schema;
use Prime_Fleet\Install\Capabilities;
use Prime_Fleet\Services\Config_Compiler;

defined( 'ABSPATH' ) || exit;

final class Services {

	const NONCE = 'prime_fleet_services';

	public static function render() {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'prime-fleet' ) );
		}

		$notice   = self::handle_delete();
		$navigate = '';

		if ( ! empty( $_POST['prime_fleet_action'] ) && 'create_service' === sanitize_key( wp_unslash( $_POST['prime_fleet_action'] ) ) ) {
			check_admin_referer( 'prime_fleet_create_service' );
			global $wpdb;
			$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
			$type = sanitize_key( wp_unslash( $_POST['booking_type'] ?? 'route' ) );
			$slug = sanitize_title( $name );
			$wpdb->insert(
				Schema::table( 'services' ),
				array(
					'name'                         => $name,
					'internal_name'                => $name,
					'slug'                         => $slug ? $slug : 'service-' . time(),
					'booking_type'                 => $type,
					'requires_manual_confirmation' => 1,
					'is_active'                    => 1,
					'customer_visible'             => 1,
				)
			);
			$id = (int) $wpdb->insert_id;
			Config_Compiler::invalidate();
			$notice   = View::notice( __( 'Service created.', 'prime-fleet' ) );
			$navigate = '<div hidden data-pf-navigate="service_edit" data-pf-query="' . esc_attr( wp_json_encode( array( 'service_id' => $id, 'tab' => 'fields' ) ) ) . '"></div>';
		}

		$list = Table_List::fetch(
			'services',
			array(
				'search_cols' => array( 'name', 'slug', 'internal_name' ),
				'status_col'  => 'is_active',
				'type_col'    => 'booking_type',
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
		echo $navigate; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '<div class="prime-fleet-card">';
		echo Table_List::toolbar(
			'services',
			$args,
			array(
				'placeholder' => __( 'Search services…', 'prime-fleet' ),
				'status'      => true,
				'types'       => array(
					'route'   => __( 'Route', 'prime-fleet' ),
					'hourly'  => __( 'Hourly', 'prime-fleet' ),
					'package' => __( 'Package', 'prime-fleet' ),
					'quote'   => __( 'Custom quote', 'prime-fleet' ),
				),
			)
		);

		if ( empty( $rows ) ) {
			$filtered = '' !== (string) ( $args['s'] ?? '' ) || '' !== (string) ( $args['status'] ?? '' ) || '' !== (string) ( $args['type'] ?? '' );
			echo View::empty_state(
				$filtered ? __( 'No matching services', 'prime-fleet' ) : __( 'No services yet', 'prime-fleet' ),
				$filtered ? __( 'Try another search or reset filters.', 'prime-fleet' ) : __( 'Add your first service to get started.', 'prime-fleet' )
			);
		} else {
			echo '<div class="prime-fleet-table-wrap"><table class="prime-fleet-table"><thead><tr>';
			echo '<th>' . esc_html__( 'Service', 'prime-fleet' ) . '</th><th>' . esc_html__( 'Type', 'prime-fleet' ) . '</th><th>' . esc_html__( 'Visible', 'prime-fleet' ) . '</th><th>' . esc_html__( 'Active', 'prime-fleet' ) . '</th><th></th>';
			echo '</tr></thead><tbody>';
			foreach ( $rows as $row ) {
				$del_q = array_merge(
					$filt,
					array(
						'prime_fleet_delete' => (int) $row['id'],
						'_wpnonce'       => wp_create_nonce( self::NONCE ),
					)
				);
				echo '<tr>';
				echo '<td><strong>' . esc_html( $row['name'] ) . '</strong><br><span class="prime-fleet-help">' . esc_html( $row['slug'] ) . '</span></td>';
				echo '<td>' . esc_html( $row['booking_type'] ) . '</td>';
				echo '<td>' . ( $row['customer_visible'] ? '<span class="prime-fleet-badge prime-fleet-badge--ok">Yes</span>' : '<span class="prime-fleet-badge prime-fleet-badge--off">No</span>' ) . '</td>';
				echo '<td>' . ( $row['is_active'] ? '<span class="prime-fleet-badge prime-fleet-badge--ok">Active</span>' : '<span class="prime-fleet-badge prime-fleet-badge--off">Off</span>' ) . '</td>';
				echo '<td class="prime-fleet-table__actions">';
				echo View::panel_button( __( 'Configure', 'prime-fleet' ), 'service_edit', 'ghost', array( 'service_id' => (int) $row['id'] ) );
				echo ' <button type="button" class="prime-fleet-btn prime-fleet-btn--danger" data-pf-confirm-title="' . esc_attr__( 'Delete service', 'prime-fleet' ) . '" data-pf-confirm="' . esc_attr__( 'This removes the service and its attached booking fields. This cannot be undone.', 'prime-fleet' ) . '" data-pf-panel="services" data-pf-query="' . esc_attr( wp_json_encode( $del_q ) ) . '">' . esc_html__( 'Delete', 'prime-fleet' ) . '</button>';
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
			echo Table_List::pagination( 'services', $list['total'], $args );
		}
		echo '</div>';

		echo '<div id="prime-fleet-tpl-service-add" class="prime-fleet-form-source" hidden>';
		echo '<form method="post" data-pf-modal-form="1">';
		wp_nonce_field( 'prime_fleet_create_service' );
		echo '<input type="hidden" name="prime_fleet_action" value="create_service" />';
		echo '<div class="prime-fleet-form-grid prime-fleet-form-grid--2">';
		echo '<div class="prime-fleet-field"><label for="svc_name">' . esc_html__( 'Service name', 'prime-fleet' ) . '</label><input id="svc_name" type="text" name="name" required /></div>';
		echo '<div class="prime-fleet-field"><label for="svc_type">' . esc_html__( 'Booking type', 'prime-fleet' ) . '</label><select id="svc_type" name="booking_type"><option value="route">Route</option><option value="hourly">Hourly</option><option value="package">Package</option><option value="quote">Custom quote</option></select></div>';
		echo '</div>';
		echo '<div class="prime-fleet-modal__footer"><button type="button" class="prime-fleet-btn prime-fleet-btn--ghost" data-pf-modal-close="1">' . esc_html__( 'Cancel', 'prime-fleet' ) . '</button>';
		echo '<button type="submit" class="prime-fleet-btn">' . esc_html__( 'Create service', 'prime-fleet' ) . '</button></div>';
		echo '</form></div>';

		View::shell(
			__( 'Services & Booking Forms', 'prime-fleet' ),
			ob_get_clean(),
			array(
				View::modal_button( __( 'Add service', 'prime-fleet' ), 'prime-fleet-tpl-service-add', __( 'Add service', 'prime-fleet' ) ),
			)
		);
	}

	/**
	 * @return string
	 */
	private static function handle_delete() {
		if ( empty( $_GET['prime_fleet_delete'] ) || empty( $_GET['_wpnonce'] ) ) {
			return '';
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), self::NONCE ) ) {
			return View::notice( __( 'Invalid request.', 'prime-fleet' ), 'error' );
		}
		$id = absint( $_GET['prime_fleet_delete'] );
		global $wpdb;
		$wpdb->delete( Schema::table( 'service_fields' ), array( 'service_id' => $id ), array( '%d' ) );
		$wpdb->delete( Schema::table( 'services' ), array( 'id' => $id ), array( '%d' ) );
		Config_Compiler::invalidate();
		return View::notice( __( 'Service deleted.', 'prime-fleet' ) );
	}
}
