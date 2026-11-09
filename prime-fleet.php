<?php
/**
 * Plugin Name:       Prime Fleet
 * Plugin URI:        https://primefleet.app
 * Description:       VIP ground transportation booking, dispatch, and payments for WordPress — white-label ready.
 * Version:           1.0.0-beta.26
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Prime Fleet
 * Text Domain:       prime-fleet
 * Domain Path:       /languages
 *
 * @package Prime_Fleet
 */

defined( 'ABSPATH' ) || exit;

define( 'PRIME_FLEET_VERSION', '1.0.0-beta.26' );
define( 'PRIME_FLEET_FILE', __FILE__ );
define( 'PRIME_FLEET_PATH', plugin_dir_path( __FILE__ ) );
define( 'PRIME_FLEET_URL', plugin_dir_url( __FILE__ ) );
define( 'PRIME_FLEET_BASENAME', plugin_basename( __FILE__ ) );

require_once PRIME_FLEET_PATH . 'includes/Autoloader.php';
Prime_Fleet\Autoloader::register();

register_activation_hook( __FILE__, array( 'Prime_Fleet\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Prime_Fleet\\Activator', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		Prime_Fleet\Plugin::instance()->boot();
	}
);
