<?php
/**
 * CLI: seed US demo data into Prime Fleet.
 *
 * Usage from WP root:
 *   wp eval-file wp-content/plugins/prime-fleet/bin/seed-demo-us.php
 * Force:
 *   wp eval-file wp-content/plugins/prime-fleet/bin/seed-demo-us.php -- --force
 *
 * @package Prime_Fleet
 */

use Prime_Fleet\Install\Demo_Seed;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$force = in_array( '--force', $GLOBALS['argv'] ?? array(), true )
	|| ( isset( $_SERVER['argv'] ) && in_array( '--force', (array) $_SERVER['argv'], true ) );

$result = Demo_Seed::run( $force || true ); // always force on explicit CLI run for QA refresh
print_r( $result );
