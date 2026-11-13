<?php
/**
 * Service editor + field builder.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Admin\Pages;

use Prime_Fleet\Admin\Condition_Builder;
use Prime_Fleet\Admin\View;
use Prime_Fleet\Database\Schema;
use Prime_Fleet\Install\Capabilities;
use Prime_Fleet\Services\Config_Compiler;

defined( 'ABSPATH' ) || exit;

final class Service_Edit {

	public static function render() {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'prime-fleet' ) );
		}

		$notice = self::handle_save();
		$id     = isset( $_GET['service_id'] ) ? absint( $_GET['service_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		global $wpdb;
		$service = $id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'services' ) . ' WHERE id = %d', $id ), ARRAY_A ) : null;

		ob_start();
		if ( $notice ) {
			echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( ! $service && $id ) {
			echo '<div class="prime-fleet-card">' . View::empty_state( __( 'Not found', 'prime-fleet' ), __( 'Service does not exist.', 'prime-fleet' ) ) . '</div>';
			View::shell( __( 'Edit Service', 'prime-fleet' ), ob_get_clean() );
			return;
		}

		if ( ! $service ) {
			self::render_create_form();
			View::shell( __( 'Add Service', 'prime-fleet' ), ob_get_clean(), array( View::panel_button( __( 'Back', 'prime-fleet' ), 'services', 'ghost' ) ) );
			return;
		}

		$pages = array(
			'general' => __( 'General', 'prime-fleet' ),
			'trip'    => __( 'Trip settings', 'prime-fleet' ),
			'fields'  => __( 'Booking form', 'prime-fleet' ),
			'addons'  => __( 'Add-ons', 'prime-fleet' ),
			'vehicle' => __( 'Vehicle behavior', 'prime-fleet' ),
		);
		if ( ! isset( $pages[ $tab ] ) ) {
			$tab = 'general';
		}

		echo '<div class="prime-fleet-subpages">';
		echo '<nav class="prime-fleet-subpages__nav prime-fleet-settings-nav" aria-label="' . esc_attr__( 'Service pages', 'prime-fleet' ) . '">';
		echo '<p class="prime-fleet-settings-nav__label">' . esc_html__( 'Service', 'prime-fleet' ) . '</p>';
		echo '<ul class="prime-fleet-settings-nav__list">';
		foreach ( $pages as $key => $label ) {
			printf(
				'<li><button type="button" class="prime-fleet-settings-nav__link%s" data-pf-panel="service_edit" data-pf-query="%s" aria-current="%s">%s</button></li>',
				$key === $tab ? ' is-active' : '',
				esc_attr( wp_json_encode( array( 'service_id' => (int) $service['id'], 'tab' => $key ) ) ),
				$key === $tab ? 'page' : 'false',
				esc_html( $label )
			);
		}
		echo '</ul></nav>';

		echo '<div class="prime-fleet-settings-main">';
		echo '<form method="post" action="">';
		wp_nonce_field( 'prime_fleet_save_service_' . (int) $service['id'] );
		echo '<input type="hidden" name="prime_fleet_action" value="save_service" />';
		echo '<input type="hidden" name="service_id" value="' . (int) $service['id'] . '" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( $tab ) . '" />';

		if ( 'general' === $tab ) {
			self::tab_general( $service );
		} elseif ( 'trip' === $tab ) {
			self::tab_trip( $service );
		} elseif ( 'fields' === $tab ) {
			self::tab_fields( $service );
		} elseif ( 'addons' === $tab ) {
			self::tab_addons( $service );
		} else {
			self::tab_vehicle( $service );
		}

		echo '<div class="prime-fleet-sticky-save"><button type="submit" class="prime-fleet-btn">' . esc_html__( 'Save changes', 'prime-fleet' ) . '</button>';
		echo View::panel_button( __( 'Back to list', 'prime-fleet' ), 'services', 'ghost' );
		echo '</div></form>';

		if ( 'fields' === $tab ) {
			self::field_edit_template( $service );
		}
		echo '</div></div>';

		View::shell(
			sprintf(
				/* translators: 1: service name, 2: section label */
				__( '%1$s · %2$s', 'prime-fleet' ),
				$service['name'],
				$pages[ $tab ]
			),
			ob_get_clean(),
			array(
				View::panel_button( __( 'All services', 'prime-fleet' ), 'services', 'ghost' ),
			)
		);
	}

	private static function render_create_form() {
		echo '<form method="post" class="prime-fleet-card">';
		wp_nonce_field( 'prime_fleet_create_service' );
		echo '<input type="hidden" name="prime_fleet_action" value="create_service" />';
		echo '<div class="prime-fleet-form-grid">';
		echo '<div class="prime-fleet-field"><label>' . esc_html__( 'Service name', 'prime-fleet' ) . '</label><input type="text" name="name" required /></div>';
		echo '<div class="prime-fleet-field"><label>' . esc_html__( 'Booking type', 'prime-fleet' ) . '</label><select name="booking_type"><option value="route">Route</option><option value="hourly">Hourly</option><option value="package">Package</option><option value="quote">Custom quote</option></select></div>';
		echo '</div>';
		echo '<div class="prime-fleet-sticky-save"><button class="prime-fleet-btn" type="submit">' . esc_html__( 'Create service', 'prime-fleet' ) . '</button></div>';
		echo '</form>';
	}

	/**
	 * @param array $service Service row.
	 */
	private static function tab_general( $service ) {
		echo '<div class="prime-fleet-card prime-fleet-section">';
		echo '<header class="prime-fleet-section__head"><h3>' . esc_html__( 'General', 'prime-fleet' ) . '</h3>';
		echo '<p>' . esc_html__( 'Name, slug, and visibility for this service.', 'prime-fleet' ) . '</p></header>';
		echo '<div class="prime-fleet-form-grid">';
		self::text( 'name', __( 'Service name', 'prime-fleet' ), $service['name'] );
		self::text( 'internal_name', __( 'Internal name', 'prime-fleet' ), $service['internal_name'] );
		self::text( 'slug', __( 'Slug', 'prime-fleet' ), $service['slug'] );
		self::number( 'display_order', __( 'Display order', 'prime-fleet' ), $service['display_order'] );
		self::textarea( 'short_description', __( 'Short description', 'prime-fleet' ), $service['short_description'] );
		echo '</div><div class="prime-fleet-checks">';
		self::checkbox( 'is_active', __( 'Active', 'prime-fleet' ), $service['is_active'] );
		self::checkbox( 'customer_visible', __( 'Customer visible', 'prime-fleet' ), $service['customer_visible'] );
		echo '</div></div>';
	}

	/**
	 * @param array $service Service row.
	 */
	private static function tab_trip( $service ) {
		echo '<div class="prime-fleet-card prime-fleet-section">';
		echo '<header class="prime-fleet-section__head"><h3>' . esc_html__( 'Trip settings', 'prime-fleet' ) . '</h3>';
		echo '<p>' . esc_html__( 'Booking type and what guests must complete on the trip step.', 'prime-fleet' ) . '</p></header>';
		echo '<div class="prime-fleet-form-grid">';
		echo '<div class="prime-fleet-field"><label>' . esc_html__( 'Booking type', 'prime-fleet' ) . '</label><select name="booking_type">';
		foreach ( array( 'route', 'hourly', 'package', 'quote' ) as $type ) {
			printf( '<option value="%1$s" %2$s>%1$s</option>', esc_attr( $type ), selected( $service['booking_type'], $type, false ) );
		}
		echo '</select></div>';
		echo '</div><div class="prime-fleet-checks">';
		self::checkbox( 'allow_one_way', __( 'Allow one-way', 'prime-fleet' ), $service['allow_one_way'] );
		self::checkbox( 'allow_round_trip', __( 'Allow round trip', 'prime-fleet' ), $service['allow_round_trip'] );
		self::checkbox( 'allow_multiple_stops', __( 'Allow multiple stops', 'prime-fleet' ), $service['allow_multiple_stops'] );
		self::checkbox( 'require_pickup', __( 'Require pickup', 'prime-fleet' ), $service['require_pickup'] );
		self::checkbox( 'require_destination', __( 'Require destination', 'prime-fleet' ), $service['require_destination'] );
		self::checkbox( 'require_passenger_count', __( 'Require passenger count', 'prime-fleet' ), $service['require_passenger_count'] );
		self::checkbox( 'require_luggage', __( 'Require luggage', 'prime-fleet' ), $service['require_luggage'] );
		self::checkbox( 'requires_manual_confirmation', __( 'Requires manual confirmation', 'prime-fleet' ), $service['requires_manual_confirmation'] );
		echo '</div></div>';
	}

	/**
	 * @param array $service Service row.
	 */
	private static function tab_vehicle( $service ) {
		echo '<div class="prime-fleet-card prime-fleet-section">';
		echo '<header class="prime-fleet-section__head"><h3>' . esc_html__( 'Vehicle behavior', 'prime-fleet' ) . '</h3>';
		echo '<p>' . esc_html__( 'How vehicles are matched and offered on this service.', 'prime-fleet' ) . '</p></header>';
		echo '<div class="prime-fleet-checks">';
		self::checkbox( 'require_vehicle_matching', __( 'Enable vehicle matching', 'prime-fleet' ), $service['require_vehicle_matching'] );
		echo '</div><div class="prime-fleet-form-grid" style="margin-top:14px">';
		echo '<div class="prime-fleet-field"><label>' . esc_html__( 'Customer vehicle choice', 'prime-fleet' ) . '</label><select name="customer_vehicle_choice">';
		foreach ( array( 'recommend' => 'Recommend only', 'choose' => 'Customer chooses', 'admin' => 'Admin only' ) as $k => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $k ), selected( $service['customer_vehicle_choice'], $k, false ), esc_html( $label ) );
		}
		echo '</select></div></div></div>';
	}

	/**
	 * @param array $service Service row.
	 */
	private static function tab_fields( $service ) {
		global $wpdb;
		$library  = $wpdb->get_results( 'SELECT * FROM ' . Schema::table( 'fields' ) . ' ORDER BY label ASC', ARRAY_A );
		$attached = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT sf.*, f.field_key, f.label AS library_label, f.field_type FROM ' . Schema::table( 'service_fields' ) . ' sf
				INNER JOIN ' . Schema::table( 'fields' ) . ' f ON f.id = sf.field_id
				WHERE sf.service_id = %d ORDER BY sf.display_order ASC, sf.id ASC',
				(int) $service['id']
			),
			ARRAY_A
		);

		$steps = array( 'service', 'trip', 'schedule', 'passengers', 'vehicle', 'addons', 'contact', 'review' );
		$attached_ids = array();
		foreach ( $attached as $row ) {
			$attached_ids[ (int) $row['field_id'] ] = true;
		}

		echo '<div class="prime-fleet-card prime-fleet-section">';
		echo '<header class="prime-fleet-section__head"><h3>' . esc_html__( 'Attached fields', 'prime-fleet' ) . '</h3>';
		echo '<p>' . esc_html__( 'Quick controls stay in the list. Use Edit for labels, help text, validation, and show/require rules.', 'prime-fleet' ) . '</p></header>';

		if ( empty( $attached ) ) {
			echo View::empty_state(
				__( 'No fields attached yet', 'prime-fleet' ),
				__( 'Pick a field from the library below and click Attach field.', 'prime-fleet' )
			);
		} else {
			echo '<div class="prime-fleet-table-wrap"><table class="prime-fleet-table prime-fleet-table--builder"><thead><tr>';
			echo '<th>' . esc_html__( 'Field', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Enabled', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Required', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Step', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Order', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Width', 'prime-fleet' ) . '</th>';
			echo '<th></th></tr></thead><tbody>';

			foreach ( $attached as $row ) {
				$sid    = (int) $row['id'];
				$val    = json_decode( (string) ( $row['validation_json'] ?? '' ), true );
				$record = array(
					'sf_id'                    => $sid,
					'label_override'           => (string) $row['label_override'],
					'help_override'            => (string) ( $row['help_override'] ?? '' ),
					'placeholder_override'     => (string) ( $row['placeholder_override'] ?? '' ),
					'validation_json'          => (string) ( $row['validation_json'] ?? '' ),
					'validation_min'           => ( is_array( $val ) && isset( $val['min'] ) ) ? (string) $val['min'] : '',
					'validation_max'           => ( is_array( $val ) && isset( $val['max'] ) ) ? (string) $val['max'] : '',
					'conditions_json'          => (string) $row['conditions_json'],
					'required_conditions_json' => (string) $row['required_conditions_json'],
					'library_label'            => $row['library_label'],
				);
				$title = $row['label_override'] ? $row['label_override'] : $row['library_label'];
				$has_vis = '' !== trim( (string) $row['conditions_json'] );
				$has_req = '' !== trim( (string) $row['required_conditions_json'] );

				echo '<tr>';
				echo '<td><strong>' . esc_html( $title ) . '</strong><br><span class="prime-fleet-help">' . esc_html( $row['field_key'] . ' · ' . $row['field_type'] ) . '</span>';
				if ( $has_vis || $has_req ) {
					echo '<br><span class="prime-fleet-badge">' . esc_html__( 'Conditional', 'prime-fleet' ) . '</span>';
				}
				echo '<input type="hidden" name="sf[' . $sid . '][id]" value="' . $sid . '" /></td>';
				echo '<td><label class="prime-fleet-switch"><input type="checkbox" name="sf[' . $sid . '][enabled]" value="1" ' . checked( (int) $row['enabled'], 1, false ) . ' /><span>' . esc_html__( 'On', 'prime-fleet' ) . '</span></label></td>';
				echo '<td><label class="prime-fleet-switch"><input type="checkbox" name="sf[' . $sid . '][required]" value="1" ' . checked( (int) $row['required'], 1, false ) . ' /><span>' . esc_html__( 'Req', 'prime-fleet' ) . '</span></label></td>';
				echo '<td><select name="sf[' . $sid . '][step_key]">';
				foreach ( $steps as $step ) {
					printf( '<option value="%1$s" %2$s>%1$s</option>', esc_attr( $step ), selected( $row['step_key'], $step, false ) );
				}
				echo '</select></td>';
				echo '<td><input type="number" name="sf[' . $sid . '][display_order]" value="' . esc_attr( (string) $row['display_order'] ) . '" /></td>';
				echo '<td><select name="sf[' . $sid . '][display_width]"><option value="full" ' . selected( $row['display_width'], 'full', false ) . '>Full</option><option value="half" ' . selected( $row['display_width'], 'half', false ) . '>Half</option></select></td>';
				echo '<td class="prime-fleet-table__actions">';
				echo View::modal_button( __( 'Edit', 'prime-fleet' ), 'prime-fleet-tpl-sf-edit', sprintf( /* translators: %s field label */ __( 'Edit: %s', 'prime-fleet' ), $title ), 'ghost', $record );
				echo ' <button type="submit" class="prime-fleet-btn prime-fleet-btn--danger" name="remove_sf" value="' . $sid . '" data-pf-confirm-title="' . esc_attr__( 'Detach field', 'prime-fleet' ) . '" data-pf-confirm-ok="' . esc_attr__( 'Detach', 'prime-fleet' ) . '" data-pf-confirm="' . esc_attr__( 'Remove this field from the booking form?', 'prime-fleet' ) . '">' . esc_html__( 'Detach', 'prime-fleet' ) . '</button>';
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
		echo '</div>';

		echo '<div class="prime-fleet-card prime-fleet-section" style="margin-top:1rem">';
		echo '<header class="prime-fleet-section__head"><h3>' . esc_html__( 'Attach from library', 'prime-fleet' ) . '</h3>';
		echo '<p>' . esc_html__( 'Choose a field and step. Fine-tune labels and conditions with Edit after attaching.', 'prime-fleet' ) . '</p></header>';
		echo '<div class="prime-fleet-form-grid prime-fleet-form-grid--2">';
		echo '<div class="prime-fleet-field"><label for="attach_field_id">' . esc_html__( 'Field', 'prime-fleet' ) . '</label><select id="attach_field_id" name="attach_field_id" data-pf-search="1"><option value="">—</option>';
		$available = 0;
		foreach ( $library as $field ) {
			if ( isset( $attached_ids[ (int) $field['id'] ] ) ) {
				continue;
			}
			++$available;
			printf( '<option value="%d">%s (%s)</option>', (int) $field['id'], esc_html( $field['label'] ), esc_html( $field['field_key'] ) );
		}
		echo '</select></div>';
		echo '<div class="prime-fleet-field"><label for="attach_step">' . esc_html__( 'Step', 'prime-fleet' ) . '</label><select id="attach_step" name="attach_step">';
		foreach ( $steps as $step ) {
			printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $step ), selected( $step, 'trip', false ) );
		}
		echo '</select></div>';
		echo '</div>';
		if ( ! $available ) {
			echo '<p class="prime-fleet-help" style="margin-top:12px">' . esc_html__( 'All library fields are already attached to this service.', 'prime-fleet' ) . '</p>';
		} else {
			echo '<div class="prime-fleet-attach-actions"><button type="submit" name="prime_fleet_action" value="attach_field" class="prime-fleet-btn">' . esc_html__( 'Attach field', 'prime-fleet' ) . '</button></div>';
		}
		echo '</div>';
	}

	/**
	 * Per-service add-on applicability.
	 *
	 * @param array $service Service row.
	 */
	private static function tab_addons( $service ) {
		global $wpdb;
		$addons = $wpdb->get_results( 'SELECT * FROM ' . Schema::table( 'addons' ) . ' ORDER BY display_order ASC, name ASC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sid    = (int) $service['id'];

		echo '<div class="prime-fleet-card prime-fleet-section">';
		echo '<header class="prime-fleet-section__head"><h3>' . esc_html__( 'Add-ons for this service', 'prime-fleet' ) . '</h3>';
		echo '<p>' . esc_html__( 'Enable extras for this service. Unchecked items stay hidden from guests here.', 'prime-fleet' ) . '</p></header>';

		if ( empty( $addons ) ) {
			echo View::empty_state( __( 'No add-ons', 'prime-fleet' ), __( 'Create add-ons first, then attach them here.', 'prime-fleet' ) );
			echo '</div>';
			return;
		}

		echo '<div class="prime-fleet-checks prime-fleet-checks--stack">';
		foreach ( $addons as $addon ) {
			$ids = json_decode( (string) ( $addon['applicable_services_json'] ?? '' ), true );
			if ( ! is_array( $ids ) ) {
				$ids = array();
			}
			$ids     = array_map( 'intval', $ids );
			$checked = in_array( $sid, $ids, true );
			$label   = $addon['name'];
			if ( ! empty( $addon['is_included_info'] ) ) {
				$label .= ' (' . __( 'included', 'prime-fleet' ) . ')';
			}
			printf(
				'<label class="prime-fleet-check"><input type="checkbox" name="service_addons[]" value="%1$d" %2$s /> <span>%3$s — %4$s</span></label>',
				(int) $addon['id'],
				checked( $checked, true, false ),
				esc_html( $label ),
				esc_html( (string) $addon['pricing_type'] . ' / ' . $addon['price'] )
			);
		}
		echo '</div></div>';
	}

	/**
	 * Modal template for field details (kept outside the main save form).
	 *
	 * @param array $service Service row.
	 */
	private static function field_edit_template( $service ) {
		echo '<div id="prime-fleet-tpl-sf-edit" class="prime-fleet-form-source" hidden><form method="post" data-pf-modal-form="1">';
		wp_nonce_field( 'prime_fleet_save_service_' . (int) $service['id'] );
		echo '<input type="hidden" name="prime_fleet_action" value="save_field_details" />';
		echo '<input type="hidden" name="service_id" value="' . (int) $service['id'] . '" />';
		echo '<input type="hidden" name="tab" value="fields" />';
		echo '<input type="hidden" name="sf_id" value="0" data-pf-field="sf_id" />';
		echo '<div class="prime-fleet-form-grid prime-fleet-form-grid--2">';
		echo '<div class="prime-fleet-field"><label for="sf_label_override">' . esc_html__( 'Label override', 'prime-fleet' ) . '</label><input id="sf_label_override" type="text" name="label_override" value="" data-pf-field="label_override" /></div>';
		echo '<div class="prime-fleet-field"><label for="sf_placeholder_override">' . esc_html__( 'Placeholder override', 'prime-fleet' ) . '</label><input id="sf_placeholder_override" type="text" name="placeholder_override" value="" data-pf-field="placeholder_override" /></div>';
		echo '<div class="prime-fleet-field prime-fleet-field--block"><label for="sf_help_override">' . esc_html__( 'Help text override', 'prime-fleet' ) . '</label><textarea id="sf_help_override" name="help_override" rows="2" data-pf-field="help_override"></textarea></div>';
		echo '<div class="prime-fleet-field prime-fleet-field--block"><label for="sf_validation_json">' . esc_html__( 'Validation (optional)', 'prime-fleet' ) . '</label>';
		echo '<div class="prime-fleet-form-grid prime-fleet-form-grid--2">';
		echo '<div class="prime-fleet-field"><label for="sf_val_min">' . esc_html__( 'Min', 'prime-fleet' ) . '</label><input id="sf_val_min" type="number" name="validation_min" data-pf-field="validation_min" step="any" /></div>';
		echo '<div class="prime-fleet-field"><label for="sf_val_max">' . esc_html__( 'Max', 'prime-fleet' ) . '</label><input id="sf_val_max" type="number" name="validation_max" data-pf-field="validation_max" step="any" /></div>';
		echo '</div>';
		echo '<input type="hidden" name="validation_json" value="" data-pf-field="validation_json" />';
		echo '<p class="prime-fleet-help">' . esc_html__( 'Leave blank to use library defaults. Used for number/quantity fields.', 'prime-fleet' ) . '</p>';
		echo '</div>';
		echo '</div>';
		echo Condition_Builder::render( 'conditions_json', __( 'Show this field if…', 'prime-fleet' ), 'group' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo Condition_Builder::render( 'required_conditions_json', __( 'Require this field if…', 'prime-fleet' ), 'group' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="prime-fleet-modal__footer"><button type="button" class="prime-fleet-btn prime-fleet-btn--ghost" data-pf-modal-close="1">' . esc_html__( 'Cancel', 'prime-fleet' ) . '</button><button type="submit" class="prime-fleet-btn">' . esc_html__( 'Save field details', 'prime-fleet' ) . '</button></div>';
		echo '</form></div>';
	}

	/**
	 * @return string
	 */
	private static function handle_save() {
		if ( empty( $_POST['prime_fleet_action'] ) ) {
			return '';
		}

		$action = sanitize_key( wp_unslash( $_POST['prime_fleet_action'] ) );

		if ( 'create_service' === $action ) {
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
					'slug'                         => $slug,
					'booking_type'                 => $type,
					'requires_manual_confirmation' => 1,
					'is_active'                    => 1,
					'customer_visible'             => 1,
				)
			);
			$id = (int) $wpdb->insert_id;
			Config_Compiler::invalidate();
			if ( apply_filters( 'prime_fleet_admin_embed', false ) ) {
				$_GET['service_id'] = $id;
				$_GET['tab']        = 'fields';
				return View::notice( __( 'Service created. Configure the booking form below.', 'prime-fleet' ) );
			}
			wp_safe_redirect( admin_url( 'admin.php?page=prime-fleet-service-edit&service_id=' . $id . '&tab=fields' ) );
			exit;
		}

		$id = absint( $_POST['service_id'] ?? 0 );
		check_admin_referer( 'prime_fleet_save_service_' . $id );

		global $wpdb;
		$table = Schema::table( 'services' );

		if ( ! empty( $_POST['remove_sf'] ) ) {
			$wpdb->delete( Schema::table( 'service_fields' ), array( 'id' => absint( $_POST['remove_sf'] ), 'service_id' => $id ), array( '%d', '%d' ) );
			Config_Compiler::invalidate();
			return View::notice( __( 'Field detached.', 'prime-fleet' ) );
		}

		if ( 'save_field_details' === $action ) {
			$sf_id = absint( $_POST['sf_id'] ?? 0 );
			if ( $sf_id ) {
				$val_min = isset( $_POST['validation_min'] ) ? sanitize_text_field( wp_unslash( $_POST['validation_min'] ) ) : '';
				$val_max = isset( $_POST['validation_max'] ) ? sanitize_text_field( wp_unslash( $_POST['validation_max'] ) ) : '';
				$validation = array();
				if ( '' !== $val_min ) {
					$validation['min'] = (float) $val_min;
				}
				if ( '' !== $val_max ) {
					$validation['max'] = (float) $val_max;
				}
				$wpdb->update(
					Schema::table( 'service_fields' ),
					array(
						'label_override'           => sanitize_text_field( wp_unslash( $_POST['label_override'] ?? '' ) ),
						'help_override'            => sanitize_textarea_field( wp_unslash( $_POST['help_override'] ?? '' ) ),
						'placeholder_override'     => sanitize_text_field( wp_unslash( $_POST['placeholder_override'] ?? '' ) ),
						'validation_json'          => $validation ? wp_json_encode( $validation ) : '',
						'conditions_json'          => Condition_Builder::sanitize_group_from_post( 'conditions_json' ),
						'required_conditions_json' => Condition_Builder::sanitize_group_from_post( 'required_conditions_json' ),
					),
					array(
						'id'         => $sf_id,
						'service_id' => $id,
					)
				);
				Config_Compiler::invalidate();
				$_GET['tab'] = 'fields';
				return View::notice( __( 'Field details saved.', 'prime-fleet' ) );
			}
		}

		if ( 'attach_field' === $action ) {
			$field_id = absint( $_POST['attach_field_id'] ?? 0 );
			$step     = sanitize_key( wp_unslash( $_POST['attach_step'] ?? 'trip' ) );
			if ( $field_id ) {
				$exists = (int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT id FROM ' . Schema::table( 'service_fields' ) . ' WHERE service_id = %d AND field_id = %d',
						$id,
						$field_id
					)
				);
				if ( ! $exists ) {
					$wpdb->insert(
						Schema::table( 'service_fields' ),
						array(
							'service_id'    => $id,
							'field_id'      => $field_id,
							'enabled'       => 1,
							'step_key'      => $step,
							'display_order' => 100,
							'display_width' => 'full',
						)
					);
				}
				Config_Compiler::invalidate();
				$_GET['tab'] = 'fields';
				return View::notice( $exists ? __( 'Field already attached.', 'prime-fleet' ) : __( 'Field attached.', 'prime-fleet' ) );
			}
		}

		$bools = array(
			'is_active',
			'customer_visible',
			'allow_one_way',
			'allow_round_trip',
			'allow_multiple_stops',
			'require_pickup',
			'require_destination',
			'require_passenger_count',
			'require_luggage',
			'require_vehicle_matching',
			'requires_manual_confirmation',
		);

		$data = array(
			'name'                    => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'internal_name'           => sanitize_text_field( wp_unslash( $_POST['internal_name'] ?? '' ) ),
			'slug'                    => sanitize_title( wp_unslash( $_POST['slug'] ?? '' ) ),
			'short_description'       => sanitize_textarea_field( wp_unslash( $_POST['short_description'] ?? '' ) ),
			'display_order'           => absint( $_POST['display_order'] ?? 0 ),
			'booking_type'            => sanitize_key( wp_unslash( $_POST['booking_type'] ?? 'route' ) ),
			'customer_vehicle_choice' => sanitize_key( wp_unslash( $_POST['customer_vehicle_choice'] ?? 'recommend' ) ),
		);

		foreach ( $bools as $b ) {
			if ( isset( $_POST[ $b ] ) || 'save_service' === $action ) {
				$data[ $b ] = ! empty( $_POST[ $b ] ) ? 1 : 0;
			}
		}

		// Only update fields present on current tab to avoid wiping.
		$tab = sanitize_key( wp_unslash( $_POST['tab'] ?? 'general' ) );
		if ( 'general' === $tab ) {
			$wpdb->update(
				$table,
				array(
					'name'              => $data['name'] ?: get_bloginfo( 'name' ),
					'internal_name'     => $data['internal_name'],
					'slug'              => $data['slug'],
					'short_description' => $data['short_description'],
					'display_order'     => $data['display_order'],
					'is_active'         => $data['is_active'],
					'customer_visible'  => $data['customer_visible'],
				),
				array( 'id' => $id )
			);
		} elseif ( 'trip' === $tab ) {
			$wpdb->update(
				$table,
				array(
					'booking_type'                 => $data['booking_type'],
					'allow_one_way'                => $data['allow_one_way'],
					'allow_round_trip'             => $data['allow_round_trip'],
					'allow_multiple_stops'         => $data['allow_multiple_stops'],
					'require_pickup'               => $data['require_pickup'],
					'require_destination'          => $data['require_destination'],
					'require_passenger_count'      => $data['require_passenger_count'],
					'require_luggage'              => $data['require_luggage'],
					'requires_manual_confirmation' => $data['requires_manual_confirmation'],
				),
				array( 'id' => $id )
			);
		} elseif ( 'vehicle' === $tab ) {
			$wpdb->update(
				$table,
				array(
					'require_vehicle_matching' => $data['require_vehicle_matching'],
					'customer_vehicle_choice'  => $data['customer_vehicle_choice'],
				),
				array( 'id' => $id )
			);
		} elseif ( 'addons' === $tab ) {
			$selected = isset( $_POST['service_addons'] ) && is_array( $_POST['service_addons'] )
				? array_map( 'absint', wp_unslash( $_POST['service_addons'] ) )
				: array();
			$selected = array_values( array_filter( array_unique( $selected ) ) );
			$addons   = $wpdb->get_results( 'SELECT id, applicable_services_json FROM ' . Schema::table( 'addons' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ( $addons as $addon ) {
				$aid  = (int) $addon['id'];
				$ids  = json_decode( (string) ( $addon['applicable_services_json'] ?? '' ), true );
				$ids  = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
				$want = in_array( $aid, $selected, true );
				$has  = in_array( $id, $ids, true );
				if ( $want && ! $has ) {
					$ids[] = $id;
				} elseif ( ! $want && $has ) {
					$ids = array_values( array_filter( $ids, static function ( $x ) use ( $id ) {
						return (int) $x !== $id;
					} ) );
				} else {
					continue;
				}
				$wpdb->update(
					Schema::table( 'addons' ),
					array( 'applicable_services_json' => wp_json_encode( $ids ) ),
					array( 'id' => $aid ),
					array( '%s' ),
					array( '%d' )
				);
			}
		} elseif ( 'fields' === $tab && ! empty( $_POST['sf'] ) && is_array( $_POST['sf'] ) ) {
			foreach ( $_POST['sf'] as $sf_id => $sf ) {
				$wpdb->update(
					Schema::table( 'service_fields' ),
					array(
						'enabled'       => ! empty( $sf['enabled'] ) ? 1 : 0,
						'required'      => ! empty( $sf['required'] ) ? 1 : 0,
						'step_key'      => sanitize_key( $sf['step_key'] ?? 'trip' ),
						'display_order' => absint( $sf['display_order'] ?? 0 ),
						'display_width' => sanitize_key( $sf['display_width'] ?? 'full' ),
					),
					array(
						'id'         => absint( $sf_id ),
						'service_id' => $id,
					)
				);
			}
		}

		Config_Compiler::invalidate();
		return View::notice( __( 'Saved.', 'prime-fleet' ) );
	}

	/**
	 * @param string $raw Raw JSON.
	 * @return string|null
	 */
	private static function sanitize_json( $raw ) {
		$raw = trim( wp_unslash( $raw ) );
		if ( '' === $raw ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		if ( null === $decoded && 'null' !== strtolower( $raw ) ) {
			return $raw; // Keep for admin to fix; validated lightly.
		}
		return wp_json_encode( $decoded );
	}

	private static function text( $name, $label, $value ) {
		printf(
			'<div class="prime-fleet-field"><label for="%1$s">%2$s</label><input id="%1$s" type="text" name="%1$s" value="%3$s" /></div>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( (string) $value )
		);
	}

	private static function number( $name, $label, $value ) {
		printf(
			'<div class="prime-fleet-field"><label for="%1$s">%2$s</label><input id="%1$s" type="number" name="%1$s" value="%3$s" /></div>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( (string) $value )
		);
	}

	private static function textarea( $name, $label, $value ) {
		printf(
			'<div class="prime-fleet-field"><label for="%1$s">%2$s</label><textarea id="%1$s" name="%1$s" rows="3">%3$s</textarea></div>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_textarea( (string) $value )
		);
	}

	private static function checkbox( $name, $label, $value ) {
		printf(
			'<label class="prime-fleet-check"><input type="checkbox" name="%1$s" value="1" %2$s /> <span>%3$s</span></label>',
			esc_attr( $name ),
			checked( (int) $value, 1, false ),
			esc_html( $label )
		);
	}
}
