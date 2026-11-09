<?php
/**
 * WhatsApp channel contract.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Messaging\WhatsApp;

defined( 'ABSPATH' ) || exit;

interface Channel_Interface {

	/**
	 * @param string $to E.164-ish recipient.
	 * @param string $body Plain text body (stub) or rendered content.
	 * @param array  $context template_key, booking_id, vars, meta template id, etc.
	 * @return array{provider_id:string,status:string,preview:string}|\WP_Error
	 */
	public function send( $to, $body, array $context = array() );
}
