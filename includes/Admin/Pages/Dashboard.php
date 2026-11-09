<?php
/**
 * Admin dashboard — Overview panel.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Admin\Pages;

use Prime_Fleet\Admin\View;
use Prime_Fleet\Database\Schema;
use Prime_Fleet\Install\Capabilities;

defined( 'ABSPATH' ) || exit;

final class Dashboard {

	public static function render() {
		if ( ! Capabilities::current_user_can( Capabilities::DISPATCH ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'prime-fleet' ) );
		}

		global $wpdb;
		$bookings = Schema::table( 'bookings' );
		$comms    = Schema::table( 'communications' );
		$services = Schema::table( 'services' );
		$zones    = Schema::table( 'zones' );
		$vehicles = Schema::table( 'vehicles' );
		$exists   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bookings ) );

		$pending = $today = $unpaid = $failed = 0;
		if ( $exists ) {
			$pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$bookings} WHERE status = 'pending_confirmation'" );
			$today   = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$bookings} WHERE DATE(pickup_datetime) = %s AND status NOT IN ('cancelled')",
					current_time( 'Y-m-d' )
				)
			);
			$unpaid = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$bookings} WHERE payment_status IN ('unpaid','link_sent','pending_verification')" );
			$failed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$comms} WHERE status = 'failed'" );
		}

		$svc_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$services}" );
		$zone_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$zones}" );
		$fleet_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$vehicles} WHERE is_active = 1" );

		ob_start();
		?>
		<section class="prime-fleet-hero">
			<div class="prime-fleet-hero__copy">
				<p class="prime-fleet-hero__kicker"><?php esc_html_e( 'Prime Fleet', 'prime-fleet' ); ?></p>
				<h3><?php esc_html_e( 'Luxury ground transport, managed in one place', 'prime-fleet' ); ?></h3>
			</div>
			<div class="prime-fleet-hero__actions">
				<?php
				echo View::panel_button( __( 'Configure services', 'prime-fleet' ), 'services' );
				echo View::panel_button( __( 'Pricing matrix', 'prime-fleet' ), 'pricing', 'ghost' );
				?>
			</div>
		</section>

		<div class="prime-fleet-stats">
			<article class="prime-fleet-stat">
				<span class="prime-fleet-stat__label"><?php esc_html_e( 'Pending confirmation', 'prime-fleet' ); ?></span>
				<strong class="prime-fleet-stat__value"><?php echo (int) $pending; ?></strong>
				<span class="prime-fleet-stat__hint"><?php esc_html_e( 'Awaiting staff review', 'prime-fleet' ); ?></span>
			</article>
			<article class="prime-fleet-stat">
				<span class="prime-fleet-stat__label"><?php esc_html_e( "Today's jobs", 'prime-fleet' ); ?></span>
				<strong class="prime-fleet-stat__value"><?php echo (int) $today; ?></strong>
				<span class="prime-fleet-stat__hint"><?php echo esc_html( wp_date( 'l, M j' ) ); ?></span>
			</article>
			<article class="prime-fleet-stat">
				<span class="prime-fleet-stat__label"><?php esc_html_e( 'Unpaid / awaiting', 'prime-fleet' ); ?></span>
				<strong class="prime-fleet-stat__value"><?php echo (int) $unpaid; ?></strong>
				<span class="prime-fleet-stat__hint"><?php esc_html_e( 'Payment follow-up', 'prime-fleet' ); ?></span>
			</article>
			<article class="prime-fleet-stat">
				<span class="prime-fleet-stat__label"><?php esc_html_e( 'Failed messages', 'prime-fleet' ); ?></span>
				<strong class="prime-fleet-stat__value"><?php echo (int) $failed; ?></strong>
				<span class="prime-fleet-stat__hint"><?php esc_html_e( 'Email / WhatsApp queue', 'prime-fleet' ); ?></span>
			</article>
		</div>

		<div class="prime-fleet-split">
			<section class="prime-fleet-card prime-fleet-card--flush prime-fleet-card--equal">
				<div class="prime-fleet-card__pad">
					<h3><?php esc_html_e( 'Foundation checklist', 'prime-fleet' ); ?></h3>
					<p class="prime-fleet-help"><?php esc_html_e( 'Core catalog pieces that power booking and pricing.', 'prime-fleet' ); ?></p>
				</div>
				<ul class="prime-fleet-checklist">
					<li class="is-done">
						<span><?php esc_html_e( 'Services seeded', 'prime-fleet' ); ?></span>
						<em><?php echo (int) $svc_count; ?></em>
						<?php echo View::panel_button( __( 'Open', 'prime-fleet' ), 'services', 'ghost' ); ?>
					</li>
					<li class="is-done">
						<span><?php esc_html_e( 'Zones ready', 'prime-fleet' ); ?></span>
						<em><?php echo (int) $zone_count; ?></em>
						<?php echo View::panel_button( __( 'Open', 'prime-fleet' ), 'zones', 'ghost' ); ?>
					</li>
					<li class="is-done">
						<span><?php esc_html_e( 'Fleet registered', 'prime-fleet' ); ?></span>
						<em><?php echo (int) $fleet_count; ?></em>
						<?php echo View::panel_button( __( 'Open', 'prime-fleet' ), 'vehicles', 'ghost' ); ?>
					</li>
					<li>
						<span><?php esc_html_e( 'Payment methods', 'prime-fleet' ); ?></span>
						<em><?php esc_html_e( 'Review', 'prime-fleet' ); ?></em>
						<?php echo View::panel_button( __( 'Open', 'prime-fleet' ), 'payments', 'ghost' ); ?>
					</li>
				</ul>
			</section>

			<section class="prime-fleet-card prime-fleet-card--equal">
				<div class="prime-fleet-card__pad" style="padding:0 0 12px">
					<h3><?php esc_html_e( 'Jump to', 'prime-fleet' ); ?></h3>
					<p class="prime-fleet-help"><?php esc_html_e( 'Frequent configuration shortcuts.', 'prime-fleet' ); ?></p>
				</div>
				<div class="prime-fleet-jump">
					<?php
					echo View::panel_button( __( 'Airport VIP form', 'prime-fleet' ), 'service_edit', 'ghost', array( 'service_id' => self::airport_id() ) );
					echo View::panel_button( __( 'Global settings', 'prime-fleet' ), 'settings', 'ghost' );
					echo View::panel_button( __( 'Add-ons & Meet & Greet', 'prime-fleet' ), 'addons', 'ghost' );
					echo View::panel_button( __( 'Nightlife / holiday rules', 'prime-fleet' ), 'pricing', 'ghost', array( 'tab' => 'rules' ) );
					?>
				</div>
			</section>
		</div>
		<?php
		View::shell( __( 'Overview', 'prime-fleet' ), ob_get_clean() );
	}

	/**
	 * @return int
	 */
	private static function airport_id() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT id FROM " . Schema::table( 'services' ) . " WHERE slug = 'airport-vip-transfer' LIMIT 1" );
	}
}
