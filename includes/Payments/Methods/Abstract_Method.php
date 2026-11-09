<?php
/**
 * Shared helpers for payment method classes.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Payments\Methods;

use Prime_Fleet\Database\Schema;
use Prime_Fleet\Payments\Contracts\Payment_Method_Interface;
use Prime_Fleet\Services\Config_Compiler;

defined( 'ABSPATH' ) || exit;

abstract class Abstract_Method implements Payment_Method_Interface {

	/**
	 * @return bool
	 */
	public function is_enabled() {
		$row = $this->row();
		return $row && ! empty( $row['is_enabled'] );
	}

	/**
	 * @return array
	 */
	public function get_settings() {
		$row = $this->row();
		if ( ! $row || empty( $row['settings_json'] ) ) {
			return array();
		}
		$data = json_decode( $row['settings_json'], true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * @param array $input Input.
	 */
	public function save_settings( array $input ) {
		global $wpdb;
		$table    = Schema::table( 'payment_methods' );
		$schema   = $this->settings_schema();
		$settings = $this->get_settings();

		foreach ( $schema as $key => $field ) {
			if ( ! isset( $input[ $key ] ) ) {
				continue;
			}
			$type = isset( $field['type'] ) ? (string) $field['type'] : 'text';
			$raw  = wp_unslash( $input[ $key ] );
			if ( 'textarea' === $type ) {
				$val = sanitize_textarea_field( $raw );
			} elseif ( 'url' === $type ) {
				$val = esc_url_raw( $raw );
			} else {
				$val = sanitize_text_field( $raw );
			}
			// Keep existing secrets when password-style fields are left blank.
			if ( in_array( $type, array( 'password', 'secret' ), true ) && '' === $val ) {
				continue;
			}
			$settings[ $key ] = $val;
		}

		$enabled = ! empty( $input['is_enabled'] ) ? 1 : 0;
		$label   = isset( $input['label'] ) ? sanitize_text_field( wp_unslash( $input['label'] ) ) : $this->label();

		$existing = $this->row();
		if ( $existing ) {
			$wpdb->update(
				$table,
				array(
					'label'         => $label,
					'is_enabled'    => $enabled,
					'settings_json' => wp_json_encode( $settings ),
				),
				array( 'method_key' => $this->id() ),
				array( '%s', '%d', '%s' ),
				array( '%s' )
			);
		} else {
			$wpdb->insert(
				$table,
				array(
					'method_key'    => $this->id(),
					'label'         => $label,
					'is_enabled'    => $enabled,
					'display_order' => 10,
					'settings_json' => wp_json_encode( $settings ),
				),
				array( '%s', '%s', '%d', '%d', '%s' )
			);
		}

		Config_Compiler::invalidate();
	}

	/**
	 * Ensure DB row exists (disabled by default).
	 */
	public function ensure_row() {
		if ( $this->row() ) {
			return;
		}
		global $wpdb;
		$wpdb->insert(
			Schema::table( 'payment_methods' ),
			array(
				'method_key'    => $this->id(),
				'label'         => $this->label(),
				'is_enabled'    => 0,
				'display_order' => 10,
				'settings_json' => '{}',
			),
			array( '%s', '%s', '%d', '%d', '%s' )
		);
	}

	/**
	 * @return array|null
	 */
	protected function row() {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table( 'payment_methods' ) . ' WHERE method_key = %s',
				$this->id()
			),
			ARRAY_A
		);
	}
}
