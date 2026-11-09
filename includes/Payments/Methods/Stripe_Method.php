<?php
/**
 * Stripe method — Checkout + webhook when credentials exist.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Payments\Methods;

defined( 'ABSPATH' ) || exit;

final class Stripe_Method extends Abstract_Method {

	public function id() {
		return 'stripe';
	}

	public function label() {
		return __( 'Stripe', 'prime-fleet' );
	}

	public function supports_link() {
		return true;
	}

	public function supports_hosted() {
		return true;
	}

	public function supports_instructions() {
		return false;
	}

	public function settings_schema() {
		return array(
			'mode'             => array(
				'type'    => 'select',
				'label'   => __( 'Mode', 'prime-fleet' ),
				'options' => array(
					'test' => __( 'Test', 'prime-fleet' ),
					'live' => __( 'Live', 'prime-fleet' ),
				),
			),
			'test_publishable' => array(
				'type'  => 'text',
				'label' => __( 'Test publishable key', 'prime-fleet' ),
			),
			'test_secret'      => array(
				'type'  => 'password',
				'label' => __( 'Test secret key (or PRIME_FLEET_STRIPE_SECRET_KEY)', 'prime-fleet' ),
			),
			'live_publishable' => array(
				'type'  => 'text',
				'label' => __( 'Live publishable key', 'prime-fleet' ),
			),
			'live_secret'      => array(
				'type'  => 'password',
				'label' => __( 'Live secret key', 'prime-fleet' ),
			),
			'webhook_secret'   => array(
				'type'  => 'password',
				'label' => __( 'Webhook signing secret (or PRIME_FLEET_STRIPE_WEBHOOK_SECRET)', 'prime-fleet' ),
			),
		);
	}
}
