<?php
/**
 * Payment method contract.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Payments\Contracts;

defined( 'ABSPATH' ) || exit;

interface Payment_Method_Interface {

	/** @return string */
	public function id();

	/** @return string */
	public function label();

	/** @return bool */
	public function supports_link();

	/** @return bool */
	public function supports_hosted();

	/** @return bool */
	public function supports_instructions();

	/** @return bool */
	public function is_enabled();

	/**
	 * Admin settings schema (key => field def).
	 *
	 * @return array
	 */
	public function settings_schema();

	/**
	 * Persist settings from admin form.
	 *
	 * @param array $input Raw input.
	 */
	public function save_settings( array $input );

	/**
	 * @return array
	 */
	public function get_settings();
}
