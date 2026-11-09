<?php
/**
 * Server-side fare calculation (source of truth).
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Pricing;

use Prime_Fleet\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class Engine {

	/**
	 * Calculate a quote from sanitized input. Never trusts client totals.
	 *
	 * @param array $input Input.
	 * @return array|\WP_Error
	 */
	public static function quote( array $input ) {
		global $wpdb;

		$service_id = absint( $input['service_id'] ?? 0 );
		if ( ! $service_id ) {
			return new \WP_Error( 'prime_fleet_invalid_service', __( 'Service is required.', 'prime-fleet' ), array( 'status' => 400 ) );
		}

		$service = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table( 'services' ) . ' WHERE id = %d AND is_active = 1 AND customer_visible = 1',
				$service_id
			),
			ARRAY_A
		);
		if ( ! $service ) {
			return new \WP_Error( 'prime_fleet_invalid_service', __( 'Service not available.', 'prime-fleet' ), array( 'status' => 404 ) );
		}

		$settings = wp_parse_args(
			get_option( 'prime_fleet_settings', array() ),
			array(
				'currency'                      => 'USD',
				'custom_quote_message'          => __( 'This request requires a custom quotation. Prime Fleet will review the details and contact you shortly.', 'prime-fleet' ),
				'waiting_complimentary_minutes' => 30,
				'seasonal_discount_pct'         => 10,
				'seasonal_discount_service_ids' => array(),
			)
		);
		if ( ! isset( $settings['seasonal_discount_pct'] ) && isset( $settings['airport_vip_seasonal_discount_pct'] ) ) {
			$settings['seasonal_discount_pct'] = $settings['airport_vip_seasonal_discount_pct'];
		}

		$type = sanitize_key( (string) $service['booking_type'] );
		$out  = array(
			'currency'     => $settings['currency'],
			'base'         => 0.0,
			'addons_total' => 0.0,
			'surcharges'   => 0.0,
			'total'        => 0.0,
			'manual_quote' => false,
			'message'      => '',
			'explanation'  => array(),
			'vehicles'     => array(),
			'breakdown'    => array(),
		);

		if ( 'quote' === $type ) {
			$out['manual_quote'] = true;
			$out['total']        = null;
			$out['message']      = (string) $settings['custom_quote_message'];
			$out['explanation'][] = __( 'Custom quote — price shown only after Prime Fleet review.', 'prime-fleet' );
			$out['vehicles']      = self::match_vehicles( $input, $service );
			return $out;
		}

		if ( 'route' === $type ) {
			$route = self::find_route( $service_id, $input );
			if ( is_wp_error( $route ) ) {
				return $route;
			}
			// Missing matrix row → custom quote (never dead-end the guest).
			if ( ! $route ) {
				$out['manual_quote']  = true;
				$out['total']         = null;
				$out['message']       = (string) $settings['custom_quote_message'];
				$out['explanation'][] = __( 'No published rate for this zone pair — Prime Fleet will quote after review.', 'prime-fleet' );
				$out['vehicles']      = self::match_vehicles( $input, $service );
				return $out;
			}
			$trip = sanitize_key( (string) ( $input['trip_type'] ?? 'one_way' ) );
			if ( 'round_trip' === $trip ) {
				$out['base']          = (float) $route['round_trip_price'];
				$out['explanation'][] = sprintf(
					/* translators: %s price */
					__( 'Round-trip route fare: %s', 'prime-fleet' ),
					self::money( $out['base'], $settings['currency'] )
				);
			} else {
				$out['base']          = (float) $route['one_way_price'];
				$out['explanation'][] = sprintf(
					/* translators: %s price */
					__( 'One-way route fare: %s', 'prime-fleet' ),
					self::money( $out['base'], $settings['currency'] )
				);
			}
			$out['breakdown']['route_id'] = (int) $route['id'];
		} elseif ( 'hourly' === $type ) {
			$hourly = self::find_hourly( $service_id );
			if ( is_wp_error( $hourly ) ) {
				return $hourly;
			}
			$hours = isset( $input['hours'] ) ? (float) $input['hours'] : 0;
			$min   = null !== $hourly['minimum_hours'] && '' !== $hourly['minimum_hours']
				? (float) $hourly['minimum_hours']
				: null;
			if ( $min && $hours < $min ) {
				$hours = $min;
				$out['explanation'][] = sprintf(
					/* translators: %s hours */
					__( 'Hours raised to service minimum (%s).', 'prime-fleet' ),
					(string) $min
				);
			}
			if ( $hours <= 0 ) {
				return new \WP_Error( 'prime_fleet_invalid_hours', __( 'Hours required for hourly service.', 'prime-fleet' ), array( 'status' => 400 ) );
			}
			$rate        = (float) $hourly['hourly_rate'];
			$out['base'] = round( $rate * $hours, 2 );
			$out['explanation'][] = sprintf(
				/* translators: 1: hours 2: rate 3: total */
				__( '%1$s h × %2$s = %3$s', 'prime-fleet' ),
				(string) $hours,
				self::money( $rate, $settings['currency'] ),
				self::money( $out['base'], $settings['currency'] )
			);
			$out['breakdown']['hours']      = $hours;
			$out['breakdown']['hourly_rate'] = $rate;
		} elseif ( 'package' === $type ) {
			$pkg = self::find_package( $input, $service_id );
			if ( is_wp_error( $pkg ) ) {
				return $pkg;
			}
			$out['base']          = (float) $pkg['fixed_price'];
			$out['explanation'][] = sprintf(
				/* translators: 1: package name 2: price */
				__( 'Package “%1$s”: %2$s', 'prime-fleet' ),
				$pkg['name'],
				self::money( $out['base'], $settings['currency'] )
			);
			$out['breakdown']['package_id'] = (int) $pkg['id'];
		} else {
			return new \WP_Error( 'prime_fleet_unsupported_type', __( 'Unsupported booking type.', 'prime-fleet' ), array( 'status' => 400 ) );
		}

		$season = self::apply_seasonal_discount( $out['base'], $service, $settings );
		if ( $season['discount'] > 0 ) {
			$out['base']                           = $season['base'];
			$out['explanation'][]                  = $season['note'];
			$out['breakdown']['seasonal_discount'] = $season['discount'];
			$out['breakdown']['seasonal_percent']  = $season['percent'];
		}

		$addon_calc           = self::calc_addons( $input, $service_id, $settings );
		$out['addons_total']  = $addon_calc['total'];
		$out['breakdown']['addons'] = $addon_calc['lines'];
		foreach ( $addon_calc['notes'] as $note ) {
			$out['explanation'][] = $note;
		}

		$sub = $out['base'] + $out['addons_total'];
		$sur = self::calc_surcharges( $input, $sub );
		$out['surcharges'] = $sur['total'];
		$out['breakdown']['surcharge_lines'] = $sur['lines'];
		foreach ( $sur['notes'] as $note ) {
			$out['explanation'][] = $note;
		}

		$out['total']    = round( $sub + $out['surcharges'], 2 );
		$out['vehicles'] = self::match_vehicles( $input, $service );

		/**
		 * Filter quote result after calculation.
		 *
		 * @param array $out Quote.
		 * @param array $input Input.
		 * @param array $service Service row.
		 */
		return apply_filters( 'prime_fleet_fare_calculated', $out, $input, $service );
	}

	/**
	 * @param int   $service_id Service.
	 * @param array $input Input.
	 * @return array|\WP_Error
	 */
	private static function find_route( $service_id, array $input ) {
		global $wpdb;
		$origin = absint( $input['origin_zone_id'] ?? 0 );
		$dest   = absint( $input['dest_zone_id'] ?? 0 );
		if ( ! $origin || ! $dest ) {
			return new \WP_Error( 'prime_fleet_invalid_zones', __( 'Pickup and destination zones are required.', 'prime-fleet' ), array( 'status' => 400 ) );
		}
		if ( $origin === $dest ) {
			return new \WP_Error( 'prime_fleet_invalid_zones', __( 'Pickup and destination must differ.', 'prime-fleet' ), array( 'status' => 400 ) );
		}

		$table = Schema::table( 'routes' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, one_way_price, round_trip_price FROM {$table}
				WHERE service_id = %d AND is_active = 1
				AND origin_zone_id = %d AND dest_zone_id = %d
				LIMIT 1",
				$service_id,
				$origin,
				$dest
			),
			ARRAY_A
		);
		if ( ! $row ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, one_way_price, round_trip_price FROM {$table}
					WHERE service_id = %d AND is_active = 1 AND applies_both_directions = 1
					AND origin_zone_id = %d AND dest_zone_id = %d
					LIMIT 1",
					$service_id,
					$dest,
					$origin
				),
				ARRAY_A
			);
		}
		if ( ! $row ) {
			return null;
		}
		return $row;
	}

	/**
	 * @param int $service_id Service.
	 * @return array|\WP_Error
	 */
	private static function find_hourly( $service_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table( 'hourly_rates' ) . ' WHERE service_id = %d AND is_active = 1 ORDER BY id ASC LIMIT 1',
				$service_id
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return new \WP_Error( 'prime_fleet_no_hourly', __( 'No hourly rate configured for this service.', 'prime-fleet' ), array( 'status' => 404 ) );
		}
		return $row;
	}

	/**
	 * @param array $input Input.
	 * @param int   $service_id Service.
	 * @return array|\WP_Error
	 */
	private static function find_package( array $input, $service_id ) {
		global $wpdb;
		$package_id = absint( $input['package_id'] ?? 0 );
		$table      = Schema::table( 'packages' );
		if ( $package_id ) {
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND is_active = 1", $package_id ),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE is_active = 1 ORDER BY display_order ASC, id ASC", ARRAY_A );
			$row  = null;
			foreach ( (array) $rows as $candidate ) {
				$ids = json_decode( (string) ( $candidate['applicable_services_json'] ?? '' ), true );
				if ( ! is_array( $ids ) || empty( $ids ) || in_array( $service_id, array_map( 'intval', $ids ), true ) ) {
					$row = $candidate;
					break;
				}
			}
		}
		if ( ! $row ) {
			return new \WP_Error( 'prime_fleet_no_package', __( 'No package found for this service.', 'prime-fleet' ), array( 'status' => 404 ) );
		}
		return $row;
	}

	/**
	 * @param array $input Input.
	 * @param int   $service_id Service.
	 * @param array $settings Settings.
	 * @return array{total:float,lines:array,notes:array}
	 */
	private static function calc_addons( array $input, $service_id, array $settings ) {
		global $wpdb;
		$raw   = isset( $input['addons'] ) && is_array( $input['addons'] ) ? $input['addons'] : array();
		$total = 0.0;
		$lines = array();
		$notes = array();
		if ( ! $raw ) {
			return compact( 'total', 'lines', 'notes' );
		}

		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$id  = absint( $item['id'] ?? 0 );
			$qty = max( 0, (int) ( $item['qty'] ?? 1 ) );
			if ( ! $id || $qty < 1 ) {
				continue;
			}
			$addon = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT id, name, pricing_type, price, min_qty, max_qty, is_included_info, applicable_services_json
					FROM ' . Schema::table( 'addons' ) . ' WHERE id = %d AND is_active = 1',
					$id
				),
				ARRAY_A
			);
			if ( ! $addon ) {
				continue;
			}
			$scope = json_decode( (string) ( $addon['applicable_services_json'] ?? '' ), true );
			if ( is_array( $scope ) && $scope && ! in_array( $service_id, array_map( 'intval', $scope ), true ) ) {
				continue;
			}
			if ( ! empty( $addon['is_included_info'] ) || 'free' === $addon['pricing_type'] ) {
				$notes[] = sprintf(
					/* translators: %s addon name */
					__( '%s is included (not charged).', 'prime-fleet' ),
					$addon['name']
				);
				continue;
			}

			$max = (int) $addon['max_qty'];
			$min = (int) $addon['min_qty'];
			if ( $max > 0 && $qty > $max ) {
				$qty = $max;
			}
			if ( $min > 0 && $qty < $min ) {
				$qty = $min;
			}

			$price = (float) $addon['price'];
			$line  = 0.0;
			if ( 'fixed' === $addon['pricing_type'] ) {
				$line = $price;
				$qty  = 1;
			} elseif ( 'per_quantity' === $addon['pricing_type'] || 'per_interval' === $addon['pricing_type'] ) {
				$line = $price * $qty;
			}
			$line   = round( $line, 2 );
			$total += $line;
			$lines[] = array(
				'id'    => (int) $addon['id'],
				'name'  => $addon['name'],
				'qty'   => $qty,
				'amount'=> $line,
			);
			$notes[] = sprintf(
				'%s × %d = %s',
				$addon['name'],
				$qty,
				self::money( $line, $settings['currency'] )
			);
		}

		return array(
			'total' => round( $total, 2 ),
			'lines' => $lines,
			'notes' => $notes,
		);
	}

	/**
	 * @param array $input Input.
	 * @param float $sub Subtotal before surcharge.
	 * @return array{total:float,lines:array,notes:array}
	 */
	private static function calc_surcharges( array $input, $sub ) {
		global $wpdb;
		$date = isset( $input['pickup_date'] ) ? sanitize_text_field( (string) $input['pickup_date'] ) : '';
		$rows = $wpdb->get_results(
			'SELECT id, name, surcharge_type, value, start_date, end_date FROM ' . Schema::table( 'surcharges' ) . ' WHERE is_active = 1',
			ARRAY_A
		);
		$total = 0.0;
		$lines = array();
		$notes = array();
		foreach ( (array) $rows as $row ) {
			if ( $date ) {
				$start = $row['start_date'] ? (string) $row['start_date'] : '';
				$end   = $row['end_date'] ? (string) $row['end_date'] : '';
				if ( $start && $date < $start ) {
					continue;
				}
				if ( $end && $date > $end ) {
					continue;
				}
			} elseif ( $row['start_date'] || $row['end_date'] ) {
				// Date-bound surcharge skipped when no pickup date provided.
				continue;
			}
			$amount = 0.0;
			if ( 'percent' === $row['surcharge_type'] ) {
				$amount = round( $sub * ( (float) $row['value'] / 100 ), 2 );
			} else {
				$amount = (float) $row['value'];
			}
			$total  += $amount;
			$lines[] = array(
				'id'     => (int) $row['id'],
				'name'   => $row['name'],
				'amount' => $amount,
			);
			$notes[] = $row['name'] . ': ' . $amount;
		}
		return array(
			'total' => round( $total, 2 ),
			'lines' => $lines,
			'notes' => $notes,
		);
	}

	/**
	 * @param array $input Input.
	 * @param array $service Service.
	 * @return array
	 */
	private static function match_vehicles( array $input, array $service ) {
		global $wpdb;
		if ( empty( $service['require_vehicle_matching'] ) ) {
			return array();
		}
		$pax     = max( 0, absint( $input['passengers'] ?? 0 ) );
		$checked = max( 0, absint( $input['checked_bags'] ?? 0 ) );
		$carry   = max( 0, absint( $input['carry_on_bags'] ?? 0 ) );

		$rows = $wpdb->get_results(
			'SELECT id, name, customer_display_name, passenger_capacity, checked_bag_capacity, carry_on_bag_capacity
			FROM ' . Schema::table( 'vehicles' ) . '
			WHERE is_active = 1 AND available_for_booking = 1 AND customer_visible = 1
			ORDER BY display_order ASC, id ASC',
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $v ) {
			$status = 'suitable';
			$notes  = array();
			$cap    = (int) $v['passenger_capacity'];
			if ( $pax && $cap && $pax > $cap ) {
				$status  = 'not_selectable';
				$notes[] = __( 'Not enough passenger capacity.', 'prime-fleet' );
			}
			$bag_checked = $v['checked_bag_capacity'];
			$bag_carry   = $v['carry_on_bag_capacity'];
			if ( null !== $bag_checked && '' !== $bag_checked && $checked > (int) $bag_checked ) {
				$status  = ( 'not_selectable' === $status ) ? $status : 'review';
				$notes[] = __( 'Checked bag capacity may be exceeded.', 'prime-fleet' );
			}
			if ( null !== $bag_carry && '' !== $bag_carry && $carry > (int) $bag_carry ) {
				$status  = ( 'not_selectable' === $status ) ? $status : 'review';
				$notes[] = __( 'Carry-on capacity may be exceeded.', 'prime-fleet' );
			}
			if ( ( null === $bag_checked || '' === $bag_checked ) && ( null === $bag_carry || '' === $bag_carry ) && ( $checked || $carry ) ) {
				$notes[] = __( 'Luggage capacity not configured — flagged for review.', 'prime-fleet' );
				if ( 'suitable' === $status ) {
					$status = 'review';
				}
			}
			$out[] = array(
				'id'                 => (int) $v['id'],
				'name'               => $v['customer_display_name'] ? $v['customer_display_name'] : $v['name'],
				'passenger_capacity' => $cap,
				'status'             => $status,
				'notes'              => $notes,
			);
		}
		return $out;
	}

	/**
	 * Seasonal % off for admin-selected services (Settings).
	 *
	 * @param float $base Base fare.
	 * @param array $service Service row.
	 * @param array $settings Settings.
	 * @return array{base:float,discount:float,percent:float,note:string}
	 */
	private static function apply_seasonal_discount( $base, array $service, array $settings ) {
		$empty = array(
			'base'     => (float) $base,
			'discount' => 0.0,
			'percent'  => 0.0,
			'note'     => '',
		);

		$service_id = (int) ( $service['id'] ?? 0 );
		$ids        = isset( $settings['seasonal_discount_service_ids'] ) ? (array) $settings['seasonal_discount_service_ids'] : array();
		$ids        = array_map( 'intval', $ids );
		if ( ! $service_id || ! in_array( $service_id, $ids, true ) ) {
			return $empty;
		}

		$percent = isset( $settings['seasonal_discount_pct'] )
			? (float) $settings['seasonal_discount_pct']
			: ( isset( $settings['airport_vip_seasonal_discount_pct'] ) ? (float) $settings['airport_vip_seasonal_discount_pct'] : 0.0 );
		if ( $percent <= 0 ) {
			return $empty;
		}
		if ( $percent > 50 ) {
			$percent = 50.0;
		}
		$discount = round( (float) $base * ( $percent / 100 ), 2 );
		$new_base = max( 0, round( (float) $base - $discount, 2 ) );
		return array(
			'base'     => $new_base,
			'discount' => $discount,
			'percent'  => $percent,
			'note'     => sprintf(
				/* translators: 1: percent 2: discount amount */
				__( 'Slow-season rate (−%1$s%%): −%2$s', 'prime-fleet' ),
				rtrim( rtrim( number_format( $percent, 1 ), '0' ), '.' ),
				self::money( $discount, $settings['currency'] )
			),
		);
	}

	/**
	 * @param float  $amount Amount.
	 * @param string $currency Currency.
	 * @return string
	 */
	private static function money( $amount, $currency ) {
		return $currency . ' ' . number_format( (float) $amount, 2, '.', '' );
	}
}
