<?php
/**
 * Gravity-style condition builder (no raw JSON for staff).
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Admin;

use Prime_Fleet\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class Condition_Builder {

	/**
	 * Allowed operators (spec §16.2).
	 *
	 * @return array<string,string>
	 */
	public static function operators() {
		return array(
			'is'                          => __( 'Is', 'prime-fleet' ),
			'is_not'                      => __( 'Is not', 'prime-fleet' ),
			'one_of'                      => __( 'Is one of', 'prime-fleet' ),
			'gt'                          => __( 'Greater than', 'prime-fleet' ),
			'lt'                          => __( 'Less than', 'prime-fleet' ),
			'has_value'                   => __( 'Has value', 'prime-fleet' ),
			'empty'                       => __( 'Is empty', 'prime-fleet' ),
			'between'                     => __( 'Between (dates)', 'prime-fleet' ),
			'between_time_cross_midnight' => __( 'Between times (cross midnight)', 'prime-fleet' ),
		);
	}

	/**
	 * Field keys available in rules.
	 *
	 * @return array<string,string>
	 */
	public static function field_choices() {
		global $wpdb;
		$out = array(
			'service_slug' => __( 'Service slug', 'prime-fleet' ),
			'pickup_date'  => __( 'Pickup date', 'prime-fleet' ),
			'pickup_time'  => __( 'Pickup time', 'prime-fleet' ),
		);
		$rows = $wpdb->get_results( 'SELECT field_key, label FROM ' . Schema::table( 'fields' ) . ' ORDER BY label ASC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$out[ $row['field_key'] ] = $row['label'] . ' (' . $row['field_key'] . ')';
			}
		}
		return $out;
	}

	/**
	 * Normalize stored JSON into { match, conditions[] }.
	 *
	 * @param mixed $raw JSON string or array.
	 * @return array{match:string,conditions:array<int,array{field:string,operator:string,value:string}>}
	 */
	public static function normalize( $raw ) {
		$data = $raw;
		if ( is_string( $raw ) ) {
			$data = json_decode( $raw, true );
		}
		if ( ! is_array( $data ) ) {
			return array(
				'match'      => 'all',
				'conditions' => array(),
			);
		}

		$match = 'all';
		$rules = array();

		if ( isset( $data['match'] ) ) {
			$match = ( 'any' === $data['match'] ) ? 'any' : 'all';
		}
		if ( isset( $data['conditions'] ) && is_array( $data['conditions'] ) ) {
			$rules = $data['conditions'];
		} elseif ( isset( $data['rules'] ) && is_array( $data['rules'] ) ) {
			$rules = $data['rules'];
		} elseif ( self::is_list( $data ) ) {
			$rules = $data;
		}

		$clean = array();
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$field = sanitize_key( (string) ( $rule['field'] ?? '' ) );
			$op    = sanitize_key( (string) ( $rule['operator'] ?? ( $rule['op'] ?? ( isset( $rule['equals'] ) ? 'is' : '' ) ) ) );
			if ( ! $field || ! $op ) {
				continue;
			}
			if ( ! isset( self::operators()[ $op ] ) ) {
				continue;
			}
			$value = $rule['value'] ?? ( $rule['equals'] ?? '' );
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', $value ) );
			}
			$clean[] = array(
				'field'    => $field,
				'operator' => $op,
				'value'    => sanitize_text_field( (string) $value ),
			);
		}

		return array(
			'match'      => $match,
			'conditions' => $clean,
		);
	}

	/**
	 * Sanitize a full group from POST (match + rules).
	 *
	 * @param string $base Field base name (e.g. conditions_json).
	 * @return string JSON or empty.
	 */
	public static function sanitize_group_from_post( $base ) {
		$match = isset( $_POST[ $base . '_match' ] ) ? sanitize_key( wp_unslash( $_POST[ $base . '_match' ] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( 'any' !== $match ) {
			$match = 'all';
		}
		$rules = self::sanitize_rules_from_post( $base );
		if ( empty( $rules ) ) {
			return '';
		}
		return wp_json_encode(
			array(
				'match'      => $match,
				'conditions' => $rules,
			)
		);
	}

	/**
	 * Sanitize rules list from POST (pricing rules JSON array).
	 *
	 * @param string $base Base name.
	 * @return array
	 */
	public static function sanitize_rules_from_post( $base ) {
		$raw = isset( $_POST[ $base . '_rules' ] ) ? wp_unslash( $_POST[ $base . '_rules' ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$ops   = self::operators();
		$clean = array();
		foreach ( $raw as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$field = sanitize_key( (string) ( $rule['field'] ?? '' ) );
			$op    = sanitize_key( (string) ( $rule['operator'] ?? '' ) );
			if ( ! $field || ! isset( $ops[ $op ] ) ) {
				continue;
			}
			if ( in_array( $op, array( 'has_value', 'empty' ), true ) ) {
				$clean[] = array(
					'field'    => $field,
					'operator' => $op,
					'value'    => '',
				);
				continue;
			}
			$value = sanitize_text_field( (string) ( $rule['value'] ?? '' ) );
			if ( '' === $value && ! in_array( $op, array( 'between', 'between_time_cross_midnight' ), true ) ) {
				continue;
			}
			$clean[] = array(
				'field'    => $field,
				'operator' => $op,
				'value'    => $value,
			);
		}
		return $clean;
	}

	/**
	 * Sanitize arbitrary JSON blob into normalized group JSON.
	 *
	 * @param string $raw Raw.
	 * @return string
	 */
	public static function sanitize_stored_json( $raw ) {
		$norm = self::normalize( is_string( $raw ) ? wp_unslash( $raw ) : $raw );
		if ( empty( $norm['conditions'] ) ) {
			return '';
		}
		return wp_json_encode( $norm );
	}

	/**
	 * Render builder UI.
	 *
	 * @param string $name Storage field name / POST base.
	 * @param string $label Label.
	 * @param string $mode group|rules.
	 * @return string
	 */
	public static function render( $name, $label, $mode = 'group' ) {
		$fields = self::field_choices();
		$ops    = self::operators();

		ob_start();
		echo '<div class="prime-fleet-conditions" data-pf-condition-builder="' . esc_attr( $name ) . '" data-pf-condition-mode="' . esc_attr( $mode ) . '">';
		echo '<div class="prime-fleet-conditions__head">';
		echo '<strong>' . esc_html( $label ) . '</strong>';
		if ( 'group' === $mode ) {
			echo '<label class="prime-fleet-conditions__match">' . esc_html__( 'Match', 'prime-fleet' ) . ' ';
			echo '<select name="' . esc_attr( $name ) . '_match" data-pf-cond-match="1">';
			echo '<option value="all">' . esc_html__( 'All rules (AND)', 'prime-fleet' ) . '</option>';
			echo '<option value="any">' . esc_html__( 'Any rule (OR)', 'prime-fleet' ) . '</option>';
			echo '</select></label>';
		}
		echo '</div>';
		echo '<p class="prime-fleet-help">' . esc_html__( 'Show / require this field when the rules below are met. Leave empty for always.', 'prime-fleet' ) . '</p>';
		echo '<div class="prime-fleet-conditions__rows" data-pf-cond-rows="1"></div>';
		echo '<button type="button" class="prime-fleet-btn prime-fleet-btn--ghost" data-pf-cond-add="1">' . esc_html__( 'Add rule', 'prime-fleet' ) . '</button>';

		// Template row for JS clone.
		echo '<template data-pf-cond-tpl="1">';
		echo '<div class="prime-fleet-conditions__row">';
		echo '<select data-pf-cond-field="1" name="' . esc_attr( $name ) . '_rules[__i__][field]" aria-label="' . esc_attr__( 'Field', 'prime-fleet' ) . '">';
		echo '<option value="">' . esc_html__( 'Select field…', 'prime-fleet' ) . '</option>';
		foreach ( $fields as $key => $flabel ) {
			printf( '<option value="%1$s">%2$s</option>', esc_attr( $key ), esc_html( $flabel ) );
		}
		echo '</select>';
		echo '<select data-pf-cond-op="1" name="' . esc_attr( $name ) . '_rules[__i__][operator]" aria-label="' . esc_attr__( 'Operator', 'prime-fleet' ) . '">';
		foreach ( $ops as $key => $olabel ) {
			printf( '<option value="%1$s">%2$s</option>', esc_attr( $key ), esc_html( $olabel ) );
		}
		echo '</select>';
		echo '<input type="text" data-pf-cond-value="1" name="' . esc_attr( $name ) . '_rules[__i__][value]" placeholder="' . esc_attr__( 'Value (comma-separated for one of / between)', 'prime-fleet' ) . '" />';
		echo '<button type="button" class="prime-fleet-btn prime-fleet-btn--danger" data-pf-cond-remove="1" aria-label="' . esc_attr__( 'Remove rule', 'prime-fleet' ) . '">&times;</button>';
		echo '</div>';
		echo '</template>';
		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * @param array $arr Array.
	 * @return bool
	 */
	private static function is_list( array $arr ) {
		if ( function_exists( 'array_is_list' ) ) {
			return array_is_list( $arr );
		}
		$i = 0;
		foreach ( $arr as $k => $_ ) {
			if ( $k !== $i++ ) {
				return false;
			}
		}
		return true;
	}
}
