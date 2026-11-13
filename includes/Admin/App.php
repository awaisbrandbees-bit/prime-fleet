<?php
/**
 * Admin console shell — WP Admin submenus + AJAX panel body (no in-app sidebar).
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
			'run_sheet'    => array( 'label' => __( 'Run sheet', 'prime-fleet' ), 'cap' => $dispatch ),
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
	 * Ordered WP Admin submenu items (WooCommerce-style).
	 * First item reuses parent slug `prime-fleet` = Overview.
	 *
	 * @return array<int,array{slug:string,panel:string,label:string,cap:string}>
	 */
	public static function wp_menu_items() {
		$order = array(
			'dashboard',
			'bookings',
			'dispatch',
			'run_sheet',
			'pricing',
			'payments',
			'settings',
			'services',
			'zones',
			'vehicles',
			'chauffeurs',
			'addons',
			'availability',
			'slot_offers',
			'coupons',
			'surcharges',
			'templates',
			'fields',
			'reports',
			'logs',
		);
		$tabs = self::tabs();
		$out  = array();
		foreach ( $order as $panel ) {
			if ( empty( $tabs[ $panel ] ) || ! empty( $tabs[ $panel ]['hidden'] ) ) {
				continue;
			}
			if ( ! Capabilities::current_user_can( $tabs[ $panel ]['cap'] ) ) {
				continue;
			}
			$out[] = array(
				'slug'  => self::panel_to_page( $panel ),
				'panel' => $panel,
				'label' => $tabs[ $panel ]['label'],
				'cap'   => $tabs[ $panel ]['cap'],
			);
		}
		return $out;
	}

	/**
	 * @param string $panel Panel key.
	 * @return string WP admin page slug.
	 */
	public static function panel_to_page( $panel ) {
		$panel = sanitize_key( (string) $panel );
		if ( 'dashboard' === $panel || '' === $panel ) {
			return 'prime-fleet';
		}
		if ( 'service_edit' === $panel ) {
			return 'prime-fleet-service-edit';
		}
		return 'prime-fleet-' . str_replace( '_', '-', $panel );
	}

	/**
	 * @return array<string,string> page slug => panel key
	 */
	public static function page_to_panel_map() {
		$map = array(
			'prime-fleet'              => 'dashboard',
			'prime-fleet-service-edit' => 'service_edit',
		);
		foreach ( array_keys( self::tabs() ) as $panel ) {
			if ( 'dashboard' === $panel || 'service_edit' === $panel ) {
				continue;
			}
			$map[ self::panel_to_page( $panel ) ] = $panel;
		}
		return $map;
	}

	/**
	 * Resolve active panel from ?panel= or ?page=.
	 *
	 * @return string
	 */
	public static function panel_from_request() {
		if ( isset( $_GET['panel'] ) && '' !== (string) $_GET['panel'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return sanitize_key( wp_unslash( $_GET['panel'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'prime-fleet'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$map  = self::page_to_panel_map();
		return isset( $map[ $page ] ) ? $map[ $page ] : 'dashboard';
	}

	/**
	 * Render full console shell (content only — navigation is WP Admin menu).
	 */
	public static function render() {
		if ( ! Capabilities::current_user_can( Capabilities::DISPATCH ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'prime-fleet' ) );
		}

		$all_tabs = self::tabs();
		$initial  = self::panel_from_request();
		/* Allow hidden panels (e.g. service_edit) — do not fall back to Overview SSR flash. */
		if ( empty( $all_tabs[ $initial ] ) || ! Capabilities::current_user_can( $all_tabs[ $initial ]['cap'] ) ) {
			$initial = 'dashboard';
		}

		$initial_label = isset( $all_tabs[ $initial ]['label'] ) ? $all_tabs[ $initial ]['label'] : __( 'Overview', 'prime-fleet' );
		?>
		<div class="wrap prime-fleet-app" id="prime-fleet-app" data-panel="<?php echo esc_attr( $initial ); ?>" data-pf-page="<?php echo esc_attr( self::panel_to_page( $initial ) ); ?>" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">
			<div class="prime-fleet-app__layout prime-fleet-app__layout--full">
				<div class="prime-fleet-app__main">
					<header class="prime-fleet-topbar" aria-label="<?php esc_attr_e( 'Page header', 'prime-fleet' ); ?>">
						<div class="prime-fleet-topbar__left">
							<p class="prime-fleet-topbar__eyebrow"><?php esc_html_e( 'Prime Fleet', 'prime-fleet' ); ?></p>
							<h1 id="prime-fleet-shell-title" class="prime-fleet-topbar__title"><?php echo esc_html( $initial_label ); ?></h1>
							<p id="prime-fleet-topbar-meta" class="prime-fleet-topbar__meta">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: localized current date */
										__( '%s · Operations console', 'prime-fleet' ),
										wp_date( 'l, M j, Y' )
									)
								);
								?>
							</p>
						</div>
						<div class="prime-fleet-topbar__right">
							<?php self::render_notifications(); ?>
							<div id="prime-fleet-topbar-actions" class="prime-fleet-topbar__actions"></div>
						</div>
					</header>

					<div id="prime-fleet-toast-stack" class="prime-fleet-toast-stack" aria-live="polite"></div>
					<?php
					/*
					 * SSR the active panel so first paint isn’t an empty band + white loader
					 * (that blank “card” CLS also made WP menu items like Contact jump).
					 */
					add_filter( 'prime_fleet_admin_embed', '__return_true' );
					ob_start();
					Ajax::dispatch( $initial );
					$boot_html = ob_get_clean();
					remove_filter( 'prime_fleet_admin_embed', '__return_true' );
					?>
					<div id="prime-fleet-panel" class="prime-fleet-panel" aria-live="polite" data-pf-ssr="1"><?php echo $boot_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				</div>
			</div>

			<div id="prime-fleet-loader" class="prime-fleet-loader-overlay" hidden>
				<div class="prime-fleet-loader" role="status" aria-live="polite" aria-busy="true">
					<span class="prime-fleet-loader__spin" aria-hidden="true"></span>
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
	 * Header notification bell + dropdown (Vik-style badge).
	 */
	private static function render_notifications() {
		if ( ! Capabilities::current_user_can( Capabilities::DISPATCH ) ) {
			return;
		}

		$feed  = Notifications::summary();
		$count = (int) $feed['count'];
		$label = $count > 0
			? sprintf(
				/* translators: %d: notification count */
				_n( '%d notification', '%d notifications', $count, 'prime-fleet' ),
				$count
			)
			: __( 'No notifications', 'prime-fleet' );
		?>
		<div class="prime-fleet-notify" id="prime-fleet-notify" data-pf-notify="1">
			<button
				type="button"
				class="prime-fleet-notify__bell"
				id="prime-fleet-notify-bell"
				aria-expanded="false"
				aria-haspopup="true"
				aria-controls="prime-fleet-notify-panel"
				aria-label="<?php echo esc_attr( $label ); ?>"
			>
				<span class="prime-fleet-notify__icon" aria-hidden="true">
					<svg viewBox="0 0 24 24" width="16" height="16" focusable="false">
						<path fill="currentColor" d="M12 22a2.4 2.4 0 0 0 2.4-2.4h-4.8A2.4 2.4 0 0 0 12 22zm7.2-6V11a7.16 7.16 0 0 0-5.4-6.92V3.6a1.8 1.8 0 1 0-3.6 0v.48A7.16 7.16 0 0 0 4.8 11v5l-1.8 1.8v.9h18v-.9L19.2 16z"/>
					</svg>
				</span>
				<span
					class="prime-fleet-notify__badge"
					id="prime-fleet-notify-badge"
					<?php echo $count ? '' : ' hidden'; ?>
				><?php echo esc_html( $count > 99 ? '99+' : (string) $count ); ?></span>
			</button>
			<div class="prime-fleet-notify__panel" id="prime-fleet-notify-panel" hidden role="region" aria-label="<?php esc_attr_e( 'Notifications', 'prime-fleet' ); ?>">
				<div class="prime-fleet-notify__head">
					<strong><?php esc_html_e( 'Notifications', 'prime-fleet' ); ?></strong>
					<button type="button" class="prime-fleet-notify__refresh" data-pf-notify-refresh="1" aria-label="<?php esc_attr_e( 'Refresh notifications', 'prime-fleet' ); ?>">
						<span class="prime-fleet-notify__refresh-label"><?php esc_html_e( 'Refresh', 'prime-fleet' ); ?></span>
						<span class="prime-fleet-notify__refresh-spin" aria-hidden="true"></span>
					</button>
				</div>
				<ul class="prime-fleet-notify__list" id="prime-fleet-notify-list">
					<?php if ( empty( $feed['items'] ) ) : ?>
						<li class="prime-fleet-notify__empty"><?php esc_html_e( 'All clear — no pending bookings or payment alerts.', 'prime-fleet' ); ?></li>
					<?php else : ?>
						<?php foreach ( $feed['items'] as $item ) : ?>
							<li>
								<button
									type="button"
									class="prime-fleet-notify__item prime-fleet-notify__item--<?php echo esc_attr( $item['tone'] ); ?>"
									data-pf-panel="bookings"
									data-pf-query="<?php echo esc_attr( wp_json_encode( array( 'id' => (int) $item['id'] ) ) ); ?>"
								>
									<span class="prime-fleet-notify__item-top">
										<span class="prime-fleet-notify__ref"><?php echo esc_html( $item['reference'] ); ?></span>
										<span class="prime-fleet-notify__reason"><?php echo esc_html( $item['reason'] ); ?></span>
									</span>
									<span class="prime-fleet-notify__item-meta">
										<?php echo esc_html( $item['customer'] ); ?>
										<?php if ( $item['when'] ) : ?>
											· <?php echo esc_html( $item['when'] ); ?>
										<?php endif; ?>
									</span>
								</button>
							</li>
						<?php endforeach; ?>
					<?php endif; ?>
				</ul>
				<div class="prime-fleet-notify__foot">
					<button type="button" class="prime-fleet-btn prime-fleet-btn--ghost prime-fleet-btn--compact" data-pf-panel="bookings" data-pf-query="<?php echo esc_attr( wp_json_encode( array( 'status' => 'pending_confirmation' ) ) ); ?>">
						<?php esc_html_e( 'Open pending', 'prime-fleet' ); ?>
					</button>
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
