<?php
/**
 * Availability blocks admin (list + modal).
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

final class Availability {

	const NONCE = 'prime_fleet_availability';

	public static function render() {
		if ( ! Capabilities::current_user_can( Capabilities::DISPATCH ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'prime-fleet' ) );
		}

		if ( ! empty( $_POST['prime_fleet_crud'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			foreach ( array( 'start_datetime', 'end_datetime' ) as $dt ) {
				if ( ! empty( $_POST[ $dt ] ) ) {
					$val = str_replace( 'T', ' ', sanitize_text_field( wp_unslash( $_POST[ $dt ] ) ) );
					if ( 16 === strlen( $val ) ) {
						$val .= ':00';
					}
					$_POST[ $dt ] = $val;
				}
			}
		}

		$notice  = Crud::handle_delete( 'availability_blocks', self::NONCE );
		$notice .= Crud::handle_save(
			'availability_blocks',
			array(
				'resource_type'  => 'text',
				'resource_id'    => 'int',
				'start_datetime' => 'text',
				'end_datetime'   => 'text',
				'reason'         => 'text',
				'notes'          => 'textarea',
			),
			self::NONCE
		);

		$list = Table_List::fetch(
			'availability_blocks',
			array(
				'search_cols' => array( 'reason', 'notes', 'resource_type' ),
				'orderby'     => 'start_datetime DESC',
			)
		);
		$rows = $list['rows'];
		$args = $list['args'];
		$filt = Table_List::query_params( $args );

		global $wpdb;
		$vehicles   = $wpdb->get_results( 'SELECT id, name FROM ' . Schema::table( 'vehicles' ) . ' ORDER BY name ASC', ARRAY_A );
		$chauffeurs = $wpdb->get_results( 'SELECT id, name FROM ' . Schema::table( 'chauffeurs' ) . ' ORDER BY name ASC', ARRAY_A );

		$vmap = array();
		$cmap = array();
		foreach ( $vehicles as $v ) {
			$vmap[ (int) $v['id'] ] = $v['name'];
		}
		foreach ( $chauffeurs as $c ) {
			$cmap[ (int) $c['id'] ] = $c['name'];
		}

		ob_start();
		if ( $notice ) {
			echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '<div class="prime-fleet-card">';
		echo Table_List::toolbar(
			'availability',
			$args,
			array(
				'placeholder' => __( 'Search availability…', 'prime-fleet' ),
			)
		);

		if ( empty( $rows ) ) {
			$filtered = '' !== (string) ( $args['s'] ?? '' );
			echo View::empty_state(
				$filtered ? __( 'No matching blocks', 'prime-fleet' ) : __( 'No blocks', 'prime-fleet' ),
				$filtered ? __( 'Try another search or reset filters.', 'prime-fleet' ) : __( 'Block vehicles or chauffeurs when they are unavailable.', 'prime-fleet' )
			);
		} else {
			echo '<div class="prime-fleet-table-wrap"><table class="prime-fleet-table"><thead><tr><th>' . esc_html__( 'Resource', 'prime-fleet' ) . '</th><th>' . esc_html__( 'Window', 'prime-fleet' ) . '</th><th>' . esc_html__( 'Reason', 'prime-fleet' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				$rid    = (int) $r['resource_id'];
				$rtype  = $r['resource_type'];
				$rlabel = ( 'chauffeur' === $rtype ? ( $cmap[ $rid ] ?? '#' . $rid ) : ( $vmap[ $rid ] ?? '#' . $rid ) );
				$record = array(
					'id'             => (int) $r['id'],
					'resource_type'  => $rtype,
					'resource_id'    => $rid,
					'start_datetime' => self::to_local( $r['start_datetime'] ?? '' ),
					'end_datetime'   => self::to_local( $r['end_datetime'] ?? '' ),
					'reason'         => $r['reason'],
					'notes'          => $r['notes'],
				);
				$del_q = array_merge(
					$filt,
					array(
						'prime_fleet_delete' => (int) $r['id'],
						'_wpnonce'       => wp_create_nonce( self::NONCE ),
					)
				);
				echo '<tr><td><strong>' . esc_html( ucfirst( $rtype ) ) . '</strong><br><span class="prime-fleet-help">' . esc_html( $rlabel ) . '</span></td>';
				echo '<td>' . esc_html( $r['start_datetime'] . ' → ' . $r['end_datetime'] ) . '</td>';
				echo '<td>' . esc_html( $r['reason'] ) . '</td>';
				echo '<td class="prime-fleet-table__actions">';
				echo View::modal_button( __( 'Edit', 'prime-fleet' ), 'prime-fleet-tpl-availability', __( 'Edit block', 'prime-fleet' ), 'ghost', $record );
				echo ' <button type="button" class="prime-fleet-btn prime-fleet-btn--danger" data-pf-confirm="' . esc_attr__( 'Delete this block?', 'prime-fleet' ) . '" data-pf-panel="availability" data-pf-query="' . esc_attr( wp_json_encode( $del_q ) ) . '">' . esc_html__( 'Delete', 'prime-fleet' ) . '</button>';
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
			echo Table_List::pagination( 'availability', $list['total'], $args );
		}
		echo '</div>';

		echo '<div id="prime-fleet-tpl-availability" class="prime-fleet-form-source" hidden><form method="post" data-pf-modal-form="1">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="prime_fleet_crud" value="1" /><input type="hidden" name="id" value="0" data-pf-field="id" />';
		echo '<div class="prime-fleet-form-grid prime-fleet-form-grid--2">';
		echo '<div class="prime-fleet-field"><label for="av_resource_type">' . esc_html__( 'Resource type', 'prime-fleet' ) . '</label><select id="av_resource_type" name="resource_type" data-pf-field="resource_type">';
		echo '<option value="vehicle">' . esc_html__( 'Vehicle', 'prime-fleet' ) . '</option>';
		echo '<option value="chauffeur">' . esc_html__( 'Chauffeur', 'prime-fleet' ) . '</option>';
		echo '</select></div>';
		echo '<div class="prime-fleet-field"><label for="av_resource_id">' . esc_html__( 'Resource', 'prime-fleet' ) . '</label><select id="av_resource_id" name="resource_id" data-pf-field="resource_id"><option value="0">—</option>';
		echo '<optgroup label="' . esc_attr__( 'Vehicles', 'prime-fleet' ) . '">';
		foreach ( $vehicles as $v ) {
			printf( '<option value="%d">%s</option>', (int) $v['id'], esc_html( $v['name'] ) );
		}
		echo '</optgroup><optgroup label="' . esc_attr__( 'Chauffeurs', 'prime-fleet' ) . '">';
		foreach ( $chauffeurs as $c ) {
			printf( '<option value="%d">%s</option>', (int) $c['id'], esc_html( $c['name'] ) );
		}
		echo '</optgroup></select></div>';
		printf( '<div class="prime-fleet-field"><label for="av_start_datetime">%s</label><input id="av_start_datetime" type="datetime-local" name="start_datetime" value="" data-pf-field="start_datetime" required /></div>', esc_html__( 'Start', 'prime-fleet' ) );
		printf( '<div class="prime-fleet-field"><label for="av_end_datetime">%s</label><input id="av_end_datetime" type="datetime-local" name="end_datetime" value="" data-pf-field="end_datetime" required /></div>', esc_html__( 'End', 'prime-fleet' ) );
		self::text( 'reason', __( 'Reason', 'prime-fleet' ), '' );
		self::textarea( 'notes', __( 'Notes', 'prime-fleet' ), '' );
		echo '</div><div class="prime-fleet-modal__footer"><button type="button" class="prime-fleet-btn prime-fleet-btn--ghost" data-pf-modal-close="1">' . esc_html__( 'Cancel', 'prime-fleet' ) . '</button><button type="submit" class="prime-fleet-btn">' . esc_html__( 'Save block', 'prime-fleet' ) . '</button></div></form></div>';

		View::shell( __( 'Availability', 'prime-fleet' ), ob_get_clean(), array( View::modal_button( __( 'Add block', 'prime-fleet' ), 'prime-fleet-tpl-availability', __( 'Add availability block', 'prime-fleet' ) ) ) );
	}

	/**
	 * @param string $mysql Datetime.
	 * @return string
	 */
	private static function to_local( $mysql ) {
		if ( ! $mysql ) {
			return '';
		}
		return str_replace( ' ', 'T', substr( $mysql, 0, 16 ) );
	}

	private static function text( $name, $label, $value ) {
		printf( '<div class="prime-fleet-field"><label for="av_%1$s">%2$s</label><input id="av_%1$s" type="text" name="%1$s" value="%3$s" data-pf-field="%1$s" /></div>', esc_attr( $name ), esc_html( $label ), esc_attr( (string) $value ) );
	}

	private static function textarea( $name, $label, $value ) {
		printf( '<div class="prime-fleet-field prime-fleet-field--block"><label for="av_%1$s">%2$s</label><textarea id="av_%1$s" name="%1$s" rows="3" data-pf-field="%1$s">%3$s</textarea></div>', esc_attr( $name ), esc_html( $label ), esc_textarea( (string) $value ) );
	}
}
