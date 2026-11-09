<?php
/**
 * Pricing admin (routes, hourly, packages, rules) — list + modals.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Admin\Pages;

use Prime_Fleet\Admin\Crud;
use Prime_Fleet\Admin\View;
use Prime_Fleet\Database\Schema;
use Prime_Fleet\Install\Capabilities;

defined( 'ABSPATH' ) || exit;

final class Pricing {

	const NONCE = 'prime_fleet_pricing';

	public static function render() {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'prime-fleet' ) );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'routes'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $tab, array( 'routes', 'hourly', 'packages', 'rules' ), true ) ) {
			$tab = 'routes';
		}

		$map = array(
			'routes'   => array(
				'table' => 'routes',
				'cols'  => array(
					'service_id'              => 'int',
					'origin_zone_id'          => 'int',
					'dest_zone_id'            => 'int',
					'applies_both_directions' => 'bool',
					'one_way_price'           => 'float',
					'round_trip_price'        => 'float',
					'is_active'               => 'bool',
					'notes'                   => 'textarea',
				),
			),
			'hourly'   => array(
				'table' => 'hourly_rates',
				'cols'  => array(
					'service_id'          => 'int',
					'label'               => 'text',
					'hourly_rate'         => 'float',
					'minimum_hours'       => 'nullable_float',
					'maximum_hours'       => 'nullable_float',
					'billing_increment'   => 'text',
					'customer_can_choose' => 'bool',
					'is_active'           => 'bool',
				),
			),
			'packages' => array(
				'table' => 'packages',
				'cols'  => array(
					'name'                 => 'text',
					'customer_description' => 'textarea',
					'minimum_duration'     => 'text',
					'maximum_duration'     => 'text',
					'fixed_price'          => 'float',
					'is_active'            => 'bool',
					'display_order'        => 'int',
				),
			),
			'rules'    => array(
				'table' => 'pricing_rules',
				'cols'  => array(
					'name'            => 'text',
					'is_active'       => 'bool',
					'priority'        => 'int',
					'match_mode'      => 'text',
					'conditions_json' => 'conditions_rules',
					'action_type'     => 'text',
					'action_value'    => 'float',
					'notes'           => 'textarea',
				),
			),
		);

		$cfg     = $map[ $tab ];
		$notice  = Crud::handle_delete( $cfg['table'], self::NONCE );
		$notice .= Crud::handle_save( $cfg['table'], $cfg['cols'], self::NONCE );

		global $wpdb;
		$rows     = $wpdb->get_results( 'SELECT * FROM ' . Schema::table( $cfg['table'] ) . ' ORDER BY id DESC LIMIT 200', ARRAY_A );
		$services = $wpdb->get_results( 'SELECT id, name FROM ' . Schema::table( 'services' ) . ' ORDER BY display_order ASC, name ASC', ARRAY_A );
		$zones    = $wpdb->get_results( 'SELECT id, name FROM ' . Schema::table( 'zones' ) . ' ORDER BY display_order ASC, name ASC', ARRAY_A );

		$tabs = array(
			'routes'   => __( 'Routes', 'prime-fleet' ),
			'hourly'   => __( 'Hourly', 'prime-fleet' ),
			'packages' => __( 'Packages', 'prime-fleet' ),
			'rules'    => __( 'Rules', 'prime-fleet' ),
		);

		$add_labels = array(
			'routes'   => __( 'Add route', 'prime-fleet' ),
			'hourly'   => __( 'Add hourly rate', 'prime-fleet' ),
			'packages' => __( 'Add package', 'prime-fleet' ),
			'rules'    => __( 'Add rule', 'prime-fleet' ),
		);
		$tpl_id = 'prime-fleet-tpl-pricing-' . $tab;

		ob_start();
		if ( $notice ) {
			echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '<div class="prime-fleet-tabs">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<button type="button" class="prime-fleet-tab-lite%s" data-pf-panel="pricing" data-pf-query="%s">%s</button>',
				$tab === $key ? ' is-active' : '',
				esc_attr( wp_json_encode( array( 'tab' => $key ) ) ),
				esc_html( $label )
			);
		}
		echo '</div>';

		if ( 'routes' === $tab ) {
			self::list_routes( $rows, $services, $zones, $tab, $tpl_id );
			self::tpl_routes( $services, $zones, $tpl_id );
		} elseif ( 'hourly' === $tab ) {
			self::list_simple( $rows, $tab, array( 'label', 'hourly_rate', 'minimum_hours' ), $tpl_id, self::hourly_records( $rows ) );
			self::tpl_hourly( $services, $tpl_id );
		} elseif ( 'packages' === $tab ) {
			self::list_simple( $rows, $tab, array( 'name', 'fixed_price', 'display_order' ), $tpl_id, self::package_records( $rows ) );
			self::tpl_packages( $tpl_id );
		} else {
			self::list_simple( $rows, $tab, array( 'name', 'action_type', 'priority' ), $tpl_id, self::rule_records( $rows ) );
			self::tpl_rules( $tpl_id );
		}

		View::shell(
			__( 'Pricing', 'prime-fleet' ),
			ob_get_clean(),
			array( View::modal_button( $add_labels[ $tab ], $tpl_id, $add_labels[ $tab ] ) )
		);
	}

	/**
	 * @param array  $rows Rows.
	 * @param array  $services Services.
	 * @param array  $zones Zones.
	 * @param string $tab Tab.
	 * @param string $tpl_id Template id.
	 */
	private static function list_routes( $rows, $services, $zones, $tab, $tpl_id ) {
		$smap = array();
		$zmap = array();
		foreach ( $services as $s ) {
			$smap[ (int) $s['id'] ] = $s['name'];
		}
		foreach ( $zones as $z ) {
			$zmap[ (int) $z['id'] ] = $z['name'];
		}
		echo '<div class="prime-fleet-card prime-fleet-table-wrap">';
		if ( empty( $rows ) ) {
			echo View::empty_state( __( 'No routes', 'prime-fleet' ), __( 'Add origin → destination fares.', 'prime-fleet' ) );
			echo '</div>';
			return;
		}
		echo '<table class="prime-fleet-table"><thead><tr><th>' . esc_html__( 'Service', 'prime-fleet' ) . '</th><th>' . esc_html__( 'Route', 'prime-fleet' ) . '</th><th>' . esc_html__( 'One-way', 'prime-fleet' ) . '</th><th>' . esc_html__( 'Round trip', 'prime-fleet' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$record = array(
				'id'                      => (int) $r['id'],
				'service_id'              => (int) $r['service_id'],
				'origin_zone_id'          => (int) $r['origin_zone_id'],
				'dest_zone_id'            => (int) $r['dest_zone_id'],
				'one_way_price'           => $r['one_way_price'],
				'round_trip_price'        => $r['round_trip_price'],
				'notes'                   => $r['notes'],
				'applies_both_directions' => (int) $r['applies_both_directions'],
				'is_active'               => (int) $r['is_active'],
			);
			$route = ( $zmap[ (int) $r['origin_zone_id'] ] ?? '?' ) . ' → ' . ( $zmap[ (int) $r['dest_zone_id'] ] ?? '?' );
			echo '<tr><td>' . esc_html( $smap[ (int) $r['service_id'] ] ?? '#' . $r['service_id'] ) . '</td><td>' . esc_html( $route ) . '</td>';
			echo '<td>' . esc_html( (string) $r['one_way_price'] ) . '</td><td>' . esc_html( (string) $r['round_trip_price'] ) . '</td>';
			echo '<td class="prime-fleet-table__actions">';
			echo View::modal_button( __( 'Edit', 'prime-fleet' ), $tpl_id, __( 'Edit route', 'prime-fleet' ), 'ghost', $record );
			echo ' ' . self::delete_btn( (int) $r['id'], $tab );
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * @param array  $rows Rows.
	 * @param string $tab Tab.
	 * @param array  $cols Columns.
	 * @param string $tpl_id Template.
	 * @param array  $records id => record.
	 */
	private static function list_simple( $rows, $tab, $cols, $tpl_id, $records ) {
		echo '<div class="prime-fleet-card prime-fleet-table-wrap">';
		if ( empty( $rows ) ) {
			echo View::empty_state( __( 'Nothing yet', 'prime-fleet' ), __( 'Use Add to create a row.', 'prime-fleet' ) );
			echo '</div>';
			return;
		}
		$titles = array(
			'routes'   => __( 'Edit route', 'prime-fleet' ),
			'hourly'   => __( 'Edit hourly rate', 'prime-fleet' ),
			'packages' => __( 'Edit package', 'prime-fleet' ),
			'rules'    => __( 'Edit rule', 'prime-fleet' ),
		);
		echo '<table class="prime-fleet-table"><thead><tr>';
		foreach ( $cols as $c ) {
			echo '<th>' . esc_html( $c ) . '</th>';
		}
		echo '<th></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$id = (int) $r['id'];
			echo '<tr>';
			foreach ( $cols as $c ) {
				echo '<td>' . esc_html( (string) ( $r[ $c ] ?? '' ) ) . '</td>';
			}
			echo '<td class="prime-fleet-table__actions">';
			echo View::modal_button( __( 'Edit', 'prime-fleet' ), $tpl_id, $titles[ $tab ], 'ghost', $records[ $id ] ?? array( 'id' => $id ) );
			echo ' ' . self::delete_btn( $id, $tab );
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * @param int    $id Id.
	 * @param string $tab Tab.
	 * @return string
	 */
	private static function delete_btn( $id, $tab ) {
		$q = array(
			'tab'           => $tab,
			'prime_fleet_delete' => $id,
			'_wpnonce'      => wp_create_nonce( self::NONCE ),
		);
		return '<button type="button" class="prime-fleet-btn prime-fleet-btn--danger" data-pf-confirm="' . esc_attr__( 'Delete this row?', 'prime-fleet' ) . '" data-pf-panel="pricing" data-pf-query="' . esc_attr( wp_json_encode( $q ) ) . '">' . esc_html__( 'Delete', 'prime-fleet' ) . '</button>';
	}

	/**
	 * @param array $rows Rows.
	 * @return array
	 */
	private static function hourly_records( $rows ) {
		$out = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r['id'] ] = array(
				'id'                  => (int) $r['id'],
				'service_id'          => (int) $r['service_id'],
				'label'               => $r['label'],
				'hourly_rate'         => $r['hourly_rate'],
				'minimum_hours'       => $r['minimum_hours'],
				'maximum_hours'       => $r['maximum_hours'],
				'billing_increment'   => $r['billing_increment'],
				'customer_can_choose' => (int) $r['customer_can_choose'],
				'is_active'           => (int) $r['is_active'],
			);
		}
		return $out;
	}

	/**
	 * @param array $rows Rows.
	 * @return array
	 */
	private static function package_records( $rows ) {
		$out = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r['id'] ] = array(
				'id'                   => (int) $r['id'],
				'name'                 => $r['name'],
				'customer_description' => $r['customer_description'],
				'minimum_duration'     => $r['minimum_duration'],
				'maximum_duration'     => $r['maximum_duration'],
				'fixed_price'          => $r['fixed_price'],
				'display_order'        => (int) $r['display_order'],
				'is_active'            => (int) $r['is_active'],
			);
		}
		return $out;
	}

	/**
	 * @param array $rows Rows.
	 * @return array
	 */
	private static function rule_records( $rows ) {
		$out = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r['id'] ] = array(
				'id'              => (int) $r['id'],
				'name'            => $r['name'],
				'priority'        => (int) $r['priority'],
				'match_mode'      => $r['match_mode'],
				'conditions_json' => $r['conditions_json'],
				'action_type'     => $r['action_type'],
				'action_value'    => $r['action_value'],
				'notes'           => $r['notes'],
				'is_active'       => (int) $r['is_active'],
			);
		}
		return $out;
	}

	private static function tpl_routes( $services, $zones, $tpl_id ) {
		echo '<div id="' . esc_attr( $tpl_id ) . '" class="prime-fleet-form-source" hidden><form method="post" data-pf-modal-form="1">';
		self::form_head();
		echo '<div class="prime-fleet-form-grid prime-fleet-form-grid--2">';
		self::select_rows( 'service_id', __( 'Service', 'prime-fleet' ), $services );
		self::select_rows( 'origin_zone_id', __( 'Origin zone', 'prime-fleet' ), $zones );
		self::select_rows( 'dest_zone_id', __( 'Destination zone', 'prime-fleet' ), $zones );
		self::number( 'one_way_price', __( 'One-way price', 'prime-fleet' ), '0' );
		self::number( 'round_trip_price', __( 'Round-trip price', 'prime-fleet' ), '0' );
		self::textarea( 'notes', __( 'Notes', 'prime-fleet' ), '' );
		echo '</div><div class="prime-fleet-checks">';
		self::checkbox( 'applies_both_directions', __( 'Applies both directions', 'prime-fleet' ), 1 );
		self::checkbox( 'is_active', __( 'Active', 'prime-fleet' ), 1 );
		echo '</div>' . self::footer( __( 'Save route', 'prime-fleet' ) ) . '</form></div>';
	}

	private static function tpl_hourly( $services, $tpl_id ) {
		echo '<div id="' . esc_attr( $tpl_id ) . '" class="prime-fleet-form-source" hidden><form method="post" data-pf-modal-form="1">';
		self::form_head();
		echo '<div class="prime-fleet-form-grid prime-fleet-form-grid--2">';
		self::select_rows( 'service_id', __( 'Service', 'prime-fleet' ), $services );
		self::text( 'label', __( 'Label', 'prime-fleet' ), '' );
		self::number( 'hourly_rate', __( 'Hourly rate', 'prime-fleet' ), '0' );
		self::number( 'minimum_hours', __( 'Minimum hours', 'prime-fleet' ), '2' );
		self::number( 'maximum_hours', __( 'Maximum hours', 'prime-fleet' ), '' );
		self::text( 'billing_increment', __( 'Billing increment', 'prime-fleet' ), 'hour' );
		echo '</div><div class="prime-fleet-checks">';
		self::checkbox( 'customer_can_choose', __( 'Customer can choose', 'prime-fleet' ), 1 );
		self::checkbox( 'is_active', __( 'Active', 'prime-fleet' ), 1 );
		echo '</div>' . self::footer( __( 'Save hourly rate', 'prime-fleet' ) ) . '</form></div>';
	}

	private static function tpl_packages( $tpl_id ) {
		echo '<div id="' . esc_attr( $tpl_id ) . '" class="prime-fleet-form-source" hidden><form method="post" data-pf-modal-form="1">';
		self::form_head();
		echo '<div class="prime-fleet-form-grid prime-fleet-form-grid--2">';
		self::text( 'name', __( 'Name', 'prime-fleet' ), '' );
		self::textarea( 'customer_description', __( 'Customer description', 'prime-fleet' ), '' );
		self::text( 'minimum_duration', __( 'Minimum duration', 'prime-fleet' ), '' );
		self::text( 'maximum_duration', __( 'Maximum duration', 'prime-fleet' ), '' );
		self::number( 'fixed_price', __( 'Fixed price', 'prime-fleet' ), '0' );
		self::number( 'display_order', __( 'Display order', 'prime-fleet' ), '0', '1' );
		echo '</div><div class="prime-fleet-checks">';
		self::checkbox( 'is_active', __( 'Active', 'prime-fleet' ), 1 );
		echo '</div>' . self::footer( __( 'Save package', 'prime-fleet' ) ) . '</form></div>';
	}

	private static function tpl_rules( $tpl_id ) {
		$actions = array( 'add_percent', 'add_fixed', 'replace_base', 'require_quote', 'flag_review' );
		echo '<div id="' . esc_attr( $tpl_id ) . '" class="prime-fleet-form-source" hidden><form method="post" data-pf-modal-form="1">';
		self::form_head();
		echo '<p class="prime-fleet-help">' . esc_html__( 'IF/THEN rules (e.g. nightlife across midnight). Use Match All / Any with visual rules — no JSON required.', 'prime-fleet' ) . '</p>';
		echo '<div class="prime-fleet-form-grid prime-fleet-form-grid--2">';
		self::text( 'name', __( 'Name', 'prime-fleet' ), '' );
		self::number( 'priority', __( 'Priority', 'prime-fleet' ), '10', '1' );
		echo '<div class="prime-fleet-field"><label for="pr_match_mode">' . esc_html__( 'Match mode', 'prime-fleet' ) . '</label><select id="pr_match_mode" name="match_mode" data-pf-field="match_mode">';
		foreach ( array( 'all' => __( 'All rules (AND)', 'prime-fleet' ), 'any' => __( 'Any rule (OR)', 'prime-fleet' ) ) as $m => $label ) {
			printf( '<option value="%1$s">%2$s</option>', esc_attr( $m ), esc_html( $label ) );
		}
		echo '</select></div>';
		echo '<div class="prime-fleet-field"><label for="pr_action_type">' . esc_html__( 'Action type', 'prime-fleet' ) . '</label><select id="pr_action_type" name="action_type" data-pf-field="action_type">';
		foreach ( $actions as $a ) {
			printf( '<option value="%1$s">%1$s</option>', esc_attr( $a ) );
		}
		echo '</select></div>';
		self::number( 'action_value', __( 'Action value', 'prime-fleet' ), '0' );
		self::textarea( 'notes', __( 'Notes', 'prime-fleet' ), '' );
		echo '</div>';
		echo '<input type="hidden" name="conditions_json" value="" data-pf-field="conditions_json" />';
		echo \Prime_Fleet\Admin\Condition_Builder::render( 'conditions_json', __( 'When…', 'prime-fleet' ), 'rules' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="prime-fleet-checks">';
		self::checkbox( 'is_active', __( 'Active', 'prime-fleet' ), 1 );
		echo '</div>' . self::footer( __( 'Save rule', 'prime-fleet' ) ) . '</form></div>';
	}

	private static function form_head() {
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="prime_fleet_crud" value="1" /><input type="hidden" name="id" value="0" data-pf-field="id" />';
	}

	/**
	 * @param string $label Submit label.
	 * @return string
	 */
	private static function footer( $label ) {
		return '<div class="prime-fleet-modal__footer"><button type="button" class="prime-fleet-btn prime-fleet-btn--ghost" data-pf-modal-close="1">' . esc_html__( 'Cancel', 'prime-fleet' ) . '</button><button type="submit" class="prime-fleet-btn">' . esc_html( $label ) . '</button></div>';
	}

	private static function select_rows( $name, $label, $rows ) {
		echo '<div class="prime-fleet-field"><label for="pr_' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label><select id="pr_' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" data-pf-field="' . esc_attr( $name ) . '"><option value="0">—</option>';
		foreach ( $rows as $r ) {
			printf( '<option value="%d">%s</option>', (int) $r['id'], esc_html( $r['name'] ) );
		}
		echo '</select></div>';
	}

	private static function text( $name, $label, $value ) {
		printf( '<div class="prime-fleet-field"><label for="pr_%1$s">%2$s</label><input id="pr_%1$s" type="text" name="%1$s" value="%3$s" data-pf-field="%1$s" /></div>', esc_attr( $name ), esc_html( $label ), esc_attr( (string) $value ) );
	}

	private static function number( $name, $label, $value, $step = '0.01' ) {
		printf( '<div class="prime-fleet-field"><label for="pr_%1$s">%2$s</label><input id="pr_%1$s" type="number" step="%4$s" name="%1$s" value="%3$s" data-pf-field="%1$s" /></div>', esc_attr( $name ), esc_html( $label ), esc_attr( (string) $value ), esc_attr( $step ) );
	}

	private static function textarea( $name, $label, $value ) {
		printf( '<div class="prime-fleet-field prime-fleet-field--block"><label for="pr_%1$s">%2$s</label><textarea id="pr_%1$s" name="%1$s" rows="3" data-pf-field="%1$s">%3$s</textarea></div>', esc_attr( $name ), esc_html( $label ), esc_textarea( (string) $value ) );
	}

	private static function checkbox( $name, $label, $value ) {
		printf( '<label class="prime-fleet-check"><input type="checkbox" name="%1$s" value="1" data-pf-field="%1$s" %2$s /> <span>%3$s</span></label>', esc_attr( $name ), checked( (int) $value, 1, false ), esc_html( $label ) );
	}
}
