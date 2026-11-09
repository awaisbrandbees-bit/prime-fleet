<?php
/**
 * Authorize.net method — registry stub until client credentials / go-live.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Payments\Methods;

defined( 'ABSPATH' ) || exit;

final class Authorize_Net_Method extends Abstract_Method {

	public function id() {
		return 'authorize_net';
	}

	public function label() {
		return __( 'Authorize.net', 'prime-fleet' );
	}

	public function supports_link() {
		return false;
	}

	public function supports_hosted() {
		return true;
	}

	public function supports_instructions() {
		return false;
	}

	public function settings_schema() {
		return array(
			'mode'              => array(
				'type'    => 'select',
				'label'   => __( 'Mode', 'prime-fleet' ),
				'options' => array(
					'sandbox' => __( 'Sandbox', 'prime-fleet' ),
					'live'    => __( 'Live', 'prime-fleet' ),
				),
			),
			'api_login_id'      => array(
				'type'  => 'text',
				'label' => __( 'API Login ID', 'prime-fleet' ),
			),
			'transaction_key'   => array(
				'type'  => 'password',
				'label' => __( 'Transaction Key', 'prime-fleet' ),
			),
			'signature_key'     => array(
				'type'  => 'password',
				'label' => __( 'Signature Key', 'prime-fleet' ),
			),
		);
	}
}
