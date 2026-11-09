<?php
/**
 * Single-page admin console (tab + AJAX shell).
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Admin;

use Prime_Fleet\Install\Capabilities;

defined( 'ABSPATH' ) || exit;

final class App {

	/**
	 * @return array<string,array{label:string,cap:string,more?:bool,hidden?:bool}>
	 */
	public static function tabs() {
		$manage   = Capabilities::MANAGE;
		$dispatch = Capabilities::DISPATCH;

		return array(
			'dashboard'    => array( 'label' => __( 'Overview', 'prime-fleet' ), 'cap' => $dispatch ),
			'bookings'     => array( 'label' => __( 'Bookings', 'prime-fleet' ), 'cap' => $dispatch ),
			'dispatch'     => array( 'label' => __( 'Dispatch', 'prime-fleet' ), 'cap' => $dispatch ),
			'services'     => array( 'label' => __( 'Services', 'prime-fleet' ), 'cap' => $manage ),
			'zones'        => array( 'label' => __( 'Locations', 'prime-fleet' ), 'cap' => $manage ),
			'pricing'      => array( 'label' => __( 'Pricing', 'prime-fleet' ), 'cap' => $manage ),
			'vehicles'     => array( 'label' => __( 'Vehicles', 'prime-fleet' ), 'cap' => $manage ),
			'chauffeurs'   => array( 'label' => __( 'Chauffeurs', 'prime-fleet' ), 'cap' => $manage ),
			'addons'       => array( 'label' => __( 'Add-ons', 'prime-fleet' ), 'cap' => $manage ),
			'payments'     => array( 'label' => __( 'Payments', 'prime-fleet' ), 'cap' => $manage ),
			'settings'     => array( 'label' => __( 'Settings', 'prime-fleet' ), 'cap' => $manage ),
			'fields'       => array( 'label' => __( 'Field library', 'prime-fleet' ), 'cap' => $manage, 'more' => true ),
			'coupons'      => array( 'label' => __( 'Coupons', 'prime-fleet' ), 'cap' => $manage, 'more' => true ),
			'surcharges'   => array( 'label' => __( 'Surcharges', 'prime-fleet' ), 'cap' => $manage, 'more' => true ),
			'availability' => array( 'label' => __( 'Availability', 'prime-fleet' ), 'cap' => $dispatch, 'more' => true ),
			'slot_offers'  => array( 'label' => __( 'Slot Offers', 'prime-fleet' ), 'cap' => $dispatch, 'more' => true ),
			'templates'    => array( 'label' => __( 'Messages', 'prime-fleet' ), 'cap' => $manage, 'more' => true ),
			'reports'      => array( 'label' => __( 'Reports', 'prime-fleet' ), 'cap' => $dispatch, 'more' => true ),
			'logs'         => array( 'label' => __( 'Logs', 'prime-fleet' ), 'cap' => $manage, 'more' => true ),
			'service_edit' => array( 'label' => __( 'Service editor', 'prime-fleet' ), 'cap' => $manage, 'hidden' => true ),
		);
	}

	/**
	 * Render full console shell.
	 */
	public static function render() {
		if ( ! Capabilities::current_user_can( Capabilities::DISPATCH ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'prime-fleet' ) );
		}

		$tabs    = self::visible_tabs();
		$initial = isset( $_GET['panel'] ) ? sanitize_key( wp_unslash( $_GET['panel'] ) ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $tabs[ $initial ] ) && 'service_edit' !== $initial ) {
			$initial = 'dashboard';
		}

		$primary = array();
		$more    = array();
		foreach ( $tabs as $key => $tab ) {
			if ( ! empty( $tab['more'] ) ) {
				$more[ $key ] = $tab;
			} else {
				$primary[ $key ] = $tab;
			}
		}
		?>
		<div class="wrap prime-fleet-app" id="prime-fleet-app" data-panel="<?php echo esc_attr( $initial ); ?>">
			<div class="prime-fleet-app__chrome">
				<header class="prime-fleet-app__top">
					<div class="prime-fleet-app__brand">
						<span class="prime-fleet-app__mark" aria-hidden="true">PF</span>
						<div>
							<p class="prime-fleet-app__eyebrow"><?php esc_html_e( 'Booking Engine', 'prime-fleet' ); ?></p>
							<h1><?php esc_html_e( 'Prime Fleet Console', 'prime-fleet' ); ?></h1>
						</div>
					</div>
				</header>

				<nav class="prime-fleet-app__nav" aria-label="<?php esc_attr_e( 'Console sections', 'prime-fleet' ); ?>">
					<div class="prime-fleet-app__nav-scroll" role="tablist">
						<?php foreach ( $primary as $key => $tab ) : ?>
							<button
								type="button"
								class="prime-fleet-tab<?php echo $key === $initial ? ' is-active' : ''; ?>"
								role="tab"
								aria-selected="<?php echo $key === $initial ? 'true' : 'false'; ?>"
								data-pf-panel="<?php echo esc_attr( $key ); ?>"
							><?php echo esc_html( $tab['label'] ); ?></button>
						<?php endforeach; ?>
					</div>

					<?php if ( $more ) : ?>
						<div class="prime-fleet-more">
							<button type="button" class="prime-fleet-tab prime-fleet-more__toggle" aria-expanded="false" aria-haspopup="true" id="prime-fleet-more-toggle">
								<?php esc_html_e( 'More', 'prime-fleet' ); ?>
								<span class="prime-fleet-more__caret" aria-hidden="true"></span>
							</button>
							<div class="prime-fleet-more__menu" id="prime-fleet-more-menu" hidden>
								<?php foreach ( $more as $key => $tab ) : ?>
									<button
										type="button"
										class="prime-fleet-more__item<?php echo $key === $initial ? ' is-active' : ''; ?>"
										data-pf-panel="<?php echo esc_attr( $key ); ?>"
									><?php echo esc_html( $tab['label'] ); ?></button>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endif; ?>
				</nav>
			</div>

			<div id="prime-fleet-toast-stack" class="prime-fleet-toast-stack" aria-live="polite"></div>
			<div id="prime-fleet-panel" class="prime-fleet-panel" aria-live="polite"></div>

			<div id="prime-fleet-loader" class="prime-fleet-loader-overlay" hidden>
				<div class="prime-fleet-loader">
					<span class="prime-fleet-loader__ring"></span>
					<span class="prime-fleet-loader__label"><?php esc_html_e( 'Loading…', 'prime-fleet' ); ?></span>
				</div>
			</div>

			<div id="prime-fleet-modal" class="prime-fleet-modal" hidden>
				<div class="prime-fleet-modal__backdrop" data-pf-modal-close="1"></div>
				<div class="prime-fleet-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="prime-fleet-modal-title">
					<header class="prime-fleet-modal__head">
						<div>
							<p class="prime-fleet-modal__eyebrow"><?php esc_html_e( 'Prime Fleet', 'prime-fleet' ); ?></p>
							<h2 id="prime-fleet-modal-title"><?php esc_html_e( 'Edit', 'prime-fleet' ); ?></h2>
						</div>
						<button type="button" class="prime-fleet-modal__close" data-pf-modal-close="1" aria-label="<?php esc_attr_e( 'Close', 'prime-fleet' ); ?>">&times;</button>
					</header>
					<div class="prime-fleet-modal__body" id="prime-fleet-modal-body"></div>
				</div>
			</div>

			<div id="prime-fleet-confirm" class="prime-fleet-confirm" hidden>
				<div class="prime-fleet-confirm__backdrop" data-pf-confirm-close="1"></div>
				<div class="prime-fleet-confirm__dialog" role="alertdialog" aria-modal="true" aria-labelledby="prime-fleet-confirm-title" aria-describedby="prime-fleet-confirm-text">
					<div class="prime-fleet-confirm__icon" aria-hidden="true"></div>
					<div class="prime-fleet-confirm__copy">
						<p class="prime-fleet-confirm__eyebrow"><?php esc_html_e( 'Confirm action', 'prime-fleet' ); ?></p>
						<h2 id="prime-fleet-confirm-title"><?php esc_html_e( 'Please confirm', 'prime-fleet' ); ?></h2>
						<p id="prime-fleet-confirm-text" class="prime-fleet-confirm__text"></p>
					</div>
					<div class="prime-fleet-confirm__actions">
						<button type="button" class="prime-fleet-btn prime-fleet-btn--ghost" id="prime-fleet-confirm-cancel"><?php esc_html_e( 'Cancel', 'prime-fleet' ); ?></button>
						<button type="button" class="prime-fleet-btn prime-fleet-btn--danger" id="prime-fleet-confirm-ok"><?php esc_html_e( 'Delete', 'prime-fleet' ); ?></button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * @return array
	 */
	public static function visible_tabs() {
		$out = array();
		foreach ( self::tabs() as $key => $tab ) {
			if ( ! empty( $tab['hidden'] ) ) {
				continue;
			}
			if ( ! Capabilities::current_user_can( $tab['cap'] ) ) {
				continue;
			}
			$out[ $key ] = $tab;
		}
		return $out;
	}
}
