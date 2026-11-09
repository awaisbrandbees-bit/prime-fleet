<?php
/**
 * Coupons admin (list + modal).
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Admin\Pages;

use Prime_Fleet\Admin\Crud;
use Prime_Fleet\Admin\Table_List;
use Prime_Fleet\Admin\View;
use Prime_Fleet\Install\Capabilities;

defined( 'ABSPATH' ) || exit;

final class Coupons {

	const NONCE = 'prime_fleet_coupons';

	public static function render() {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'prime-fleet' ) );
		}

		if ( ! empty( $_POST['prime_fleet_crud'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			foreach ( array( 'starts_at', 'ends_at' ) as $dt ) {
				if ( ! empty( $_POST[ $dt ] ) ) {
					$val = str_replace( 'T', ' ', sanitize_text_field( wp_unslash( $_POST[ $dt ] ) ) );
					if ( 16 === strlen( $val ) ) {
						$val .= ':00';
					}
					$_POST[ $dt ] = $val;
				}
			}
		}

		$notice  = Crud::handle_delete( 'coupons', self::NONCE );
		$notice .= Crud::handle_save(
			'coupons',
			array(
				'code'           => 'text',
				'discount_type'  => 'text',
				'discount_value' => 'float',
				'use_type'       => 'text',
				'max_uses'       => 'nullable_int',
				'max_per_email'  => 'nullable_int',
				'bound_email'    => 'text',
				'min_fare'       => 'float',
				'starts_at'      => 'text',
				'ends_at'        => 'text',
				'is_active'      => 'bool',
			),
			self::NONCE
		);

		$list = Table_List::fetch(
			'coupons',
			array(
				'search_cols' => array( 'code', 'bound_email' ),
				'status_col'  => 'is_active',
				'type_col'    => 'use_type',
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
			'coupons',
			$args,
			array(
				'placeholder' => __( 'Search coupons…', 'prime-fleet' ),
				'status'      => true,
				'types'       => array(
					'multi'       => __( 'Multi-use', 'prime-fleet' ),
					'one_time'    => __( 'One-time', 'prime-fleet' ),
					'email_bound' => __( 'Email-bound', 'prime-fleet' ),
				),
			)
		);

		if ( empty( $rows ) ) {
			$filtered = '' !== (string) ( $args['s'] ?? '' ) || '' !== (string) ( $args['status'] ?? '' ) || '' !== (string) ( $args['type'] ?? '' );
			echo View::empty_state(
				$filtered ? __( 'No matching coupons', 'prime-fleet' ) : __( 'No coupons', 'prime-fleet' ),
				$filtered ? __( 'Try another search or reset filters.', 'prime-fleet' ) : __( 'Create promo codes with Add coupon.', 'prime-fleet' )
			);
		} else {
			echo '<div class="prime-fleet-table-wrap"><table class="prime-fleet-table"><thead><tr><th>' . esc_html__( 'Code', 'prime-fleet' ) . '</th><th>' . esc_html__( 'Discount', 'prime-fleet' ) . '</th><th>' . esc_html__( 'Use', 'prime-fleet' ) . '</th><th>' . esc_html__( 'Active', 'prime-fleet' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				$record = array(
					'id'             => (int) $r['id'],
					'code'           => $r['code'],
					'discount_type'  => $r['discount_type'],
					'discount_value' => $r['discount_value'],
					'use_type'       => $r['use_type'],
					'max_uses'       => $r['max_uses'],
					'max_per_email'  => $r['max_per_email'],
					'bound_email'    => $r['bound_email'],
					'min_fare'       => $r['min_fare'],
					'starts_at'      => self::to_local( $r['starts_at'] ?? '' ),
					'ends_at'        => self::to_local( $r['ends_at'] ?? '' ),
					'is_active'      => (int) $r['is_active'],
				);
				$del_q = array_merge(
					$filt,
					array(
						'prime_fleet_delete' => (int) $r['id'],
						'_wpnonce'       => wp_create_nonce( self::NONCE ),
					)
				);
				echo '<tr><td><strong>' . esc_html( $r['code'] ) . '</strong></td>';
				echo '<td>' . esc_html( $r['discount_type'] . ' ' . $r['discount_value'] ) . '</td>';
				echo '<td>' . esc_html( $r['use_type'] ) . '</td>';
				echo '<td>' . ( $r['is_active'] ? '<span class="prime-fleet-badge prime-fleet-badge--ok">Active</span>' : '<span class="prime-fleet-badge prime-fleet-badge--off">Off</span>' ) . '</td>';
				echo '<td class="prime-fleet-table__actions">';
				echo View::modal_button( __( 'Edit', 'prime-fleet' ), 'prime-fleet-tpl-coupon', __( 'Edit coupon', 'prime-fleet' ), 'ghost', $record );
				echo ' <button type="button" class="prime-fleet-btn prime-fleet-btn--danger" data-pf-confirm="' . esc_attr__( 'Delete this coupon?', 'prime-fleet' ) . '" data-pf-panel="coupons" data-pf-query="' . esc_attr( wp_json_encode( $del_q ) ) . '">' . esc_html__( 'Delete', 'prime-fleet' ) . '</button>';
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
			echo Table_List::pagination( 'coupons', $list['total'], $args );
		}
		echo '</div>';

		echo '<div id="prime-fleet-tpl-coupon" class="prime-fleet-form-source" hidden><form method="post" data-pf-modal-form="1">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="prime_fleet_crud" value="1" /><input type="hidden" name="id" value="0" data-pf-field="id" />';
		echo '<div class="prime-fleet-form-grid prime-fleet-form-grid--2">';
		self::text( 'code', __( 'Code', 'prime-fleet' ), '' );
		echo '<div class="prime-fleet-field"><label for="co_discount_type">' . esc_html__( 'Discount type', 'prime-fleet' ) . '</label><select id="co_discount_type" name="discount_type" data-pf-field="discount_type">';
		foreach ( array( 'percent' => __( 'Percent', 'prime-fleet' ), 'fixed' => __( 'Fixed', 'prime-fleet' ) ) as $k => $label ) {
			printf( '<option value="%1$s">%2$s</option>', esc_attr( $k ), esc_html( $label ) );
		}
		echo '</select></div>';
		self::number( 'discount_value', __( 'Discount value', 'prime-fleet' ), '0' );
		echo '<div class="prime-fleet-field"><label for="co_use_type">' . esc_html__( 'Use type', 'prime-fleet' ) . '</label><select id="co_use_type" name="use_type" data-pf-field="use_type">';
		foreach ( array( 'multi' => __( 'Multi-use', 'prime-fleet' ), 'one_time' => __( 'One-time', 'prime-fleet' ), 'email_bound' => __( 'Email-bound', 'prime-fleet' ) ) as $k => $label ) {
			printf( '<option value="%1$s">%2$s</option>', esc_attr( $k ), esc_html( $label ) );
		}
		echo '</select></div>';
		self::number( 'max_uses', __( 'Max uses', 'prime-fleet' ), '' );
		self::number( 'max_per_email', __( 'Max per email', 'prime-fleet' ), '' );
		self::text( 'bound_email', __( 'Bound email', 'prime-fleet' ), '' );
		self::number( 'min_fare', __( 'Min fare', 'prime-fleet' ), '' );
		printf( '<div class="prime-fleet-field"><label for="co_starts_at">%s</label><input id="co_starts_at" type="datetime-local" name="starts_at" value="" data-pf-field="starts_at" /></div>', esc_html__( 'Starts at', 'prime-fleet' ) );
		printf( '<div class="prime-fleet-field"><label for="co_ends_at">%s</label><input id="co_ends_at" type="datetime-local" name="ends_at" value="" data-pf-field="ends_at" /></div>', esc_html__( 'Ends at', 'prime-fleet' ) );
		echo '</div><div class="prime-fleet-checks">';
		self::checkbox( 'is_active', __( 'Active', 'prime-fleet' ), 1 );
		echo '</div><div class="prime-fleet-modal__footer"><button type="button" class="prime-fleet-btn prime-fleet-btn--ghost" data-pf-modal-close="1">' . esc_html__( 'Cancel', 'prime-fleet' ) . '</button><button type="submit" class="prime-fleet-btn">' . esc_html__( 'Save coupon', 'prime-fleet' ) . '</button></div></form></div>';

		View::shell( __( 'Coupons', 'prime-fleet' ), ob_get_clean(), array( View::modal_button( __( 'Add coupon', 'prime-fleet' ), 'prime-fleet-tpl-coupon', __( 'Add coupon', 'prime-fleet' ) ) ) );
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
		printf( '<div class="prime-fleet-field"><label for="co_%1$s">%2$s</label><input id="co_%1$s" type="text" name="%1$s" value="%3$s" data-pf-field="%1$s" /></div>', esc_attr( $name ), esc_html( $label ), esc_attr( (string) $value ) );
	}

	private static function number( $name, $label, $value ) {
		printf( '<div class="prime-fleet-field"><label for="co_%1$s">%2$s</label><input id="co_%1$s" type="number" step="0.01" name="%1$s" value="%3$s" data-pf-field="%1$s" /></div>', esc_attr( $name ), esc_html( $label ), esc_attr( (string) $value ) );
	}

	private static function checkbox( $name, $label, $value ) {
		printf( '<label class="prime-fleet-check"><input type="checkbox" name="%1$s" value="1" data-pf-field="%1$s" %2$s /> <span>%3$s</span></label>', esc_attr( $name ), checked( (int) $value, 1, false ), esc_html( $label ) );
	}
}
