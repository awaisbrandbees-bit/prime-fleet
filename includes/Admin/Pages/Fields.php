<?php
/**
 * Field library admin (list + modal form).
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

final class Fields {

	const NONCE = 'prime_fleet_fields';

	/**
	 * Field type options for filter + form.
	 *
	 * @return array<string,string>
	 */
	private static function field_types() {
		return array(
			'text'     => __( 'Text', 'prime-fleet' ),
			'textarea' => __( 'Textarea', 'prime-fleet' ),
			'number'   => __( 'Number', 'prime-fleet' ),
			'email'    => __( 'Email', 'prime-fleet' ),
			'phone'    => __( 'Phone', 'prime-fleet' ),
			'date'     => __( 'Date', 'prime-fleet' ),
			'time'     => __( 'Time', 'prime-fleet' ),
			'dropdown' => __( 'Dropdown', 'prime-fleet' ),
			'radio'    => __( 'Radio', 'prime-fleet' ),
			'checkbox' => __( 'Checkbox', 'prime-fleet' ),
			'zone'     => __( 'Zone', 'prime-fleet' ),
			'info'     => __( 'Info', 'prime-fleet' ),
		);
	}

	public static function render() {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'prime-fleet' ) );
		}

		$notice  = self::handle_delete();
		$notice .= self::handle_save();

		$types = self::field_types();

		$list = Table_List::fetch(
			'fields',
			array(
				'search_cols' => array( 'label', 'field_key' ),
				'type_col'    => 'field_type',
				'orderby'     => 'label ASC, id ASC',
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
			'fields',
			$args,
			array(
				'placeholder' => __( 'Search fields…', 'prime-fleet' ),
				'types'       => $types,
			)
		);

		if ( empty( $rows ) ) {
			$filtered = '' !== (string) ( $args['s'] ?? '' ) || '' !== (string) ( $args['type'] ?? '' );
			echo View::empty_state(
				$filtered ? __( 'No matching fields', 'prime-fleet' ) : __( 'No fields yet', 'prime-fleet' ),
				$filtered ? __( 'Try another search or reset filters.', 'prime-fleet' ) : __( 'Add reusable fields for service forms.', 'prime-fleet' )
			);
		} else {
			echo '<div class="prime-fleet-table-wrap"><table class="prime-fleet-table"><thead><tr>';
			echo '<th>' . esc_html__( 'Label', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Key', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Type', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'System', 'prime-fleet' ) . '</th>';
			echo '<th></th>';
			echo '</tr></thead><tbody>';
			foreach ( $rows as $row ) {
				$is_system = ! empty( $row['is_system'] );
				$record    = array(
					'id'           => (int) $row['id'],
					'label'        => $row['label'],
					'field_key'    => $row['field_key'],
					'field_type'   => $row['field_type'],
					'placeholder'  => $row['placeholder'],
					'help_text'    => $row['help_text'],
					'options_json' => $row['options_json'],
					'is_system'    => (int) $is_system,
				);
				echo '<tr>';
				echo '<td><strong>' . esc_html( $row['label'] ) . '</strong></td>';
				echo '<td><code>' . esc_html( $row['field_key'] ) . '</code></td>';
				echo '<td>' . esc_html( $row['field_type'] ) . '</td>';
				echo '<td>' . ( $is_system ? '<span class="prime-fleet-badge prime-fleet-badge--ok">' . esc_html__( 'System', 'prime-fleet' ) . '</span>' : '—' ) . '</td>';
				echo '<td class="prime-fleet-table__actions">';
				echo View::modal_button( __( 'Edit', 'prime-fleet' ), 'prime-fleet-tpl-field', __( 'Edit field', 'prime-fleet' ), 'ghost', $record );
				if ( ! $is_system ) {
					$del_q = array_merge(
						$filt,
						array(
							'prime_fleet_delete' => (int) $row['id'],
							'_wpnonce'       => wp_create_nonce( self::NONCE ),
						)
					);
					echo ' <button type="button" class="prime-fleet-btn prime-fleet-btn--danger" data-pf-confirm-title="' . esc_attr__( 'Delete field', 'prime-fleet' ) . '" data-pf-confirm="' . esc_attr__( 'Delete this field? Services using it may break until updated.', 'prime-fleet' ) . '" data-pf-panel="fields" data-pf-query="' . esc_attr( wp_json_encode( $del_q ) ) . '">' . esc_html__( 'Delete', 'prime-fleet' ) . '</button>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
			echo Table_List::pagination( 'fields', $list['total'], $args );
		}
		echo '</div>';

		echo '<div id="prime-fleet-tpl-field" class="prime-fleet-form-source" hidden>';
		echo '<form method="post" data-pf-modal-form="1">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="prime_fleet_crud" value="1" /><input type="hidden" name="id" value="0" data-pf-field="id" />';
		echo '<input type="hidden" name="is_system" value="0" data-pf-field="is_system" />';
		echo '<p class="prime-fleet-help">' . esc_html__( 'System field keys cannot be changed or deleted. Options JSON is used for dropdown/radio choices.', 'prime-fleet' ) . '</p>';
		echo '<div class="prime-fleet-form-grid prime-fleet-form-grid--2">';
		self::field_text( 'label', __( 'Label', 'prime-fleet' ), '' );
		self::field_text( 'field_key', __( 'Field key', 'prime-fleet' ), '' );
		echo '<div class="prime-fleet-field"><label for="f_field_type">' . esc_html__( 'Field type', 'prime-fleet' ) . '</label><select id="f_field_type" name="field_type" data-pf-field="field_type">';
		foreach ( $types as $k => $label ) {
			printf( '<option value="%1$s">%2$s</option>', esc_attr( $k ), esc_html( $label ) );
		}
		echo '</select></div>';
		self::field_text( 'placeholder', __( 'Placeholder', 'prime-fleet' ), '' );
		self::field_textarea( 'help_text', __( 'Help text', 'prime-fleet' ), '' );
		self::field_textarea( 'options_json', __( 'Options JSON', 'prime-fleet' ), '' );
		echo '</div>';
		echo '<p class="prime-fleet-help">' . esc_html__( 'JSON options for dropdown/radio, e.g. {"options":["One Way","Round Trip"]}.', 'prime-fleet' ) . '</p>';
		echo '<div class="prime-fleet-modal__footer"><button type="button" class="prime-fleet-btn prime-fleet-btn--ghost" data-pf-modal-close="1">' . esc_html__( 'Cancel', 'prime-fleet' ) . '</button>';
		echo '<button type="submit" class="prime-fleet-btn">' . esc_html__( 'Save field', 'prime-fleet' ) . '</button></div>';
		echo '</form></div>';

		View::shell(
			__( 'Field library', 'prime-fleet' ),
			ob_get_clean(),
			array(
				View::modal_button( __( 'Add field', 'prime-fleet' ), 'prime-fleet-tpl-field', __( 'Add field', 'prime-fleet' ) ),
			)
		);
	}

	/**
	 * @return string
	 */
	private static function handle_delete() {
		if ( empty( $_GET['prime_fleet_delete'] ) || empty( $_GET['_wpnonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return '';
		}
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return View::notice( __( 'You do not have permission to delete.', 'prime-fleet' ), 'error' );
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), self::NONCE ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return View::notice( __( 'Invalid request.', 'prime-fleet' ), 'error' );
		}

		global $wpdb;
		$id        = absint( $_GET['prime_fleet_delete'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_system = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT is_system FROM ' . Schema::table( 'fields' ) . ' WHERE id = %d', $id )
		);
		if ( $is_system ) {
			return View::notice( __( 'System fields cannot be deleted.', 'prime-fleet' ), 'error' );
		}

		return Crud::handle_delete( 'fields', self::NONCE );
	}

	/**
	 * @return string
	 */
	private static function handle_save() {
		if ( empty( $_POST['prime_fleet_crud'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return '';
		}
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return View::notice( __( 'You do not have permission to save.', 'prime-fleet' ), 'error' );
		}

		$id = absint( $_POST['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$cols = array(
			'label'        => 'text',
			'field_type'   => 'text',
			'placeholder'  => 'text',
			'help_text'    => 'textarea',
			'options_json' => 'textarea',
		);

		if ( $id ) {
			global $wpdb;
			$is_system = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT is_system FROM ' . Schema::table( 'fields' ) . ' WHERE id = %d', $id )
			);
			if ( ! $is_system ) {
				$cols = array_merge( array( 'field_key' => 'text' ), $cols );
			}
		} else {
			$cols = array_merge( array( 'field_key' => 'text' ), $cols );
		}

		return Crud::handle_save( 'fields', $cols, self::NONCE );
	}

	private static function field_text( $name, $label, $value ) {
		printf( '<div class="prime-fleet-field"><label for="f_%1$s">%2$s</label><input id="f_%1$s" type="text" name="%1$s" value="%3$s" data-pf-field="%1$s" /></div>', esc_attr( $name ), esc_html( $label ), esc_attr( (string) $value ) );
	}

	private static function field_textarea( $name, $label, $value ) {
		printf( '<div class="prime-fleet-field prime-fleet-field--block"><label for="f_%1$s">%2$s</label><textarea id="f_%1$s" name="%1$s" rows="3" data-pf-field="%1$s">%3$s</textarea></div>', esc_attr( $name ), esc_html( $label ), esc_textarea( (string) $value ) );
	}
}
