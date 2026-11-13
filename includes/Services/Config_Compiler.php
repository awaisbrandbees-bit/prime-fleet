<?php
/**
 * Compiled public config cache (guest-safe).
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Services;

use Prime_Fleet\Database\Schema;
use Prime_Fleet\I18n\Catalog;
use Prime_Fleet\Payments\Stripe_Gateway;
use Prime_Fleet\Plugin;

defined( 'ABSPATH' ) || exit;

final class Config_Compiler {

	const TRANSIENT      = 'prime_fleet_compiled_config_v5';
	const VERSION_OPTION = 'prime_fleet_config_cache_ver';

	/**
	 * Cache key includes version so invalidate drops every locale at once.
	 *
	 * @return string
	 */
	private static function cache_key() {
		$ver = (int) get_option( self::VERSION_OPTION, 1 );
		return self::TRANSIENT . '_v' . max( 1, $ver ) . '_' . md5( Catalog::locale() );
	}

	/**
	 * Invalidate cache after admin saves (all locales).
	 */
	public static function invalidate() {
		$old_key = self::cache_key();
		$ver     = (int) get_option( self::VERSION_OPTION, 1 );
		update_option( self::VERSION_OPTION, $ver + 1, false );
		delete_transient( $old_key );
		delete_transient( self::TRANSIENT );
		delete_transient( 'prime_fleet_compiled_config_v4' );
		delete_transient( 'prime_fleet_compiled_config_v3' );
		delete_transient( 'prime_fleet_compiled_config_v2' );
		delete_transient( 'prime_fleet_compiled_config_v1' );
	}

	/**
	 * @return array
	 */
	public static function get() {
		$key    = self::cache_key();
		$cached = get_transient( $key );
		if ( ! is_array( $cached ) ) {
			$cached = self::compile();
			set_transient( $key, $cached, HOUR_IN_SECONDS );
		}

		// Live pickup window (not cached — depends on wall clock).
		$cached['pickup'] = \Prime_Fleet\Bookings\Pickup_Rules::public_rules();
		return $cached;
	}

	/**
	 * @return array
	 */
	public static function compile() {
		global $wpdb;

		$services = $wpdb->get_results(
			'SELECT id, name, slug, booking_type, short_description, display_order,
				allow_one_way, allow_round_trip, allow_multiple_stops,
				require_pickup, require_destination, require_passenger_count, require_luggage,
				require_vehicle_matching, customer_vehicle_choice, requires_manual_confirmation
			FROM ' . Schema::table( 'services' ) . '
			WHERE is_active = 1 AND customer_visible = 1
			ORDER BY display_order ASC, id ASC',
			ARRAY_A
		);
		$zones = $wpdb->get_results(
			'SELECT id, name, customer_label, display_order FROM ' . Schema::table( 'zones' ) . ' WHERE is_active = 1 ORDER BY display_order ASC, id ASC',
			ARRAY_A
		);
		$addons = $wpdb->get_results(
			'SELECT id, name, customer_description, pricing_type, price, unit_label, min_qty, max_qty,
				applicable_services_json, display_order, is_included_info
			FROM ' . Schema::table( 'addons' ) . ' WHERE is_active = 1 ORDER BY display_order ASC, id ASC',
			ARRAY_A
		);
		$vehicles = $wpdb->get_results(
			'SELECT id, name, customer_display_name, passenger_capacity, checked_bag_capacity, carry_on_bag_capacity, display_order
			FROM ' . Schema::table( 'vehicles' ) . '
			WHERE is_active = 1 AND available_for_booking = 1 AND customer_visible = 1
			ORDER BY display_order ASC, id ASC',
			ARRAY_A
		);
		$packages = $wpdb->get_results(
			'SELECT id, name, customer_description, minimum_duration, maximum_duration, fixed_price, applicable_services_json, display_order
			FROM ' . Schema::table( 'packages' ) . ' WHERE is_active = 1 ORDER BY display_order ASC, id ASC',
			ARRAY_A
		);

		$service_payload = array();
		foreach ( (array) $services as $service ) {
			// Hide accidental test rows from the guest wizard.
			$name_key = strtolower( trim( (string) $service['name'] ) );
			$slug_key = strtolower( trim( (string) $service['slug'] ) );
			if ( in_array( $name_key, array( 'clutch', 'google', 'test', 'demo' ), true )
				|| in_array( $slug_key, array( 'clutch', 'google', 'test', 'demo' ), true ) ) {
				continue;
			}

			$sid    = (int) $service['id'];
			$fields = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT sf.label_override, sf.help_override, sf.placeholder_override, sf.required, sf.step_key,
						sf.display_order, sf.display_width, sf.conditions_json, sf.required_conditions_json, sf.validation_json,
						f.field_key, f.field_type, f.label AS library_label, f.placeholder AS library_placeholder,
						f.help_text AS library_help, f.options_json, f.validation_json AS library_validation
					FROM ' . Schema::table( 'service_fields' ) . ' sf
					INNER JOIN ' . Schema::table( 'fields' ) . ' f ON f.id = sf.field_id
					WHERE sf.service_id = %d AND sf.enabled = 1 AND sf.customer_visible = 1
					ORDER BY sf.display_order ASC, sf.id ASC',
					$sid
				),
				ARRAY_A
			);

			$route_zones = array(
				'pickup'  => array(),
				'dropoff' => array(),
			);
			if ( 'route' === $service['booking_type'] ) {
				$route_rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT origin_zone_id, dest_zone_id, applies_both_directions FROM ' . Schema::table( 'routes' ) . ' WHERE service_id = %d AND is_active = 1',
						$sid
					),
					ARRAY_A
				);
				$pickup  = array();
				$dropoff = array();
				foreach ( (array) $route_rows as $rr ) {
					$o = (int) $rr['origin_zone_id'];
					$d = (int) $rr['dest_zone_id'];
					$pickup[ $o ]  = $o;
					$dropoff[ $d ] = $d;
					if ( (int) $rr['applies_both_directions'] ) {
						$pickup[ $d ]  = $d;
						$dropoff[ $o ] = $o;
					}
				}
				$route_zones['pickup']  = array_values( $pickup );
				$route_zones['dropoff'] = array_values( $dropoff );
			}

			$service_payload[] = array(
				'id'           => $sid,
				'name'         => Catalog::text( 'service', $sid, 'name', (string) $service['name'] ),
				'slug'         => $service['slug'],
				'description'  => Catalog::text( 'service', $sid, 'description', (string) $service['short_description'] ),
				'booking_type' => $service['booking_type'],
				'settings'     => array(
					'allow_one_way'                => (int) $service['allow_one_way'],
					'allow_round_trip'             => (int) $service['allow_round_trip'],
					'allow_multiple_stops'         => (int) $service['allow_multiple_stops'],
					'require_pickup'               => (int) $service['require_pickup'],
					'require_destination'          => (int) $service['require_destination'],
					'require_passenger_count'      => (int) $service['require_passenger_count'],
					'require_luggage'              => (int) $service['require_luggage'],
					'require_vehicle_matching'     => (int) $service['require_vehicle_matching'],
					'customer_vehicle_choice'      => $service['customer_vehicle_choice'],
					'requires_manual_confirmation' => (int) $service['requires_manual_confirmation'],
				),
				'route_zones'  => $route_zones,
				'fields'       => array_map( array( __CLASS__, 'public_field' ), (array) $fields ),
			);
		}

		// Wizard payment step is optional (default remains post-confirm). Flag only when a hosted gateway can charge.
		$enabled_guest_gateway = Stripe_Gateway::is_ready();
		$payment_methods       = array();
		foreach ( Plugin::instance()->payments()->enabled() as $method ) {
			$payment_methods[] = array(
				'id'             => $method->id(),
				'label'          => $method->label(),
				'supports_hosted'=> (bool) $method->supports_hosted(),
				'ready'          => ( 'stripe' === $method->id() ) ? Stripe_Gateway::is_ready() : true,
			);
		}

		$settings = get_option( 'prime_fleet_settings', array() );
		$theme    = isset( $settings['form_theme'] ) ? sanitize_key( (string) $settings['form_theme'] ) : 'dark';
		if ( ! in_array( $theme, array( 'dark', 'light' ), true ) ) {
			$theme = 'dark';
		}

		return array(
			'version'      => PRIME_FLEET_VERSION,
			'currency'     => isset( $settings['currency'] ) ? $settings['currency'] : 'USD',
			'timezone'     => isset( $settings['timezone'] ) ? $settings['timezone'] : 'UTC',
			'theme'        => $theme,
			'payment_step' => (bool) $enabled_guest_gateway,
			'payment_methods' => $payment_methods,
			'steps'        => array( 'service', 'trip', 'schedule', 'passengers', 'vehicle', 'addons', 'contact', 'review' ),
			'services'     => $service_payload,
			'zones'        => array_map(
				static function ( $z ) {
					$zid  = (int) $z['id'];
					$base = $z['customer_label'] ? $z['customer_label'] : $z['name'];
					return array(
						'id'    => $zid,
						'name'  => Catalog::text( 'zone', $zid, 'name', (string) $base ),
						'order' => (int) $z['display_order'],
					);
				},
				(array) $zones
			),
			'addons'       => array_map( array( __CLASS__, 'public_addon' ), (array) $addons ),
			'vehicles'     => array_map(
				static function ( $v ) {
					$vid  = (int) $v['id'];
					$base = $v['customer_display_name'] ? $v['customer_display_name'] : $v['name'];
					return array(
						'id'                 => $vid,
						'name'               => Catalog::text( 'vehicle', $vid, 'name', (string) $base ),
						'passenger_capacity' => (int) $v['passenger_capacity'],
						'checked_bags'       => null === $v['checked_bag_capacity'] ? null : (int) $v['checked_bag_capacity'],
						'carry_on_bags'      => null === $v['carry_on_bag_capacity'] ? null : (int) $v['carry_on_bag_capacity'],
						'order'              => (int) $v['display_order'],
					);
				},
				(array) $vehicles
			),
			'packages'     => array_map( array( __CLASS__, 'public_package' ), (array) $packages ),
			'copy'         => array(
				'pending_message'      => Catalog::text( 'copy', 0, 'pending_message', isset( $settings['pending_message'] ) ? (string) $settings['pending_message'] : __( 'Your transfer request is Pending Confirmation.', 'prime-fleet' ) ),
				'submit_label'         => Catalog::text( 'copy', 0, 'submit_label', isset( $settings['submit_label'] ) ? (string) $settings['submit_label'] : __( 'Submit request', 'prime-fleet' ) ),
				'custom_quote_message' => Catalog::text( 'copy', 0, 'custom_quote_message', isset( $settings['custom_quote_message'] ) ? (string) $settings['custom_quote_message'] : __( 'This request requires a custom quotation. Prime Fleet will review the details and contact you shortly.', 'prime-fleet' ) ),
			),
			'locale'       => Catalog::locale(),
		);
	}

	/**
	 * @param array $f Field join row.
	 * @return array
	 */
	private static function public_field( array $f ) {
		$options = json_decode( (string) ( $f['options_json'] ?? '' ), true );
		$val     = json_decode( (string) ( ( $f['validation_json'] ?? '' ) !== '' ? $f['validation_json'] : ( $f['library_validation'] ?? '' ) ), true );
		$cond    = json_decode( (string) ( $f['conditions_json'] ?? '' ), true );
		$req_c   = json_decode( (string) ( $f['required_conditions_json'] ?? '' ), true );

		return array(
			'key'                  => $f['field_key'],
			'type'                 => $f['field_type'],
			'label'                => $f['label_override'] !== '' ? $f['label_override'] : $f['library_label'],
			'placeholder'          => $f['placeholder_override'] !== '' ? $f['placeholder_override'] : (string) $f['library_placeholder'],
			'help'                 => $f['help_override'] !== '' && null !== $f['help_override'] ? $f['help_override'] : (string) $f['library_help'],
			'required'             => (int) $f['required'],
			'step'                 => $f['step_key'],
			'order'                => (int) $f['display_order'],
			'width'                => $f['display_width'],
			'options'              => is_array( $options ) ? $options : null,
			'validation'           => is_array( $val ) ? $val : null,
			'conditions'           => is_array( $cond ) ? $cond : null,
			'required_conditions'  => is_array( $req_c ) ? $req_c : null,
		);
	}

	/**
	 * @param array $a Addon row.
	 * @return array
	 */
	private static function public_addon( array $a ) {
		$ids = json_decode( (string) ( $a['applicable_services_json'] ?? '' ), true );
		$aid = (int) $a['id'];
		return array(
			'id'             => $aid,
			'name'           => Catalog::text( 'addon', $aid, 'name', (string) $a['name'] ),
			'description'    => Catalog::text( 'addon', $aid, 'description', (string) $a['customer_description'] ),
			'pricing_type'   => $a['pricing_type'],
			'price'          => (float) $a['price'],
			'unit_label'     => (string) $a['unit_label'],
			'min_qty'        => (int) $a['min_qty'],
			'max_qty'        => (int) $a['max_qty'],
			'service_ids'    => is_array( $ids ) ? array_values( array_map( 'intval', $ids ) ) : array(),
			'included'       => (int) $a['is_included_info'],
			'order'          => (int) $a['display_order'],
		);
	}

	/**
	 * @param array $p Package row.
	 * @return array
	 */
	private static function public_package( array $p ) {
		$ids = json_decode( (string) ( $p['applicable_services_json'] ?? '' ), true );
		$pid = (int) $p['id'];
		return array(
			'id'            => $pid,
			'name'          => Catalog::text( 'package', $pid, 'name', (string) $p['name'] ),
			'description'   => Catalog::text( 'package', $pid, 'description', (string) $p['customer_description'] ),
			'min_duration'  => (string) $p['minimum_duration'],
			'max_duration'  => (string) $p['maximum_duration'],
			'fixed_price'   => (float) $p['fixed_price'],
			'service_ids'   => is_array( $ids ) ? array_values( array_map( 'intval', $ids ) ) : array(),
			'order'         => (int) $p['display_order'],
		);
	}
}
