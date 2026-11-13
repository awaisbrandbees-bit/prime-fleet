<?php
/**
 * System log writer (Logs admin panel).
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Logging;

use Prime_Fleet\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class Logger {

	/**
	 * @param string               $level   error|warning|info|debug.
	 * @param string               $context Short context key.
	 * @param string               $message Human message.
	 * @param array<string,mixed>  $meta    Optional meta (JSON).
	 */
	public static function log( $level, $context, $message, array $meta = array() ) {
		global $wpdb;

		$level   = sanitize_key( (string) $level );
		$allowed = array( 'error', 'warning', 'info', 'debug' );
		if ( ! in_array( $level, $allowed, true ) ) {
			$level = 'info';
		}

		$context = sanitize_key( (string) $context );
		if ( strlen( $context ) > 64 ) {
			$context = substr( $context, 0, 64 );
		}

		$message = wp_strip_all_tags( (string) $message );
		if ( strlen( $message ) > 2000 ) {
			$message = substr( $message, 0, 2000 );
		}

		$wpdb->insert(
			Schema::table( 'system_logs' ),
			array(
				'level'     => $level,
				'context'   => $context ? $context : 'general',
				'message'   => $message,
				'meta_json' => $meta ? wp_json_encode( $meta ) : null,
			),
			array( '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * @param string              $context Context.
	 * @param string              $message Message.
	 * @param array<string,mixed> $meta    Meta.
	 */
	public static function warning( $context, $message, array $meta = array() ) {
		self::log( 'warning', $context, $message, $meta );
	}

	/**
	 * @param string              $context Context.
	 * @param string              $message Message.
	 * @param array<string,mixed> $meta    Meta.
	 */
	public static function info( $context, $message, array $meta = array() ) {
		self::log( 'info', $context, $message, $meta );
	}
}
