<?php
use Prime_Fleet\Logging\Logger;
use Prime_Fleet\I18n\Catalog;
use Prime_Fleet\REST\Rate_Limiter;
use Prime_Fleet\Payments\Payment_Service;

Logger::warning( 'rate_limit', 'S3 smoke 429 sample', array( 'bucket' => 'smoke' ) );
Logger::warning( 'honeypot', 'S3 smoke honeypot sample', array( 'ip' => '127.0.0.1' ) );

update_option( 'prime_fleet_catalog_i18n', array(
	'ur' => array( 'service' => array( '1' => array( 'name' => 'ٹیسٹ سروس' ) ) ),
) );
$en = Catalog::text( 'service', 1, 'name', 'Airport VIP', 'en_US' );
$ur = Catalog::text( 'service', 1, 'name', 'Airport VIP', 'ur' );
WP_CLI::log( "catalog en=$en ur=$ur" );
WP_CLI::log( 'cooldown_sec=' . Payment_Service::payment_notify_cooldown_seconds() );
WP_CLI::log( 'rtl_css_booking=' . ( false !== strpos( file_get_contents( PRIME_FLEET_PATH . 'assets/frontend/css/booking.css' ), '[dir="rtl"]' ) ? 'yes' : 'no' ) );
WP_CLI::log( 'docs=' . ( file_exists( PRIME_FLEET_PATH . 'docs/ADMIN_GUIDE.md' ) && file_exists( PRIME_FLEET_PATH . 'readme.txt' ) ? 'yes' : 'no' ) );
if ( 'Airport VIP' !== $en || 'ٹیسٹ سروس' !== $ur ) {
	WP_CLI::error( 'catalog resolve failed' );
}
WP_CLI::success( 'S3/RTL/catalog/docs smoke OK' );
