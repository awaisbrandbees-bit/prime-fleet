<?php
/**
 * Payment methods admin — ready methods + extensible catalog.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Admin\Pages;

use Prime_Fleet\Admin\View;
use Prime_Fleet\Database\Schema;
use Prime_Fleet\Install\Capabilities;
use Prime_Fleet\Plugin;
use Prime_Fleet\Services\Config_Compiler;

defined( 'ABSPATH' ) || exit;

final class Payments {

	const NONCE = 'prime_fleet_payments';

	/**
	 * Gateways with full settings UI (order = display order).
	 *
	 * @return string[]
	 */
	private static function ready_ids() {
		return array( 'instructions', 'custom_link', 'stripe' );
	}

	public static function render() {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'prime-fleet' ) );
		}

		$notice  = self::handle_save();
		$methods = Plugin::instance()->payments()->all();

		foreach ( $methods as $method ) {
			if ( method_exists( $method, 'ensure_row' ) ) {
				$method->ensure_row();
			}
		}

		self::maybe_restore_ready_gateways();
		self::ensure_coming_soon_disabled( $methods );

		$ready   = array();
		$catalog = array();
		foreach ( self::ready_ids() as $id ) {
			if ( isset( $methods[ $id ] ) ) {
				$ready[ $id ] = $methods[ $id ];
			}
		}
		foreach ( $methods as $id => $method ) {
			if ( ! isset( $ready[ $id ] ) ) {
				$catalog[ $id ] = $method;
			}
		}

		ob_start();
		if ( $notice ) {
			echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '<form method="post">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="prime_fleet_action" value="save_payments" />';

		echo '<div class="prime-fleet-card prime-fleet-section">';
		echo '<header class="prime-fleet-section__head">';
		echo '<h3>' . esc_html__( 'Configured methods', 'prime-fleet' ) . '</h3>';
		echo '<p>' . esc_html__( 'Enable gateways and copy shown at checkout.', 'prime-fleet' ) . '</p>';
		echo '</header>';
		echo '</div>';

		foreach ( $ready as $method ) {
			self::render_ready_card( $method );
		}

		echo '<div class="prime-fleet-card prime-fleet-section" style="margin-top:1rem">';
		echo '<header class="prime-fleet-section__head">';
		echo '<h3>' . esc_html__( 'More payment methods', 'prime-fleet' ) . '</h3>';
		echo '<p>' . esc_html__( 'Additional gateways appear here when registered.', 'prime-fleet' ) . '</p>';
		echo '</header>';

		if ( empty( $catalog ) ) {
			echo View::empty_state( __( 'No additional gateways', 'prime-fleet' ), __( 'Register more methods via the payment registry.', 'prime-fleet' ) );
		} else {
			echo '<div class="prime-fleet-pay-catalog">';
			foreach ( $catalog as $method ) {
				self::render_catalog_row( $method );
			}
			echo '</div>';
		}
		echo '</div>';

		echo '<div class="prime-fleet-sticky-save"><button type="submit" class="prime-fleet-btn">' . esc_html__( 'Save payment methods', 'prime-fleet' ) . '</button></div>';
		echo '</form>';

		View::shell( __( 'Payments', 'prime-fleet' ), ob_get_clean() );
	}

	/**
	 * @param \Prime_Fleet\Payments\Contracts\Payment_Method_Interface $method Method.
	 */
	private static function render_ready_card( $method ) {
		global $wpdb;
		$method_id = $method->id();
		$settings  = $method->get_settings();
		$schema    = $method->settings_schema();
		$enabled   = $method->is_enabled();
		$db_label  = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT label FROM ' . Schema::table( 'payment_methods' ) . ' WHERE method_key = %s',
				$method_id
			)
		);
		$label = $db_label ? $db_label : $method->label();

		$caps = array();
		if ( $method->supports_hosted() ) {
			$caps[] = __( 'Hosted checkout', 'prime-fleet' );
		}
		if ( $method->supports_link() ) {
			$caps[] = __( 'Payment link', 'prime-fleet' );
		}
		if ( $method->supports_instructions() ) {
			$caps[] = __( 'Instructions', 'prime-fleet' );
		}

		$is_stripe = ( 'stripe' === $method_id );

		echo '<div class="prime-fleet-card prime-fleet-pay-primary" style="margin-top:1rem">';
		echo '<div class="prime-fleet-pay-primary__head">';
		echo '<div>';
		echo '<p class="prime-fleet-pay-card__key">' . esc_html( $method_id ) . '</p>';
		echo '<h3>' . esc_html( $label ) . '</h3>';
		if ( $caps ) {
			echo '<p class="prime-fleet-help">' . esc_html( implode( ' · ', $caps ) ) . ( $is_stripe ? ' · ' . esc_html__( 'Hosted Checkout when secret key is set', 'prime-fleet' ) : '' ) . '</p>';
		}
		echo '</div>';
		echo '<span class="prime-fleet-badge ' . ( $enabled ? 'prime-fleet-badge--ok' : 'prime-fleet-badge--off' ) . '">' . esc_html( $enabled ? __( 'Enabled', 'prime-fleet' ) : __( 'Disabled', 'prime-fleet' ) ) . '</span>';
		echo '</div>';

		if ( $is_stripe ) {
			$wh = rest_url( 'prime-fleet/v1/webhooks/stripe' );
			echo '<p class="prime-fleet-help" style="margin-top:10px">' . esc_html__( 'Prefer secrets in wp-config:', 'prime-fleet' ) . ' <code>PRIME_FLEET_STRIPE_SECRET_KEY</code>, <code>PRIME_FLEET_STRIPE_WEBHOOK_SECRET</code>. ';
			echo esc_html__( 'Webhook endpoint:', 'prime-fleet' ) . ' <code>' . esc_html( $wh ) . '</code></p>';
		}

		echo '<div class="prime-fleet-checks" style="margin-top:14px">';
		printf(
			'<label class="prime-fleet-check"><input type="checkbox" name="%1$s[is_enabled]" value="1" %2$s /> <span>%3$s</span></label>',
			esc_attr( $method_id ),
			checked( $enabled, true, false ),
			esc_html__( 'Enable for customers', 'prime-fleet' )
		);
		echo '</div>';

		echo '<div class="prime-fleet-form-grid prime-fleet-form-grid--2" style="margin-top:14px">';
		printf(
			'<div class="prime-fleet-field"><label for="%1$s_label">%2$s</label><input id="%1$s_label" type="text" name="%1$s[label]" value="%3$s" /></div>',
			esc_attr( $method_id ),
			esc_html__( 'Customer label', 'prime-fleet' ),
			esc_attr( $label )
		);

		foreach ( $schema as $key => $field ) {
			$type   = $field['type'] ?? 'text';
			$flabel = $field['label'] ?? $key;
			$value  = isset( $settings[ $key ] ) ? $settings[ $key ] : '';
			$name   = $method_id . '[' . $key . ']';
			$id     = $method_id . '_' . $key;

			if ( 'textarea' === $type ) {
				printf( '<div class="prime-fleet-field prime-fleet-field--block"><label for="%1$s">%2$s</label><textarea id="%1$s" name="%3$s" rows="4">%4$s</textarea></div>', esc_attr( $id ), esc_html( $flabel ), esc_attr( $name ), esc_textarea( (string) $value ) );
			} elseif ( 'select' === $type && ! empty( $field['options'] ) && is_array( $field['options'] ) ) {
				echo '<div class="prime-fleet-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $flabel ) . '</label><select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
				foreach ( $field['options'] as $opt => $opt_label ) {
					printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $opt ), selected( (string) $value, (string) $opt, false ), esc_html( $opt_label ) );
				}
				echo '</select></div>';
			} else {
				$input_type = in_array( $type, array( 'password', 'url', 'email' ), true ) ? $type : 'text';
				$display    = ( 'password' === $input_type ) ? '' : (string) $value;
				$ph         = ( 'password' === $input_type && '' !== (string) $value )
					? __( '••••••••  (leave blank to keep)', 'prime-fleet' )
					: '';
				printf(
					'<div class="prime-fleet-field"><label for="%1$s">%2$s</label><input id="%1$s" type="%3$s" name="%4$s" value="%5$s" placeholder="%6$s" autocomplete="new-password" /></div>',
					esc_attr( $id ),
					esc_html( $flabel ),
					esc_attr( $input_type ),
					esc_attr( $name ),
					esc_attr( $display ),
					esc_attr( $ph )
				);
			}
		}
		echo '</div></div>';
	}

	/**
	 * @param \Prime_Fleet\Payments\Contracts\Payment_Method_Interface $method Method.
	 */
	private static function render_catalog_row( $method ) {
		$id    = $method->id();
		$label = $method->label();
		$caps  = array();
		if ( $method->supports_hosted() ) {
			$caps[] = __( 'Hosted', 'prime-fleet' );
		}
		if ( $method->supports_link() ) {
			$caps[] = __( 'Link', 'prime-fleet' );
		}
		if ( $method->supports_instructions() ) {
			$caps[] = __( 'Instructions', 'prime-fleet' );
		}

		$msg = sprintf(
			/* translators: %s: gateway name */
			__( '%s is registered in the multi-gateway layer, but this integration is not live yet. Enable will be available when credentials and charge flow are wired.', 'prime-fleet' ),
			$label
		);

		echo '<div class="prime-fleet-pay-catalog__row">';
		echo '<div class="prime-fleet-pay-catalog__info">';
		echo '<strong>' . esc_html( $label ) . '</strong>';
		echo '<span class="prime-fleet-help">' . esc_html( $id . ( $caps ? ' · ' . implode( ' · ', $caps ) : '' ) ) . '</span>';
		echo '</div>';
		echo '<span class="prime-fleet-badge prime-fleet-badge--warn">' . esc_html__( 'Coming soon', 'prime-fleet' ) . '</span>';
		echo '<button type="button" class="prime-fleet-btn prime-fleet-btn--ghost" data-pf-coming-soon-title="' . esc_attr__( 'Coming soon', 'prime-fleet' ) . '" data-pf-coming-soon="' . esc_attr( $msg ) . '" data-pf-coming-soon-ok="' . esc_attr__( 'Got it', 'prime-fleet' ) . '">' . esc_html__( 'Enable', 'prime-fleet' ) . '</button>';
		echo '</div>';
	}

	/**
	 * One-time: Bank + Custom link are ready (not coming soon) and enabled by default.
	 */
	private static function maybe_restore_ready_gateways() {
		if ( get_option( 'prime_fleet_restored_ready_gateways' ) ) {
			return;
		}
		global $wpdb;
		$table = Schema::table( 'payment_methods' );
		foreach ( array( 'instructions', 'custom_link' ) as $key ) {
			$wpdb->update( $table, array( 'is_enabled' => 1 ), array( 'method_key' => $key ), array( '%d' ), array( '%s' ) );
		}
		update_option( 'prime_fleet_restored_ready_gateways', 1, false );
	}

	/**
	 * Keep non-ready catalog gateways off.
	 *
	 * @param array $methods Methods.
	 */
	private static function ensure_coming_soon_disabled( $methods ) {
		global $wpdb;
		$table = Schema::table( 'payment_methods' );
		$ready = array_fill_keys( self::ready_ids(), true );
		foreach ( $methods as $id => $method ) {
			if ( isset( $ready[ $id ] ) ) {
				continue;
			}
			if ( $method->is_enabled() ) {
				$wpdb->update( $table, array( 'is_enabled' => 0 ), array( 'method_key' => $id ), array( '%d' ), array( '%s' ) );
			}
		}
	}

	/**
	 * @return string
	 */
	private static function handle_save() {
		if ( empty( $_POST['prime_fleet_action'] ) || 'save_payments' !== sanitize_key( wp_unslash( $_POST['prime_fleet_action'] ) ) ) {
			return '';
		}
		check_admin_referer( self::NONCE );

		$methods = Plugin::instance()->payments()->all();
		foreach ( self::ready_ids() as $id ) {
			if ( ! isset( $methods[ $id ] ) ) {
				continue;
			}
			$input = isset( $_POST[ $id ] ) && is_array( $_POST[ $id ] ) ? wp_unslash( $_POST[ $id ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$methods[ $id ]->save_settings( $input );
		}

		self::ensure_coming_soon_disabled( $methods );
		Config_Compiler::invalidate();
		return View::notice( __( 'Payment methods saved.', 'prime-fleet' ) );
	}
}
