<?php
/**
 * Activation / deactivation.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet;

use Prime_Fleet\Database\Schema;
use Prime_Fleet\Install\Seeder;
use Prime_Fleet\Install\Capabilities;

defined( 'ABSPATH' ) || exit;

final class Activator {

	/**
	 * Plugin activation.
	 */
	public static function activate() {
		self::migrate();
		Capabilities::install();
		Seeder::run();
		update_option( 'prime_fleet_db_version', PRIME_FLEET_VERSION );
		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation.
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}

	/**
	 * Create / update tables.
	 */
	public static function migrate() {
		Schema::install();
	}
}
