<?php
/**
 * SMTP configuration for wp_mail (Gmail / any SMTP).
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Messaging;

defined( 'ABSPATH' ) || exit;

final class Smtp {

	/**
	 * Hooks.
	 */
	public function hooks() {
		add_action( 'phpmailer_init', array( $this, 'configure' ) );
		add_filter( 'wp_mail_from', array( $this, 'from_address' ) );
		add_filter( 'wp_mail_from_name', array( $this, 'from_name' ) );
	}

	/**
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer Mailer.
	 */
	public function configure( $phpmailer ) {
		if ( ! defined( 'PRIME_FLEET_SMTP_HOST' ) || ! PRIME_FLEET_SMTP_HOST ) {
			return;
		}

		$phpmailer->isSMTP();
		$phpmailer->Host       = PRIME_FLEET_SMTP_HOST;
		$phpmailer->Port       = defined( 'PRIME_FLEET_SMTP_PORT' ) ? (int) PRIME_FLEET_SMTP_PORT : 587;
		$phpmailer->SMTPAuth   = true;
		$phpmailer->Username   = defined( 'PRIME_FLEET_SMTP_USER' ) ? (string) PRIME_FLEET_SMTP_USER : '';
		$phpmailer->Password   = defined( 'PRIME_FLEET_SMTP_PASS' ) ? (string) PRIME_FLEET_SMTP_PASS : '';
		$enc                   = defined( 'PRIME_FLEET_SMTP_ENCRYPTION' ) ? strtolower( (string) PRIME_FLEET_SMTP_ENCRYPTION ) : 'tls';
		$phpmailer->SMTPSecure = in_array( $enc, array( 'tls', 'ssl' ), true ) ? $enc : 'tls';
		$phpmailer->Timeout    = 20;
	}

	/**
	 * @param string $from From.
	 * @return string
	 */
	public function from_address( $from ) {
		if ( defined( 'PRIME_FLEET_MAIL_FROM' ) && is_email( PRIME_FLEET_MAIL_FROM ) ) {
			return PRIME_FLEET_MAIL_FROM;
		}
		return $from;
	}

	/**
	 * @param string $name Name.
	 * @return string
	 */
	public function from_name( $name ) {
		if ( defined( 'PRIME_FLEET_MAIL_FROM_NAME' ) && PRIME_FLEET_MAIL_FROM_NAME ) {
			return (string) PRIME_FLEET_MAIL_FROM_NAME;
		}
		return $name ? $name : 'Prime Fleet';
	}
}
