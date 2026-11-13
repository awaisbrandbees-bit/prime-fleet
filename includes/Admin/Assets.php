<?php
/**
 * Admin assets.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Admin;

defined( 'ABSPATH' ) || exit;

final class Assets {

	/**
	 * Hooks.
	 */
	public function hooks() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
	}

	/**
	 * Mark every Prime Fleet admin screen (top-level + submenus).
	 *
	 * @param string $classes Body classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && isset( $screen->id ) && false !== strpos( (string) $screen->id, 'prime-fleet' ) ) {
			$classes .= ' prime-fleet-admin';
		}
		return $classes;
	}

	/**
	 * @param string $hook Hook suffix.
	 */
	public function enqueue( $hook ) {
		if ( false === strpos( $hook, 'prime-fleet' ) ) {
			return;
		}

		$css_path = \PRIME_FLEET_PATH . 'assets/admin/css/admin.css';
		$css_ver  = ( file_exists( $css_path ) ? (string) filemtime( $css_path ) : \PRIME_FLEET_VERSION ) . '-v128';

		wp_enqueue_style(
			'prime-fleet-admin',
			\PRIME_FLEET_URL . 'assets/admin/css/admin.css',
			array(),
			$css_ver
		);

		/* Plugin band fills the WP content column; do not restyle #wpcontent overflow. */
		$b = 'body.prime-fleet-admin';
		/*
		 * WIDTH / SCROLL LOCK (keep in sync with admin.css):
		 * - overflow-x:clip ONLY on html — never on body (nested scrollport trap),
		 *   never on #wpcontent/#wpbody/#wpwrap (clears floated admin menu → console drops ~1200px).
		 * - Never width:100% + horizontal margin on .prime-fleet-app.
		 * - Gutters = padding inside .prime-fleet-app (border-box); bottom = ::after spacer.
		 */
		wp_add_inline_style(
			'prime-fleet-admin',
			'html:has(' . $b . '){height:auto!important;overflow-y:scroll!important;overflow-x:clip!important;scrollbar-gutter:stable}'
			. $b . '{height:auto!important;overflow:visible!important}'
			. $b . ' #wpwrap,' . $b . ' #wpcontent,'
			. $b . ' #wpbody,' . $b . ' #wpbody-content{'
			. 'overflow:visible!important;max-height:none!important;height:auto!important}'
			/* Content column only — never restyle #adminmenu / #adminmenuwrap. */
			. $b . ' #wpcontent,' . $b . ' #wpbody,' . $b . ' #wpbody-content{display:flow-root}'
			. $b . ' #wpcontent{position:static}'
			. $b . '.prime-fleet-admin--short #wpcontent{position:sticky;top:var(--wp-admin--admin-bar--height,32px);z-index:1}'
			. $b . ' #wpbody-content{float:none;padding-bottom:48px!important}'
			. $b . ' #wpbody-content>.wrap.prime-fleet-app{'
			. 'margin:12px 0 0!important;padding:0 16px 0 0!important;'
			. 'width:auto!important;max-width:100%!important;min-width:0!important;box-sizing:border-box!important;'
			. 'overflow:visible!important}'
			. $b . ' #wpbody-content>.wrap.prime-fleet-app::after{'
			. 'content:""!important;display:block!important;height:160px!important;width:100%!important;max-width:100%!important;'
			. 'pointer-events:none!important;flex-shrink:0!important}'
			. $b . ' #wpfooter{position:relative!important;inset:auto!important;margin:8px 0 24px!important;padding:12px 20px!important;clear:none!important}'
			. $b . ' .prime-fleet-panel,' . $b . ' .prime-fleet-panel-body{'
			. 'overflow:visible!important;max-height:none!important;max-width:100%!important;min-width:0!important}'
			. '.prime-fleet-app__layout,.prime-fleet-app__layout--full{display:block!important;width:100%!important;max-width:100%!important;min-width:0!important;grid-template-columns:none!important;box-sizing:border-box!important}'
			. '.prime-fleet-app__sidebar{display:none!important}'
			. '.prime-fleet-settings-nav,.prime-fleet-subpages__nav{top:var(--wp-admin--admin-bar--height,32px)!important;'
			. 'max-height:calc(100vh - var(--wp-admin--admin-bar--height,32px) - 16px)!important}'
			. '@media screen and (max-width:782px){.prime-fleet-settings-nav,.prime-fleet-subpages__nav{'
			. 'top:var(--wp-admin--admin-bar--height,46px)!important;'
			. 'max-height:calc(100vh - var(--wp-admin--admin-bar--height,46px) - 16px)!important}}'
			. '.prime-fleet-app{width:auto!important;max-width:100%!important;min-width:0!important;clear:none!important;display:flow-root!important;overflow:visible!important;box-sizing:border-box!important}'
			. '.prime-fleet-table-tools.tablenav,.prime-fleet-table-tools{clear:none!important;float:none!important}'
			. '.prime-fleet-table-tools select,.prime-fleet-table-tools__select{float:none!important}'
			. '.prime-fleet-dispatch-bar.tablenav,.prime-fleet-dispatch-bar{clear:none!important;float:none!important;height:auto!important}'
			. '.prime-fleet-dispatch-bar__day.alignleft,.prime-fleet-dispatch-bar__day{float:none!important}'
			. '.prime-fleet-reports__filters .prime-fleet-dt,.prime-fleet-table-tools .prime-fleet-dt{'
			. 'width:8.5rem!important;max-width:min(8.5rem,100%)!important;min-width:7.5rem!important;flex:0 1 auto!important}'
			. '.prime-fleet-reports__filters .prime-fleet-table-tools__select{'
			. 'min-width:6.75rem!important;max-width:8.5rem!important;flex:0 1 auto!important}'
			. '.prime-fleet-reports__filters .prime-fleet-table-tools__actions{gap:6px!important}'
			. '.prime-fleet-reports__filter-fields{display:contents}'
			. '.prime-fleet-reports__actions{display:inline-flex;flex-wrap:wrap;align-items:center;gap:8px;margin-left:auto;flex:0 0 auto}'
			. '.prime-fleet-table-tools__select,.prime-fleet-table-tools input[type="search"],'
			. '.prime-fleet-table-tools input[type="text"],.prime-fleet-cal select.prime-fleet-cal__ctrl{'
			. 'border:1px solid var(--sle-line-strong)!important;border-radius:var(--sle-radius-xs)!important;'
			. 'font-family:inherit;font-size:13px!important;font-weight:500!important;line-height:1.3!important}'
			. '.prime-fleet-table-tools__actions .button,.prime-fleet-cal__toolbar .button,'
			. '.prime-fleet-dispatch-bar .button{border-radius:var(--sle-radius-xs)!important;font-size:13px!important;font-weight:500!important}'
			. '.prime-fleet-table-tools .button,.prime-fleet-table-tools .button.action,'
			. '.prime-fleet-table-tools input.button,.prime-fleet-table-tools__search .button,'
			. '.prime-fleet-table-tools__search input[type="submit"],.prime-fleet-table-tools input[type="submit"].button,'
			. '.prime-fleet-cal__toolbar .button,.prime-fleet-cal__filters .button,'
			. '.prime-fleet-dispatch-bar .button{color:var(--sle-navy)!important;border-color:var(--sle-navy)!important;background:#fff!important;box-shadow:none!important;text-shadow:none!important;border-radius:var(--sle-radius-xs)!important}'
			. '.prime-fleet-table-tools .button-primary,.prime-fleet-table-tools .button.button-primary,'
			. '.prime-fleet-cal__toolbar .button-primary,.prime-fleet-cal__filters .button-primary,'
			. '.prime-fleet-dispatch-bar .button-primary,.prime-fleet-dispatch-bar .button.button-primary{'
			. 'color:#fff!important;background:var(--sle-navy)!important;border-color:var(--sle-navy)!important}'
			. '.prime-fleet-table-tools .button-primary:hover,.prime-fleet-dispatch-bar .button-primary:hover{'
			. 'background:var(--sle-navy-2)!important;border-color:var(--sle-navy-2)!important;color:#fff!important}'
			. '.prime-fleet-app__main,.prime-fleet-panel,.prime-fleet-panel-body,.prime-fleet-card,'
			. '.prime-fleet-dash,.prime-fleet-dash__board,.prime-fleet-dash__full,'
			. '.prime-fleet-topbar,.prime-fleet-table-tools{width:100%;max-width:100%;min-width:0;box-sizing:border-box}'
			. '.prime-fleet-dash,.prime-fleet-dash__board,.prime-fleet-dash__full,.prime-fleet-dash__main,.prime-fleet-dash__rail{overflow:visible!important}'
			. '.prime-fleet-dash__board{grid-template-columns:minmax(0,1fr) minmax(0,300px)!important;align-items:stretch!important}'
			. '.prime-fleet-dash__full{display:flex!important;flex-direction:column!important;gap:8px!important;width:100%!important;height:auto!important;margin-top:8px!important}'
			. '.prime-fleet-dash__main,.prime-fleet-dash__rail{height:100%!important}'
			. '.prime-fleet-dash__main>.prime-fleet-card,.prime-fleet-dash__rail>.prime-fleet-card{flex:0 0 auto!important;height:auto!important}'
			. '.prime-fleet-dash__main>.prime-fleet-card:last-child,.prime-fleet-dash__rail>.prime-fleet-card:last-child{flex:1 1 auto!important;display:flex!important;flex-direction:column!important;min-height:0!important}'
			. '.prime-fleet-dash__main>.prime-fleet-card:last-child .prime-fleet-dash-pager,.prime-fleet-dash__rail>.prime-fleet-card:last-child .prime-fleet-checklist{margin-top:auto!important}'
			. '.prime-fleet-table-wrap{display:block;width:100%;max-width:100%;min-width:0;overflow-x:auto;overflow-y:visible;-webkit-overflow-scrolling:touch;box-sizing:border-box}'
			. '.prime-fleet-settings-layout,.prime-fleet-subpages{grid-template-columns:minmax(0,220px) minmax(0,1fr)!important;max-width:100%!important;min-width:0!important}'
			. '.prime-fleet-btn--ghost:hover,.prime-fleet-btn--ghost:focus-visible{'
			. 'color:var(--sle-ink,#1d2327)!important;background:#f6f7f7!important}'
			. '.prime-fleet-select__search-input,.prime-fleet-select__search-input[type="search"]{'
			. 'padding:0 12px 0 36px!important;box-sizing:border-box!important}'
			. '.prime-fleet-select__search-icon{left:16px!important;top:15px!important;margin-top:0!important}'
			. '#prime-fleet-modal[hidden]{display:none!important}'
			. '#prime-fleet-modal:not([hidden]){'
			. 'position:fixed!important;inset:0!important;z-index:100100!important;'
			. 'display:flex!important;align-items:flex-start!important;justify-content:center!important;'
			. 'box-sizing:border-box!important;padding:12px!important;overflow:hidden!important}'
			. '#prime-fleet-modal .prime-fleet-modal__dialog{'
			. 'width:560px!important;max-width:100%!important;'
			. 'max-height:min(560px,100%)!important;overflow:hidden!important;'
			. 'display:flex!important;flex-direction:column!important;min-height:0!important}'
			. '#prime-fleet-modal .prime-fleet-modal__body{'
			. 'flex:1 1 auto!important;min-height:0!important;overflow-y:auto!important}'
			. '@media (max-width:720px){#prime-fleet-modal:not([hidden]){padding:12px!important}}'
		);

		$js_ver_suffix = '-v86';

		$motion_path = \PRIME_FLEET_PATH . 'assets/admin/js/pf-nav-motion.js';
		$motion_ver  = file_exists( $motion_path ) ? (string) filemtime( $motion_path ) : \PRIME_FLEET_VERSION;
		wp_enqueue_script(
			'prime-fleet-nav-motion',
			\PRIME_FLEET_URL . 'assets/admin/js/pf-nav-motion.js',
			array(),
			$motion_ver,
			true
		);

		$js_path = \PRIME_FLEET_PATH . 'assets/admin/js/admin.js';
		$js_ver  = ( file_exists( $js_path ) ? (string) filemtime( $js_path ) : \PRIME_FLEET_VERSION ) . $js_ver_suffix;

		wp_enqueue_script(
			'prime-fleet-admin',
			\PRIME_FLEET_URL . 'assets/admin/js/admin.js',
			array( 'prime-fleet-nav-motion' ),
			$js_ver,
			true
		);

		$panel_pages = array();
		foreach ( array_keys( App::tabs() ) as $panel_key ) {
			$panel_pages[ $panel_key ] = App::panel_to_page( $panel_key );
		}

		$panel_labels = array();
		foreach ( App::tabs() as $panel_key => $tab ) {
			$panel_labels[ $panel_key ] = isset( $tab['label'] ) ? (string) $tab['label'] : $panel_key;
		}

		wp_localize_script(
			'prime-fleet-admin',
			'primeFleetAdmin',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'prime_fleet_admin' ),
				'adminUrl'    => admin_url( 'admin.php' ),
				'panelPages'  => $panel_pages,
				'pageToPanel' => App::page_to_panel_map(),
				'panelLabels' => $panel_labels,
				'i18n'        => \Prime_Fleet\I18n\Script_Strings::admin(),
			)
		);
	}
}
