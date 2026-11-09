<?php
/**
 * Coming-soon placeholder panels.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Admin\Pages;

use Prime_Fleet\Admin\View;
use Prime_Fleet\Install\Capabilities;

defined( 'ABSPATH' ) || exit;

final class Placeholder {

	public static function bookings() {
		self::page(
			__( 'Bookings', 'prime-fleet' ),
			__( 'Bookings coming soon. The queue and booking detail screens will appear here.', 'prime-fleet' ),
			'bookings'
		);
	}

	public static function dispatch() {
		self::page(
			__( 'Dispatch', 'prime-fleet' ),
			__( 'Dispatch coming soon. Assignments, conflicts, and the day board will appear here.', 'prime-fleet' ),
			'dispatch'
		);
	}

	public static function slot_offers() {
		self::page(
			__( 'Slot Offers', 'prime-fleet' ),
			__( 'Slot offers coming soon.', 'prime-fleet' ),
			'slots'
		);
	}

	public static function templates() {
		self::page(
			__( 'Messages', 'prime-fleet' ),
			__( 'Message editor coming soon. Templates are already seeded in the database.', 'prime-fleet' ),
			'messages',
			Capabilities::MANAGE
		);
	}

	public static function reports() {
		self::page(
			__( 'Reports', 'prime-fleet' ),
			__( 'Reports coming soon.', 'prime-fleet' ),
			'reports'
		);
	}

	/**
	 * @param string $title Title.
	 * @param string $text Text.
	 * @param string $icon Icon key.
	 * @param string $cap Cap.
	 */
	private static function page( $title, $text, $icon = 'box', $cap = Capabilities::DISPATCH ) {
		if ( ! Capabilities::current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'prime-fleet' ) );
		}

		$html  = '<div class="prime-fleet-card prime-fleet-coming">';
		$html .= '<div class="prime-fleet-coming__icon prime-fleet-coming__icon--' . esc_attr( $icon ) . '" aria-hidden="true">' . self::svg( $icon ) . '</div>';
		$html .= '<h3>' . esc_html( $title ) . '</h3>';
		$html .= '<p>' . esc_html( $text ) . '</p>';
		$html .= '</div>';

		View::shell( $title, $html );
	}

	/**
	 * @param string $icon Icon key.
	 * @return string
	 */
	private static function svg( $icon ) {
		$paths = array(
			'bookings' => '<path d="M6 4h12a2 2 0 0 1 2 2v14l-4-2-4 2-4-2-4 2V6a2 2 0 0 1 2-2z"/><path d="M9 9h6M9 13h6"/>',
			'dispatch' => '<path d="M4 7h16v10H4z"/><path d="M8 17v2M16 17v2M7 10h3M14 10h3M7 13h10"/>',
			'slots'    => '<circle cx="12" cy="12" r="8"/><path d="M12 8v5l3 2"/>',
			'messages' => '<path d="M4 6h16v10H8l-4 3V6z"/>',
			'reports'  => '<path d="M5 19V9M10 19V5M15 19v-7M20 19V8"/>',
			'box'      => '<path d="M4 8l8-4 8 4v8l-8 4-8-4V8z"/><path d="M12 12v8M4 8l8 4 8-4"/>',
		);
		$d = isset( $paths[ $icon ] ) ? $paths[ $icon ] : $paths['box'];
		return '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $d . '</svg>';
	}
}
