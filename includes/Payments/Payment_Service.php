<?php
/**
 * Payment issue / status / token resolution (M4).
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Payments;

use Prime_Fleet\Database\Schema;
use Prime_Fleet\Messaging\Queue;
use Prime_Fleet\Plugin;

defined( 'ABSPATH' ) || exit;

final class Payment_Service {

	/**
	 * Guest pay URL for a token.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	public static function guest_url( $token ) {
		return home_url( '/?prime_fleet_pay=' . rawurlencode( (string) $token ) );
	}

	/**
	 * Methods staff can issue in Phase A (instructions + custom link).
	 *
	 * @return array<string,\Prime_Fleet\Payments\Contracts\Payment_Method_Interface>
	 */
	public static function issueable_methods() {
		$out = array();
		foreach ( Plugin::instance()->payments()->enabled() as $method ) {
			$id = $method->id();
			if ( in_array( $id, array( 'paypal', 'authorize_net' ), true ) ) {
				continue;
			}
			if ( 'stripe' === $id ) {
				if ( ! Stripe_Gateway::is_ready() ) {
					continue;
				}
				$out[ $id ] = $method;
				continue;
			}
			if ( $method->supports_instructions() || $method->supports_link() ) {
				$out[ $id ] = $method;
			}
		}
		return $out;
	}

	/**
	 * Latest payment row for a booking.
	 *
	 * @param int $booking_id Booking ID.
	 * @return array|null
	 */
	public static function latest_for_booking( $booking_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table( 'payments' ) . ' WHERE booking_id = %d ORDER BY id DESC LIMIT 1',
				absint( $booking_id )
			),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * Resolve guest token to payment + booking context.
	 *
	 * @param string $token Token.
	 * @return array{payment:array,booking:array,method:?array,settings:array}|null
	 */
	public static function resolve_token( $token ) {
		global $wpdb;
		$token = sanitize_text_field( (string) $token );
		if ( '' === $token ) {
			return null;
		}

		$payment = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table( 'payments' ) . ' WHERE token = %s LIMIT 1',
				$token
			),
			ARRAY_A
		);
		if ( ! $payment ) {
			return null;
		}

		$booking = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table( 'bookings' ) . ' WHERE id = %d',
				(int) $payment['booking_id']
			),
			ARRAY_A
		);
		if ( ! $booking ) {
			return null;
		}

		$method_row = null;
		$settings   = array();
		if ( ! empty( $payment['method_id'] ) ) {
			$method_row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM ' . Schema::table( 'payment_methods' ) . ' WHERE id = %d',
					(int) $payment['method_id']
				),
				ARRAY_A
			);
			if ( $method_row && ! empty( $method_row['settings_json'] ) ) {
				$decoded = json_decode( (string) $method_row['settings_json'], true );
				$settings = is_array( $decoded ) ? $decoded : array();
			}
		}

		return array(
			'payment'  => $payment,
			'booking'  => $booking,
			'method'   => $method_row,
			'settings' => $settings,
		);
	}

	/**
	 * Issue (or re-use) a tracked payment link and notify guest.
	 *
	 * @param int    $booking_id Booking ID.
	 * @param string $method_key Method key (empty = first issueable).
	 * @param array  $args Optional: custom_url, notify (default true), actor_id.
	 * @return array{payment:array,guest_url:string,reused:bool}|\WP_Error
	 */
	public static function issue( $booking_id, $method_key = '', array $args = array() ) {
		global $wpdb;

		$booking_id = absint( $booking_id );
		$booking    = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'bookings' ) . ' WHERE id = %d', $booking_id ),
			ARRAY_A
		);
		if ( ! $booking ) {
			return new \WP_Error( 'prime_fleet_pay_booking', __( 'Booking not found.', 'prime-fleet' ), array( 'status' => 404 ) );
		}

		/* Cooldown before any payment mutate — avoids orphan links on notify failure. */
		$notify = ! array_key_exists( 'notify', $args ) || ! empty( $args['notify'] );
		if ( $notify ) {
			$cooldown = self::payment_notify_cooldown( $booking_id );
			if ( is_wp_error( $cooldown ) ) {
				return $cooldown;
			}
		}

		$issueable = self::issueable_methods();
		if ( ! $issueable ) {
			return new \WP_Error( 'prime_fleet_pay_method', __( 'No payment methods enabled. Enable Bank/instructions or Custom link under Payments.', 'prime-fleet' ), array( 'status' => 400 ) );
		}

		$method_key = sanitize_key( (string) $method_key );
		if ( '' === $method_key ) {
			$keys       = array_keys( $issueable );
			$method_key = (string) $keys[0];
		}
		if ( ! isset( $issueable[ $method_key ] ) ) {
			return new \WP_Error( 'prime_fleet_pay_method', __( 'That payment method is not available.', 'prime-fleet' ), array( 'status' => 400 ) );
		}

		$method     = $issueable[ $method_key ];
		$method_row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table( 'payment_methods' ) . ' WHERE method_key = %s',
				$method_key
			),
			ARRAY_A
		);
		if ( ! $method_row ) {
			return new \WP_Error( 'prime_fleet_pay_method', __( 'Payment method row missing.', 'prime-fleet' ), array( 'status' => 500 ) );
		}

		$settings   = $method->get_settings();
		$custom_url = '';
		if ( ! empty( $args['custom_url'] ) ) {
			$custom_url = esc_url_raw( (string) $args['custom_url'] );
		} elseif ( ! empty( $settings['default_url'] ) ) {
			$custom_url = esc_url_raw( (string) $settings['default_url'] );
		}

		$latest = self::latest_for_booking( $booking_id );
		$reused = false;
		$payment = null;

		if (
			empty( $args['force_new'] )
			&& $latest
			&& (int) $latest['method_id'] === (int) $method_row['id']
			&& in_array( (string) $latest['status'], array( 'unpaid', 'link_sent' ), true )
			&& ! empty( $latest['token'] )
		) {
			$payment = $latest;
			$reused  = true;
			if ( $custom_url && $custom_url !== (string) $payment['custom_url'] ) {
				$wpdb->update(
					Schema::table( 'payments' ),
					array( 'custom_url' => $custom_url ),
					array( 'id' => (int) $payment['id'] ),
					array( '%s' ),
					array( '%d' )
				);
				$payment['custom_url'] = $custom_url;
			}
		} else {
			$token = self::generate_token();
			$ok    = $wpdb->insert(
				Schema::table( 'payments' ),
				array(
					'booking_id'  => $booking_id,
					'method_id'   => (int) $method_row['id'],
					'amount'      => (float) $booking['fare_total'],
					'currency'    => $booking['currency'] ? $booking['currency'] : 'USD',
					'token'       => $token,
					'status'      => 'link_sent',
					'external_id' => '',
					'custom_url'  => $custom_url ? $custom_url : null,
					'failure_code'=> '',
				),
				array( '%d', '%d', '%f', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			if ( ! $ok ) {
				return new \WP_Error( 'prime_fleet_pay_insert', __( 'Could not create payment record.', 'prime-fleet' ), array( 'status' => 500 ) );
			}
			$payment = $wpdb->get_row(
				$wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'payments' ) . ' WHERE id = %d', (int) $wpdb->insert_id ),
				ARRAY_A
			);
		}

		// Stripe: create / refresh Checkout Session URL on the payment row.
		if ( 'stripe' === $method_key ) {
			$need_session = empty( $payment['custom_url'] ) || ! empty( $args['force_new'] ) || ! $reused;
			if ( $need_session || empty( $payment['external_id'] ) ) {
				$session = Stripe_Gateway::create_checkout_session( $payment, $booking );
				if ( is_wp_error( $session ) ) {
					return $session;
				}
				$wpdb->update(
					Schema::table( 'payments' ),
					array(
						'custom_url'  => $session['url'],
						'external_id' => $session['session_id'],
					),
					array( 'id' => (int) $payment['id'] ),
					array( '%s', '%s' ),
					array( '%d' )
				);
				$payment['custom_url']  = $session['url'];
				$payment['external_id'] = $session['session_id'];
			}
		}

		self::set_booking_payment_status( $booking_id, 'link_sent' );
		self::record_event(
			(int) $payment['id'],
			'payment_issued',
			array(
				'method_key' => $method_key,
				'reused'     => $reused,
				'actor_id'   => isset( $args['actor_id'] ) ? (int) $args['actor_id'] : get_current_user_id(),
			)
		);

		$guest_url = self::guest_url( $payment['token'] );
		if ( $notify ) {
			Queue::on_payment_link( $booking_id, $guest_url );
			self::mark_payment_notify_sent( $booking_id );
		}

		$wpdb->insert(
			Schema::table( 'booking_events' ),
			array(
				'booking_id' => $booking_id,
				'event_type' => 'payment_link_sent',
				'actor_id'   => isset( $args['actor_id'] ) ? (int) $args['actor_id'] : get_current_user_id(),
				'message'    => $reused ? 'Payment link re-sent' : 'Payment link issued',
				'meta_json'  => wp_json_encode(
					array(
						'payment_id'   => (int) $payment['id'],
						'method_key'   => $method_key,
						'payment_link' => $guest_url,
					)
				),
			),
			array( '%d', '%s', '%d', '%s', '%s' )
		);

		/**
		 * After a payment link is issued.
		 *
		 * @param array $payment Payment row.
		 * @param array $booking Booking row.
		 */
		do_action( 'prime_fleet_payment_issued', $payment, $booking );

		return array(
			'payment'   => $payment,
			'guest_url' => $guest_url,
			'reused'    => $reused,
		);
	}

	/**
	 * Mark booking (and latest open payment) as paid.
	 *
	 * @param int   $booking_id Booking ID.
	 * @param array $args Optional: notify, actor_id, idempotency_key, source.
	 * @return array{payment:?array,already_paid:bool}|\WP_Error
	 */
	public static function mark_paid( $booking_id, array $args = array() ) {
		global $wpdb;

		$booking_id = absint( $booking_id );
		$booking    = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'bookings' ) . ' WHERE id = %d', $booking_id ),
			ARRAY_A
		);
		if ( ! $booking ) {
			return new \WP_Error( 'prime_fleet_pay_booking', __( 'Booking not found.', 'prime-fleet' ), array( 'status' => 404 ) );
		}

		$already = ( 'paid' === (string) $booking['payment_status'] );
		$payment = self::latest_for_booking( $booking_id );

		if ( $payment && 'paid' !== (string) $payment['status'] ) {
			$wpdb->update(
				Schema::table( 'payments' ),
				array( 'status' => 'paid' ),
				array( 'id' => (int) $payment['id'] ),
				array( '%s' ),
				array( '%d' )
			);
			$payment['status'] = 'paid';
		}

		self::set_booking_payment_status( $booking_id, 'paid' );

		$idem = ! empty( $args['idempotency_key'] )
			? sanitize_text_field( (string) $args['idempotency_key'] )
			: 'mark_paid_' . $booking_id . '_' . ( $payment ? (int) $payment['id'] : 0 );

		if ( $payment ) {
			self::record_event(
				(int) $payment['id'],
				'payment_paid',
				array(
					'source'   => isset( $args['source'] ) ? (string) $args['source'] : 'staff',
					'actor_id' => isset( $args['actor_id'] ) ? (int) $args['actor_id'] : get_current_user_id(),
				),
				$idem
			);
		}

		$notify = ! array_key_exists( 'notify', $args ) || ! empty( $args['notify'] );
		if ( $notify && ! $already ) {
			Queue::on_payment_confirmed( $booking_id );
			$wpdb->insert(
				Schema::table( 'booking_events' ),
				array(
					'booking_id' => $booking_id,
					'event_type' => 'payment_confirmed_notify',
					'actor_id'   => isset( $args['actor_id'] ) ? (int) $args['actor_id'] : get_current_user_id(),
					'message'    => 'Payment marked paid',
					'meta_json'  => wp_json_encode(
						array(
							'payment_id' => $payment ? (int) $payment['id'] : 0,
							'source'     => isset( $args['source'] ) ? (string) $args['source'] : 'staff',
						)
					),
				),
				array( '%d', '%s', '%d', '%s', '%s' )
			);
		}

		/**
		 * After payment marked paid.
		 *
		 * @param int        $booking_id Booking ID.
		 * @param array|null $payment Payment row.
		 * @param bool       $already Whether already paid.
		 */
		do_action( 'prime_fleet_payment_paid', $booking_id, $payment, $already );
		do_action( 'prime_fleet_payment_status_changed', $booking_id, 'paid', (string) $booking['payment_status'] );

		return array(
			'payment'      => $payment,
			'already_paid' => $already,
		);
	}

	/**
	 * Mark booking (and latest payment) as refunded.
	 *
	 * @param int   $booking_id Booking ID.
	 * @param array $args Optional: notify (default true), actor_id, source.
	 * @return array{payment:?array,already_refunded:bool}|\WP_Error
	 */
	public static function mark_refunded( $booking_id, array $args = array() ) {
		global $wpdb;

		$booking_id = absint( $booking_id );
		$booking    = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'bookings' ) . ' WHERE id = %d', $booking_id ),
			ARRAY_A
		);
		if ( ! $booking ) {
			return new \WP_Error( 'prime_fleet_pay_booking', __( 'Booking not found.', 'prime-fleet' ), array( 'status' => 404 ) );
		}

		$already = ( 'refunded' === (string) $booking['payment_status'] );
		$payment = self::latest_for_booking( $booking_id );

		if ( $payment && 'refunded' !== (string) $payment['status'] ) {
			$wpdb->update(
				Schema::table( 'payments' ),
				array( 'status' => 'refunded' ),
				array( 'id' => (int) $payment['id'] ),
				array( '%s' ),
				array( '%d' )
			);
			$payment['status'] = 'refunded';
		}

		$prev = (string) $booking['payment_status'];
		self::set_booking_payment_status( $booking_id, 'refunded' );

		if ( $payment ) {
			self::record_event(
				(int) $payment['id'],
				'payment_refunded',
				array(
					'source'   => isset( $args['source'] ) ? (string) $args['source'] : 'staff',
					'actor_id' => isset( $args['actor_id'] ) ? (int) $args['actor_id'] : get_current_user_id(),
				),
				'mark_refunded_' . $booking_id . '_' . (int) $payment['id']
			);
		}

		$notify = ! array_key_exists( 'notify', $args ) || ! empty( $args['notify'] );
		if ( $notify && ! $already ) {
			Queue::on_payment_refunded( $booking_id );
			$wpdb->insert(
				Schema::table( 'booking_events' ),
				array(
					'booking_id' => $booking_id,
					'event_type' => 'payment_refunded_notify',
					'actor_id'   => isset( $args['actor_id'] ) ? (int) $args['actor_id'] : get_current_user_id(),
					'message'    => 'Payment refund notify queued',
					'meta_json'  => wp_json_encode(
						array(
							'payment_id' => $payment ? (int) $payment['id'] : 0,
							'source'     => isset( $args['source'] ) ? (string) $args['source'] : 'staff',
						)
					),
				),
				array( '%d', '%s', '%d', '%s', '%s' )
			);
		} elseif ( ! $already ) {
			$wpdb->insert(
				Schema::table( 'booking_events' ),
				array(
					'booking_id' => $booking_id,
					'event_type' => 'payment_refunded',
					'actor_id'   => isset( $args['actor_id'] ) ? (int) $args['actor_id'] : get_current_user_id(),
					'message'    => 'Payment marked refunded',
					'meta_json'  => wp_json_encode(
						array(
							'payment_id' => $payment ? (int) $payment['id'] : 0,
							'source'     => isset( $args['source'] ) ? (string) $args['source'] : 'staff',
						)
					),
				),
				array( '%d', '%s', '%d', '%s', '%s' )
			);
		}

		do_action( 'prime_fleet_payment_status_changed', $booking_id, 'refunded', $prev );

		return array(
			'payment'          => $payment,
			'already_refunded' => $already,
		);
	}

	/**
	 * Invalidate current open link and issue a fresh token (guest notified).
	 *
	 * @param int   $booking_id Booking ID.
	 * @param array $args Optional: method_key, custom_url, actor_id.
	 * @return array{payment:array,guest_url:string,reused:bool}|\WP_Error
	 */
	public static function regenerate( $booking_id, array $args = array() ) {
		global $wpdb;

		$booking_id = absint( $booking_id );
		$notify     = ! array_key_exists( 'notify', $args ) || ! empty( $args['notify'] );
		if ( $notify ) {
			$cooldown = self::payment_notify_cooldown( $booking_id );
			if ( is_wp_error( $cooldown ) ) {
				return $cooldown;
			}
		}

		$latest = self::latest_for_booking( $booking_id );
		if ( $latest && in_array( (string) $latest['status'], array( 'unpaid', 'link_sent' ), true ) ) {
			$wpdb->update(
				Schema::table( 'payments' ),
				array(
					'status'       => 'expired',
					'failure_code' => 'regenerated',
				),
				array( 'id' => (int) $latest['id'] ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			self::record_event(
				(int) $latest['id'],
				'payment_expired',
				array(
					'reason'   => 'regenerated',
					'actor_id' => isset( $args['actor_id'] ) ? (int) $args['actor_id'] : get_current_user_id(),
				)
			);
		}

		$method_key = isset( $args['method_key'] ) ? (string) $args['method_key'] : '';
		if ( '' === $method_key && $latest && ! empty( $latest['method_id'] ) ) {
			$method_key = (string) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT method_key FROM ' . Schema::table( 'payment_methods' ) . ' WHERE id = %d',
					(int) $latest['method_id']
				)
			);
		}

		$args['force_new'] = true;
		return self::issue( $booking_id, $method_key, $args );
	}

	/**
	 * Seconds between payment-link guest notifies for one booking (default 120).
	 *
	 * @return int
	 */
	public static function payment_notify_cooldown_seconds() {
		$settings = get_option( 'prime_fleet_settings', array() );
		if ( is_array( $settings ) && isset( $settings['payment_notify_cooldown_sec'] ) && '' !== $settings['payment_notify_cooldown_sec'] ) {
			return max( 0, min( HOUR_IN_SECONDS, absint( $settings['payment_notify_cooldown_sec'] ) ) );
		}
		return 120;
	}

	/**
	 * @param int $booking_id Booking ID.
	 * @return true|\WP_Error
	 */
	private static function payment_notify_cooldown( $booking_id ) {
		$sec = self::payment_notify_cooldown_seconds();
		if ( $sec <= 0 ) {
			return true;
		}
		$key  = 'prime_fleet_pay_notify_' . absint( $booking_id );
		$last = get_transient( $key );
		if ( $last ) {
			$wait = max( 1, $sec - ( time() - (int) $last ) );
			return new \WP_Error(
				'prime_fleet_pay_notify_cooldown',
				sprintf(
					/* translators: %d: seconds to wait */
					__( 'Payment notify cooldown — wait %d seconds before re-sending.', 'prime-fleet' ),
					$wait
				),
				array( 'status' => 429, 'retry_after' => $wait )
			);
		}
		return true;
	}

	/**
	 * @param int $booking_id Booking ID.
	 */
	private static function mark_payment_notify_sent( $booking_id ) {
		$sec = self::payment_notify_cooldown_seconds();
		if ( $sec <= 0 ) {
			return;
		}
		set_transient( 'prime_fleet_pay_notify_' . absint( $booking_id ), time(), $sec );
	}

	/**
	 * Link TTL in days (0 = never expires by age).
	 *
	 * @return int
	 */
	public static function link_ttl_days() {
		$settings = get_option( 'prime_fleet_settings', array() );
		if ( ! isset( $settings['payment_link_ttl_days'] ) || '' === $settings['payment_link_ttl_days'] ) {
			return 14;
		}
		return max( 0, (int) $settings['payment_link_ttl_days'] );
	}

	/**
	 * Whether a payment link is past TTL (open statuses only).
	 *
	 * @param array $payment Payment row.
	 * @return bool
	 */
	public static function is_expired( array $payment ) {
		$status = (string) ( $payment['status'] ?? '' );
		if ( 'expired' === $status ) {
			return true;
		}
		if ( ! in_array( $status, array( 'unpaid', 'link_sent' ), true ) ) {
			return false;
		}
		$ttl = self::link_ttl_days();
		if ( $ttl <= 0 || empty( $payment['created_at'] ) ) {
			return false;
		}
		$created = strtotime( (string) $payment['created_at'] );
		if ( ! $created ) {
			return false;
		}
		return ( time() - $created ) > ( $ttl * DAY_IN_SECONDS );
	}

	/**
	 * Public JSON summary for a guest payment token (no secrets).
	 *
	 * @param string $token Token.
	 * @return array|\WP_Error
	 */
	public static function public_summary( $token ) {
		$ctx = self::resolve_token( $token );
		if ( ! $ctx ) {
			return new \WP_Error( 'prime_fleet_pay_token', __( 'Payment link not found.', 'prime-fleet' ), array( 'status' => 404 ) );
		}

		$payment = $ctx['payment'];
		$booking = $ctx['booking'];
		$method  = $ctx['method'];
		$status  = (string) $payment['status'];
		$paid    = ( 'paid' === $status || 'paid' === (string) $booking['payment_status'] );
		$refunded = ( 'refunded' === $status || 'refunded' === (string) $booking['payment_status'] );
		$expired = ! $paid && ! $refunded && self::is_expired( $payment );
		$state   = $paid ? 'paid' : ( $refunded ? 'refunded' : ( $expired ? 'expired' : 'open' ) );
		$method_key = $method ? (string) $method['method_key'] : '';

		return array(
			'reference'       => (string) $booking['reference'],
			'amount'          => (float) $payment['amount'],
			'currency'        => $payment['currency'] ? (string) $payment['currency'] : 'USD',
			'payment_status'  => (string) $booking['payment_status'],
			'payment_state'   => $state,
			'method_key'      => $method_key,
			'method_label'    => $method && ! empty( $method['label'] ) ? (string) $method['label'] : '',
			'guest_url'       => self::guest_url( $payment['token'] ),
			'can_ack'         => ( 'open' === $state && 'instructions' === $method_key ),
			'expires_at'      => null,
		);
	}

	/**
	 * Guest “I’ve paid” ack for bank/instructions → pending_verification.
	 *
	 * @param string $token Token.
	 * @return array|\WP_Error
	 */
	public static function acknowledge_paid( $token ) {
		global $wpdb;

		$ctx = self::resolve_token( $token );
		if ( ! $ctx ) {
			return new \WP_Error( 'prime_fleet_pay_token', __( 'Payment link not found.', 'prime-fleet' ), array( 'status' => 404 ) );
		}

		$payment = $ctx['payment'];
		$booking = $ctx['booking'];
		$method  = $ctx['method'];
		$method_key = $method ? (string) $method['method_key'] : '';

		if ( 'instructions' !== $method_key ) {
			return new \WP_Error( 'prime_fleet_pay_ack', __( 'This payment method does not support guest acknowledgement.', 'prime-fleet' ), array( 'status' => 400 ) );
		}

		$booking_id = (int) $booking['id'];
		$pay_status = (string) $booking['payment_status'];
		if ( in_array( $pay_status, array( 'paid', 'refunded' ), true ) ) {
			return new \WP_Error( 'prime_fleet_pay_ack', __( 'Payment is already finalized.', 'prime-fleet' ), array( 'status' => 409 ) );
		}
		if ( self::is_expired( $payment ) ) {
			return new \WP_Error( 'prime_fleet_pay_expired', __( 'This payment link has expired.', 'prime-fleet' ), array( 'status' => 410 ) );
		}

		$already = ( 'pending_verification' === $pay_status );
		if ( ! $already ) {
			$wpdb->update(
				Schema::table( 'payments' ),
				array( 'status' => 'pending_verification' ),
				array( 'id' => (int) $payment['id'] ),
				array( '%s' ),
				array( '%d' )
			);
			self::set_booking_payment_status( $booking_id, 'pending_verification' );
			self::record_event(
				(int) $payment['id'],
				'guest_ack',
				array( 'source' => 'guest' ),
				'guest_ack_' . (int) $payment['id']
			);
			$wpdb->insert(
				Schema::table( 'booking_events' ),
				array(
					'booking_id' => $booking_id,
					'event_type' => 'payment_pending_verification',
					'actor_id'   => 0,
					'message'    => 'Guest marked payment as sent (awaiting staff verification)',
					'meta_json'  => wp_json_encode( array( 'payment_id' => (int) $payment['id'] ) ),
				),
				array( '%d', '%s', '%d', '%s', '%s' )
			);
			do_action( 'prime_fleet_payment_status_changed', $booking_id, 'pending_verification', $pay_status );
		}

		$payment['status'] = 'pending_verification';
		return array(
			'payment'  => $payment,
			'already'  => $already,
			'status'   => 'pending_verification',
			'reference'=> (string) $booking['reference'],
		);
	}

	/**
	 * Recent payment_events for a payment row.
	 *
	 * @param int $payment_id Payment ID.
	 * @param int $limit Max rows.
	 * @return array<int,array>
	 */
	public static function events_for_payment( $payment_id, $limit = 20 ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, event_type, idempotency_key, payload_json, created_at FROM ' . Schema::table( 'payment_events' ) . ' WHERE payment_id = %d ORDER BY id DESC LIMIT %d',
				absint( $payment_id ),
				max( 1, absint( $limit ) )
			),
			ARRAY_A
		);
		return $rows ? $rows : array();
	}

	/**
	 * Staff-facing payment snapshot for a booking.
	 *
	 * @param int $booking_id Booking ID.
	 * @return array|\WP_Error
	 */
	public static function staff_snapshot( $booking_id ) {
		global $wpdb;
		$booking_id = absint( $booking_id );
		$booking    = $wpdb->get_row(
			$wpdb->prepare( 'SELECT id, reference, payment_status, fare_total, currency FROM ' . Schema::table( 'bookings' ) . ' WHERE id = %d', $booking_id ),
			ARRAY_A
		);
		if ( ! $booking ) {
			return new \WP_Error( 'prime_fleet_pay_booking', __( 'Booking not found.', 'prime-fleet' ), array( 'status' => 404 ) );
		}

		$payment = self::latest_for_booking( $booking_id );
		$events  = $payment ? self::events_for_payment( (int) $payment['id'] ) : array();
		$method_key = '';
		if ( $payment && ! empty( $payment['method_id'] ) ) {
			$method_key = (string) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT method_key FROM ' . Schema::table( 'payment_methods' ) . ' WHERE id = %d',
					(int) $payment['method_id']
				)
			);
		}

		return array(
			'booking_id'     => $booking_id,
			'reference'      => (string) $booking['reference'],
			'payment_status' => (string) $booking['payment_status'],
			'fare_total'     => (float) $booking['fare_total'],
			'currency'       => $booking['currency'] ? (string) $booking['currency'] : 'USD',
			'payment'        => $payment
				? array(
					'id'          => (int) $payment['id'],
					'status'      => (string) $payment['status'],
					'amount'      => (float) $payment['amount'],
					'currency'    => (string) $payment['currency'],
					'method_key'  => $method_key,
					'token'       => (string) $payment['token'],
					'guest_url'   => self::guest_url( $payment['token'] ),
					'external_id' => (string) ( $payment['external_id'] ?? '' ),
					'created_at'  => (string) $payment['created_at'],
					'expired'     => self::is_expired( $payment ),
				)
				: null,
			'events'         => array_map(
				static function ( $ev ) {
					$payload = json_decode( (string) $ev['payload_json'], true );
					return array(
						'id'         => (int) $ev['id'],
						'event_type' => (string) $ev['event_type'],
						'payload'    => is_array( $payload ) ? $payload : array(),
						'created_at' => (string) $ev['created_at'],
					);
				},
				$events
			),
		);
	}

	/**
	 * @param int    $booking_id Booking ID.
	 * @param string $status Status.
	 */
	public static function set_booking_payment_status( $booking_id, $status ) {
		global $wpdb;
		$wpdb->update(
			Schema::table( 'bookings' ),
			array( 'payment_status' => sanitize_key( $status ) ),
			array( 'id' => absint( $booking_id ) ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * @param int    $payment_id Payment ID.
	 * @param string $event_type Event type.
	 * @param array  $payload Payload.
	 * @param string $idempotency_key Optional key.
	 * @return bool
	 */
	public static function record_event( $payment_id, $event_type, array $payload = array(), $idempotency_key = '' ) {
		global $wpdb;
		if ( '' === $idempotency_key ) {
			$idempotency_key = $event_type . '_' . (int) $payment_id . '_' . wp_generate_password( 8, false );
		}
		$ok = $wpdb->insert(
			Schema::table( 'payment_events' ),
			array(
				'payment_id'      => absint( $payment_id ),
				'idempotency_key' => sanitize_text_field( $idempotency_key ),
				'event_type'      => sanitize_key( $event_type ),
				'payload_json'    => wp_json_encode( $payload ),
			),
			array( '%d', '%s', '%s', '%s' )
		);
		return (bool) $ok;
	}

	/**
	 * @return string
	 */
	private static function generate_token() {
		try {
			return bin2hex( random_bytes( 20 ) );
		} catch ( \Exception $e ) {
			return wp_generate_password( 40, false, false );
		}
	}
}
