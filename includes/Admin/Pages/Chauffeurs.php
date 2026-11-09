<?php
/**
 * Chauffeurs admin (list + modal).
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Admin\Pages;

use Prime_Fleet\Admin\Crud;
use Prime_Fleet\Admin\Table_List;
use Prime_Fleet\Admin\View;
use Prime_Fleet\Database\Schema;
use Prime_Fleet\Install\Capabilities;

defined( 'ABSPATH' ) || exit;

final class Chauffeurs {

	const NONCE = 'prime_fleet_chauffeurs';

	public static function render() {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'prime-fleet' ) );
		}

		$notice  = Crud::handle_delete( 'chauffeurs', self::NONCE );
		$notice .= Crud::handle_save(
			'chauffeurs',
			array(
				'name'               => 'text',
				'phone'              => 'text',
				'whatsapp'           => 'text',
				'email'              => 'text',
				'default_vehicle_id' => 'int',
				'is_active'          => 'bool',
				'internal_notes'     => 'textarea',
			),
			self::NONCE
		);

		$list = Table_List::fetch(
			'chauffeurs',
			array(
				'search_cols' => array( 'name', 'phone', 'email', 'whatsapp' ),
				'status_col'  => 'is_active',
				'orderby'     => 'name ASC',
			)
		);
		$rows = $list['rows'];
		$args = $list['args'];
		$filt = Table_List::query_params( $args );

		global $wpdb;
		$vehicles = $wpdb->get_results( 'SELECT id, name FROM ' . Schema::table( 'vehicles' ) . ' ORDER BY display_order ASC, name ASC', ARRAY_A );
		$vmap     = array();
		foreach ( $vehicles as $v ) {
			$vmap[ (int) $v['id'] ] = $v['name'];
		}

		ob_start();
		if ( $notice ) {
			echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '<div class="prime-fleet-card">';
		echo Table_List::toolbar(
			'chauffeurs',
			$args,
			array(
				'placeholder' => __( 'Search chauffeurs…', 'prime-fleet' ),
				'status'      => true,
			)
		);

		if ( empty( $rows ) ) {
			$filtered = '' !== (string) ( $args['s'] ?? '' ) || '' !== (string) ( $args['status'] ?? '' );
			echo View::empty_state(
				$filtered ? __( 'No matching chauffeurs', 'prime-fleet' ) : __( 'No chauffeurs', 'prime-fleet' ),
				$filtered ? __( 'Try another search or reset filters.', 'prime-fleet' ) : __( 'Add drivers with Add chauffeur.', 'prime-fleet' )
			);
		} else {
			echo '<div class="prime-fleet-table-wrap"><table class="prime-fleet-table"><thead><tr><th>' . esc_html__( 'Name', 'prime-fleet' ) . '</th><th>' . esc_html__( 'Contact', 'prime-fleet' ) . '</th><th>' . esc_html__( 'Vehicle', 'prime-fleet' ) . '</th><th>' . esc_html__( 'Active', 'prime-fleet' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				$record = array(
					'id'                 => (int) $r['id'],
					'name'               => $r['name'],
					'phone'              => $r['phone'],
					'whatsapp'           => $r['whatsapp'],
					'email'              => $r['email'],
					'default_vehicle_id' => (int) $r['default_vehicle_id'],
					'internal_notes'     => $r['internal_notes'],
					'is_active'          => (int) $r['is_active'],
				);
				$vid   = (int) $r['default_vehicle_id'];
				$del_q = array_merge(
					$filt,
					array(
						'prime_fleet_delete' => (int) $r['id'],
						'_wpnonce'       => wp_create_nonce( self::NONCE ),
					)
				);
				echo '<tr><td><strong>' . esc_html( $r['name'] ) . '</strong></td>';
				echo '<td>' . esc_html( $r['phone'] ?: $r['whatsapp'] ?: $r['email'] ) . '</td>';
				echo '<td>' . esc_html( $vmap[ $vid ] ?? '—' ) . '</td>';
				echo '<td>' . ( $r['is_active'] ? '<span class="prime-fleet-badge prime-fleet-badge--ok">Active</span>' : '<span class="prime-fleet-badge prime-fleet-badge--off">Off</span>' ) . '</td>';
				echo '<td class="prime-fleet-table__actions">';
				echo View::modal_button( __( 'Edit', 'prime-fleet' ), 'prime-fleet-tpl-chauffeur', __( 'Edit chauffeur', 'prime-fleet' ), 'ghost', $record );
				echo ' <button type="button" class="prime-fleet-btn prime-fleet-btn--danger" data-pf-confirm="' . esc_attr__( 'Delete this chauffeur?', 'prime-fleet' ) . '" data-pf-panel="chauffeurs" data-pf-query="' . esc_attr( wp_json_encode( $del_q ) ) . '">' . esc_html__( 'Delete', 'prime-fleet' ) . '</button>';
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
			echo Table_List::pagination( 'chauffeurs', $list['total'], $args );
		}
		echo '</div>';

		echo '<div id="prime-fleet-tpl-chauffeur" class="prime-fleet-form-source" hidden><form method="post" data-pf-modal-form="1">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="prime_fleet_crud" value="1" /><input type="hidden" name="id" value="0" data-pf-field="id" />';
		echo '<div class="prime-fleet-form-grid prime-fleet-form-grid--2">';
		self::text( 'name', __( 'Name', 'prime-fleet' ), '' );
		self::text( 'phone', __( 'Phone', 'prime-fleet' ), '' );
		self::text( 'whatsapp', __( 'WhatsApp', 'prime-fleet' ), '' );
		self::text( 'email', __( 'Email', 'prime-fleet' ), '' );
		echo '<div class="prime-fleet-field"><label for="ch_default_vehicle_id">' . esc_html__( 'Default vehicle', 'prime-fleet' ) . '</label><select id="ch_default_vehicle_id" name="default_vehicle_id" data-pf-field="default_vehicle_id"><option value="0">—</option>';
		foreach ( $vehicles as $v ) {
			printf( '<option value="%d">%s</option>', (int) $v['id'], esc_html( $v['name'] ) );
		}
		echo '</select></div>';
		self::textarea( 'internal_notes', __( 'Internal notes', 'prime-fleet' ), '' );
		echo '</div><div class="prime-fleet-checks">';
		self::checkbox( 'is_active', __( 'Active', 'prime-fleet' ), 1 );
		echo '</div><div class="prime-fleet-modal__footer"><button type="button" class="prime-fleet-btn prime-fleet-btn--ghost" data-pf-modal-close="1">' . esc_html__( 'Cancel', 'prime-fleet' ) . '</button><button type="submit" class="prime-fleet-btn">' . esc_html__( 'Save chauffeur', 'prime-fleet' ) . '</button></div></form></div>';

		View::shell( __( 'Chauffeurs', 'prime-fleet' ), ob_get_clean(), array( View::modal_button( __( 'Add chauffeur', 'prime-fleet' ), 'prime-fleet-tpl-chauffeur', __( 'Add chauffeur', 'prime-fleet' ) ) ) );
	}

	private static function text( $name, $label, $value ) {
		printf( '<div class="prime-fleet-field"><label for="ch_%1$s">%2$s</label><input id="ch_%1$s" type="text" name="%1$s" value="%3$s" data-pf-field="%1$s" /></div>', esc_attr( $name ), esc_html( $label ), esc_attr( (string) $value ) );
	}

	private static function textarea( $name, $label, $value ) {
		printf( '<div class="prime-fleet-field prime-fleet-field--block"><label for="ch_%1$s">%2$s</label><textarea id="ch_%1$s" name="%1$s" rows="3" data-pf-field="%1$s">%3$s</textarea></div>', esc_attr( $name ), esc_html( $label ), esc_textarea( (string) $value ) );
	}

	private static function checkbox( $name, $label, $value ) {
		printf( '<label class="prime-fleet-check"><input type="checkbox" name="%1$s" value="1" data-pf-field="%1$s" %2$s /> <span>%3$s</span></label>', esc_attr( $name ), checked( (int) $value, 1, false ), esc_html( $label ) );
	}
}
