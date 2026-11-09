<?php
/**
 * Add-ons admin (list + modal).
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

final class Addons {

	const NONCE = 'prime_fleet_addons';

	public static function render() {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'prime-fleet' ) );
		}

		$notice  = Crud::handle_delete( 'addons', self::NONCE );
		$notice .= Crud::handle_save(
			'addons',
			array(
				'name'                      => 'text',
				'customer_description'      => 'textarea',
				'is_active'                 => 'bool',
				'pricing_type'              => 'text',
				'price'                     => 'float',
				'unit_label'                => 'text',
				'min_qty'                   => 'int',
				'max_qty'                   => 'int',
				'display_order'             => 'int',
				'is_included_info'          => 'bool',
				'applicable_services_json'  => 'json_ids',
			),
			self::NONCE
		);

		$types = array(
			'fixed'        => __( 'Fixed', 'prime-fleet' ),
			'per_quantity' => __( 'Per quantity', 'prime-fleet' ),
			'per_interval' => __( 'Per interval', 'prime-fleet' ),
			'free'         => __( 'Free', 'prime-fleet' ),
		);

		global $wpdb;
		$services = $wpdb->get_results( 'SELECT id, name FROM ' . Schema::table( 'services' ) . ' ORDER BY display_order ASC, name ASC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$list = Table_List::fetch(
			'addons',
			array(
				'search_cols' => array( 'name' ),
				'status_col'  => 'is_active',
				'type_col'    => 'pricing_type',
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

		echo '<div class="prime-fleet-card">';
		echo Table_List::toolbar(
			'addons',
			$args,
			array(
				'placeholder' => __( 'Search add-ons…', 'prime-fleet' ),
				'status'      => true,
				'types'       => $types,
			)
		);

		if ( empty( $rows ) ) {
			$filtered = '' !== (string) ( $args['s'] ?? '' ) || '' !== (string) ( $args['status'] ?? '' ) || '' !== (string) ( $args['type'] ?? '' );
			echo View::empty_state(
				$filtered ? __( 'No matching add-ons', 'prime-fleet' ) : __( 'No add-ons', 'prime-fleet' ),
				$filtered ? __( 'Try another search or reset filters.', 'prime-fleet' ) : __( 'Create extras like child seats and grocery stops.', 'prime-fleet' )
			);
		} else {
			echo '<div class="prime-fleet-table-wrap"><table class="prime-fleet-table"><thead><tr><th>' . esc_html__( 'Add-on', 'prime-fleet' ) . '</th><th>' . esc_html__( 'Type', 'prime-fleet' ) . '</th><th>' . esc_html__( 'Price', 'prime-fleet' ) . '</th><th>' . esc_html__( 'Services', 'prime-fleet' ) . '</th><th>' . esc_html__( 'Active', 'prime-fleet' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				$svc_ids = json_decode( (string) ( $r['applicable_services_json'] ?? '' ), true );
				$svc_ids = is_array( $svc_ids ) ? array_map( 'intval', $svc_ids ) : array();
				$record  = array(
					'id'                         => (int) $r['id'],
					'name'                       => $r['name'],
					'customer_description'       => $r['customer_description'],
					'pricing_type'               => $r['pricing_type'],
					'price'                      => $r['price'],
					'unit_label'                 => $r['unit_label'],
					'min_qty'                    => (int) $r['min_qty'],
					'max_qty'                    => (int) $r['max_qty'],
					'display_order'              => (int) $r['display_order'],
					'is_active'                  => (int) $r['is_active'],
					'is_included_info'           => (int) $r['is_included_info'],
					'applicable_services_json'   => $svc_ids,
				);
				$del_q = array_merge(
					$filt,
					array(
						'prime_fleet_delete' => (int) $r['id'],
						'_wpnonce'       => wp_create_nonce( self::NONCE ),
					)
				);
				$badge = ! empty( $r['is_included_info'] ) ? ' <span class="prime-fleet-badge prime-fleet-badge--ok">' . esc_html__( 'Included', 'prime-fleet' ) . '</span>' : '';
				$svc_label = empty( $svc_ids ) ? '—' : (string) count( $svc_ids );
				echo '<tr><td><strong>' . esc_html( $r['name'] ) . '</strong>' . $badge . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<td>' . esc_html( $r['pricing_type'] ) . '</td><td>' . esc_html( (string) $r['price'] ) . '</td>';
				echo '<td>' . esc_html( $svc_label ) . '</td>';
				echo '<td>' . ( $r['is_active'] ? '<span class="prime-fleet-badge prime-fleet-badge--ok">Active</span>' : '<span class="prime-fleet-badge prime-fleet-badge--off">Off</span>' ) . '</td>';
				echo '<td class="prime-fleet-table__actions">';
				echo View::modal_button( __( 'Edit', 'prime-fleet' ), 'prime-fleet-tpl-addon', __( 'Edit add-on', 'prime-fleet' ), 'ghost', $record );
				echo ' <button type="button" class="prime-fleet-btn prime-fleet-btn--danger" data-pf-confirm="' . esc_attr__( 'Delete this add-on?', 'prime-fleet' ) . '" data-pf-panel="addons" data-pf-query="' . esc_attr( wp_json_encode( $del_q ) ) . '">' . esc_html__( 'Delete', 'prime-fleet' ) . '</button>';
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
			echo Table_List::pagination( 'addons', $list['total'], $args );
		}
		echo '</div>';

		echo '<div id="prime-fleet-tpl-addon" class="prime-fleet-form-source" hidden><form method="post" data-pf-modal-form="1">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="prime_fleet_crud" value="1" /><input type="hidden" name="id" value="0" data-pf-field="id" />';
		echo '<p class="prime-fleet-help">' . esc_html__( 'Meet & Greet must be included info, not a paid airport add-on. Tick services this add-on applies to (e.g. Airport VIP for Child Seat + Grocery).', 'prime-fleet' ) . '</p>';
		echo '<div class="prime-fleet-form-grid prime-fleet-form-grid--2">';
		self::text( 'name', __( 'Name', 'prime-fleet' ), '' );
		self::textarea( 'customer_description', __( 'Customer description', 'prime-fleet' ), '' );
		echo '<div class="prime-fleet-field"><label for="ad_pricing_type">' . esc_html__( 'Pricing type', 'prime-fleet' ) . '</label><select id="ad_pricing_type" name="pricing_type" data-pf-field="pricing_type">';
		foreach ( $types as $k => $label ) {
			printf( '<option value="%1$s">%2$s</option>', esc_attr( $k ), esc_html( $label ) );
		}
		echo '</select></div>';
		self::number( 'price', __( 'Price', 'prime-fleet' ), '0', '0.01' );
		self::text( 'unit_label', __( 'Unit label', 'prime-fleet' ), '' );
		self::number( 'min_qty', __( 'Min qty', 'prime-fleet' ), '0' );
		self::number( 'max_qty', __( 'Max qty', 'prime-fleet' ), '1' );
		self::number( 'display_order', __( 'Display order', 'prime-fleet' ), '0' );
		echo '</div>';
		echo '<div class="prime-fleet-field prime-fleet-field--block"><span class="prime-fleet-field__label">' . esc_html__( 'Applies to services', 'prime-fleet' ) . '</span>';
		echo '<div class="prime-fleet-checks prime-fleet-checks--stack" data-pf-multi="applicable_services_json">';
		foreach ( $services as $svc ) {
			printf(
				'<label class="prime-fleet-check"><input type="checkbox" name="applicable_services_json[]" value="%1$d" data-pf-multi-value="%1$d" /> <span>%2$s</span></label>',
				(int) $svc['id'],
				esc_html( $svc['name'] )
			);
		}
		echo '</div><p class="prime-fleet-help">' . esc_html__( 'Leave all unchecked only if you will assign services from the Service → Add-ons tab.', 'prime-fleet' ) . '</p></div>';
		echo '<div class="prime-fleet-checks">';
		self::checkbox( 'is_active', __( 'Active', 'prime-fleet' ), 1 );
		self::checkbox( 'is_included_info', __( 'Included info (not charged)', 'prime-fleet' ), 0 );
		echo '</div><div class="prime-fleet-modal__footer"><button type="button" class="prime-fleet-btn prime-fleet-btn--ghost" data-pf-modal-close="1">' . esc_html__( 'Cancel', 'prime-fleet' ) . '</button><button type="submit" class="prime-fleet-btn">' . esc_html__( 'Save add-on', 'prime-fleet' ) . '</button></div></form></div>';

		View::shell( __( 'Add-ons', 'prime-fleet' ), ob_get_clean(), array( View::modal_button( __( 'Add add-on', 'prime-fleet' ), 'prime-fleet-tpl-addon', __( 'Add add-on', 'prime-fleet' ) ) ) );
	}

	private static function text( $name, $label, $value ) {
		printf( '<div class="prime-fleet-field"><label for="ad_%1$s">%2$s</label><input id="ad_%1$s" type="text" name="%1$s" value="%3$s" data-pf-field="%1$s" /></div>', esc_attr( $name ), esc_html( $label ), esc_attr( (string) $value ) );
	}

	private static function number( $name, $label, $value, $step = '1' ) {
		printf( '<div class="prime-fleet-field"><label for="ad_%1$s">%2$s</label><input id="ad_%1$s" type="number" step="%4$s" name="%1$s" value="%3$s" data-pf-field="%1$s" /></div>', esc_attr( $name ), esc_html( $label ), esc_attr( (string) $value ), esc_attr( $step ) );
	}

	private static function textarea( $name, $label, $value ) {
		printf( '<div class="prime-fleet-field prime-fleet-field--block"><label for="ad_%1$s">%2$s</label><textarea id="ad_%1$s" name="%1$s" rows="3" data-pf-field="%1$s">%3$s</textarea></div>', esc_attr( $name ), esc_html( $label ), esc_textarea( (string) $value ) );
	}

	private static function checkbox( $name, $label, $value ) {
		printf( '<label class="prime-fleet-check"><input type="checkbox" name="%1$s" value="1" data-pf-field="%1$s" %2$s /> <span>%3$s</span></label>', esc_attr( $name ), checked( (int) $value, 1, false ), esc_html( $label ) );
	}
}
