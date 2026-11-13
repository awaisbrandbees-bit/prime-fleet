<?php
use Prime_Fleet\Services\Config_Compiler;
use Prime_Fleet\Payments\Payment_Service;

$v1 = (int) get_option( Config_Compiler::VERSION_OPTION, 1 );
Config_Compiler::invalidate();
$v2 = (int) get_option( Config_Compiler::VERSION_OPTION, 1 );
WP_CLI::log( "cache_ver $v1 -> $v2" );

// Cooldown: mark sent then issue should fail before mutate.
$ref = $GLOBALS['wpdb']->get_row( "SELECT id, status FROM {$GLOBALS['wpdb']->prefix}prime_fleet_bookings ORDER BY id DESC LIMIT 1", ARRAY_A );
if ( ! $ref ) { WP_CLI::error( 'no booking' ); }
$id = (int) $ref['id'];
$sec = Payment_Service::payment_notify_cooldown_seconds();
set_transient( 'prime_fleet_pay_notify_' . $id, time(), max( 30, $sec ) );
$r = Payment_Service::issue( $id, '', array( 'notify' => true ) );
if ( ! is_wp_error( $r ) || 'prime_fleet_pay_notify_cooldown' !== $r->get_error_code() ) {
	WP_CLI::error( 'expected cooldown error, got ' . ( is_wp_error( $r ) ? $r->get_error_code() : 'ok' ) );
}
WP_CLI::log( 'cooldown blocked issue: ' . $r->get_error_message() );

$r2 = Payment_Service::regenerate( $id, array( 'notify' => true ) );
if ( ! is_wp_error( $r2 ) || 'prime_fleet_pay_notify_cooldown' !== $r2->get_error_code() ) {
	WP_CLI::error( 'expected regenerate cooldown' );
}
WP_CLI::log( 'cooldown blocked regenerate OK' );

if ( $v2 <= $v1 ) { WP_CLI::error( 'version not bumped' ); }
WP_CLI::success( 'High/Med fixes smoke OK' );
