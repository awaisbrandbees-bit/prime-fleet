<?php
/**
 * Admin assets.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Admin;

defined( 'ABSPATH' ) || exit;

final class Assets {

	/**
	 * Hooks.
	 */
	public function hooks() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * @param string $hook Hook suffix.
	 */
	public function enqueue( $hook ) {
		if ( false === strpos( $hook, 'prime-fleet' ) ) {
			return;
		}

		$css_path = \PRIME_FLEET_PATH . 'assets/admin/css/admin.css';
		$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : \PRIME_FLEET_VERSION;

		wp_enqueue_style(
			'prime-fleet-admin',
			\PRIME_FLEET_URL . 'assets/admin/css/admin.css',
			array(),
			$css_ver
		);

		$js_path = \PRIME_FLEET_PATH . 'assets/admin/js/admin.js';
		$js_ver  = file_exists( $js_path ) ? (string) filemtime( $js_path ) : \PRIME_FLEET_VERSION;

		wp_enqueue_script(
			'prime-fleet-admin',
			\PRIME_FLEET_URL . 'assets/admin/js/admin.js',
			array(),
			$js_ver,
			true
		);

		wp_localize_script(
			'prime-fleet-admin',
			'primeFleetAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'prime_fleet_admin' ),
			)
		);
	}
}
