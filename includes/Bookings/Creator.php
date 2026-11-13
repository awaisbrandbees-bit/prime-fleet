<?php
/**
 * Create guest bookings (save-first, then side effects).
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Bookings;

use Prime_Fleet\Database\Schema;
use Prime_Fleet\Pricing\Engine;
use Prime_Fleet\REST\Controller;
use Prime_Fleet\REST\Rate_Limiter;
use Prime_Fleet\Security\Captcha;

defined( 'ABSPATH' ) || exit;

final class Creator {

	/**
	 * Validate → calculate → save → return reference. Emails queued after.
	 *
	 * @param array $raw Raw request body.
	 * @return array|\WP_Error
	 */
	public static function create_from_request( array $raw ) {
		$limit = Rate_Limiter::allow_configured( 'bookings' );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}

		$email_for_limit = isset( $raw['customer_email'] ) ? strtolower( sanitize_email( (string) $raw['customer_email'] ) ) : '';
		if ( $email_for_limit ) {
			$email_max = 24;
			$settings  = get_option( 'prime_fleet_settings', array() );
			if ( is_array( $settings ) && isset( $settings['rl_bookings_email_per_hour'] ) && '' !== $settings['rl_bookings_email_per_hour'] ) {
				$email_max = max( 1, min( 200, absint( $settings['rl_bookings_email_per_hour'] ) ) );
			}
			$email_limit = Rate_Limiter::allow( 'bookings_email_' . md5( $email_for_limit ), $email_max, HOUR_IN_SECONDS );
			if ( is_wp_error( $email_limit ) ) {
				return $email_limit;
			}
		}

		// Honeypot — must be empty.
		$hp = isset( $raw['company_website'] ) ? (string) $raw['company_website'] : ( isset( $raw['website'] ) ? (string) $raw['website'] : '' );
		if ( '' !== trim( $hp ) ) {
			\Prime_Fleet\Logging\Logger::warning(
				'honeypot',
				'Booking honeypot tripped — soft-fake success returned',
				array(
					'ip'    => Rate_Limiter::client_ip(),
					'field' => isset( $raw['company_website'] ) ? 'company_website' : 'website',
				)
			);
			// Soft-fail: look like success to bots without saving.
			return array(
				'reference' => 'PF-' . strtoupper( substr( md5( (string) microtime( true ) ), 0, 6 ) ),
				'status'    => 'pending_confirmation',
				'message'   => __( 'Your transfer request is Pending Confirmation.', 'prime-fleet' ),
			);
		}

		$captcha = Captcha::verify_request( $raw );
		if ( is_wp_error( $captcha ) ) {
			return $captcha;
		}

		$idem = isset( $raw['idempotency_key'] ) ? sanitize_text_field( (string) $raw['idempotency_key'] ) : '';
		if ( strlen( $idem ) > 8 ) {
			$existing = self::find_by_idempotency( $idem );
			if ( $existing ) {
				return $existing;
			}
		}

		$token_check = Submit_Token::consume( isset( $raw['booking_token'] ) ? (string) $raw['booking_token'] : '' );
		if ( is_wp_error( $token_check ) ) {
			return $token_check;
		}

		$quote_input = Controller::sanitize_quote_input( $raw );
		unset( $quote_input['total'], $quote_input['price'], $quote_input['amount'], $quote_input['fare'] );

		$contact = self::sanitize_contact( $raw );
		if ( is_wp_error( $contact ) ) {
			return $contact;
		}

		if ( empty( $quote_input['service_id'] ) ) {
			return new \WP_Error( 'prime_fleet_invalid_service', __( 'Service is required.', 'prime-fleet' ), array( 'status' => 400 ) );
		}

		$pickup = self::normalize_pickup( $raw );
		if ( is_wp_error( $pickup ) ) {
			return $pickup;
		}
		$quote_input['pickup_date'] = $pickup['date'];
		$quote_input['pickup_time'] = $pickup['time'];

		$terms = ! empty( $raw['terms_accepted'] ) || ! empty( $raw['accept_terms'] );
		if ( ! $terms ) {
			return new \WP_Error( 'prime_fleet_terms', __( 'Please accept the terms to continue.', 'prime-fleet' ), array( 'status' => 400 ) );
		}

		$quote = Engine::quote( $quote_input );
		if ( is_wp_error( $quote ) ) {
			return $quote;
		}

		$vehicle_id = absint( $raw['vehicle_id'] ?? 0 );
		if ( $vehicle_id && ! empty( $quote['vehicles'] ) && is_array( $quote['vehicles'] ) ) {
			$ok = false;
			foreach ( $quote['vehicles'] as $v ) {
				if ( (int) ( $v['id'] ?? 0 ) === $vehicle_id && 'not_selectable' !== ( $v['status'] ?? '' ) ) {
					$ok = true;
					break;
				}
			}
			if ( ! $ok ) {
				$vehicle_id = 0;
			}
		}

		$settings = wp_parse_args(
			get_option( 'prime_fleet_settings', array() ),
			array(
				'pending_message' => __( 'Your transfer request is Pending Confirmation.', 'prime-fleet' ),
				'currency'        => 'USD',
			)
		);

		$occ = Occupancy::check(
			$pickup['datetime'],
			array(
				'vehicle_id' => $vehicle_id,
				'hours'      => $quote_input['hours'],
			)
		);
		$quote['availability'] = $occ;
		if ( ! $vehicle_id && ! empty( $occ['available'] ) && ! empty( $occ['vehicle_id'] ) ) {
			$vehicle_id = (int) $occ['vehicle_id'];
		}

		$reference     = self::generate_reference();
		$manual_quote  = ! empty( $quote['manual_quote'] );
		$manual_review = $manual_quote || ( empty( $occ['available'] ) && empty( $occ['manual'] ) );
		$total         = $manual_quote ? 0.0 : (float) ( $quote['total'] ?? 0 );
		$currency      = isset( $quote['currency'] ) ? (string) $quote['currency'] : $settings['currency'];

		$coupon_code = strtoupper( sanitize_text_field( (string) ( $raw['coupon_code'] ?? '' ) ) );
		if ( $coupon_code && ! $manual_quote ) {
			$coupon = Coupons::validate(
				$coupon_code,
				array(
					'service_id' => (int) $quote_input['service_id'],
					'fare_total' => $total,
					'email'      => $contact['email'],
				)
			);
			if ( is_wp_error( $coupon ) ) {
				return $coupon;
			}
			$quote['coupon'] = $coupon;
			$total           = (float) $coupon['fare_after'];
			if ( empty( $quote['explanation'] ) || ! is_array( $quote['explanation'] ) ) {
				$quote['explanation'] = array();
			}
			$quote['explanation'][] = sprintf(
				/* translators: 1: coupon code, 2: discount amount */
				__( 'Coupon %1$s: −%2$s', 'prime-fleet' ),
				$coupon_code,
				number_format( (float) $coupon['discount'], 2 )
			);
		} else {
			$coupon_code = '';
		}

		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );

		try {
			$inserted = $wpdb->insert(
				Schema::table( 'bookings' ),
				array(
					'reference'            => $reference,
					'status'               => 'pending_confirmation',
					'payment_status'       => 'unpaid',
					'service_id'           => (int) $quote_input['service_id'],
					'customer_name'        => $contact['name'],
					'customer_email'       => $contact['email'],
					'customer_phone'       => $contact['phone'],
					'customer_whatsapp'    => $contact['whatsapp'],
					'pickup_datetime'      => $pickup['datetime'],
					'assigned_vehicle_id'  => $vehicle_id,
					'passenger_count'      => (int) $quote_input['passengers'],
					'manual_review'        => $manual_review ? 1 : 0,
					'fare_snapshot_json'   => wp_json_encode( $quote ),
					'fare_total'           => $total,
					'currency'             => $currency,
					'coupon_code'          => $coupon_code,
					'created_by'           => 0,
				),
				array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%f', '%s', '%s', '%d' )
			);

			if ( ! $inserted ) {
				throw new \RuntimeException( 'booking insert failed' );
			}
			$booking_id = (int) $wpdb->insert_id;

			$wpdb->insert(
				Schema::table( 'booking_legs' ),
				array(
					'booking_id'         => $booking_id,
					'leg_type'           => 'outbound',
					'pickup_zone_id'     => (int) $quote_input['origin_zone_id'],
					'dest_zone_id'       => (int) $quote_input['dest_zone_id'],
					'pickup_detail'      => sanitize_text_field( (string) ( $raw['pickup_detail'] ?? '' ) ),
					'dest_detail'        => sanitize_text_field( (string) ( $raw['dest_detail'] ?? '' ) ),
					'scheduled_datetime' => $pickup['datetime'],
					'meta_json'          => wp_json_encode(
						array(
							'trip_type' => $quote_input['trip_type'],
						)
					),
				),
				array( '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
			);

			if ( 'round_trip' === $quote_input['trip_type'] ) {
				$wpdb->insert(
					Schema::table( 'booking_legs' ),
					array(
						'booking_id'         => $booking_id,
						'leg_type'           => 'return',
						'pickup_zone_id'     => (int) $quote_input['dest_zone_id'],
						'dest_zone_id'       => (int) $quote_input['origin_zone_id'],
						'pickup_detail'      => '',
						'dest_detail'        => '',
						'scheduled_datetime' => null,
						'meta_json'          => null,
					),
					array( '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
				);
			}

			if ( ! empty( $quote['breakdown']['addons'] ) && is_array( $quote['breakdown']['addons'] ) ) {
				foreach ( $quote['breakdown']['addons'] as $line ) {
					$wpdb->insert(
						Schema::table( 'booking_items' ),
						array(
							'booking_id' => $booking_id,
							'item_type'  => 'addon',
							'item_id'    => (int) ( $line['id'] ?? 0 ),
							'label'      => (string) ( $line['name'] ?? '' ),
							'qty'        => (float) ( $line['qty'] ?? 1 ),
							'unit_price' => (float) ( $line['amount'] ?? 0 ),
							'line_total' => (float) ( $line['amount'] ?? 0 ),
						),
						array( '%d', '%s', '%d', '%s', '%f', '%f', '%f' )
					);
				}
			}

			$meta_rows = array(
				'trip_type'        => $quote_input['trip_type'],
				'checked_bags'     => (string) (int) $quote_input['checked_bags'],
				'carry_on_bags'    => (string) (int) $quote_input['carry_on_bags'],
				'hours'            => (string) $quote_input['hours'],
				'package_id'       => (string) (int) $quote_input['package_id'],
				'terms_accepted'   => '1',
				'idempotency_key'  => $idem,
				'submit_ip'        => Rate_Limiter::client_ip(),
			);
			foreach ( $meta_rows as $k => $v ) {
				if ( '' === $v && 'idempotency_key' !== $k ) {
					continue;
				}
				$wpdb->insert(
					Schema::table( 'booking_meta' ),
					array(
						'booking_id' => $booking_id,
						'meta_key'   => $k,
						'meta_value' => $v,
					),
					array( '%d', '%s', '%s' )
				);
			}

			$wpdb->insert(
				Schema::table( 'booking_events' ),
				array(
					'booking_id' => $booking_id,
					'event_type' => 'created',
					'actor_id'   => 0,
					'message'    => 'Guest booking submitted',
					'meta_json'  => wp_json_encode( array( 'source' => 'wizard' ) ),
				),
				array( '%d', '%s', '%d', '%s', '%s' )
			);

			if ( $coupon_code && ! empty( $quote['coupon'] ) ) {
				$coupon_id = (int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT id FROM ' . Schema::table( 'coupons' ) . ' WHERE code = %s LIMIT 1',
						$coupon_code
					)
				);
				if ( $coupon_id ) {
					$wpdb->insert(
						Schema::table( 'coupon_redemptions' ),
						array(
							'coupon_id'  => $coupon_id,
							'booking_id' => $booking_id,
							'email'      => $contact['email'],
						),
						array( '%d', '%d', '%s' )
					);
				}
			}

			// Queue notification after save (non-blocking for guest).
			$wpdb->insert(
				Schema::table( 'jobs' ),
				array(
					'job_type'     => 'booking_created_notify',
					'payload_json' => wp_json_encode(
						array(
							'booking_id' => $booking_id,
							'reference'  => $reference,
						)
					),
					'status'       => 'pending',
					'run_at'       => gmdate( 'Y-m-d H:i:s' ),
				),
				array( '%s', '%s', '%s', '%s' )
			);

			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'prime_fleet_save_failed', __( 'Could not save booking. Please try again.', 'prime-fleet' ), array( 'status' => 500 ) );
		}

		$result = array(
			'reference'    => $reference,
			'status'       => 'pending_confirmation',
			'booking_id'   => $booking_id,
			'manual_quote' => $manual_quote,
			'fare_total'   => $manual_quote ? null : $total,
			'currency'     => $currency,
			'message'      => (string) $settings['pending_message'],
		);

		/**
		 * After booking row is committed.
		 *
		 * @param array $result Result.
		 * @param int   $booking_id ID.
		 * @param array $quote Quote snapshot.
		 */
		do_action( 'prime_fleet_booking_created', $result, $booking_id, $quote );

		return $result;
	}

	/**
	 * @param array $raw Raw.
	 * @return array|\WP_Error
	 */
	private static function sanitize_contact( array $raw ) {
		$name  = sanitize_text_field( (string) ( $raw['customer_name'] ?? $raw['name'] ?? '' ) );
		$email = sanitize_email( (string) ( $raw['customer_email'] ?? $raw['email'] ?? '' ) );
		$phone = sanitize_text_field( (string) ( $raw['customer_phone'] ?? $raw['phone'] ?? '' ) );
		$wa    = sanitize_text_field( (string) ( $raw['customer_whatsapp'] ?? $raw['whatsapp'] ?? '' ) );

		if ( strlen( $name ) < 2 ) {
			return new \WP_Error( 'prime_fleet_name', __( 'Please enter your name.', 'prime-fleet' ), array( 'status' => 400 ) );
		}
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'prime_fleet_email', __( 'Please enter a valid email address.', 'prime-fleet' ), array( 'status' => 400 ) );
		}
		if ( strlen( $phone ) < 7 ) {
			return new \WP_Error( 'prime_fleet_phone', __( 'Please enter a valid phone number.', 'prime-fleet' ), array( 'status' => 400 ) );
		}

		return array(
			'name'     => $name,
			'email'    => $email,
			'phone'    => $phone,
			'whatsapp' => $wa ? $wa : $phone,
		);
	}

	/**
	 * @param array $raw Raw.
	 * @return array{date:string,time:string,datetime:string}|\WP_Error
	 */
	private static function normalize_pickup( array $raw ) {
		$date = sanitize_text_field( (string) ( $raw['pickup_date'] ?? '' ) );
		$time = sanitize_text_field( (string) ( $raw['pickup_time'] ?? '' ) );
		if ( ! empty( $raw['pickup_datetime'] ) ) {
			$dt = sanitize_text_field( (string) $raw['pickup_datetime'] );
			$dt = str_replace( 'T', ' ', $dt );
			if ( preg_match( '/^(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2})/', $dt, $m ) ) {
				$date = $m[1];
				$time = $m[2];
			}
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new \WP_Error( 'prime_fleet_date', __( 'Pickup date is required.', 'prime-fleet' ), array( 'status' => 400 ) );
		}
		if ( ! preg_match( '/^\d{2}:\d{2}/', $time ) ) {
			return new \WP_Error( 'prime_fleet_time', __( 'Pickup time is required.', 'prime-fleet' ), array( 'status' => 400 ) );
		}
		$time = substr( $time, 0, 5 );
		$ok   = Pickup_Rules::validate( $date, $time );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		return array(
			'date'     => $date,
			'time'     => $time,
			'datetime' => $date . ' ' . $time . ':00',
		);
	}

	/**
	 * @return string
	 */
	private static function generate_reference() {
		global $wpdb;
		$table = Schema::table( 'bookings' );
		for ( $i = 0; $i < 12; $i++ ) {
			$ref = 'PF-' . strtoupper( wp_generate_password( 6, false, false ) );
			$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE reference = %s LIMIT 1", $ref ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $exists ) {
				return $ref;
			}
		}
		return 'PF-' . strtoupper( substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 8 ) );
	}

	/**
	 * @param string $key Idempotency key.
	 * @return array|null
	 */
	private static function find_by_idempotency( $key ) {
		global $wpdb;
		$booking_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT booking_id FROM ' . Schema::table( 'booking_meta' ) . ' WHERE meta_key = %s AND meta_value = %s ORDER BY id DESC LIMIT 1',
				'idempotency_key',
				$key
			)
		);
		if ( ! $booking_id ) {
			return null;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT reference, status, fare_total, currency, manual_review FROM ' . Schema::table( 'bookings' ) . ' WHERE id = %d', $booking_id ),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		$settings = get_option( 'prime_fleet_settings', array() );
		return array(
			'reference'    => $row['reference'],
			'status'       => $row['status'],
			'booking_id'   => $booking_id,
			'manual_quote' => (int) $row['manual_review'] === 1,
			'fare_total'   => (int) $row['manual_review'] === 1 ? null : (float) $row['fare_total'],
			'currency'     => $row['currency'],
			'message'      => isset( $settings['pending_message'] ) ? $settings['pending_message'] : __( 'Your transfer request is Pending Confirmation.', 'prime-fleet' ),
			'idempotent'   => true,
		);
	}
}
