<?php
/**
 * Admin menu — single console entry.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Admin;

use Prime_Fleet\Install\Capabilities;

defined( 'ABSPATH' ) || exit;

final class Menu {

	/**
	 * Hooks.
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'register' ) );
		add_action( 'admin_menu', array( $this, 'collapse_submenus' ), 999 );
	}

	/**
	 * Register Prime Fleet console only.
	 */
	public function register() {
		add_menu_page(
			__( 'Prime Fleet', 'prime-fleet' ),
			__( 'Prime Fleet', 'prime-fleet' ),
			Capabilities::DISPATCH,
			'prime-fleet',
			array( App::class, 'render' ),
			'dashicons-car',
			26
		);

		// Keep a hidden legacy route for direct service editor bookmarks.
		add_submenu_page(
			null,
			__( 'Edit Service', 'prime-fleet' ),
			__( 'Edit Service', 'prime-fleet' ),
			Capabilities::MANAGE,
			'prime-fleet-service-edit',
			static function () {
				add_filter( 'prime_fleet_admin_embed', '__return_false' );
				echo '<div class="wrap prime-fleet-app"><div class="prime-fleet-panel">';
				\Prime_Fleet\Admin\Pages\Service_Edit::render();
				echo '</div></div>';
			}
		);
	}

	/**
	 * Remove auto-created duplicate submenu under Prime Fleet.
	 */
	public function collapse_submenus() {
		remove_submenu_page( 'prime-fleet', 'prime-fleet' );
	}
}
