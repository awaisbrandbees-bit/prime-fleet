<?php
/**
 * Shared admin view helpers.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Admin;

defined( 'ABSPATH' ) || exit;

final class View {

	/**
	 * @param string $title Page title.
	 * @param string $content HTML content.
	 * @param array  $actions Optional header actions HTML bits.
	 */
	public static function shell( $title, $content, $actions = array() ) {
		$embed = (bool) apply_filters( 'prime_fleet_admin_embed', false );

		if ( $embed ) {
			echo '<div class="prime-fleet-panel-head">';
			echo '<div class="prime-fleet-panel-head__text">';
			echo '<h2 class="prime-fleet-panel-head__title">' . esc_html( $title ) . '</h2>';
			echo '</div>';
			if ( $actions ) {
				echo '<div class="prime-fleet-panel-head__actions">';
				foreach ( $actions as $action ) {
					echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				echo '</div>';
			}
			echo '</div>';
			echo '<div class="prime-fleet-panel-body">' . $content . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		echo '<div class="wrap prime-fleet-admin">';
		echo '<div class="prime-fleet-admin__header">';
		echo '<div>';
		echo '<p class="prime-fleet-admin__eyebrow">' . esc_html__( 'Prime Fleet', 'prime-fleet' ) . '</p>';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		echo '</div>';
		if ( $actions ) {
			echo '<div class="prime-fleet-admin__actions">';
			foreach ( $actions as $action ) {
				echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</div>';
		}
		echo '</div>';
		echo '<div class="prime-fleet-admin__body">' . $content . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
	}

	/**
	 * @param string $message Message.
	 * @param string $type success|error|info.
	 * @return string
	 */
	public static function notice( $message, $type = 'success' ) {
		return '<div class="prime-fleet-notice prime-fleet-notice--' . esc_attr( $type ) . '" role="status">' . esc_html( $message ) . '</div>';
	}

	/**
	 * @param string $title Title.
	 * @param string $text Text.
	 * @param string $cta_html Optional CTA.
	 * @return string
	 */
	public static function empty_state( $title, $text, $cta_html = '' ) {
		return '<div class="prime-fleet-empty"><div class="prime-fleet-empty__icon" aria-hidden="true"></div><h3>' . esc_html( $title ) . '</h3><p>' . esc_html( $text ) . '</p>' . $cta_html . '</div>';
	}

	/**
	 * Button that opens the shared modal with a form template.
	 *
	 * @param string $label Label.
	 * @param string $template_id DOM id of template/form source.
	 * @param string $title Modal title.
	 * @param string $variant primary|ghost.
	 * @param array  $record Optional record to fill.
	 * @return string
	 */
	public static function modal_button( $label, $template_id, $title, $variant = 'primary', $record = array() ) {
		$class = 'primary' === $variant ? 'prime-fleet-btn' : 'prime-fleet-btn prime-fleet-btn--ghost';
		$attrs = '';
		if ( $record ) {
			$attrs = ' data-pf-record="' . esc_attr( wp_json_encode( $record ) ) . '"';
		}
		return '<button type="button" class="' . esc_attr( $class ) . '" data-pf-open-modal="' . esc_attr( $template_id ) . '" data-pf-modal-title="' . esc_attr( $title ) . '"' . $attrs . '>' . esc_html( $label ) . '</button>';
	}

	/**
	 * Panel tab button (AJAX) instead of full page link.
	 *
	 * @param string $label Label.
	 * @param string $panel Panel key.
	 * @param string $variant primary|ghost.
	 * @param array  $query Extra query.
	 * @return string
	 */
	public static function panel_button( $label, $panel, $variant = 'primary', $query = array() ) {
		if ( 'link' === $variant ) {
			$class = 'prime-fleet-row-link';
		} elseif ( 'primary' === $variant ) {
			$class = 'prime-fleet-btn';
		} else {
			$class = 'prime-fleet-btn prime-fleet-btn--ghost';
		}
		$q = $query ? ' data-pf-query="' . esc_attr( wp_json_encode( $query ) ) . '"' : '';
		return '<button type="button" class="' . esc_attr( $class ) . '" data-pf-panel="' . esc_attr( $panel ) . '"' . $q . '>' . esc_html( $label ) . '</button>';
	}

	/**
	 * @param string $label Label.
	 * @param string $url URL.
	 * @param string $variant primary|ghost.
	 * @return string
	 */
	public static function button_link( $label, $url, $variant = 'primary' ) {
		// Convert legacy admin.php?page=… links to panel buttons when possible.
		$panel_map = array(
			'prime-fleet'              => 'dashboard',
			'prime-fleet-services'     => 'services',
			'prime-fleet-zones'        => 'zones',
			'prime-fleet-pricing'      => 'pricing',
			'prime-fleet-vehicles'     => 'vehicles',
			'prime-fleet-payments'     => 'payments',
			'prime-fleet-settings'     => 'settings',
			'prime-fleet-service-edit' => 'service_edit',
		);

		$query = wp_parse_url( $url, PHP_URL_QUERY );
		$args  = array();
		if ( $query ) {
			parse_str( $query, $args );
		}
		if ( ! empty( $args['page'] ) && isset( $panel_map[ $args['page'] ] ) ) {
			$panel = $panel_map[ $args['page'] ];
			unset( $args['page'] );
			return self::panel_button( $label, $panel, $variant, $args );
		}

		$class = 'primary' === $variant ? 'prime-fleet-btn' : 'prime-fleet-btn prime-fleet-btn--ghost';
		return '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}

	/**
	 * Centered SVG chevron for icon buttons (‹ › glyphs sit optically low).
	 *
	 * @param string $dir 'prev' | 'next'.
	 * @return string Safe HTML.
	 */
	public static function chevron( $dir = 'next' ) {
		$path = ( 'prev' === $dir )
			? 'M10.5 3.5L5.5 8l5 4.5'
			: 'M5.5 3.5l5 4.5-5 4.5';
		return '<svg class="prime-fleet-chev" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="' . $path . '"/></svg>';
	}

	/**
	 * Compact Prev / numbers / Next chrome for dense card lists/tables.
	 * Wrap rows with data-pf-card-pager (or data-pf-dash-pager) + data-pf-dash-row.
	 *
	 * @param int  $total Total rows.
	 * @param int  $per_page Rows per page.
	 * @param bool $always Always render the bar (keeps sibling card footers aligned).
	 */
	public static function render_card_pager( $total, $per_page = 5, $always = false ) {
		$total    = max( 0, (int) $total );
		$per_page = max( 1, (int) $per_page );
		if ( ! $always && $total <= $per_page ) {
			return;
		}
		$pages = max( 1, (int) ceil( max( 1, $total ) / $per_page ) );
		$end   = min( $per_page, max( 0, $total ) );
		$meta  = $total > 0
			? sprintf(
				/* translators: 1: from, 2: to, 3: total */
				__( 'Showing %1$d–%2$d of %3$d', 'prime-fleet' ),
				$total > 0 ? 1 : 0,
				$end,
				$total
			)
			: __( 'Showing 0 of 0', 'prime-fleet' );

		echo '<div class="prime-fleet-dash-pager__bar" role="navigation" aria-label="' . esc_attr__( 'Pagination', 'prime-fleet' ) . '">';
		echo '<span class="prime-fleet-dash-pager__meta" data-pf-dash-page-meta>' . esc_html( $meta ) . '</span>';
		echo '<div class="prime-fleet-dash-pager__nav">';
		echo '<button type="button" class="prime-fleet-table-pager__btn" data-pf-dash-page="prev" disabled>' . esc_html__( 'Prev', 'prime-fleet' ) . '</button>';
		echo '<span class="prime-fleet-dash-pager__pages" data-pf-dash-pages>';
		for ( $i = 1; $i <= $pages; $i++ ) {
			$class = 'prime-fleet-table-pager__btn' . ( 1 === $i ? ' is-active' : '' );
			echo '<button type="button" class="' . esc_attr( $class ) . '" data-pf-dash-page-num="' . esc_attr( (string) $i ) . '">' . esc_html( (string) $i ) . '</button>';
		}
		echo '</span>';
		echo '<button type="button" class="prime-fleet-table-pager__btn" data-pf-dash-page="next"' . ( $pages <= 1 ? ' disabled' : '' ) . '>' . esc_html__( 'Next', 'prime-fleet' ) . '</button>';
		echo '</div></div>';
	}
}
