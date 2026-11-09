<?php
/**
 * Instructions-only payment method.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Payments\Methods;

defined( 'ABSPATH' ) || exit;

final class Instructions_Method extends Abstract_Method {

	public function id() {
		return 'instructions';
	}

	public function label() {
		return __( 'Bank / instructions', 'prime-fleet' );
	}

	public function supports_link() {
		return false;
	}

	public function supports_hosted() {
		return false;
	}

	public function supports_instructions() {
		return true;
	}

	public function settings_schema() {
		return array(
			'instructions' => array(
				'type'  => 'textarea',
				'label' => __( 'Default payment instructions', 'prime-fleet' ),
			),
		);
	}
}
