<?php
/**
 * Public REST API (prime-fleet/v1).
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\REST;

use Prime_Fleet\Bookings\Creator;
use Prime_Fleet\Bookings\Coupons;
use Prime_Fleet\Bookings\Lookup;
use Prime_Fleet\Bookings\Occupancy;
use Prime_Fleet\Bookings\Slot_Offers;
use Prime_Fleet\Bookings\Submit_Token;
use Prime_Fleet\Install\Capabilities;
use Prime_Fleet\Pricing\Engine;
use Prime_Fleet\Payments\Payment_Service;
use Prime_Fleet\Payments\Stripe_Gateway;
use Prime_Fleet\Services\Config_Compiler;

defined( 'ABSPATH' ) || exit;

final class Controller {

	const NAMESPACE = 'prime-fleet/v1';

	/**
	 * Hooks.
	 */
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register public routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/config',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_config' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/quote',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'post_quote' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'service_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/booking-token',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'post_booking_token' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/bookings',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'post_bookings' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/availability',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_availability' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/coupons/validate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'post_coupon_validate' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/lookup',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'post_lookup' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/offers/(?P<token>[a-f0-9]{20,64})',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_offer' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'post_offer_accept' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/pay/(?P<token>[a-f0-9]{20,64})',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_pay' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/pay/(?P<token>[a-f0-9]{20,64})/ack',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'post_pay_ack' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/bookings/(?P<id>\d+)/payment',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_booking_payment' ),
				'permission_callback' => array( $this, 'permission_staff' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/bookings/(?P<id>\d+)/payment/issue',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'post_booking_payment_issue' ),
				'permission_callback' => array( $this, 'permission_staff' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/webhooks/stripe',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'post_stripe_webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Staff / dispatcher capability for private payment routes.
	 *
	 * @return bool|\WP_Error
	 */
	public function permission_staff() {
		if ( Capabilities::current_user_can( Capabilities::DISPATCH ) ) {
			return true;
		}
		$status = is_user_logged_in() ? 403 : 401;
		return new \WP_Error(
			'prime_fleet_forbidden',
			__( 'Authentication required.', 'prime-fleet' ),
			array( 'status' => $status )
		);
	}

	/**
	 * GET /config
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_config( $request ) {
		$limit = Rate_Limiter::allow_configured( 'config' );
		if ( is_wp_error( $limit ) ) {
			return $this->error_response( $limit );
		}

		$config   = Config_Compiler::get();
		$response = rest_ensure_response( $config );
		$response->set_headers(
			array(
				'Cache-Control' => 'public, max-age=60',
				'X-Prime-Fleet'     => PRIME_FLEET_VERSION,
			)
		);
		return $response;
	}

	/**
	 * POST /quote — server recalculates; ignores client totals.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_quote( $request ) {
		$limit = Rate_Limiter::allow_configured( 'quote' );
		if ( is_wp_error( $limit ) ) {
			return $this->error_response( $limit );
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$input = self::sanitize_quote_input( is_array( $params ) ? $params : array() );
		unset( $input['total'], $input['price'], $input['amount'], $input['fare'] );

		$result = Engine::quote( $input );
		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}

		if ( ! empty( $input['pickup_date'] ) && ! empty( $input['pickup_time'] ) ) {
			$dt = $input['pickup_date'] . ' ' . substr( $input['pickup_time'], 0, 5 ) . ':00';
			$result['availability'] = Occupancy::check(
				$dt,
				array(
					'vehicle_id' => absint( $params['vehicle_id'] ?? 0 ),
					'hours'      => $input['hours'],
				)
			);
		}

		do_action( 'prime_fleet_quote_calculated', $result, $input );

		return rest_ensure_response( $result );
	}

	/**
	 * GET /availability
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_availability( $request ) {
		$limit = Rate_Limiter::allow( 'availability', 90, 60 );
		if ( is_wp_error( $limit ) ) {
			return $this->error_response( $limit );
		}

		$date = sanitize_text_field( (string) $request->get_param( 'pickup_date' ) );
		$time = sanitize_text_field( (string) $request->get_param( 'pickup_time' ) );
		$dt   = sanitize_text_field( (string) $request->get_param( 'pickup_datetime' ) );
		if ( $dt ) {
			$pickup = $dt;
		} elseif ( $date && $time ) {
			$pickup = $date . ' ' . substr( $time, 0, 5 ) . ':00';
		} else {
			return $this->error_response(
				new \WP_Error( 'prime_fleet_datetime', __( 'Pickup date and time are required.', 'prime-fleet' ), array( 'status' => 400 ) )
			);
		}

		$result = Occupancy::check(
			$pickup,
			array(
				'vehicle_id' => absint( $request->get_param( 'vehicle_id' ) ),
				'hours'      => (float) $request->get_param( 'hours' ),
			)
		);
		$response = rest_ensure_response( $result );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * POST /coupons/validate
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_coupon_validate( $request ) {
		$limit = Rate_Limiter::allow_configured( 'coupon' );
		if ( is_wp_error( $limit ) ) {
			return $this->error_response( $limit );
		}
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		/* Never trust client fare_total — re-quote from trip fields. */
		$fare  = 0.0;
		$input = self::sanitize_quote_input( is_array( $params ) ? $params : array() );
		unset( $input['total'], $input['price'], $input['amount'], $input['fare'], $input['fare_total'] );
		if ( ! empty( $input['service_id'] ) ) {
			$quote = Engine::quote( $input );
			if ( ! is_wp_error( $quote ) && empty( $quote['manual_quote'] ) && isset( $quote['total'] ) ) {
				$fare = (float) $quote['total'];
			}
		}

		$result = Coupons::validate(
			isset( $params['code'] ) ? (string) $params['code'] : '',
			array(
				'service_id' => absint( $params['service_id'] ?? $input['service_id'] ?? 0 ),
				'fare_total' => $fare,
				'email'      => isset( $params['email'] ) ? (string) $params['email'] : '',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}
		return rest_ensure_response( $result );
	}

	/**
	 * POST /lookup
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_lookup( $request ) {
		$limit = Rate_Limiter::allow_configured( 'lookup' );
		if ( is_wp_error( $limit ) ) {
			return $this->error_response( $limit );
		}
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}
		$result = Lookup::find(
			isset( $params['reference'] ) ? (string) $params['reference'] : '',
			isset( $params['email'] ) ? (string) $params['email'] : ''
		);
		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}
		$response = rest_ensure_response( $result );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * GET /pay/{token} — guest payment summary.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_pay( $request ) {
		$limit = Rate_Limiter::allow( 'pay_get', 60, 60 );
		if ( is_wp_error( $limit ) ) {
			return $this->error_response( $limit );
		}
		$result = Payment_Service::public_summary( (string) $request['token'] );
		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}
		$response = rest_ensure_response( $result );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * POST /pay/{token}/ack — guest “I’ve paid” for instructions.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_pay_ack( $request ) {
		$limit = Rate_Limiter::allow( 'pay_ack', 20, 60 );
		if ( is_wp_error( $limit ) ) {
			return $this->error_response( $limit );
		}
		$result = Payment_Service::acknowledge_paid( (string) $request['token'] );
		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}
		$response = rest_ensure_response(
			array(
				'status'    => $result['status'],
				'reference' => $result['reference'],
				'already'   => ! empty( $result['already'] ),
				'message'   => __( 'Thanks — Prime Fleet will verify your payment shortly.', 'prime-fleet' ),
			)
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * GET /bookings/{id}/payment — staff snapshot.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_booking_payment( $request ) {
		$result = Payment_Service::staff_snapshot( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}
		$response = rest_ensure_response( $result );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * POST /bookings/{id}/payment/issue — staff issue / re-send.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_booking_payment_issue( $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}
		$method = isset( $params['method_key'] ) ? (string) $params['method_key'] : '';
		$args   = array(
			'actor_id' => get_current_user_id(),
			'notify'   => ! isset( $params['notify'] ) || ! empty( $params['notify'] ),
		);
		if ( ! empty( $params['custom_url'] ) ) {
			$args['custom_url'] = (string) $params['custom_url'];
		}
		if ( ! empty( $params['force_new'] ) ) {
			$args['force_new'] = true;
		}
		$result = Payment_Service::issue( (int) $request['id'], $method, $args );
		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}
		$response = rest_ensure_response(
			array(
				'guest_url'  => $result['guest_url'],
				'reused'     => ! empty( $result['reused'] ),
				'payment_id' => (int) $result['payment']['id'],
				'status'     => (string) $result['payment']['status'],
			)
		);
		$response->set_status( ! empty( $result['reused'] ) ? 200 : 201 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * GET /offers/{token}
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_offer( $request ) {
		$limit = Rate_Limiter::allow( 'offer_get', 60, 60 );
		if ( is_wp_error( $limit ) ) {
			return $this->error_response( $limit );
		}
		$result = Slot_Offers::get_by_token( (string) $request['token'] );
		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}
		$response = rest_ensure_response( $result );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * POST /offers/{token}/accept — also accepts POST to /offers/{token} with action=accept.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_offer_accept( $request ) {
		$limit = Rate_Limiter::allow( 'offer_accept', 20, 60 );
		if ( is_wp_error( $limit ) ) {
			return $this->error_response( $limit );
		}
		$result = Slot_Offers::accept( (string) $request['token'] );
		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}
		$response = rest_ensure_response( $result );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * POST /booking-token — short-lived one-time submit token.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_booking_token( $request ) {
		$result = Submit_Token::issue();
		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}
		$response = rest_ensure_response( $result );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * POST /bookings — validate, calc, save, return PF- ref (mail after).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_bookings( $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		// Prefer header for idempotency when present.
		$header_key = $request->get_header( 'x-idempotency-key' );
		if ( $header_key && empty( $params['idempotency_key'] ) ) {
			$params['idempotency_key'] = $header_key;
		}

		$result = Creator::create_from_request( $params );
		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}

		// Never expose internal ids in public payload beyond what's needed.
		$public = array(
			'reference'    => $result['reference'],
			'status'       => $result['status'],
			'manual_quote' => ! empty( $result['manual_quote'] ),
			'fare_total'   => array_key_exists( 'fare_total', $result ) ? $result['fare_total'] : null,
			'currency'     => isset( $result['currency'] ) ? $result['currency'] : 'USD',
			'message'      => isset( $result['message'] ) ? $result['message'] : '',
		);

		$response = rest_ensure_response( $public );
		$response->set_status( ! empty( $result['idempotent'] ) ? 200 : 201 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * @param array $raw Raw.
	 * @return array
	 */
	public static function sanitize_quote_input( array $raw ) {
		$addons = array();
		if ( ! empty( $raw['addons'] ) && is_array( $raw['addons'] ) ) {
			foreach ( $raw['addons'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$addons[] = array(
					'id'  => absint( $row['id'] ?? 0 ),
					'qty' => absint( $row['qty'] ?? 1 ),
				);
			}
		}

		$trip = sanitize_key( (string) ( $raw['trip_type'] ?? 'one_way' ) );
		if ( ! in_array( $trip, array( 'one_way', 'round_trip' ), true ) ) {
			$trip = 'one_way';
		}

		return array(
			'service_id'     => absint( $raw['service_id'] ?? 0 ),
			'trip_type'      => $trip,
			'origin_zone_id' => absint( $raw['origin_zone_id'] ?? 0 ),
			'dest_zone_id'   => absint( $raw['dest_zone_id'] ?? 0 ),
			'hours'          => isset( $raw['hours'] ) ? (float) $raw['hours'] : 0,
			'package_id'     => absint( $raw['package_id'] ?? 0 ),
			'addons'         => $addons,
			'passengers'     => absint( $raw['passengers'] ?? 0 ),
			'checked_bags'   => absint( $raw['checked_bags'] ?? 0 ),
			'carry_on_bags'  => absint( $raw['carry_on_bags'] ?? 0 ),
			'pickup_date'    => sanitize_text_field( (string) ( $raw['pickup_date'] ?? '' ) ),
			'pickup_time'    => sanitize_text_field( (string) ( $raw['pickup_time'] ?? '' ) ),
		);
	}

	/**
	 * POST /webhooks/stripe — Stripe signed webhook.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_stripe_webhook( $request ) {
		$limit = Rate_Limiter::allow( 'stripe_webhook', 180, 60 );
		if ( is_wp_error( $limit ) ) {
			return $this->error_response( $limit );
		}

		$payload = $request->get_body();
		$header  = (string) $request->get_header( 'stripe_signature' );
		if ( '' === $header ) {
			$header = (string) $request->get_header( 'Stripe-Signature' );
		}

		$event = Stripe_Gateway::construct_event( $payload, $header );
		if ( is_wp_error( $event ) ) {
			return $this->error_response( $event );
		}

		$result = Stripe_Gateway::handle_event( $event );
		if ( is_wp_error( $result ) ) {
			// 404 payment mismatch should not retry forever — acknowledge with 200 after logging.
			if ( 'prime_fleet_stripe_payment' === $result->get_error_code() ) {
				return rest_ensure_response(
					array(
						'received' => true,
						'ignored'  => $result->get_error_message(),
					)
				);
			}
			return $this->error_response( $result );
		}

		return rest_ensure_response( array( 'received' => true ) );
	}

	/**
	 * @param \WP_Error $error Error.
	 * @return \WP_REST_Response
	 */
	private function error_response( $error ) {
		$status = 400;
		$data   = $error->get_error_data();
		if ( is_array( $data ) && isset( $data['status'] ) ) {
			$status = (int) $data['status'];
		}
		$payload = array(
			'code'    => $error->get_error_code(),
			'message' => $error->get_error_message(),
			'data'    => array( 'status' => $status ),
		);
		if ( 429 === $status && is_array( $data ) && isset( $data['retry_after'] ) ) {
			$payload['data']['retry_after'] = (int) $data['retry_after'];
		}
		$response = new \WP_REST_Response( $payload, $status );
		if ( 429 === $status && isset( $payload['data']['retry_after'] ) ) {
			$response->header( 'Retry-After', (string) $payload['data']['retry_after'] );
		}
		return $response;
	}
}
