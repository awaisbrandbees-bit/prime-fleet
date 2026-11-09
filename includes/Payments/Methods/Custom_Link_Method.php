<?php
/**
 * Custom URL payment method.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Payments\Methods;

defined( 'ABSPATH' ) || exit;

final class Custom_Link_Method extends Abstract_Method {

	public function id() {
		return 'custom_link';
	}

	public function label() {
		return __( 'Custom payment link', 'prime-fleet' );
	}

	public function supports_link() {
		return true;
	}

	public function supports_hosted() {
		return false;
	}

	public function supports_instructions() {
		return true;
	}

	public function settings_schema() {
		return array(
			'default_url'  => array(
				'type'  => 'url',
				'label' => __( 'Default outbound URL', 'prime-fleet' ),
			),
			'instructions' => array(
				'type'  => 'textarea',
				'label' => __( 'Instructions shown on pay page', 'prime-fleet' ),
			),
		);
	}
}
