<?php
/**
 * Admin menu — WooCommerce-style WP submenus (no in-app sidebar).
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
	}

	/**
	 * Register Prime Fleet + one WP submenu per console page.
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

		foreach ( App::wp_menu_items() as $item ) {
			add_submenu_page(
				'prime-fleet',
				$item['label'],
				$item['label'],
				$item['cap'],
				$item['slug'],
				array( App::class, 'render' )
			);
		}

		// Keep a hidden legacy route for direct service editor bookmarks.
		// Parent = prime-fleet so the left submenu stays open (null parent collapses it).
		add_submenu_page(
			'prime-fleet',
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
		remove_submenu_page( 'prime-fleet', 'prime-fleet-service-edit' );
	}
}
