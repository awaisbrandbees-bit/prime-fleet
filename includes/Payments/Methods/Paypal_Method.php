<?php
/**
 * PayPal method — registry stub until client credentials / go-live.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Payments\Methods;

defined( 'ABSPATH' ) || exit;

final class Paypal_Method extends Abstract_Method {

	public function id() {
		return 'paypal';
	}

	public function label() {
		return __( 'PayPal', 'prime-fleet' );
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
			'mode'           => array(
				'type'    => 'select',
				'label'   => __( 'Mode', 'prime-fleet' ),
				'options' => array(
					'sandbox' => __( 'Sandbox', 'prime-fleet' ),
					'live'    => __( 'Live', 'prime-fleet' ),
				),
			),
			'client_id'      => array(
				'type'  => 'text',
				'label' => __( 'Client ID', 'prime-fleet' ),
			),
			'client_secret'  => array(
				'type'  => 'password',
				'label' => __( 'Client secret', 'prime-fleet' ),
			),
			'webhook_id'     => array(
				'type'  => 'text',
				'label' => __( 'Webhook ID', 'prime-fleet' ),
			),
		);
	}
}
