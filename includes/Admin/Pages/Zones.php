<?php
/**
 * Locations / zones admin (list + modal form).
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Admin\Pages;

use Prime_Fleet\Admin\Crud;
use Prime_Fleet\Admin\Table_List;
use Prime_Fleet\Admin\View;
use Prime_Fleet\Install\Capabilities;

defined( 'ABSPATH' ) || exit;

final class Zones {

	const NONCE = 'prime_fleet_zones';

	public static function render() {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'prime-fleet' ) );
		}

		$notice  = Crud::handle_delete( 'zones', self::NONCE );
		$notice .= Crud::handle_save(
			'zones',
			array(
				'name'                => 'text',
				'customer_label'      => 'text',
				'short_description'   => 'textarea',
				'pickup_instructions' => 'textarea',
				'is_active'           => 'bool',
				'display_order'       => 'int',
			),
			self::NONCE
		);

		$list = Table_List::fetch(
			'zones',
			array(
				'search_cols' => array( 'name', 'customer_label' ),
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
			'zones',
			$args,
			array(
				'placeholder' => __( 'Search zones…', 'prime-fleet' ),
				'status'      => true,
			)
		);

		if ( empty( $rows ) ) {
			$filtered = '' !== (string) ( $args['s'] ?? '' ) || '' !== (string) ( $args['status'] ?? '' );
			echo View::empty_state(
				$filtered ? __( 'No matching zones', 'prime-fleet' ) : __( 'No zones yet', 'prime-fleet' ),
				$filtered ? __( 'Try another search or reset filters.', 'prime-fleet' ) : __( 'Add pickup and destination zones.', 'prime-fleet' )
			);
		} else {
			echo '<div class="prime-fleet-table-wrap"><table class="prime-fleet-table"><thead><tr>';
			echo '<th>' . esc_html__( 'Zone', 'prime-fleet' ) . '</th><th>' . esc_html__( 'Order', 'prime-fleet' ) . '</th><th>' . esc_html__( 'Active', 'prime-fleet' ) . '</th><th></th>';
			echo '</tr></thead><tbody>';
			foreach ( $rows as $row ) {
				$record = array(
					'id'                  => (int) $row['id'],
					'name'                => $row['name'],
					'customer_label'      => $row['customer_label'],
					'short_description'   => $row['short_description'],
					'pickup_instructions' => $row['pickup_instructions'],
					'display_order'       => (int) $row['display_order'],
					'is_active'           => (int) $row['is_active'],
				);
				$del_q = array_merge(
					$filt,
					array(
						'prime_fleet_delete' => (int) $row['id'],
						'_wpnonce'       => wp_create_nonce( self::NONCE ),
					)
				);
				echo '<tr>';
				echo '<td><strong>' . esc_html( $row['name'] ) . '</strong>';
				if ( $row['customer_label'] && $row['customer_label'] !== $row['name'] ) {
					echo '<br><span class="prime-fleet-help">' . esc_html( $row['customer_label'] ) . '</span>';
				}
				echo '</td>';
				echo '<td>' . (int) $row['display_order'] . '</td>';
				echo '<td>' . ( $row['is_active'] ? '<span class="prime-fleet-badge prime-fleet-badge--ok">Active</span>' : '<span class="prime-fleet-badge prime-fleet-badge--off">Off</span>' ) . '</td>';
				echo '<td class="prime-fleet-table__actions">';
				echo View::modal_button( __( 'Edit', 'prime-fleet' ), 'prime-fleet-tpl-zone', __( 'Edit zone', 'prime-fleet' ), 'ghost', $record );
				echo ' <button type="button" class="prime-fleet-btn prime-fleet-btn--danger" data-pf-confirm-title="' . esc_attr__( 'Delete zone', 'prime-fleet' ) . '" data-pf-confirm="' . esc_attr__( 'Delete this zone? Routes using it may break until updated.', 'prime-fleet' ) . '" data-pf-panel="zones" data-pf-query="' . esc_attr( wp_json_encode( $del_q ) ) . '">' . esc_html__( 'Delete', 'prime-fleet' ) . '</button>';
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
			echo Table_List::pagination( 'zones', $list['total'], $args );
		}
		echo '</div>';

		echo '<div id="prime-fleet-tpl-zone" class="prime-fleet-form-source" hidden>';
		echo '<form method="post" data-pf-modal-form="1">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="prime_fleet_crud" value="1" /><input type="hidden" name="id" value="0" data-pf-field="id" />';
		echo '<div class="prime-fleet-form-grid prime-fleet-form-grid--2">';
		self::field_text( 'name', __( 'Name', 'prime-fleet' ), '' );
		self::field_text( 'customer_label', __( 'Customer label', 'prime-fleet' ), '' );
		self::field_textarea( 'short_description', __( 'Short description', 'prime-fleet' ), '' );
		self::field_textarea( 'pickup_instructions', __( 'Pickup instructions', 'prime-fleet' ), '' );
		self::field_number( 'display_order', __( 'Display order', 'prime-fleet' ), 0 );
		echo '</div><div class="prime-fleet-checks">';
		self::field_checkbox( 'is_active', __( 'Active', 'prime-fleet' ), 1 );
		echo '</div>';
		echo '<div class="prime-fleet-modal__footer"><button type="button" class="prime-fleet-btn prime-fleet-btn--ghost" data-pf-modal-close="1">' . esc_html__( 'Cancel', 'prime-fleet' ) . '</button>';
		echo '<button type="submit" class="prime-fleet-btn">' . esc_html__( 'Save zone', 'prime-fleet' ) . '</button></div>';
		echo '</form></div>';

		View::shell(
			__( 'Locations / Zones', 'prime-fleet' ),
			ob_get_clean(),
			array(
				View::modal_button( __( 'Add zone', 'prime-fleet' ), 'prime-fleet-tpl-zone', __( 'Add zone', 'prime-fleet' ) ),
			)
		);
	}

	private static function field_text( $name, $label, $value ) {
		printf( '<div class="prime-fleet-field"><label for="z_%1$s">%2$s</label><input id="z_%1$s" type="text" name="%1$s" value="%3$s" data-pf-field="%1$s" /></div>', esc_attr( $name ), esc_html( $label ), esc_attr( (string) $value ) );
	}

	private static function field_number( $name, $label, $value ) {
		printf( '<div class="prime-fleet-field"><label for="z_%1$s">%2$s</label><input id="z_%1$s" type="number" name="%1$s" value="%3$s" data-pf-field="%1$s" /></div>', esc_attr( $name ), esc_html( $label ), esc_attr( (string) $value ) );
	}

	private static function field_textarea( $name, $label, $value ) {
		printf( '<div class="prime-fleet-field prime-fleet-field--block"><label for="z_%1$s">%2$s</label><textarea id="z_%1$s" name="%1$s" rows="3" data-pf-field="%1$s">%3$s</textarea></div>', esc_attr( $name ), esc_html( $label ), esc_textarea( (string) $value ) );
	}

	private static function field_checkbox( $name, $label, $value ) {
		printf( '<label class="prime-fleet-check"><input type="checkbox" name="%1$s" value="1" data-pf-field="%1$s" %2$s /> <span>%3$s</span></label>', esc_attr( $name ), checked( (int) $value, 1, false ), esc_html( $label ) );
	}
}
