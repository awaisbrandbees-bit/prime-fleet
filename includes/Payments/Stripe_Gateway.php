<?php
/**
 * Stripe Checkout + webhook helpers (M4 Phase C).
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Payments;

use Prime_Fleet\Database\Schema;
use Prime_Fleet\Payments\Methods\Stripe_Method;
use Prime_Fleet\Plugin;

defined( 'ABSPATH' ) || exit;

final class Stripe_Gateway {

	const API = 'https://api.stripe.com/v1';

	/**
	 * @return Stripe_Method|null
	 */
	public static function method() {
		$m = Plugin::instance()->payments()->get( 'stripe' );
		return $m instanceof Stripe_Method ? $m : null;
	}

	/**
	 * Whether Stripe can create hosted sessions.
	 *
	 * @return bool
	 */
	public static function is_ready() {
		$method = self::method();
		return $method && $method->is_enabled() && '' !== self::secret_key();
	}

	/**
	 * Secret key: constant preferred, then settings for active mode.
	 *
	 * @return string
	 */
	public static function secret_key() {
		if ( defined( 'PRIME_FLEET_STRIPE_SECRET_KEY' ) && PRIME_FLEET_STRIPE_SECRET_KEY ) {
			return (string) PRIME_FLEET_STRIPE_SECRET_KEY;
		}
		$method = self::method();
		if ( ! $method ) {
			return '';
		}
		$settings = $method->get_settings();
		$mode     = ( isset( $settings['mode'] ) && 'live' === $settings['mode'] ) ? 'live' : 'test';
		$key      = 'live' === $mode ? 'live_secret' : 'test_secret';
		return isset( $settings[ $key ] ) ? trim( (string) $settings[ $key ] ) : '';
	}

	/**
	 * @return string
	 */
	public static function webhook_secret() {
		if ( defined( 'PRIME_FLEET_STRIPE_WEBHOOK_SECRET' ) && PRIME_FLEET_STRIPE_WEBHOOK_SECRET ) {
			return (string) PRIME_FLEET_STRIPE_WEBHOOK_SECRET;
		}
		$method = self::method();
		if ( ! $method ) {
			return '';
		}
		$settings = $method->get_settings();
		return isset( $settings['webhook_secret'] ) ? trim( (string) $settings['webhook_secret'] ) : '';
	}

	/**
	 * Create a Checkout Session for an existing payments row.
	 *
	 * @param array $payment Payment row.
	 * @param array $booking Booking row.
	 * @return array{url:string,session_id:string}|\WP_Error
	 */
	public static function create_checkout_session( array $payment, array $booking ) {
		$secret = self::secret_key();
		if ( '' === $secret ) {
			return new \WP_Error( 'prime_fleet_stripe_secret', __( 'Stripe secret key is not configured.', 'prime-fleet' ), array( 'status' => 400 ) );
		}

		$amount   = (float) $payment['amount'];
		$currency = strtolower( $payment['currency'] ? (string) $payment['currency'] : 'usd' );
		$cents    = (int) round( $amount * 100 );
		if ( $cents < 50 ) {
			return new \WP_Error( 'prime_fleet_stripe_amount', __( 'Amount is too low for Stripe Checkout.', 'prime-fleet' ), array( 'status' => 400 ) );
		}

		$guest_url = Payment_Service::guest_url( $payment['token'] );
		$ref       = (string) $booking['reference'];
		$product   = sprintf(
			/* translators: %s booking reference */
			__( 'Prime Fleet transfer %s', 'prime-fleet' ),
			$ref
		);

		$body = array(
			'mode'                                 => 'payment',
			'success_url'                          => add_query_arg( 'pf_paid', '1', $guest_url ),
			'cancel_url'                           => add_query_arg( 'pf_cancel', '1', $guest_url ),
			'client_reference_id'                  => (string) $payment['token'],
			'customer_email'                       => ! empty( $booking['customer_email'] ) ? (string) $booking['customer_email'] : '',
			'line_items[0][quantity]'              => 1,
			'line_items[0][price_data][currency]'  => $currency,
			'line_items[0][price_data][unit_amount]' => $cents,
			'line_items[0][price_data][product_data][name]' => $product,
			'metadata[booking_id]'                 => (string) (int) $booking['id'],
			'metadata[payment_id]'                 => (string) (int) $payment['id'],
			'metadata[booking_reference]'          => $ref,
			'metadata[prime_fleet_token]'              => (string) $payment['token'],
		);
		if ( '' === $body['customer_email'] ) {
			unset( $body['customer_email'] );
		}

		$response = self::api_post( '/checkout/sessions', $body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$url = isset( $response['url'] ) ? (string) $response['url'] : '';
		$sid = isset( $response['id'] ) ? (string) $response['id'] : '';
		if ( '' === $url || '' === $sid ) {
			return new \WP_Error( 'prime_fleet_stripe_session', __( 'Stripe did not return a Checkout URL.', 'prime-fleet' ), array( 'status' => 502 ) );
		}

		return array(
			'url'        => $url,
			'session_id' => $sid,
		);
	}

	/**
	 * Verify Stripe-Signature and return event array.
	 *
	 * @param string $payload Raw body.
	 * @param string $header Stripe-Signature header.
	 * @return array|\WP_Error
	 */
	public static function construct_event( $payload, $header ) {
		$secret = self::webhook_secret();
		if ( '' === $secret ) {
			return new \WP_Error( 'prime_fleet_stripe_whsec', __( 'Stripe webhook secret is not configured.', 'prime-fleet' ), array( 'status' => 500 ) );
		}

		$parts = array();
		foreach ( explode( ',', (string) $header ) as $piece ) {
			$kv = explode( '=', trim( $piece ), 2 );
			if ( 2 === count( $kv ) ) {
				$parts[ $kv[0] ][] = $kv[1];
			}
		}
		$timestamp = isset( $parts['t'][0] ) ? (int) $parts['t'][0] : 0;
		$signatures = isset( $parts['v1'] ) ? (array) $parts['v1'] : array();
		if ( ! $timestamp || ! $signatures ) {
			return new \WP_Error( 'prime_fleet_stripe_sig', __( 'Invalid Stripe signature header.', 'prime-fleet' ), array( 'status' => 400 ) );
		}

		// Reject stale timestamps (>5 min).
		if ( abs( time() - $timestamp ) > 300 ) {
			return new \WP_Error( 'prime_fleet_stripe_sig_time', __( 'Stripe signature timestamp out of range.', 'prime-fleet' ), array( 'status' => 400 ) );
		}

		$signed   = $timestamp . '.' . $payload;
		$expected = hash_hmac( 'sha256', $signed, $secret );
		$ok       = false;
		foreach ( $signatures as $sig ) {
			if ( hash_equals( $expected, $sig ) ) {
				$ok = true;
				break;
			}
		}
		if ( ! $ok ) {
			return new \WP_Error( 'prime_fleet_stripe_sig', __( 'Stripe signature verification failed.', 'prime-fleet' ), array( 'status' => 400 ) );
		}

		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) || empty( $event['type'] ) ) {
			return new \WP_Error( 'prime_fleet_stripe_event', __( 'Invalid Stripe event payload.', 'prime-fleet' ), array( 'status' => 400 ) );
		}
		return $event;
	}

	/**
	 * Apply a verified Stripe event to payments.
	 *
	 * @param array $event Event.
	 * @return true|\WP_Error
	 */
	public static function handle_event( array $event ) {
		$type = (string) $event['type'];
		$id   = isset( $event['id'] ) ? (string) $event['id'] : '';
		$obj  = isset( $event['data']['object'] ) && is_array( $event['data']['object'] ) ? $event['data']['object'] : array();

		if ( 'checkout.session.completed' === $type || 'checkout.session.async_payment_succeeded' === $type ) {
			return self::handle_session_paid( $obj, $id );
		}
		if ( 'charge.refunded' === $type ) {
			return self::handle_charge_refunded( $obj, $id );
		}
		return true; // Ignore unrelated events.
	}

	/**
	 * @param array  $session Session object.
	 * @param string $event_id Event id.
	 * @return true|\WP_Error
	 */
	private static function handle_session_paid( array $session, $event_id ) {
		global $wpdb;

		$status = isset( $session['payment_status'] ) ? (string) $session['payment_status'] : '';
		if ( $status && 'paid' !== $status && 'no_payment_required' !== $status ) {
			return true;
		}

		$payment = self::find_payment_from_session( $session );
		if ( ! $payment ) {
			return new \WP_Error( 'prime_fleet_stripe_payment', __( 'No matching Prime Fleet payment for Checkout session.', 'prime-fleet' ), array( 'status' => 404 ) );
		}

		$session_id = isset( $session['id'] ) ? (string) $session['id'] : '';
		$pi         = isset( $session['payment_intent'] ) ? (string) $session['payment_intent'] : '';
		$external   = $pi ? $pi : $session_id;

		if ( $external && $external !== (string) $payment['external_id'] ) {
			$wpdb->update(
				Schema::table( 'payments' ),
				array( 'external_id' => $external ),
				array( 'id' => (int) $payment['id'] ),
				array( '%s' ),
				array( '%d' )
			);
		}

		$result = Payment_Service::mark_paid(
			(int) $payment['booking_id'],
			array(
				'source'          => 'stripe_webhook',
				'notify'          => true,
				'actor_id'        => 0,
				'idempotency_key' => $event_id ? 'stripe_' . $event_id : 'stripe_session_' . $session_id,
			)
		);
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * @param array  $charge Charge object.
	 * @param string $event_id Event id.
	 * @return true|\WP_Error
	 */
	private static function handle_charge_refunded( array $charge, $event_id ) {
		global $wpdb;

		$refunded = ! empty( $charge['refunded'] );
		$amount   = isset( $charge['amount'] ) ? (int) $charge['amount'] : 0;
		$refunded_amount = isset( $charge['amount_refunded'] ) ? (int) $charge['amount_refunded'] : 0;
		if ( ! $refunded && $amount > 0 && $refunded_amount < $amount ) {
			return true; // Partial — ignore for MVP.
		}

		$pi = isset( $charge['payment_intent'] ) ? (string) $charge['payment_intent'] : '';
		$payment = null;
		if ( $pi ) {
			$payment = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM ' . Schema::table( 'payments' ) . ' WHERE external_id = %s ORDER BY id DESC LIMIT 1',
					$pi
				),
				ARRAY_A
			);
		}
		if ( ! $payment && ! empty( $charge['metadata']['payment_id'] ) ) {
			$payment = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM ' . Schema::table( 'payments' ) . ' WHERE id = %d',
					absint( $charge['metadata']['payment_id'] )
				),
				ARRAY_A
			);
		}
		if ( ! $payment ) {
			return true; // Not our charge.
		}

		$result = Payment_Service::mark_refunded(
			(int) $payment['booking_id'],
			array(
				'source'   => 'stripe_webhook',
				'actor_id' => 0,
			)
		);
		if ( ! is_wp_error( $result ) && $event_id ) {
			Payment_Service::record_event(
				(int) $payment['id'],
				'stripe_refunded',
				array( 'event_id' => $event_id ),
				'stripe_' . $event_id
			);
		}
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * @param array $session Session.
	 * @return array|null
	 */
	private static function find_payment_from_session( array $session ) {
		global $wpdb;

		$token = '';
		if ( ! empty( $session['client_reference_id'] ) ) {
			$token = (string) $session['client_reference_id'];
		} elseif ( ! empty( $session['metadata']['prime_fleet_token'] ) ) {
			$token = (string) $session['metadata']['prime_fleet_token'];
		}
		if ( $token ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM ' . Schema::table( 'payments' ) . ' WHERE token = %s LIMIT 1',
					$token
				),
				ARRAY_A
			);
			if ( $row ) {
				return $row;
			}
		}

		if ( ! empty( $session['metadata']['payment_id'] ) ) {
			return $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM ' . Schema::table( 'payments' ) . ' WHERE id = %d',
					absint( $session['metadata']['payment_id'] )
				),
				ARRAY_A
			);
		}

		if ( ! empty( $session['id'] ) ) {
			return $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM ' . Schema::table( 'payments' ) . ' WHERE external_id = %s ORDER BY id DESC LIMIT 1',
					(string) $session['id']
				),
				ARRAY_A
			);
		}

		return null;
	}

	/**
	 * When guest returns from Checkout with ?sle_paid=1, pull session status
	 * so local sites without webhooks still mark Paid.
	 *
	 * @param array $payment Payment row.
	 * @return bool|\WP_Error True if marked paid / already paid.
	 */
	public static function reconcile_return( array $payment ) {
		if ( in_array( (string) $payment['status'], array( 'paid', 'refunded' ), true ) ) {
			return true;
		}

		$session_id = (string) ( $payment['external_id'] ?? '' );
		// Prefer Checkout Session ids; fall back to parsing hosted Checkout URL.
		if ( 0 !== strpos( $session_id, 'cs_' ) ) {
			$session_id = '';
			if ( ! empty( $payment['custom_url'] ) && preg_match( '#(cs_(?:test_|live_)[A-Za-z0-9]+)#', (string) $payment['custom_url'], $m ) ) {
				$session_id = $m[1];
			}
		}
		if ( '' === $session_id ) {
			return false;
		}

		$session = self::api_get( '/checkout/sessions/' . rawurlencode( $session_id ) );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$pay_status = isset( $session['payment_status'] ) ? (string) $session['payment_status'] : '';
		if ( 'paid' !== $pay_status && 'no_payment_required' !== $pay_status ) {
			return false;
		}

		return self::handle_session_paid( $session, 'return_' . $session_id );
	}

	/**
	 * @param string $path Path starting with /.
	 * @return array|\WP_Error
	 */
	private static function api_get( $path ) {
		$secret = self::secret_key();
		if ( '' === $secret ) {
			return new \WP_Error( 'prime_fleet_stripe_secret', __( 'Stripe secret key is not configured.', 'prime-fleet' ) );
		}
		$res = wp_remote_get(
			self::API . $path,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $secret,
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$raw  = (string) wp_remote_retrieve_body( $res );
		$data = json_decode( $raw, true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $data ) && ! empty( $data['error']['message'] )
				? (string) $data['error']['message']
				: __( 'Stripe API request failed.', 'prime-fleet' );
			return new \WP_Error( 'prime_fleet_stripe_api', $msg, array( 'status' => $code ?: 502 ) );
		}
		return is_array( $data ) ? $data : array();
	}

	/**
	 * @param string $path Path starting with /.
	 * @param array  $body Form body.
	 * @return array|\WP_Error
	 */
	private static function api_post( $path, array $body ) {
		$secret = self::secret_key();
		$res    = wp_remote_post(
			self::API . $path,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $secret,
				),
				'body'    => $body,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$raw  = (string) wp_remote_retrieve_body( $res );
		$data = json_decode( $raw, true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $data ) && ! empty( $data['error']['message'] )
				? (string) $data['error']['message']
				: __( 'Stripe API request failed.', 'prime-fleet' );
			return new \WP_Error( 'prime_fleet_stripe_api', $msg, array( 'status' => $code ?: 502 ) );
		}
		return is_array( $data ) ? $data : array();
	}
}
