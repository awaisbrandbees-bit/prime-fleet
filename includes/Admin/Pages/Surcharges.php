<?php
/**
 * Surcharges admin (list + modal).
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Admin\Pages;

use Prime_Fleet\Admin\Crud;
use Prime_Fleet\Admin\Table_List;
use Prime_Fleet\Admin\View;
use Prime_Fleet\Install\Capabilities;

defined( 'ABSPATH' ) || exit;

final class Surcharges {

	const NONCE = 'prime_fleet_surcharges';

	public static function render() {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'prime-fleet' ) );
		}

		$notice  = Crud::handle_delete( 'surcharges', self::NONCE );
		$notice .= Crud::handle_save(
			'surcharges',
			array(
				'name'           => 'text',
				'is_active'      => 'bool',
				'surcharge_type' => 'text',
				'value'          => 'float',
				'start_date'     => 'text',
				'end_date'       => 'text',
				'notes'          => 'textarea',
			),
			self::NONCE
		);

		$list = Table_List::fetch(
			'surcharges',
			array(
				'search_cols' => array( 'name' ),
				'status_col'  => 'is_active',
				'type_col'    => 'surcharge_type',
				'orderby'     => 'id DESC',
			)
		);
		$rows = $list['rows'];
		$args = $list['args'];
		$filt = Table_List::query_params( $args );

		ob_start();
		if ( $notice ) {
			echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '<div class="prime-fleet-card">';
		echo Table_List::toolbar(
			'surcharges',
			$args,
			array(
				'placeholder' => __( 'Search surcharges…', 'prime-fleet' ),
				'status'      => true,
				'types'       => array(
					'percent' => __( 'Percent', 'prime-fleet' ),
					'fixed'   => __( 'Fixed', 'prime-fleet' ),
				),
			)
		);

		if ( empty( $rows ) ) {
			$filtered = '' !== (string) ( $args['s'] ?? '' ) || '' !== (string) ( $args['status'] ?? '' ) || '' !== (string) ( $args['type'] ?? '' );
			echo View::empty_state(
				$filtered ? __( 'No matching surcharges', 'prime-fleet' ) : __( 'No surcharges', 'prime-fleet' ),
				$filtered ? __( 'Try another search or reset filters.', 'prime-fleet' ) : __( 'Holiday and seasonal surcharges go here. Leave dates empty until the client confirms.', 'prime-fleet' )
			);
		} else {
			echo '<div class="prime-fleet-table-wrap"><table class="prime-fleet-table"><thead><tr><th>' . esc_html__( 'Name', 'prime-fleet' ) . '</th><th>' . esc_html__( 'Type', 'prime-fleet' ) . '</th><th>' . esc_html__( 'Value', 'prime-fleet' ) . '</th><th>' . esc_html__( 'Window', 'prime-fleet' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				$record = array(
					'id'             => (int) $r['id'],
					'name'           => $r['name'],
					'surcharge_type' => $r['surcharge_type'],
					'value'          => $r['value'],
					'start_date'     => $r['start_date'],
					'end_date'       => $r['end_date'],
					'notes'          => $r['notes'],
					'is_active'      => (int) $r['is_active'],
				);
				$del_q  = array_merge(
					$filt,
					array(
						'prime_fleet_delete' => (int) $r['id'],
						'_wpnonce'       => wp_create_nonce( self::NONCE ),
					)
				);
				$window = trim( ( $r['start_date'] ?: '—' ) . ' → ' . ( $r['end_date'] ?: '—' ) );
				echo '<tr><td><strong>' . esc_html( $r['name'] ) . '</strong></td><td>' . esc_html( $r['surcharge_type'] ) . '</td><td>' . esc_html( (string) $r['value'] ) . '</td><td>' . esc_html( $window ) . '</td>';
				echo '<td class="prime-fleet-table__actions">';
				echo View::modal_button( __( 'Edit', 'prime-fleet' ), 'prime-fleet-tpl-surcharge', __( 'Edit surcharge', 'prime-fleet' ), 'ghost', $record );
				echo ' <button type="button" class="prime-fleet-btn prime-fleet-btn--danger" data-pf-confirm="' . esc_attr__( 'Delete this surcharge?', 'prime-fleet' ) . '" data-pf-panel="surcharges" data-pf-query="' . esc_attr( wp_json_encode( $del_q ) ) . '">' . esc_html__( 'Delete', 'prime-fleet' ) . '</button>';
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
			echo Table_List::pagination( 'surcharges', $list['total'], $args );
		}
		echo '</div>';

		echo '<div id="prime-fleet-tpl-surcharge" class="prime-fleet-form-source" hidden><form method="post" data-pf-modal-form="1">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="prime_fleet_crud" value="1" /><input type="hidden" name="id" value="0" data-pf-field="id" />';
		echo '<div class="prime-fleet-form-grid prime-fleet-form-grid--2">';
		self::text( 'name', __( 'Name', 'prime-fleet' ), '' );
		echo '<div class="prime-fleet-field"><label for="su_surcharge_type">' . esc_html__( 'Type', 'prime-fleet' ) . '</label><select id="su_surcharge_type" name="surcharge_type" data-pf-field="surcharge_type">';
		foreach ( array( 'percent' => __( 'Percent', 'prime-fleet' ), 'fixed' => __( 'Fixed', 'prime-fleet' ) ) as $k => $label ) {
			printf( '<option value="%1$s">%2$s</option>', esc_attr( $k ), esc_html( $label ) );
		}
		echo '</select></div>';
		self::number( 'value', __( 'Value', 'prime-fleet' ), '0' );
		printf( '<div class="prime-fleet-field"><label for="su_start_date">%s</label><input id="su_start_date" type="date" name="start_date" value="" data-pf-field="start_date" /></div>', esc_html__( 'Start date', 'prime-fleet' ) );
		printf( '<div class="prime-fleet-field"><label for="su_end_date">%s</label><input id="su_end_date" type="date" name="end_date" value="" data-pf-field="end_date" /></div>', esc_html__( 'End date', 'prime-fleet' ) );
		self::textarea( 'notes', __( 'Notes', 'prime-fleet' ), '' );
		echo '</div><div class="prime-fleet-checks">';
		self::checkbox( 'is_active', __( 'Active', 'prime-fleet' ), 1 );
		echo '</div><div class="prime-fleet-modal__footer"><button type="button" class="prime-fleet-btn prime-fleet-btn--ghost" data-pf-modal-close="1">' . esc_html__( 'Cancel', 'prime-fleet' ) . '</button><button type="submit" class="prime-fleet-btn">' . esc_html__( 'Save surcharge', 'prime-fleet' ) . '</button></div></form></div>';

		View::shell( __( 'Surcharges', 'prime-fleet' ), ob_get_clean(), array( View::modal_button( __( 'Add surcharge', 'prime-fleet' ), 'prime-fleet-tpl-surcharge', __( 'Add surcharge', 'prime-fleet' ) ) ) );
	}

	private static function text( $name, $label, $value ) {
		printf( '<div class="prime-fleet-field"><label for="su_%1$s">%2$s</label><input id="su_%1$s" type="text" name="%1$s" value="%3$s" data-pf-field="%1$s" /></div>', esc_attr( $name ), esc_html( $label ), esc_attr( (string) $value ) );
	}

	private static function number( $name, $label, $value ) {
		printf( '<div class="prime-fleet-field"><label for="su_%1$s">%2$s</label><input id="su_%1$s" type="number" step="0.01" name="%1$s" value="%3$s" data-pf-field="%1$s" /></div>', esc_attr( $name ), esc_html( $label ), esc_attr( (string) $value ) );
	}

	private static function textarea( $name, $label, $value ) {
		printf( '<div class="prime-fleet-field prime-fleet-field--block"><label for="su_%1$s">%2$s</label><textarea id="su_%1$s" name="%1$s" rows="3" data-pf-field="%1$s">%3$s</textarea></div>', esc_attr( $name ), esc_html( $label ), esc_textarea( (string) $value ) );
	}

	private static function checkbox( $name, $label, $value ) {
		printf( '<label class="prime-fleet-check"><input type="checkbox" name="%1$s" value="1" data-pf-field="%1$s" %2$s /> <span>%3$s</span></label>', esc_attr( $name ), checked( (int) $value, 1, false ), esc_html( $label ) );
	}
}
