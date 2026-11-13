<?php
/**
 * Hide WP / plugin / theme admin notices on Prime Fleet screens.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Admin;

defined( 'ABSPATH' ) || exit;

final class Notices_Guard {

	/**
	 * Hooks.
	 */
	public function hooks() {
		add_action( 'current_screen', array( $this, 'maybe_suppress' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_hide_css' ), 1 );
	}

	/**
	 * @param \WP_Screen $screen Screen.
	 */
	public function maybe_suppress( $screen ) {
		if ( ! $this->is_prime_fleet_screen( $screen ) ) {
			return;
		}

		$this->strip_notice_hooks();

		// Re-strip late so plugins that attach during admin_init / current_screen are cleared.
		add_action( 'admin_head', array( $this, 'strip_notice_hooks' ), 0 );
		add_action( 'in_admin_header', array( $this, 'strip_notice_hooks' ), 0 );
		add_action( 'admin_print_styles', array( $this, 'strip_notice_hooks' ), 0 );
		add_action( 'admin_notices', array( $this, 'strip_notice_hooks' ), -10000 );
		add_action( 'all_admin_notices', array( $this, 'strip_notice_hooks' ), -10000 );

		// Hide WP admin footer ("Thank you…" / Version x.x) so it never overlays cards.
		add_filter( 'admin_footer_text', '__return_empty_string', 999 );
		add_filter( 'update_footer', '__return_empty_string', 999 );
	}

	/**
	 * Remove notice action callbacks.
	 */
	public function strip_notice_hooks() {
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'network_admin_notices' );
		remove_all_actions( 'user_admin_notices' );
	}

	/**
	 * CSS backup for late / hardcoded banners.
	 *
	 * @param string $hook Hook.
	 */
	public function enqueue_hide_css( $hook ) {
		if ( false === strpos( (string) $hook, 'prime-fleet' ) ) {
			return;
		}

		$css = '
			body.prime-fleet-admin #wpbody-content > .notice,
			body.prime-fleet-admin #wpbody-content > .notice-success,
			body.prime-fleet-admin #wpbody-content > .notice-warning,
			body.prime-fleet-admin #wpbody-content > .notice-error,
			body.prime-fleet-admin #wpbody-content > .notice-info,
			body.prime-fleet-admin #wpbody-content > .updated,
			body.prime-fleet-admin #wpbody-content > .error,
			body.prime-fleet-admin #wpbody-content > .update-nag,
			body.prime-fleet-admin #wpbody-content > .e-notice,
			body.prime-fleet-admin #wpbody-content > .elementor-message,
			body.prime-fleet-admin #wpbody-content > .woocommerce-message,
			body.prime-fleet-admin #wpbody-content > .woocommerce-error,
			body.prime-fleet-admin #wpbody-content > .woocommerce-info,
			body.prime-fleet-admin .wrap > .notice:not(.prime-fleet-notice),
			.prime-fleet-app ~ .notice,
			.prime-fleet-panel > .notice:not(.prime-fleet-notice) {
				display: none !important;
			}
			/* Do NOT hide #wpfooter — that collapses page height and breaks window scroll. */
		';
		wp_register_style( 'prime-fleet-hide-notices', false, array(), PRIME_FLEET_VERSION );
		wp_enqueue_style( 'prime-fleet-hide-notices' );
		wp_add_inline_style( 'prime-fleet-hide-notices', $css );
	}

	/**
	 * @param \WP_Screen|null $screen Screen.
	 * @return bool
	 */
	private function is_prime_fleet_screen( $screen = null ) {
		if ( ! $screen ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		}
		if ( ! $screen ) {
			return false;
		}

		$id   = isset( $screen->id ) ? (string) $screen->id : '';
		$base = isset( $screen->base ) ? (string) $screen->base : '';

		if ( false !== strpos( $id, 'prime-fleet' ) || false !== strpos( $base, 'prime-fleet' ) ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return ( 0 === strpos( $page, 'prime-fleet' ) );
	}
}
