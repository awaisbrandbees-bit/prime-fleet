<?php
/**
 * Simple CRUD helper for admin list/edit forms.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Admin;

use Prime_Fleet\Database\Schema;
use Prime_Fleet\Install\Capabilities;
use Prime_Fleet\Services\Config_Compiler;

defined( 'ABSPATH' ) || exit;

final class Crud {

	/**
	 * @param string $table Logical table.
	 * @param array  $columns Column => sanitize callback name.
	 * @param string $nonce_action Nonce.
	 * @param string $cap Capability required.
	 * @return string Notice HTML.
	 */
	public static function handle_save( $table, array $columns, $nonce_action, $cap = Capabilities::MANAGE ) {
		if ( empty( $_POST['prime_fleet_crud'] ) ) {
			return '';
		}
		if ( ! current_user_can( $cap ) ) {
			return View::notice( __( 'You do not have permission to save.', 'prime-fleet' ), 'error' );
		}
		check_admin_referer( $nonce_action );

		global $wpdb;
		$full = Schema::table( $table );
		$id   = absint( $_POST['id'] ?? 0 );
		$data = array();
		$fmt  = array();

		foreach ( $columns as $col => $type ) {
			$raw = $_POST[ $col ] ?? null;
			if ( 'bool' === $type ) {
				$data[ $col ] = ! empty( $raw ) ? 1 : 0;
				$fmt[]         = '%d';
			} elseif ( 'int' === $type ) {
				$data[ $col ] = absint( $raw );
				$fmt[]         = '%d';
			} elseif ( 'float' === $type ) {
				$data[ $col ] = (float) $raw;
				$fmt[]         = '%f';
			} elseif ( 'textarea' === $type ) {
				$data[ $col ] = sanitize_textarea_field( wp_unslash( (string) $raw ) );
				$fmt[]         = '%s';
			} elseif ( 'nullable_int' === $type ) {
				$data[ $col ] = ( '' === $raw || null === $raw ) ? null : absint( $raw );
				$fmt[]         = '%d';
			} elseif ( 'nullable_float' === $type ) {
				$data[ $col ] = ( '' === $raw || null === $raw ) ? null : (float) $raw;
				$fmt[]         = '%f';
			} elseif ( 'json_ids' === $type ) {
				$ids = array();
				if ( is_array( $raw ) ) {
					foreach ( $raw as $v ) {
						$n = absint( $v );
						if ( $n ) {
							$ids[] = $n;
						}
					}
				}
				$data[ $col ] = wp_json_encode( array_values( array_unique( $ids ) ) );
				$fmt[]         = '%s';
			} elseif ( 'conditions_group' === $type ) {
				$data[ $col ] = Condition_Builder::sanitize_group_from_post( $col );
				$fmt[]         = '%s';
			} elseif ( 'conditions_rules' === $type ) {
				$rules         = Condition_Builder::sanitize_rules_from_post( $col );
				$data[ $col ] = $rules ? wp_json_encode( $rules ) : '';
				$fmt[]         = '%s';
			} else {
				$data[ $col ] = sanitize_text_field( wp_unslash( (string) $raw ) );
				$fmt[]         = '%s';
			}
		}

		if ( $id ) {
			$wpdb->update( $full, $data, array( 'id' => $id ), $fmt, array( '%d' ) );
		} else {
			$wpdb->insert( $full, $data, $fmt );
		}

		Config_Compiler::invalidate();
		return View::notice( __( 'Saved.', 'prime-fleet' ) );
	}

	/**
	 * @param string $table Table.
	 * @param string $nonce_action Nonce.
	 * @param string $cap Capability.
	 * @return string
	 */
	public static function handle_delete( $table, $nonce_action, $cap = Capabilities::MANAGE ) {
		if ( empty( $_GET['prime_fleet_delete'] ) || empty( $_GET['_wpnonce'] ) ) {
			return '';
		}
		if ( ! current_user_can( $cap ) ) {
			return View::notice( __( 'You do not have permission to delete.', 'prime-fleet' ), 'error' );
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), $nonce_action ) ) {
			return View::notice( __( 'Invalid request.', 'prime-fleet' ), 'error' );
		}
		global $wpdb;
		$wpdb->delete( Schema::table( $table ), array( 'id' => absint( $_GET['prime_fleet_delete'] ) ), array( '%d' ) );
		Config_Compiler::invalidate();
		return View::notice( __( 'Deleted.', 'prime-fleet' ) );
	}
}
