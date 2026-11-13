<?php
/**
 * US-market demo data for local QA (dashboard / calendar / dispatch).
 *
 * Run: wp eval-file wp-content/plugins/prime-fleet/bin/seed-demo-us.php
 * Or:  Demo_Seed::run( true ); // force re-seed
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Install;

use Prime_Fleet\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class Demo_Seed {

	const OPTION = 'prime_fleet_demo_us_v1';

	/**
	 * @param bool $force Re-run even if already seeded (clears prior demo rows).
	 * @return array<string,int|string>
	 */
	public static function run( $force = false ) {
		if ( ! $force && get_option( self::OPTION ) ) {
			return array( 'status' => 'already_seeded' );
		}

		if ( $force ) {
			self::clear_previous_demo();
		}

		self::settings_us();
		$zone_ids     = self::zones_us();
		$vehicle_ids  = self::vehicles_us();
		$chauffeur_ids = self::chauffeurs_us( $vehicle_ids );
		self::coupons();
		self::surcharges();
		self::availability( $vehicle_ids );
		$counts = self::bookings( $zone_ids, $vehicle_ids, $chauffeur_ids );

		update_option( self::OPTION, gmdate( 'c' ) );

		return array_merge(
			array( 'status' => 'ok' ),
			$counts
		);
	}

	private static function clear_previous_demo() {
		global $wpdb;
		$bookings = Schema::table( 'bookings' );
		$ids      = $wpdb->get_col( "SELECT id FROM {$bookings} WHERE internal_notes LIKE '%[demo-seed]%'" );
		if ( empty( $ids ) ) {
			delete_option( self::OPTION );
			return;
		}
		$id_list = implode( ',', array_map( 'absint', $ids ) );
		foreach ( array( 'booking_legs', 'booking_items', 'booking_meta', 'booking_events', 'booking_adjustments', 'communications', 'payments', 'slot_offers', 'coupon_redemptions' ) as $t ) {
			$table = Schema::table( $t );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$table} WHERE booking_id IN ({$id_list})" );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$bookings} WHERE id IN ({$id_list})" );

		$veh = Schema::table( 'vehicles' );
		$ch  = Schema::table( 'chauffeurs' );
		$wpdb->query( "DELETE FROM {$veh} WHERE name LIKE 'US Demo %'" );
		$wpdb->query( "DELETE FROM {$ch} WHERE internal_notes LIKE '%[demo-seed]%'" );
		$wpdb->query( 'DELETE FROM ' . Schema::table( 'zones' ) . " WHERE name LIKE 'US · %'" );
		$wpdb->query( 'DELETE FROM ' . Schema::table( 'coupons' ) . " WHERE code LIKE 'US%'" );
		$wpdb->query( 'DELETE FROM ' . Schema::table( 'surcharges' ) . " WHERE notes LIKE '%[demo-seed]%'" );
		$wpdb->query( 'DELETE FROM ' . Schema::table( 'availability_blocks' ) . " WHERE reason LIKE '%[demo-seed]%'" );

		delete_option( self::OPTION );
	}

	private static function settings_us() {
		$s = get_option( 'prime_fleet_settings', array() );
		if ( ! is_array( $s ) ) {
			$s = array();
		}
		$s['currency']         = 'USD';
		$s['timezone']         = 'America/New_York';
		$s['reference_prefix'] = 'PF-';
		$s['phone']            = '(212) 555-0148';
		$s['whatsapp']         = '12125550148';
		$s['min_advance_hours'] = '2';
		$s['same_day_allowed']  = 1;
		update_option( 'prime_fleet_settings', $s );
		update_option( 'timezone_string', 'America/New_York' );
	}

	/**
	 * @return array<int,int> zone ids keyed by slug.
	 */
	private static function zones_us() {
		global $wpdb;
		$table = Schema::table( 'zones' );
		$rows  = array(
			'jfk'       => 'US · JFK Airport (NY)',
			'lga'       => 'US · LaGuardia (NY)',
			'ewr'       => 'US · Newark EWR (NJ)',
			'midtown'   => 'US · Manhattan Midtown (NY)',
			'downtown'  => 'US · Lower Manhattan (NY)',
			'brooklyn'  => 'US · Brooklyn (NY)',
			'lax'       => 'US · LAX Airport (CA)',
			'beverly'   => 'US · Beverly Hills (CA)',
			'santa'     => 'US · Santa Monica (CA)',
			'sfo'       => 'US · SFO Airport (CA)',
			'sf_fin'    => 'US · San Francisco Financial (CA)',
			'mia'       => 'US · MIA Airport (FL)',
			'miami_bh'  => 'US · Miami Beach (FL)',
			'ord'       => 'US · O’Hare ORD (IL)',
			'chicago'   => 'US · Chicago Loop (IL)',
			'dal'       => 'US · DFW Airport (TX)',
			'dallas'    => 'US · Dallas Uptown (TX)',
		);

		$ids  = array();
		$order = 200;
		foreach ( $rows as $slug => $name ) {
			$existing = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$table} WHERE name = %s LIMIT 1", $name )
			);
			if ( $existing ) {
				$ids[ $slug ] = $existing;
				continue;
			}
			$wpdb->insert(
				$table,
				array(
					'name'           => $name,
					'customer_label' => $name,
					'is_active'      => 1,
					'display_order'  => $order,
				),
				array( '%s', '%s', '%d', '%d' )
			);
			$ids[ $slug ] = (int) $wpdb->insert_id;
			$order       += 10;
		}
		return $ids;
	}

	/**
	 * @return int[]
	 */
	private static function vehicles_us() {
		global $wpdb;
		$table = Schema::table( 'vehicles' );

		$rows = array(
			array( 'US Demo Escalade Black', 'Cadillac Escalade ESV', 'Cadillac Escalade ESV', 6, 6, 8, 100 ),
			array( 'US Demo Escalade Pearl', 'Cadillac Escalade ESV', 'Cadillac Escalade ESV', 6, 6, 8, 110 ),
			array( 'US Demo Suburban', 'Chevrolet Suburban', 'Chevrolet Suburban', 7, 7, 8, 120 ),
			array( 'US Demo Navigator', 'Lincoln Navigator', 'Lincoln Navigator', 6, 6, 8, 130 ),
			array( 'US Demo S-Class', 'Mercedes-Benz S-Class', 'Mercedes-Benz S-Class', 3, 2, 3, 140 ),
			array( 'US Demo Sprinter 1', 'Mercedes-Benz Sprinter', 'Mercedes-Benz Sprinter (13 pax)', 13, 12, 13, 150 ),
			array( 'US Demo Sprinter 2', 'Mercedes-Benz Sprinter', 'Mercedes-Benz Sprinter (13 pax)', 13, 12, 13, 160 ),
			array( 'US Demo Tahoe', 'Chevrolet Tahoe', 'Chevrolet Tahoe', 5, 5, 6, 170 ),
		);

		$ids = array();
		foreach ( $rows as $r ) {
			$existing = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$table} WHERE name = %s LIMIT 1", $r[0] )
			);
			if ( $existing ) {
				$ids[] = $existing;
				continue;
			}
			$wpdb->insert(
				$table,
				array(
					'name'                  => $r[0],
					'vehicle_type'          => $r[1],
					'customer_display_name' => $r[2],
					'passenger_capacity'    => $r[3],
					'checked_bag_capacity'  => $r[4],
					'carry_on_bag_capacity' => $r[5],
					'is_active'             => 1,
					'available_for_booking' => 1,
					'customer_visible'      => 1,
					'display_order'         => $r[6],
					'description'           => 'US demo fleet unit [demo-seed]',
				)
			);
			$ids[] = (int) $wpdb->insert_id;
		}

		// Also include any pre-existing active vehicles.
		$extra = $wpdb->get_col( "SELECT id FROM {$table} WHERE is_active = 1 ORDER BY display_order ASC, id ASC" );
		foreach ( (array) $extra as $id ) {
			$id = (int) $id;
			if ( $id && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * @param int[] $vehicle_ids Vehicle ids.
	 * @return int[]
	 */
	private static function chauffeurs_us( array $vehicle_ids ) {
		global $wpdb;
		$table = Schema::table( 'chauffeurs' );

		$rows = array(
			array( 'James Whitaker', '+12125550101', 'james.whitaker@primefleet.demo', 'NY metro lead' ),
			array( 'Marcus Ellison', '+17185550122', 'marcus.ellison@primefleet.demo', 'NY / NJ airports' ),
			array( 'Sofia Reyes', '+13105550133', 'sofia.reyes@primefleet.demo', 'LAX / Westside' ),
			array( 'Daniel Cho', '+14155550144', 'daniel.cho@primefleet.demo', 'SFO / Peninsula' ),
			array( 'Ava Thompson', '+13055550155', 'ava.thompson@primefleet.demo', 'Miami Beach' ),
			array( 'Ryan Caldwell', '+13125550166', 'ryan.caldwell@primefleet.demo', 'Chicago Loop' ),
			array( 'Priya Nair', '+12145550177', 'priya.nair@primefleet.demo', 'DFW / Dallas' ),
			array( 'Chris Donovan', '+16465550188', 'chris.donovan@primefleet.demo', 'Corporate Manhattan' ),
			array( 'Elena Vasquez', '+18185550199', 'elena.vasquez@primefleet.demo', 'LA evenings' ),
			array( 'Michael Brooks', '+19735550200', 'michael.brooks@primefleet.demo', 'EWR specialist' ),
		);

		$ids = array();
		$i   = 0;
		foreach ( $rows as $r ) {
			$existing = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$table} WHERE email = %s LIMIT 1", $r[2] )
			);
			if ( $existing ) {
				$ids[] = $existing;
				++$i;
				continue;
			}
			$veh = $vehicle_ids[ $i % max( 1, count( $vehicle_ids ) ) ];
			$wpdb->insert(
				$table,
				array(
					'name'               => $r[0],
					'phone'              => $r[1],
					'whatsapp'           => $r[1],
					'email'              => $r[2],
					'default_vehicle_id' => (int) $veh,
					'is_active'          => 1,
					'internal_notes'     => $r[3] . ' [demo-seed]',
				)
			);
			$ids[] = (int) $wpdb->insert_id;
			++$i;
		}
		return $ids;
	}

	private static function coupons() {
		global $wpdb;
		$table = Schema::table( 'coupons' );
		$rows  = array(
			array( 'USWELCOME10', 'percent', 10, 'multi', 500, null, 100 ),
			array( 'USCORP25', 'fixed', 25, 'multi', 200, 150, 0 ),
			array( 'USVIP15', 'percent', 15, 'multi', 100, null, 200 ),
			array( 'USFIRST50', 'fixed', 50, 'single', 50, 250, 0 ),
		);
		foreach ( $rows as $r ) {
			$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE code = %s", $r[0] ) );
			if ( $exists ) {
				continue;
			}
			$wpdb->insert(
				$table,
				array(
					'code'           => $r[0],
					'discount_type'  => $r[1],
					'discount_value' => $r[2],
					'use_type'       => $r[3],
					'max_uses'       => $r[4],
					'min_fare'       => $r[5],
					'is_active'      => 1,
					'starts_at'      => gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) ),
					'ends_at'        => gmdate( 'Y-m-d H:i:s', strtotime( '+180 days' ) ),
				)
			);
		}
	}

	private static function surcharges() {
		global $wpdb;
		$table = Schema::table( 'surcharges' );
		$rows  = array(
			array( 'US Thanksgiving Peak', 'percent', 15, '2026-11-25', '2026-11-29' ),
			array( 'US New Year Eve', 'percent', 20, '2026-12-31', '2027-01-01' ),
			array( 'US Holiday Flat', 'fixed', 35, '2026-12-24', '2026-12-26' ),
		);
		foreach ( $rows as $r ) {
			$exists = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$table} WHERE name = %s LIMIT 1", $r[0] )
			);
			if ( $exists ) {
				continue;
			}
			$wpdb->insert(
				$table,
				array(
					'name'           => $r[0],
					'is_active'      => 1,
					'surcharge_type' => $r[1],
					'value'          => $r[2],
					'start_date'     => $r[3],
					'end_date'       => $r[4],
					'notes'          => 'US demo surcharge [demo-seed]',
				)
			);
		}
	}

	/**
	 * @param int[] $vehicle_ids Vehicles.
	 */
	private static function availability( array $vehicle_ids ) {
		global $wpdb;
		$table = Schema::table( 'availability_blocks' );
		if ( empty( $vehicle_ids ) ) {
			return;
		}
		$v1 = (int) $vehicle_ids[0];
		$v2 = (int) ( $vehicle_ids[1] ?? $vehicle_ids[0] );
		$rows = array(
			array( $v1, '+3 days 09:00', '+3 days 17:00', 'Scheduled maintenance [demo-seed]' ),
			array( $v2, '+10 days 08:00', '+11 days 08:00', 'Detailing bay [demo-seed]' ),
		);
		foreach ( $rows as $r ) {
			$start = gmdate( 'Y-m-d H:i:s', strtotime( $r[1] ) );
			$end   = gmdate( 'Y-m-d H:i:s', strtotime( $r[2] ) );
			$exists = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE resource_id = %d AND reason = %s LIMIT 1",
					$r[0],
					$r[3]
				)
			);
			if ( $exists ) {
				continue;
			}
			$wpdb->insert(
				$table,
				array(
					'resource_type'  => 'vehicle',
					'resource_id'    => $r[0],
					'start_datetime' => $start,
					'end_datetime'   => $end,
					'reason'         => $r[3],
					'notes'          => 'Demo block',
				)
			);
		}
	}

	/**
	 * @param array<string,int> $zones Zones.
	 * @param int[]             $vehicles Vehicles.
	 * @param int[]             $chauffeurs Chauffeurs.
	 * @return array<string,int>
	 */
	private static function bookings( array $zones, array $vehicles, array $chauffeurs ) {
		global $wpdb;

		$guests = array(
			array( 'Jordan Miles', 'jordan.miles@example.com', '+12125551001', 'NY' ),
			array( 'Emily Carter', 'emily.carter@example.com', '+13105551002', 'CA' ),
			array( 'Noah Patel', 'noah.patel@example.com', '+14155551003', 'CA' ),
			array( 'Olivia Grant', 'olivia.grant@example.com', '+13055551004', 'FL' ),
			array( 'Liam Brooks', 'liam.brooks@example.com', '+13125551005', 'IL' ),
			array( 'Sophia Nguyen', 'sophia.nguyen@example.com', '+12145551006', 'TX' ),
			array( 'Ethan Morales', 'ethan.morales@example.com', '+17185551007', 'NY' ),
			array( 'Isabella Cruz', 'isabella.cruz@example.com', '+18185551008', 'CA' ),
			array( 'Mason Reed', 'mason.reed@example.com', '+19735551009', 'NJ' ),
			array( 'Ava Bennett', 'ava.bennett@example.com', '+16465551010', 'NY' ),
			array( 'Lucas Kim', 'lucas.kim@example.com', '+14085551011', 'CA' ),
			array( 'Mia Foster', 'mia.foster@example.com', '+17865551012', 'FL' ),
			array( 'Harper Diaz', 'harper.diaz@example.com', '+14695551013', 'TX' ),
			array( 'Benjamin Cole', 'ben.cole@example.com', '+17735551014', 'IL' ),
			array( 'Charlotte Hayes', 'charlotte.hayes@example.com', '+12125551015', 'NY' ),
		);

		$pairs = array(
			array( 'jfk', 'midtown', 'JFK T4 Arrivals', 'The Plaza Hotel, 5th Ave' ),
			array( 'lga', 'downtown', 'LGA Terminal B', 'One World Trade Center' ),
			array( 'ewr', 'midtown', 'EWR Terminal C', 'Bryant Park Hotel' ),
			array( 'midtown', 'jfk', 'Park Hyatt New York', 'JFK T8 Departures' ),
			array( 'lax', 'beverly', 'LAX TBIT', 'Beverly Wilshire' ),
			array( 'beverly', 'lax', 'Rodeo Drive', 'LAX Terminal 7' ),
			array( 'sfo', 'sf_fin', 'SFO International', 'Salesforce Tower' ),
			array( 'mia', 'miami_bh', 'MIA Terminal D', 'Fontainebleau Miami Beach' ),
			array( 'ord', 'chicago', 'ORD Terminal 5', 'The Langham Chicago' ),
			array( 'dal', 'dallas', 'DFW Terminal A', 'Ritz-Carlton Dallas' ),
			array( 'brooklyn', 'jfk', 'Dumbo, Brooklyn', 'JFK T5' ),
			array( 'santa', 'lax', 'Santa Monica Pier', 'LAX TBIT' ),
		);

		$statuses = array(
			'pending_confirmation',
			'pending_confirmation',
			'confirmed',
			'confirmed',
			'confirmed',
			'completed',
			'completed',
			'cancelled',
			'no_show',
		);
		$payments = array(
			'unpaid',
			'unpaid',
			'link_sent',
			'pending_verification',
			'paid',
			'paid',
			'refunded',
		);

		$service_ids = $wpdb->get_col( 'SELECT id FROM ' . Schema::table( 'services' ) . ' WHERE is_active = 1 ORDER BY id ASC' );
		if ( empty( $service_ids ) ) {
			$service_ids = array( 1 );
		}

		$coupons = array( '', '', 'USWELCOME10', '', 'USCORP25', '', 'USVIP15', '' );

		$created = 0;
		$today   = current_time( 'Y-m-d' );

		// Explicit density around "today" for dashboard / calendar.
		$schedule = array();
		for ( $d = -10; $d <= 18; $d++ ) {
			$day = gmdate( 'Y-m-d', strtotime( $today . " {$d} days" ) );
			$per = ( 0 === $d ) ? 6 : ( ( abs( $d ) <= 3 ) ? 3 : 2 );
			for ( $n = 0; $n < $per; $n++ ) {
				$hour = 6 + ( ( $n * 3 + abs( $d ) ) % 14 );
				$min  = ( 0 === $n % 2 ) ? 0 : 30;
				$schedule[] = sprintf( '%s %02d:%02d:00', $day, $hour, $min );
			}
		}

		foreach ( $schedule as $idx => $pickup ) {
			$guest = $guests[ $idx % count( $guests ) ];
			$pair  = $pairs[ $idx % count( $pairs ) ];
			$pu_z  = (int) ( $zones[ $pair[0] ] ?? 0 );
			$de_z  = (int) ( $zones[ $pair[1] ] ?? 0 );
			if ( ! $pu_z || ! $de_z ) {
				continue;
			}

			$status  = $statuses[ $idx % count( $statuses ) ];
			$pay     = $payments[ $idx % count( $payments ) ];
			$veh     = (int) $vehicles[ $idx % count( $vehicles ) ];
			$chauf   = (int) $chauffeurs[ $idx % count( $chauffeurs ) ];
			$svc     = (int) $service_ids[ $idx % count( $service_ids ) ];
			$coupon  = $coupons[ $idx % count( $coupons ) ];
			$pax     = 1 + ( $idx % 6 );
			$fare    = 95 + ( ( $idx * 17 ) % 320 );
			if ( $coupon ) {
				$fare = max( 60, $fare - 15 );
			}

			// Past completed / no_show bias; future pending/confirmed bias.
			$ts = strtotime( $pickup );
			if ( $ts < time() - DAY_IN_SECONDS ) {
				$status = ( 0 === $idx % 7 ) ? 'no_show' : ( ( 0 === $idx % 5 ) ? 'cancelled' : 'completed' );
				$pay    = ( 'cancelled' === $status ) ? 'unpaid' : ( ( 0 === $idx % 4 ) ? 'refunded' : 'paid' );
			} elseif ( $ts > time() + 2 * DAY_IN_SECONDS ) {
				$status = ( 0 === $idx % 3 ) ? 'pending_confirmation' : 'confirmed';
			}

			$assign_v = in_array( $status, array( 'confirmed', 'completed', 'no_show' ), true ) ? $veh : ( ( 0 === $idx % 4 ) ? $veh : 0 );
			$assign_c = $assign_v ? $chauf : 0;

			$ref = self::unique_ref();
			$ok  = $wpdb->insert(
				Schema::table( 'bookings' ),
				array(
					'reference'             => $ref,
					'status'                => $status,
					'payment_status'        => $pay,
					'service_id'            => $svc,
					'customer_name'         => $guest[0],
					'customer_email'        => $guest[1],
					'customer_phone'        => $guest[2],
					'customer_whatsapp'     => $guest[2],
					'pickup_datetime'       => $pickup,
					'assigned_vehicle_id'   => $assign_v,
					'assigned_chauffeur_id' => $assign_c,
					'passenger_count'       => $pax,
					'manual_review'         => ( 0 === $idx % 11 ) ? 1 : 0,
					'fare_total'            => $fare,
					'currency'              => 'USD',
					'coupon_code'           => $coupon,
					'internal_notes'        => sprintf(
						'[demo-seed] %s guest · %s → %s · market US',
						$guest[3],
						$pair[2],
						$pair[3]
					),
					'fare_snapshot_json'    => wp_json_encode(
						array(
							'currency' => 'USD',
							'total'    => $fare,
							'lines'    => array(
								array( 'label' => 'Private transfer', 'amount' => $fare ),
							),
						)
					),
					'created_at'            => gmdate( 'Y-m-d H:i:s', max( strtotime( '-20 days' ), $ts - 3 * DAY_IN_SECONDS ) ),
				)
			);
			if ( ! $ok ) {
				continue;
			}
			$bid = (int) $wpdb->insert_id;
			++$created;

			$wpdb->insert(
				Schema::table( 'booking_legs' ),
				array(
					'booking_id'         => $bid,
					'leg_type'           => 'outbound',
					'pickup_zone_id'     => $pu_z,
					'dest_zone_id'       => $de_z,
					'pickup_detail'      => $pair[2],
					'dest_detail'        => $pair[3],
					'scheduled_datetime' => $pickup,
					'meta_json'          => wp_json_encode( array( 'flight' => 'AA' . ( 100 + ( $idx % 800 ) ) ) ),
				)
			);

			if ( 0 === $idx % 5 ) {
				$ret = gmdate( 'Y-m-d H:i:s', $ts + 5 * HOUR_IN_SECONDS );
				$wpdb->insert(
					Schema::table( 'booking_legs' ),
					array(
						'booking_id'         => $bid,
						'leg_type'           => 'return',
						'pickup_zone_id'     => $de_z,
						'dest_zone_id'       => $pu_z,
						'pickup_detail'      => $pair[3],
						'dest_detail'        => $pair[2],
						'scheduled_datetime' => $ret,
					)
				);
			}

			if ( 0 === $idx % 4 ) {
				$wpdb->insert(
					Schema::table( 'booking_items' ),
					array(
						'booking_id' => $bid,
						'item_type'  => 'addon',
						'item_id'    => 0,
						'label'      => 'Child / Booster Seat',
						'qty'        => 1,
						'unit_price' => 15,
						'line_total' => 15,
					)
				);
			}

			$wpdb->insert(
				Schema::table( 'booking_events' ),
				array(
					'booking_id' => $bid,
					'event_type' => 'demo_seeded',
					'actor_id'   => 0,
					'message'    => 'US demo booking seeded',
					'meta_json'  => wp_json_encode( array( 'source' => 'demo-seed' ) ),
				)
			);

			// Communications sample for dashboard failed/sent counters.
			$wpdb->insert(
				Schema::table( 'communications' ),
				array(
					'booking_id'   => $bid,
					'channel'      => 'email',
					'template_key' => 'booking_received',
					'recipient'    => $guest[1],
					'status'       => 'sent',
					'body_preview' => 'We received your request ' . $ref,
					'provider_id'  => 'demo-email-' . $bid,
				)
			);
			if ( 0 === $idx % 6 ) {
				$wpdb->insert(
					Schema::table( 'communications' ),
					array(
						'booking_id'   => $bid,
						'channel'      => 'whatsapp',
						'template_key' => 'booking_confirmed',
						'recipient'    => $guest[2],
						'status'       => 'failed',
						'body_preview' => 'Confirmed — ' . $ref,
						'provider_id'  => 'demo-wa-fail-' . $bid,
					)
				);
			} else {
				$wpdb->insert(
					Schema::table( 'communications' ),
					array(
						'booking_id'   => $bid,
						'channel'      => 'whatsapp',
						'template_key' => 'booking_confirmed',
						'recipient'    => $guest[2],
						'status'       => 'sent',
						'body_preview' => 'Confirmed — ' . $ref,
						'provider_id'  => 'demo-wa-' . $bid,
					)
				);
			}

			if ( in_array( $pay, array( 'paid', 'link_sent', 'refunded', 'pending_verification' ), true ) ) {
				$token = wp_generate_password( 20, false, false );
				$wpdb->insert(
					Schema::table( 'payments' ),
					array(
						'booking_id'  => $bid,
						'method_id'   => 0,
						'amount'      => $fare,
						'currency'    => 'USD',
						'token'       => $token,
						'status'      => ( 'link_sent' === $pay ) ? 'link_sent' : ( ( 'pending_verification' === $pay ) ? 'pending_verification' : ( ( 'refunded' === $pay ) ? 'refunded' : 'paid' ) ),
						'external_id' => 'demo_' . $bid,
					)
				);
			}
		}

		return array(
			'bookings'   => $created,
			'vehicles'   => count( $vehicles ),
			'chauffeurs' => count( $chauffeurs ),
			'zones_us'   => count( $zones ),
		);
	}

	/**
	 * @return string
	 */
	private static function unique_ref() {
		global $wpdb;
		$table = Schema::table( 'bookings' );
		for ( $i = 0; $i < 20; $i++ ) {
			$ref = 'PF-' . strtoupper( wp_generate_password( 6, false, false ) );
			$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE reference = %s", $ref ) );
			if ( ! $exists ) {
				return $ref;
			}
		}
		return 'PF-' . strtoupper( substr( md5( uniqid( '', true ) ), 0, 6 ) );
	}
}
