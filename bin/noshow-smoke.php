<?php
global $wpdb;
use Prime_Fleet\Database\Schema;
use Prime_Fleet\Messaging\Queue;

$row = $wpdb->get_row(
	"SELECT id, reference, status, fare_total FROM " . Schema::table( 'bookings' ) . " WHERE status = 'confirmed' ORDER BY id DESC LIMIT 1",
	ARRAY_A
);
if ( ! $row ) {
	WP_CLI::warning( 'No confirmed booking to test; looking for any non-terminal…' );
	$row = $wpdb->get_row(
		"SELECT id, reference, status, fare_total FROM " . Schema::table( 'bookings' ) . " WHERE status NOT IN ('cancelled','completed','no_show') ORDER BY id DESC LIMIT 1",
		ARRAY_A
	);
}
if ( ! $row ) {
	WP_CLI::error( 'No suitable booking found' );
}
$id = (int) $row['id'];
$prev = (string) $row['status'];
$fare_before = (float) $row['fare_total'];
WP_CLI::log( "Using #{$id} {$row['reference']} status={$prev} fare={$fare_before}" );

if ( 'confirmed' !== $prev ) {
	$wpdb->update( Schema::table( 'bookings' ), array( 'status' => 'confirmed' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
	WP_CLI::log( 'Forced status to confirmed for smoke' );
}

$wpdb->update( Schema::table( 'bookings' ), array( 'status' => 'no_show' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
$wpdb->insert(
	Schema::table( 'booking_events' ),
	array(
		'booking_id' => $id,
		'event_type' => 'status_changed',
		'actor_id'   => 0,
		'message'    => 'Marked no-show by staff (smoke)',
		'meta_json'  => wp_json_encode( array( 'status' => 'no_show', 'smoke' => 1 ) ),
	),
	array( '%d', '%s', '%d', '%s', '%s' )
);
Queue::cancel_pending_reminders( $id );
do_action( 'prime_fleet_booking_status_changed', $id, 'no_show', 'confirmed' );

$after = $wpdb->get_row( $wpdb->prepare( 'SELECT status, fare_total FROM ' . Schema::table( 'bookings' ) . ' WHERE id = %d', $id ), ARRAY_A );
$ev = $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM " . Schema::table( 'booking_events' ) . " WHERE booking_id = %d AND event_type = 'status_changed' AND message LIKE %s",
	$id,
	'%no-show%'
) );
WP_CLI::success( 'status=' . $after['status'] . ' fare=' . $after['fare_total'] . ' events=' . $ev );
if ( 'no_show' !== $after['status'] ) {
	WP_CLI::error( 'status not no_show' );
}
if ( abs( $fare_before - (float) $after['fare_total'] ) > 0.001 ) {
	WP_CLI::error( 'fare changed' );
}
