<?php
/**
 * Capability and role setup.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Install;

defined( 'ABSPATH' ) || exit;

final class Capabilities {

	const MANAGE  = 'prime_fleet_manage';
	const DISPATCH = 'prime_fleet_dispatch';

	/**
	 * Add caps to roles.
	 */
	public static function install() {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( self::MANAGE );
			$admin->add_cap( self::DISPATCH );
		}

		$dispatcher = get_role( 'prime_fleet_dispatcher' );
		if ( ! $dispatcher ) {
			add_role(
				'prime_fleet_dispatcher',
				__( 'Prime Fleet Dispatcher', 'prime-fleet' ),
				array(
					'read' => true,
				)
			);
			$dispatcher = get_role( 'prime_fleet_dispatcher' );
		}

		if ( $dispatcher ) {
			$dispatcher->add_cap( self::DISPATCH );
			$dispatcher->add_cap( 'read' );
		}
	}

	/**
	 * @param string $cap Capability.
	 * @return bool
	 */
	public static function current_user_can( $cap ) {
		return current_user_can( $cap ) || current_user_can( self::MANAGE );
	}
}
