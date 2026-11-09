<?php
/**
 * Seed default config data (idempotent).
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Install;

use Prime_Fleet\Database\Schema;
use Prime_Fleet\Services\Config_Compiler;

defined( 'ABSPATH' ) || exit;

final class Seeder {

	/**
	 * Run seeds once (or fill missing rows).
	 */
	public static function run() {
		if ( get_option( 'prime_fleet_seeded_v1' ) ) {
			self::ensure_payment_rows();
			self::ensure_settings_defaults();
			self::ensure_m1_data_fixes();
			return;
		}

		self::seed_settings();
		self::seed_zones();
		self::seed_fields();
		self::seed_vehicles();
		self::seed_addons();
		self::seed_packages();
		self::seed_services_and_pricing();
		self::seed_templates();
		self::ensure_payment_rows();

		update_option( 'prime_fleet_seeded_v1', 1 );
		Config_Compiler::invalidate();
	}

	/**
	 * Merge missing settings keys on existing installs.
	 */
	private static function ensure_settings_defaults() {
		$current = get_option( 'prime_fleet_settings', array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		$defaults = array(
			'waiting_complimentary_minutes' => 30,
			'same_day_allowed'              => 1,
		);
		$merged  = $current;
		$changed = false;
		foreach ( $defaults as $k => $v ) {
			if ( ! array_key_exists( $k, $merged ) ) {
				$merged[ $k ] = $v;
				$changed       = true;
			}
		}
		if ( ! array_key_exists( 'min_advance_hours', $merged ) || '' === (string) $merged['min_advance_hours'] ) {
			$merged['min_advance_hours'] = '2';
			$changed                      = true;
		}
		// One-time: same-day bookings on with the 2h advance window.
		if ( empty( $merged['pickup_rules_migrated'] ) ) {
			$merged['same_day_allowed']      = 1;
			$merged['pickup_rules_migrated'] = 1;
			$changed                          = true;
		}
		if ( $changed ) {
			update_option( 'prime_fleet_settings', $merged );
		}
	}

	/**
	 * Idempotent M1 data fixes for already-seeded sites.
	 */
	private static function ensure_m1_data_fixes() {
		global $wpdb;
		self::seed_extra_routes();
		self::link_packages_to_services();

		$wedding_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . Schema::table( 'services' ) . ' WHERE slug = %s',
				'wedding-event-standby'
			)
		);
		if ( $wedding_id ) {
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . Schema::table( 'hourly_rates' ) . ' SET minimum_hours = NULL WHERE service_id = %d AND label = %s',
					$wedding_id,
					'Wedding / Event'
				)
			);
		}
		self::ensure_settings_defaults();
		self::ensure_airport_vip_client_updates();
	}

	/**
	 * Client feedback (Sep 2026): busy-season Airport VIP matrix, Sprinter bookable, slow-season toggle.
	 */
	private static function ensure_airport_vip_client_updates() {
		global $wpdb;

		$services = Schema::table( 'services' );
		$vehicles = Schema::table( 'vehicles' );
		$routes   = Schema::table( 'routes' );
		$zones    = Schema::table( 'zones' );

		$airport_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$services} WHERE slug = %s LIMIT 1", 'airport-vip-transfer' )
		);
		if ( $airport_id ) {
			// Guests can pick Escalade or Sprinter on the Vehicle step.
			$wpdb->update(
				$services,
				array( 'customer_vehicle_choice' => 'optional' ),
				array( 'id' => $airport_id ),
				array( '%s' ),
				array( '%d' )
			);
		}

		// Ensure Mercedes Sprinter exists and is guest-visible (13 pax).
		$sprinter_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$vehicles} WHERE name = %s OR customer_display_name LIKE %s LIMIT 1",
				'Sprinter Van',
				'%Sprinter%'
			)
		);
		if ( $sprinter_id ) {
			$wpdb->update(
				$vehicles,
				array(
					'name'                  => 'Sprinter Van',
					'vehicle_type'          => 'Mercedes-Benz Sprinter',
					'customer_display_name' => 'Mercedes-Benz Sprinter (13 passengers)',
					'passenger_capacity'    => 13,
					'is_active'             => 1,
					'available_for_booking' => 1,
					'customer_visible'      => 1,
					'display_order'         => 30,
				),
				array( 'id' => $sprinter_id ),
				array( '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d' ),
				array( '%d' )
			);
		} else {
			$wpdb->insert(
				$vehicles,
				array(
					'name'                  => 'Sprinter Van',
					'vehicle_type'          => 'Mercedes-Benz Sprinter',
					'customer_display_name' => 'Mercedes-Benz Sprinter (13 passengers)',
					'passenger_capacity'    => 13,
					'checked_bag_capacity'  => null,
					'carry_on_bag_capacity' => null,
					'is_active'             => 1,
					'available_for_booking' => 1,
					'customer_visible'      => 1,
					'display_order'         => 30,
				)
			);
		}

		// Busy-season published bases (slow season = Settings % off at quote time).
		$settings = get_option( 'prime_fleet_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings_dirty = false;
		if ( ! array_key_exists( 'seasonal_discount_pct', $settings ) ) {
			$settings['seasonal_discount_pct'] = isset( $settings['airport_vip_seasonal_discount_pct'] )
				? (float) $settings['airport_vip_seasonal_discount_pct']
				: 10;
			$settings_dirty                    = true;
		}
		if ( ! array_key_exists( 'airport_vip_seasonal_discount_pct', $settings ) ) {
			$settings['airport_vip_seasonal_discount_pct'] = $settings['seasonal_discount_pct'];
			$settings_dirty                                 = true;
		}
		// Default: tick Airport VIP Transfer for seasonal discount (admin can change anytime).
		if ( ! array_key_exists( 'seasonal_discount_service_ids', $settings ) ) {
			$default_ids = array();
			if ( $airport_id ) {
				$default_ids[] = $airport_id;
			}
			$settings['seasonal_discount_service_ids'] = $default_ids;
			$settings_dirty                             = true;
		}

		if ( $airport_id && empty( $settings['airport_rates_busy_season_v1'] ) ) {
			$pls = (int) $wpdb->get_var( "SELECT id FROM {$zones} WHERE name = 'PLS Airport'" );
			$rate_map = array(
				'Grace Bay'                     => array( 145, 290 ),
				'Long Bay'                      => array( 145, 290 ),
				'Leeward'                       => array( 145, 290 ),
				'Turtle Cove'                   => array( 130, 260 ),
				'Blue Hills'                    => array( 130, 260 ),
				'Chalk Sound'                   => array( 145, 290 ),
				'Taylor Bay'                    => array( 145, 290 ),
				'Sapodilla'                     => array( 145, 290 ),
				'Remote Villas / Gated Estates' => array( 170, 340 ),
			);
			foreach ( $rate_map as $zone_name => $price ) {
				$zid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$zones} WHERE name = %s", $zone_name ) );
				if ( ! $pls || ! $zid ) {
					continue;
				}
				$existing = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$routes} WHERE service_id = %d AND (
							(origin_zone_id = %d AND dest_zone_id = %d) OR (origin_zone_id = %d AND dest_zone_id = %d AND applies_both_directions = 1)
						) LIMIT 1",
						$airport_id,
						$pls,
						$zid,
						$zid,
						$pls
					)
				);
				if ( $existing ) {
					$wpdb->update(
						$routes,
						array(
							'one_way_price'    => $price[0],
							'round_trip_price' => $price[1],
							'is_active'        => 1,
						),
						array( 'id' => $existing ),
						array( '%f', '%f', '%d' ),
						array( '%d' )
					);
				} else {
					$wpdb->insert(
						$routes,
						array(
							'service_id'              => $airport_id,
							'origin_zone_id'          => $pls,
							'dest_zone_id'            => $zid,
							'applies_both_directions' => 1,
							'one_way_price'           => $price[0],
							'round_trip_price'        => $price[1],
							'is_active'               => 1,
						)
					);
				}
			}
			$settings['airport_rates_busy_season_v1'] = 1;
			$settings_dirty                            = true;
		}

		if ( $settings_dirty ) {
			update_option( 'prime_fleet_settings', $settings );
		}

		Config_Compiler::invalidate();
	}

	private static function seed_settings() {
		if ( get_option( 'prime_fleet_settings' ) ) {
			return;
		}
		update_option(
			'prime_fleet_settings',
			array(
				'currency'            => 'USD',
				'timezone'            => 'UTC',
				'reference_prefix'    => 'PF-',
				'default_status'      => 'pending_confirmation',
				'slot_increment'      => 30,
				'pending_message'     => 'Your transfer request is Pending Confirmation. Prime Fleet will verify vehicle availability and contact you shortly.',
				'submit_label'        => 'Submit request',
				'custom_quote_message'=> 'This request requires a custom quotation. Prime Fleet will review the details and contact you shortly.',
				'phone'               => '(649) 331-6340',
				'whatsapp'            => '16493316340',
				'reminder_24h'        => 1440,
				'reminder_2h'         => 120,
				'min_advance_hours'   => '2',
				'same_day_allowed'    => 1,
				'waiting_complimentary_minutes' => 30,
				'seasonal_discount_pct'         => 10,
				'seasonal_discount_service_ids' => array(),
				'airport_vip_seasonal_discount_pct' => 10,
			)
		);
	}

	private static function seed_zones() {
		global $wpdb;
		$table = Schema::table( 'zones' );
		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) > 0 ) {
			return;
		}

		$zones = array(
			'PLS Airport',
			'Grace Bay',
			'Long Bay',
			'Leeward',
			'Turtle Cove',
			'Blue Hills',
			'Chalk Sound',
			'Taylor Bay',
			'Sapodilla',
			'Remote Villas / Gated Estates',
			'Blue Haven Marina',
			'Turtle Cove Marina',
			'Private Aviation / FBO',
		);

		$i = 10;
		foreach ( $zones as $name ) {
			$wpdb->insert(
				$table,
				array(
					'name'           => $name,
					'customer_label' => $name,
					'is_active'      => 1,
					'display_order'  => $i,
				),
				array( '%s', '%s', '%d', '%d' )
			);
			$i += 10;
		}
	}

	private static function seed_fields() {
		global $wpdb;
		$table = Schema::table( 'fields' );
		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) > 0 ) {
			return;
		}

		$fields = array(
			array( 'trip_type', 'Trip Type', 'radio', '{"options":["One Way","Round Trip"]}' ),
			array( 'pickup_zone', 'Pickup Zone', 'zone', '' ),
			array( 'pickup_location', 'Pickup Location', 'text', '' ),
			array( 'destination_zone', 'Destination Zone', 'zone', '' ),
			array( 'destination_location', 'Destination Location', 'text', '' ),
			array( 'pickup_date', 'Pickup Date', 'date', '' ),
			array( 'pickup_time', 'Pickup Time', 'time', '' ),
			array( 'return_date', 'Return Date', 'date', '' ),
			array( 'return_time', 'Return Time', 'time', '' ),
			array( 'airline', 'Airline', 'text', '' ),
			array( 'flight_number', 'Flight Number', 'text', '' ),
			array( 'flight_direction', 'Flight Direction', 'dropdown', '{"options":["Arriving","Departing"]}' ),
			array( 'flight_time', 'Flight Time', 'time', '' ),
			array( 'accommodation', 'Hotel / Villa / Accommodation', 'text', '' ),
			array( 'return_airline', 'Return Airline', 'text', '' ),
			array( 'return_flight_number', 'Return Flight Number', 'text', '' ),
			array( 'return_flight_direction', 'Return Flight Direction', 'dropdown', '{"options":["Arriving","Departing"]}' ),
			array( 'return_flight_time', 'Return Flight Time', 'time', '' ),
			array( 'return_accommodation', 'Return Accommodation / Pickup', 'text', '' ),
			array( 'passengers', 'Number of Passengers', 'number', '{"min":1}' ),
			array( 'checked_bags', 'Checked Bags', 'number', '{"min":0}' ),
			array( 'carry_on_bags', 'Carry-On Bags', 'number', '{"min":0}' ),
			array( 'hours_required', 'Hours Required', 'number', '{"min":1}' ),
			array( 'expected_itinerary', 'Expected Itinerary', 'textarea', '' ),
			array( 'occasion', 'Occasion / Event Details', 'textarea', '' ),
			array( 'full_name', 'Full Name', 'text', '' ),
			array( 'email', 'Email', 'email', '' ),
			array( 'phone', 'Phone', 'phone', '' ),
			array( 'whatsapp', 'WhatsApp Number', 'phone', '' ),
			array( 'special_requests', 'Special Requests', 'textarea', '' ),
			array( 'terms', 'I agree to the terms', 'checkbox', '' ),
			array( 'meet_greet_info', 'Meet & Greet', 'info', '{"html":"Meet & Greet is included with Airport VIP Transfers."}' ),
		);

		foreach ( $fields as $f ) {
			$wpdb->insert(
				$table,
				array(
					'field_key'    => $f[0],
					'label'        => $f[1],
					'field_type'   => $f[2],
					'options_json' => $f[3],
					'is_system'    => 1,
				),
				array( '%s', '%s', '%s', '%s', '%d' )
			);
		}
	}

	private static function seed_vehicles() {
		global $wpdb;
		$table = Schema::table( 'vehicles' );
		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) > 0 ) {
			return;
		}

		$rows = array(
			array( 'Escalade ESV #1', 'Cadillac Escalade ESV', 'Cadillac Escalade ESV', 6, 10 ),
			array( 'Escalade ESV #2', 'Cadillac Escalade ESV', 'Cadillac Escalade ESV', 6, 20 ),
			array( 'Sprinter Van', 'Mercedes-Benz Sprinter', 'Mercedes-Benz Sprinter (13 passengers)', 13, 30 ),
		);

		foreach ( $rows as $r ) {
			$wpdb->insert(
				$table,
				array(
					'name'                  => $r[0],
					'vehicle_type'          => $r[1],
					'customer_display_name' => $r[2],
					'passenger_capacity'    => $r[3],
					'checked_bag_capacity'  => null,
					'carry_on_bag_capacity' => null,
					'is_active'             => 1,
					'available_for_booking' => 1,
					'customer_visible'      => 1,
					'display_order'         => $r[4],
				)
			);
		}
	}

	private static function seed_addons() {
		global $wpdb;
		$table = Schema::table( 'addons' );
		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) > 0 ) {
			return;
		}

		$rows = array(
			array( 'VIP Priority Arrival', 'fixed', 25, 0, 1, 10, 0 ),
			array( 'Grocery Stop', 'fixed', 25, 0, 1, 20, 0 ),
			array( 'Additional Stop', 'fixed', 30, 0, 1, 30, 0 ),
			array( 'Waiting Time', 'per_interval', 50, 0, 20, 40, 0 ),
			array( 'Child / Booster Seat', 'per_quantity', 15, 1, 4, 50, 0 ),
			array( 'Meet & Greet (Included)', 'free', 0, 0, 1, 5, 1 ),
		);

		foreach ( $rows as $r ) {
			$wpdb->insert(
				$table,
				array(
					'name'             => $r[0],
					'pricing_type'     => $r[1],
					'price'            => $r[2],
					'min_qty'          => $r[3],
					'max_qty'          => $r[4],
					'display_order'    => $r[5],
					'is_included_info' => $r[6],
					'is_active'        => 1,
					'unit_label'       => 'per_interval' === $r[1] ? '30 min' : ( 'per_quantity' === $r[1] ? 'seat' : '' ),
				)
			);
		}
	}

	private static function seed_packages() {
		global $wpdb;
		$table = Schema::table( 'packages' );
		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) > 0 ) {
			return;
		}

		$rows = array(
			array( 'Explore Provo', '3–4 hours', '4 hours', 475, 10 ),
			array( 'Island Lifestyle Experience', '5–6 hours', '6 hours', 800, 20 ),
			array( 'Ultimate Full-Day VIP', '8 hours', '8 hours', 950, 30 ),
		);

		foreach ( $rows as $r ) {
			$wpdb->insert(
				$table,
				array(
					'name'               => $r[0],
					'minimum_duration'   => $r[1],
					'maximum_duration'   => $r[2],
					'fixed_price'        => $r[3],
					'display_order'      => $r[4],
					'is_active'          => 1,
					'customer_description' => $r[0],
				)
			);
		}
	}

	private static function seed_services_and_pricing() {
		global $wpdb;
		$services = Schema::table( 'services' );
		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$services}" ) > 0 ) {
			return;
		}

		$list = array(
			array( 'Airport VIP Transfer', 'airport-vip-transfer', 'route', 1, 1, 10 ),
			array( 'Point-to-Point Private Transfer', 'point-to-point', 'route', 1, 1, 20 ),
			array( 'Standard VIP Chauffeur', 'standard-vip-chauffeur', 'hourly', 0, 0, 30 ),
			array( 'Premium VIP Chauffeur', 'premium-vip-chauffeur', 'hourly', 0, 0, 40 ),
			array( 'Wedding / Event Standby', 'wedding-event-standby', 'hourly', 0, 0, 50 ),
			array( 'Nightlife Transportation', 'nightlife', 'route', 1, 0, 60 ),
			array( 'Dinner Transportation', 'dinner', 'route', 1, 0, 70 ),
			array( 'Marina Transfer', 'marina-transfer', 'route', 1, 1, 80 ),
			array( 'Private Aviation / FBO Transfer', 'fbo-transfer', 'route', 1, 1, 90 ),
			array( 'Explore Provo', 'explore-provo', 'package', 0, 0, 100 ),
			array( 'Island Lifestyle Experience', 'island-lifestyle', 'package', 0, 0, 110 ),
			array( 'Ultimate Full-Day VIP', 'ultimate-full-day', 'package', 0, 0, 120 ),
			array( 'Excursion', 'excursion', 'quote', 0, 0, 130 ),
			array( 'Custom Transportation Request', 'custom-request', 'quote', 1, 1, 140 ),
		);

		foreach ( $list as $s ) {
			$wpdb->insert(
				$services,
				array(
					'name'                         => $s[0],
					'internal_name'                => $s[0],
					'slug'                         => $s[1],
					'booking_type'                 => $s[2],
					'allow_one_way'                => $s[3],
					'allow_round_trip'             => $s[4],
					'require_pickup'               => 1,
					'require_destination'          => 'hourly' === $s[2] || 'package' === $s[2] || 'quote' === $s[2] ? 0 : 1,
					'require_passenger_count'      => 1,
					'require_luggage'              => 'airport-vip-transfer' === $s[1] ? 1 : 0,
					'require_vehicle_matching'     => 1,
					'customer_vehicle_choice'      => 'airport-vip-transfer' === $s[1] ? 'optional' : 'recommend',
					'requires_manual_confirmation' => 1,
					'is_active'                    => 1,
					'customer_visible'             => 1,
					'display_order'                => $s[5],
					'short_description'            => $s[0],
				)
			);
		}

		$airport_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$services} WHERE slug = %s", 'airport-vip-transfer' ) );
		$standard_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$services} WHERE slug = %s", 'standard-vip-chauffeur' ) );
		$premium_id  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$services} WHERE slug = %s", 'premium-vip-chauffeur' ) );
		$wedding_id  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$services} WHERE slug = %s", 'wedding-event-standby' ) );

		self::attach_airport_fields( $airport_id );
		self::seed_airport_routes( $airport_id );
		self::seed_extra_routes();
		self::link_packages_to_services();
		self::seed_hourly( $standard_id, 'Standard VIP', 130, 2 );
		self::seed_hourly( $premium_id, 'Premium VIP', 165, 3 );
		// Wedding minimum hours: client must confirm (do not invent 3–4).
		self::seed_hourly( $wedding_id, 'Wedding / Event', 175, null );

		// Demo holiday surcharge rule (empty dates until client confirms — inactive).
		$wpdb->insert(
			Schema::table( 'pricing_rules' ),
			array(
				'name'          => 'Holiday surcharge (configure dates)',
				'is_active'     => 0,
				'priority'      => 50,
				'match_mode'    => 'all',
				'conditions_json' => wp_json_encode(
					array(
						array(
							'field'    => 'pickup_date',
							'operator' => 'between',
							'value'    => array( '', '' ),
						),
					)
				),
				'action_type'   => 'add_percent',
				'action_value'  => 25,
				'notes'         => 'Activate and set dates when client confirms holiday windows.',
			)
		);

		$wpdb->insert(
			Schema::table( 'pricing_rules' ),
			array(
				'name'            => 'Nightlife flat fare (11 PM–3 AM)',
				'is_active'       => 1,
				'priority'        => 100,
				'match_mode'      => 'all',
				'conditions_json' => wp_json_encode(
					array(
						array(
							'field'    => 'service_slug',
							'operator' => 'is',
							'value'    => 'nightlife',
						),
						array(
							'field'    => 'pickup_time',
							'operator' => 'between_time_cross_midnight',
							'value'    => array( '23:00', '03:00' ),
						),
					)
				),
				'action_type'     => 'replace_base',
				'action_value'    => 150,
			)
		);
	}

	/**
	 * @param int $service_id Service ID.
	 */
	private static function attach_airport_fields( $service_id ) {
		if ( ! $service_id ) {
			return;
		}
		global $wpdb;
		$sf     = Schema::table( 'service_fields' );
		$fields = Schema::table( 'fields' );

		$map = array(
			'trip_type'               => array( 'trip', 10, 1 ),
			'pickup_zone'             => array( 'trip', 20, 1 ),
			'pickup_location'         => array( 'trip', 30, 1 ),
			'destination_zone'        => array( 'trip', 40, 1 ),
			'destination_location'    => array( 'trip', 50, 1 ),
			'pickup_date'             => array( 'schedule', 10, 1 ),
			'pickup_time'             => array( 'schedule', 20, 1 ),
			'airline'                 => array( 'schedule', 30, 1 ),
			'flight_number'           => array( 'schedule', 40, 1 ),
			'flight_direction'        => array( 'schedule', 50, 1 ),
			'flight_time'             => array( 'schedule', 60, 0 ),
			'accommodation'           => array( 'schedule', 70, 1 ),
			'return_date'             => array( 'schedule', 80, 0 ),
			'return_time'             => array( 'schedule', 90, 0 ),
			'return_airline'          => array( 'schedule', 100, 0 ),
			'return_flight_number'    => array( 'schedule', 110, 0 ),
			'return_flight_direction' => array( 'schedule', 120, 0 ),
			'return_flight_time'      => array( 'schedule', 130, 0 ),
			'return_accommodation'    => array( 'schedule', 140, 0 ),
			'passengers'              => array( 'passengers', 10, 1 ),
			'checked_bags'            => array( 'passengers', 20, 1 ),
			'carry_on_bags'           => array( 'passengers', 30, 1 ),
			'meet_greet_info'         => array( 'addons', 5, 0 ),
			'special_requests'        => array( 'addons', 100, 0 ),
			'full_name'               => array( 'contact', 10, 1 ),
			'email'                   => array( 'contact', 20, 1 ),
			'phone'                   => array( 'contact', 30, 1 ),
			'whatsapp'                => array( 'contact', 40, 0 ),
			'terms'                   => array( 'review', 10, 1 ),
		);

		$round_trip_condition = wp_json_encode(
			array(
				'match'      => 'all',
				'conditions' => array(
					array(
						'field'    => 'trip_type',
						'operator' => 'is',
						'value'    => 'Round Trip',
					),
				),
			)
		);

		foreach ( $map as $key => $meta ) {
			$field_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$fields} WHERE field_key = %s", $key ) );
			if ( ! $field_id ) {
				continue;
			}

			$conditions = null;
			$req_cond   = null;
			$required   = (int) $meta[2];

			if ( 0 === strpos( $key, 'return_' ) ) {
				$conditions = $round_trip_condition;
				if ( 'return_flight_number' === $key ) {
					$required = 0;
					$req_cond = $round_trip_condition;
				}
			}

			$wpdb->insert(
				$sf,
				array(
					'service_id'               => $service_id,
					'field_id'                 => $field_id,
					'enabled'                  => 1,
					'required'                 => $required,
					'step_key'                 => $meta[0],
					'display_order'            => $meta[1],
					'display_width'            => 'half',
					'conditions_json'          => $conditions,
					'required_conditions_json' => $req_cond,
				)
			);
		}

		// Attach Grocery + Child Seat add-ons applicability via addon JSON later; mark on airport service notes not needed.
		$child  = (int) $wpdb->get_var( "SELECT id FROM " . Schema::table( 'addons' ) . " WHERE name = 'Child / Booster Seat'" );
		$grocery = (int) $wpdb->get_var( "SELECT id FROM " . Schema::table( 'addons' ) . " WHERE name = 'Grocery Stop'" );
		foreach ( array( $child, $grocery ) as $aid ) {
			if ( ! $aid ) {
				continue;
			}
			$wpdb->update(
				Schema::table( 'addons' ),
				array( 'applicable_services_json' => wp_json_encode( array( $service_id ) ) ),
				array( 'id' => $aid ),
				array( '%s' ),
				array( '%d' )
			);
		}
	}

	/**
	 * @param int $airport_id Airport service id.
	 */
	private static function seed_airport_routes( $airport_id ) {
		global $wpdb;
		$zones = Schema::table( 'zones' );
		$pls   = (int) $wpdb->get_var( "SELECT id FROM {$zones} WHERE name = 'PLS Airport'" );

		$rates = array(
			'Grace Bay'                     => array( 145, 290 ),
			'Long Bay'                      => array( 145, 290 ),
			'Leeward'                       => array( 145, 290 ),
			'Turtle Cove'                   => array( 130, 260 ),
			'Blue Hills'                    => array( 130, 260 ),
			'Chalk Sound'                   => array( 145, 290 ),
			'Taylor Bay'                    => array( 145, 290 ),
			'Sapodilla'                     => array( 145, 290 ),
			'Remote Villas / Gated Estates' => array( 170, 340 ),
		);

		foreach ( $rates as $zone_name => $price ) {
			$zid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$zones} WHERE name = %s", $zone_name ) );
			if ( ! $pls || ! $zid ) {
				continue;
			}
			$wpdb->insert(
				Schema::table( 'routes' ),
				array(
					'service_id'              => $airport_id,
					'origin_zone_id'          => $pls,
					'dest_zone_id'            => $zid,
					'applies_both_directions' => 1,
					'one_way_price'           => $price[0],
					'round_trip_price'        => $price[1],
					'is_active'               => 1,
				)
			);
		}
	}

	/**
	 * Point-to-point, dinner, marina, and FBO routes (Appendix-style).
	 * Skips a service when it already has routes.
	 */
	private static function seed_extra_routes() {
		global $wpdb;
		$services = Schema::table( 'services' );
		$zones    = Schema::table( 'zones' );

		$zone_id = static function ( $name ) use ( $wpdb, $zones ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$zones} WHERE name = %s", $name ) );
		};

		$service_id = static function ( $slug ) use ( $wpdb, $services ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$services} WHERE slug = %s", $slug ) );
		};

		$route_count = static function ( $sid ) use ( $wpdb ) {
			if ( ! $sid ) {
				return 0;
			}
			return (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'routes' ) . ' WHERE service_id = %d', $sid )
			);
		};

		$insert_ow = static function ( $sid, $origin, $dest, $one_way, $round_trip = 0.0, $notes = '' ) use ( $wpdb ) {
			if ( ! $sid || ! $origin || ! $dest ) {
				return;
			}
			$wpdb->insert(
				Schema::table( 'routes' ),
				array(
					'service_id'              => $sid,
					'origin_zone_id'          => $origin,
					'dest_zone_id'            => $dest,
					'applies_both_directions' => 1,
					'one_way_price'           => $one_way,
					'round_trip_price'        => $round_trip,
					'is_active'               => 1,
					'notes'                   => $notes,
				)
			);
		};

		// Point-to-Point: $75 / $85 / $110 OW pairs.
		$p2p = $service_id( 'point-to-point' );
		if ( $p2p && 0 === $route_count( $p2p ) ) {
			$grace   = $zone_id( 'Grace Bay' );
			$long    = $zone_id( 'Long Bay' );
			$leeward = $zone_id( 'Leeward' );
			$turtle  = $zone_id( 'Turtle Cove' );
			$blue    = $zone_id( 'Blue Hills' );
			$chalk   = $zone_id( 'Chalk Sound' );
			$sapo    = $zone_id( 'Sapodilla' );

			foreach ( array( array( $grace, $long ), array( $grace, $leeward ), array( $long, $leeward ) ) as $pair ) {
				$insert_ow( $p2p, $pair[0], $pair[1], 75 );
			}
			foreach ( array( array( $grace, $turtle ), array( $grace, $blue ) ) as $pair ) {
				$insert_ow( $p2p, $pair[0], $pair[1], 85 );
			}
			foreach ( array( array( $grace, $chalk ), array( $grace, $sapo ) ) as $pair ) {
				$insert_ow( $p2p, $pair[0], $pair[1], 110 );
			}
		}

		// Dinner: PLS ↔ Grace Bay style $165.
		$dinner = $service_id( 'dinner' );
		if ( $dinner && 0 === $route_count( $dinner ) ) {
			$insert_ow(
				$dinner,
				$zone_id( 'PLS Airport' ),
				$zone_id( 'Grace Bay' ),
				165,
				165,
				'Dinner transport bundle — confirm with client'
			);
		}

		// Marina: Blue Haven $80, Turtle Cove Marina $85 (from Grace Bay hub).
		$marina = $service_id( 'marina-transfer' );
		if ( $marina && 0 === $route_count( $marina ) ) {
			$grace = $zone_id( 'Grace Bay' );
			$insert_ow( $marina, $grace, $zone_id( 'Blue Haven Marina' ), 80 );
			$insert_ow( $marina, $grace, $zone_id( 'Turtle Cove Marina' ), 85 );
		}

		// FBO: Private Jet / FBO Arrivals $185.
		$fbo = $service_id( 'fbo-transfer' );
		if ( $fbo && 0 === $route_count( $fbo ) ) {
			$insert_ow(
				$fbo,
				$zone_id( 'Grace Bay' ),
				$zone_id( 'Private Aviation / FBO' ),
				185
			);
		}
	}

	/**
	 * Link package rows to matching package services via applicable_services_json.
	 */
	private static function link_packages_to_services() {
		global $wpdb;
		$packages = Schema::table( 'packages' );
		$col      = $wpdb->get_results( "SHOW COLUMNS FROM {$packages} LIKE 'applicable_services_json'" );
		if ( empty( $col ) ) {
			return;
		}

		$services = Schema::table( 'services' );
		$map      = array(
			'Explore Provo'               => 'explore-provo',
			'Island Lifestyle Experience' => 'island-lifestyle',
			'Ultimate Full-Day VIP'       => 'ultimate-full-day',
		);

		foreach ( $map as $pkg_name => $slug ) {
			$sid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$services} WHERE slug = %s", $slug ) );
			$pid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$packages} WHERE name = %s", $pkg_name ) );
			if ( ! $sid || ! $pid ) {
				continue;
			}
			$wpdb->update(
				$packages,
				array( 'applicable_services_json' => wp_json_encode( array( $sid ) ) ),
				array( 'id' => $pid ),
				array( '%s' ),
				array( '%d' )
			);
		}
	}

	/**
	 * @param int        $service_id Service.
	 * @param string     $label Label.
	 * @param float      $rate Rate.
	 * @param float|null $min Min hours (null = unset / client must confirm).
	 */
	private static function seed_hourly( $service_id, $label, $rate, $min ) {
		if ( ! $service_id ) {
			return;
		}
		global $wpdb;
		$row = array(
			'service_id'          => $service_id,
			'label'               => $label,
			'hourly_rate'         => $rate,
			'billing_increment'   => 'hour',
			'customer_can_choose' => 1,
			'is_active'           => 1,
		);
		if ( null === $min || '' === $min ) {
			$row['minimum_hours'] = null;
		} else {
			$row['minimum_hours'] = (float) $min;
		}
		$wpdb->insert( Schema::table( 'hourly_rates' ), $row );
	}

	private static function seed_templates() {
		global $wpdb;
		$table = Schema::table( 'message_templates' );
		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) > 0 ) {
			return;
		}

		$templates = array(
			array( 'booking_received', 'Booking Received', 'We received your request {{booking_reference}}', 'Request received' ),
			array( 'booking_confirmed', 'Booking Confirmed', 'Your booking {{booking_reference}} is confirmed', 'Confirmed' ),
			array( 'payment_link', 'Payment Link Issued', 'Payment for {{booking_reference}}', 'Payment instructions' ),
			array( 'payment_confirmed', 'Payment Confirmed', 'Payment received for {{booking_reference}}', 'Paid' ),
			array( 'reminder_24h', 'Upcoming Transfer Reminder', 'Reminder: transfer {{booking_reference}}', 'Tomorrow' ),
			array( 'chauffeur_vehicle', 'Chauffeur & Vehicle Info', 'Your chauffeur details for {{booking_reference}}', 'Driver details' ),
			array( 'reminder_2h', 'Pickup Reminder', 'Pickup soon for {{booking_reference}}', 'Pickup soon' ),
			array( 'booking_updated', 'Booking Updated', 'Update to {{booking_reference}}', 'Updated' ),
			array( 'booking_cancelled', 'Booking Cancelled', 'Cancellation of {{booking_reference}}', 'Cancelled' ),
		);

		foreach ( $templates as $t ) {
			$wpdb->insert(
				$table,
				array(
					'template_key'     => $t[0],
					'label'            => $t[1],
					'is_enabled'       => 1,
					'email_enabled'    => 1,
					'whatsapp_enabled' => 0,
					'subject'          => $t[2],
					'heading'          => $t[3],
					'body_html'        => '<p>Hello {{customer_name}},</p><p>' . esc_html( $t[1] ) . ' for <strong>{{booking_reference}}</strong>.</p>',
				)
			);
		}
	}

	private static function ensure_payment_rows() {
		global $wpdb;
		$table   = Schema::table( 'payment_methods' );
		$methods = array(
			array( 'instructions', 'Bank / instructions', 1, 10 ),
			array( 'custom_link', 'Custom payment link', 1, 20 ),
			array( 'stripe', 'Stripe', 0, 30 ),
			array( 'paypal', 'PayPal', 0, 40 ),
			array( 'authorize_net', 'Authorize.net', 0, 50 ),
		);
		foreach ( $methods as $m ) {
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE method_key = %s", $m[0] ) );
			if ( $exists ) {
				continue;
			}
			$wpdb->insert(
				$table,
				array(
					'method_key'    => $m[0],
					'label'         => $m[1],
					'is_enabled'    => $m[2],
					'display_order' => $m[3],
					'settings_json' => '{}',
				)
			);
		}
	}
}
