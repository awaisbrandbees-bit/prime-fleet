<?php
/**
 * Admin AJAX panel router.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Admin;

use Prime_Fleet\Admin\Pages\Dashboard;
use Prime_Fleet\Admin\Pages\Bookings;
use Prime_Fleet\Admin\Pages\Dispatch;
use Prime_Fleet\Admin\Pages\Run_Sheet;
use Prime_Fleet\Admin\Pages\Services;
use Prime_Fleet\Admin\Pages\Service_Edit;
use Prime_Fleet\Admin\Pages\Zones;
use Prime_Fleet\Admin\Pages\Fields;
use Prime_Fleet\Admin\Pages\Pricing;
use Prime_Fleet\Admin\Pages\Vehicles;
use Prime_Fleet\Admin\Pages\Chauffeurs;
use Prime_Fleet\Admin\Pages\Addons;
use Prime_Fleet\Admin\Pages\Surcharges;
use Prime_Fleet\Admin\Pages\Availability;
use Prime_Fleet\Admin\Pages\Coupons;
use Prime_Fleet\Admin\Pages\Payments;
use Prime_Fleet\Admin\Pages\Settings;
use Prime_Fleet\Admin\Pages\Logs;
use Prime_Fleet\Admin\Pages\Messages;
use Prime_Fleet\Admin\Pages\Slot_Offers_Page;
use Prime_Fleet\Admin\Pages\Placeholder;
use Prime_Fleet\Admin\Pages\Reports;
use Prime_Fleet\Install\Capabilities;

defined( 'ABSPATH' ) || exit;

final class Ajax {

	/**
	 * Hooks.
	 */
	public function hooks() {
		add_action( 'wp_ajax_prime_fleet_panel', array( $this, 'panel' ) );
		add_action( 'wp_ajax_prime_fleet_notifications', array( $this, 'notifications' ) );
	}

	/**
	 * Header notification feed (pending / payment attention).
	 */
	public function notifications() {
		if ( ! check_ajax_referer( 'prime_fleet_admin', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid session. Refresh and try again.', 'prime-fleet' ) ), 403 );
		}
		if ( ! Capabilities::current_user_can( Capabilities::DISPATCH ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'prime-fleet' ) ), 403 );
		}
		wp_send_json_success( Notifications::summary() );
	}

	/**
	 * Return panel HTML.
	 */
	public function panel() {
		if ( ! check_ajax_referer( 'prime_fleet_admin', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid session. Refresh and try again.', 'prime-fleet' ) ), 403 );
		}

		$panel = isset( $_REQUEST['panel'] ) ? sanitize_key( wp_unslash( $_REQUEST['panel'] ) ) : 'dashboard';
		$tabs  = App::tabs();

		if ( ! isset( $tabs[ $panel ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown panel.', 'prime-fleet' ) ), 404 );
		}

		if ( ! Capabilities::current_user_can( $tabs[ $panel ]['cap'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'prime-fleet' ) ), 403 );
		}

		// Embed mode: page classes echo body only (no second chrome).
		add_filter( 'prime_fleet_admin_embed', '__return_true' );

		// Map query args from AJAX into $_GET for editors that read them.
		if ( ! empty( $_REQUEST['query'] ) && is_array( $_REQUEST['query'] ) ) {
			foreach ( wp_unslash( $_REQUEST['query'] ) as $k => $v ) {
				$_GET[ sanitize_key( $k ) ] = sanitize_text_field( $v );
			}
		}

		// If this is a form POST for a panel, let the page handler run first.
		ob_start();
		self::dispatch( $panel );
		$html = ob_get_clean();

		$toast      = '';
		$toast_type = 'success';
		if ( preg_match( '/prime-fleet-notice--(success|error|info)[^"]*"[^>]*>(.*?)<\/div>/is', $html, $m ) ) {
			$toast_type = $m[1];
			$toast      = wp_strip_all_tags( $m[2] );
			$html       = preg_replace( '/<div class="prime-fleet-notice[^>]*>.*?<\/div>/is', '', $html, 1 );
		}

		wp_send_json_success(
			array(
				'panel'      => $panel,
				'html'       => $html,
				'title'      => $tabs[ $panel ]['label'],
				'toast'      => $toast,
				'toast_type' => $toast_type,
			)
		);
	}

	/**
	 * @param string $panel Panel key.
	 */
	public static function dispatch( $panel ) {
		switch ( $panel ) {
			case 'dashboard':
				Dashboard::render();
				break;
			case 'bookings':
				Bookings::render();
				break;
			case 'dispatch':
				Dispatch::render();
				break;
			case 'run_sheet':
				Run_Sheet::render();
				break;
			case 'services':
				Services::render();
				break;
			case 'service_edit':
				Service_Edit::render();
				break;
			case 'zones':
				Zones::render();
				break;
			case 'fields':
				Fields::render();
				break;
			case 'pricing':
				Pricing::render();
				break;
			case 'slot_offers':
				Slot_Offers_Page::render();
				break;
			case 'vehicles':
				Vehicles::render();
				break;
			case 'chauffeurs':
				Chauffeurs::render();
				break;
			case 'addons':
				Addons::render();
				break;
			case 'surcharges':
				Surcharges::render();
				break;
			case 'availability':
				Availability::render();
				break;
			case 'coupons':
				Coupons::render();
				break;
			case 'payments':
				Payments::render();
				break;
			case 'templates':
				Messages::render();
				break;
			case 'reports':
				Reports::render();
				break;
			case 'settings':
				Settings::render();
				break;
			case 'logs':
				Logs::render();
				break;
			default:
				echo '<div class="prime-fleet-empty"><h3>' . esc_html__( 'Coming soon', 'prime-fleet' ) . '</h3></div>';
		}
	}
}
