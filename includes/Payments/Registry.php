<?php
/**
 * Payment method registry.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Payments;

use Prime_Fleet\Payments\Contracts\Payment_Method_Interface;

defined( 'ABSPATH' ) || exit;

final class Registry {

	/** @var array<string,Payment_Method_Interface> */
	private $methods = array();

	/**
	 * @param Payment_Method_Interface $method Method.
	 */
	public function register( Payment_Method_Interface $method ) {
		$this->methods[ $method->id() ] = $method;
	}

	/**
	 * @return array<string,Payment_Method_Interface>
	 */
	public function all() {
		return $this->methods;
	}

	/**
	 * @param string $id Method id.
	 * @return Payment_Method_Interface|null
	 */
	public function get( $id ) {
		return isset( $this->methods[ $id ] ) ? $this->methods[ $id ] : null;
	}

	/**
	 * @return Payment_Method_Interface[]
	 */
	public function enabled() {
		return array_filter(
			$this->methods,
			static function ( Payment_Method_Interface $m ) {
				return $m->is_enabled();
			}
		);
	}
}
