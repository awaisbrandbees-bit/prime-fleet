<?php
/**
 * Admin dashboard — Vik-inspired dense Overview (fleet ops).
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

		$data = self::gather();

		ob_start();
		?>
		<div class="prime-fleet-dash">
			<?php self::render_kpis( $data ); ?>

			<div class="prime-fleet-dash__board">
				<div class="prime-fleet-dash__main">
					<?php
					self::render_notes( $data );
					self::render_pickups_today( $data );
					self::render_attention( $data );
					?>
				</div>
				<aside class="prime-fleet-dash__rail" aria-label="<?php esc_attr_e( 'Overview side panels', 'prime-fleet' ); ?>">
					<?php
					self::render_activity( $data );
					self::render_counters( $data );
					self::render_catalog( $data );
					?>
				</aside>
			</div>
			<div class="prime-fleet-dash__full">
				<?php
				self::render_week( $data );
				self::render_fleet_today( $data );
				self::render_forecast( $data );
				?>
			</div>
		</div>
		<?php
		View::shell(
			__( 'Overview', 'prime-fleet' ),
			ob_get_clean(),
			array(
				View::panel_button( __( 'Dispatch board', 'prime-fleet' ), 'dispatch' ),
				View::panel_button( __( 'All bookings', 'prime-fleet' ), 'bookings', 'ghost', array( 'view' => 'calendar' ) ),
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function gather() {
		global $wpdb;

		$bookings = Schema::table( 'bookings' );
		$comms    = Schema::table( 'communications' );
		$services = Schema::table( 'services' );
		$zones    = Schema::table( 'zones' );
		$vehicles = Schema::table( 'vehicles' );
		$exists   = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bookings ) );

		$today = current_time( 'Y-m-d' );
		$now   = current_time( 'mysql' );

		$out = array(
			'today'            => $today,
			'pending'          => 0,
			'today_jobs'       => 0,
			'unpaid'           => 0,
			'failed'           => 0,
			'confirmed_today'  => 0,
			'fleet_active'     => 0,
			'svc_count'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$services}" ),
			'zone_count'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$zones}" ),
			'fleet_count'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$vehicles} WHERE is_active = 1" ),
			'pickups'          => array(),
			'attention'        => array(),
			'week'             => array(),
			'fleet_load'       => array(),
			'activity'         => array(),
			'notes'            => array(),
			'month_jobs'       => 0,
			'last_month_jobs'  => 0,
		);

		$out['fleet_active'] = $out['fleet_count'];

		if ( ! $exists ) {
			$out['week'] = self::empty_week( $today, max( 1, $out['fleet_active'] ) );
			return $out;
		}

		$out['pending'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$bookings} WHERE status = 'pending_confirmation'" );
		$out['today_jobs'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$bookings} WHERE DATE(pickup_datetime) = %s AND status NOT IN ('cancelled')",
				$today
			)
		);
		$out['confirmed_today'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$bookings} WHERE DATE(pickup_datetime) = %s AND status = 'confirmed'",
				$today
			)
		);
		$out['unpaid'] = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$bookings} WHERE payment_status IN ('unpaid','link_sent','pending_verification') AND status NOT IN ('cancelled')"
		);
		$out['failed'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$comms} WHERE status = 'failed'" );

		$month_start      = gmdate( 'Y-m-01', strtotime( $today ) );
		$last_month_start = gmdate( 'Y-m-01', strtotime( $month_start . ' -1 month' ) );
		$last_month_end   = gmdate( 'Y-m-t', strtotime( $last_month_start ) );
		$out['month_jobs'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$bookings} WHERE pickup_datetime >= %s AND pickup_datetime < %s AND status NOT IN ('cancelled')",
				$month_start . ' 00:00:00',
				gmdate( 'Y-m-d', strtotime( $month_start . ' +1 month' ) ) . ' 00:00:00'
			)
		);
		$out['last_month_jobs'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$bookings} WHERE pickup_datetime >= %s AND pickup_datetime <= %s AND status NOT IN ('cancelled')",
				$last_month_start . ' 00:00:00',
				$last_month_end . ' 23:59:59'
			)
		);

		$out['pickups'] = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.id, b.reference, b.status, b.payment_status, b.customer_name, b.passenger_count,
					b.pickup_datetime, b.assigned_vehicle_id, s.name AS service_name,
					v.customer_display_name AS vehicle_label, v.name AS vehicle_name
				FROM {$bookings} b
				LEFT JOIN {$services} s ON s.id = b.service_id
				LEFT JOIN {$vehicles} v ON v.id = b.assigned_vehicle_id
				WHERE DATE(b.pickup_datetime) = %s AND b.status NOT IN ('cancelled')
				ORDER BY b.pickup_datetime ASC
				LIMIT 12",
				$today
			),
			ARRAY_A
		);

		$out['attention'] = $wpdb->get_results(
			"SELECT b.id, b.reference, b.status, b.payment_status, b.customer_name, b.pickup_datetime, s.name AS service_name
			FROM {$bookings} b
			LEFT JOIN {$services} s ON s.id = b.service_id
			WHERE b.status = 'pending_confirmation'
			   OR (b.payment_status IN ('unpaid','link_sent','pending_verification') AND b.status NOT IN ('cancelled'))
			ORDER BY
				CASE WHEN b.status = 'pending_confirmation' THEN 0 ELSE 1 END,
				b.pickup_datetime ASC
			LIMIT 10",
			ARRAY_A
		);

		$out['notes'] = $wpdb->get_results(
			"SELECT id, reference, customer_name, internal_notes, updated_at
			FROM {$bookings}
			WHERE internal_notes IS NOT NULL AND TRIM(internal_notes) <> ''
			ORDER BY updated_at DESC
			LIMIT 6",
			ARRAY_A
		);

		$out['activity'] = $wpdb->get_results(
			"SELECT c.id, c.channel, c.status, c.recipient, c.body_preview, c.created_at, c.template_key, b.reference, b.customer_name
			FROM {$comms} c
			LEFT JOIN {$bookings} b ON b.id = c.booking_id
			ORDER BY c.created_at DESC
			LIMIT 4",
			ARRAY_A
		);

		$cap = max( 1, (int) $out['fleet_active'] );
		$week = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$day = gmdate( 'Y-m-d', strtotime( $today . " +{$i} day" ) );
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$bookings} WHERE DATE(pickup_datetime) = %s AND status NOT IN ('cancelled')",
					$day
				)
			);
			$week[] = array(
				'date'  => $day,
				'label' => date_i18n( 'D', strtotime( $day . ' 12:00:00' ) ),
				'day'   => date_i18n( 'j', strtotime( $day . ' 12:00:00' ) ),
				'count' => $count,
				'cap'   => $cap,
				'pct'   => (int) min( 100, round( ( $count / $cap ) * 100 ) ),
			);
		}
		$out['week'] = $week;

		/*
		 * Today's fleet load = vehicles that actually have work today.
		 * Idle (0 jobs) belong on Vehicles, not this Overview signal.
		 */
		$fleet_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT v.id, v.name, v.customer_display_name, COUNT(b.id) AS jobs_today
				FROM {$vehicles} v
				INNER JOIN {$bookings} b
					ON b.assigned_vehicle_id = v.id
					AND DATE(b.pickup_datetime) = %s
					AND b.status NOT IN ('cancelled')
				WHERE v.is_active = 1
				GROUP BY v.id, v.name, v.customer_display_name, v.display_order
				ORDER BY jobs_today DESC, v.display_order ASC, v.id ASC
				LIMIT 24",
				$today
			),
			ARRAY_A
		);
		$out['fleet_load'] = is_array( $fleet_rows ) ? $fleet_rows : array();

		unset( $now );
		return $out;
	}

	/**
	 * @param string $today Y-m-d.
	 * @param int    $cap Capacity.
	 * @return array<int,array<string,mixed>>
	 */
	private static function empty_week( $today, $cap ) {
		$week = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$day = gmdate( 'Y-m-d', strtotime( $today . " +{$i} day" ) );
			$week[] = array(
				'date'  => $day,
				'label' => date_i18n( 'D', strtotime( $day . ' 12:00:00' ) ),
				'day'   => date_i18n( 'j', strtotime( $day . ' 12:00:00' ) ),
				'count' => 0,
				'cap'   => $cap,
				'pct'   => 0,
			);
		}
		return $week;
	}

	/**
	 * @param array<string,mixed> $data Data.
	 */
	private static function render_kpis( $data ) {
		$items = array(
			array( __( 'Pending confirmation', 'prime-fleet' ), (int) $data['pending'], __( 'Awaiting staff review', 'prime-fleet' ), 'warn' ),
			array( __( "Today's pickups", 'prime-fleet' ), (int) $data['today_jobs'], wp_date( 'l, M j' ), 'navy' ),
			array( __( 'Unpaid / awaiting', 'prime-fleet' ), (int) $data['unpaid'], __( 'Payment follow-up', 'prime-fleet' ), 'pay' ),
			array( __( 'Failed messages', 'prime-fleet' ), (int) $data['failed'], __( 'Email / WhatsApp queue', 'prime-fleet' ), 'danger' ),
		);
		echo '<div class="prime-fleet-stats prime-fleet-dash__kpis">';
		foreach ( $items as $item ) {
			printf(
				'<article class="prime-fleet-stat prime-fleet-stat--%s"><span class="prime-fleet-stat__label">%s</span><strong class="prime-fleet-stat__value">%d</strong><span class="prime-fleet-stat__hint">%s</span></article>',
				esc_attr( $item[3] ),
				esc_html( $item[0] ),
				(int) $item[1],
				esc_html( $item[2] )
			);
		}
		echo '</div>';
	}

	/**
	 * @param array<string,mixed> $data Data.
	 */
	private static function render_notes( $data ) {
		$notes = is_array( $data['notes'] ) ? $data['notes'] : array();
		echo '<section class="prime-fleet-card prime-fleet-dash-widget">';
		echo '<header class="prime-fleet-dash-widget__head">';
		echo '<h3 class="prime-fleet-dash-widget__title">' . esc_html__( 'Staff notes', 'prime-fleet' ) . '</h3>';
		echo '<span class="prime-fleet-dash-widget__meta">' . esc_html__( 'From booking internal notes', 'prime-fleet' ) . '</span>';
		echo '</header>';
		if ( empty( $notes ) ) {
			echo '<div class="prime-fleet-dash-notes prime-fleet-dash-notes--empty">';
			echo '<div class="prime-fleet-dash-note prime-fleet-dash-note--tip">';
			echo '<p>' . esc_html__( 'No staff notes yet. Add internal notes on a booking — they surface here for the team.', 'prime-fleet' ) . '</p>';
			echo '</div></div>';
		} else {
			echo '<div class="prime-fleet-dash-notes-carousel" data-pf-notes-carousel="1">';
			echo '<div class="prime-fleet-dash-notes-viewport">';
			echo '<div class="prime-fleet-dash-notes" data-pf-notes-track="1">';
			$colors = array( 'amber', 'sage', 'sky', 'rose' );
			$i      = 0;
			foreach ( $notes as $n ) {
				$color = $colors[ $i % count( $colors ) ];
				++$i;
				$raw = wp_strip_all_tags( (string) $n['internal_notes'] );
				$raw = preg_replace( '/^\s*\[demo-seed\]\s*/i', '', $raw );
				$snippet = wp_html_excerpt( (string) $raw, 72, '…' );
				echo '<article class="prime-fleet-dash-note prime-fleet-dash-note--' . esc_attr( $color ) . '">';
				echo '<p>' . esc_html( $snippet ) . '</p>';
				echo '<footer>';
				echo '<button type="button" class="prime-fleet-dash-note__ref" data-pf-panel="bookings" data-pf-query="' . esc_attr( wp_json_encode( array( 'id' => (int) $n['id'] ) ) ) . '">' . esc_html( $n['reference'] ? $n['reference'] : ( '#' . (int) $n['id'] ) ) . '</button>';
				echo '<span>' . esc_html( $n['customer_name'] ? $n['customer_name'] : '—' ) . '</span>';
				echo '</footer></article>';
			}
			echo '</div></div>';
			echo '<div class="prime-fleet-dash-notes__nav">';
			echo '<button type="button" class="prime-fleet-dash-notes__arrow" data-pf-notes-prev="1" aria-label="' . esc_attr__( 'Previous notes', 'prime-fleet' ) . '">' . View::chevron( 'prev' ) . '</button>';
			echo '<button type="button" class="prime-fleet-dash-notes__arrow" data-pf-notes-next="1" aria-label="' . esc_attr__( 'Next notes', 'prime-fleet' ) . '">' . View::chevron( 'next' ) . '</button>';
			echo '</div>';
			echo '</div>';
		}
		echo '</section>';
	}

	/**
	 * Compact pager chrome for dashboard tables.
	 *
	 * @param int $total Total rows.
	 * @param int $per_page Rows per page.
	 */
	private static function render_dash_pager( $total, $per_page = 4 ) {
		View::render_card_pager( $total, $per_page );
	}

	/**
	 * @param array<string,mixed> $data Data.
	 */
	private static function render_pickups_today( $data ) {
		$rows     = is_array( $data['pickups'] ) ? $data['pickups'] : array();
		$per_page = 4;
		echo '<section class="prime-fleet-card prime-fleet-card--flush prime-fleet-dash-widget">';
		echo '<header class="prime-fleet-dash-widget__head prime-fleet-dash-widget__head--pad">';
		echo '<h3 class="prime-fleet-dash-widget__title">' . esc_html__( 'Pickups today', 'prime-fleet' );
		echo ' <span class="prime-fleet-dash-count">' . esc_html( (string) count( $rows ) ) . '</span></h3>';
		echo View::panel_button( __( 'Open dispatch', 'prime-fleet' ), 'dispatch', 'ghost' );
		echo '</header>';
		if ( empty( $rows ) ) {
			echo '<div class="prime-fleet-dash-empty">' . esc_html__( 'No pickups scheduled for today.', 'prime-fleet' ) . '</div>';
		} else {
			echo '<div class="prime-fleet-dash-pager" data-pf-dash-pager="1" data-per-page="' . esc_attr( (string) $per_page ) . '">';
			echo '<div class="prime-fleet-dash-table-wrap"><table class="prime-fleet-dash-table">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Time', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Guest', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Service', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Vehicle', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'prime-fleet' ) . '</th>';
			echo '<th></th>';
			echo '</tr></thead><tbody>';
			foreach ( $rows as $r ) {
				$time    = ! empty( $r['pickup_datetime'] ) ? date_i18n( 'g:i A', strtotime( $r['pickup_datetime'] ) ) : '—';
				$vehicle = $r['vehicle_label'] ? $r['vehicle_label'] : ( $r['vehicle_name'] ? $r['vehicle_name'] : '—' );
				echo '<tr data-pf-dash-row="1">';
				echo '<td><strong>' . esc_html( $time ) . '</strong></td>';
				echo '<td>' . esc_html( $r['customer_name'] ? $r['customer_name'] : '—' );
				echo ' <span class="prime-fleet-muted">· ' . esc_html( (string) (int) $r['passenger_count'] ) . ' pax</span></td>';
				echo '<td>' . esc_html( $r['service_name'] ? $r['service_name'] : '—' ) . '</td>';
				echo '<td>' . esc_html( $vehicle ) . '</td>';
				echo '<td>' . self::status_badge( $r['status'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<td class="prime-fleet-dash-table__act">';
				echo '<button type="button" class="prime-fleet-btn prime-fleet-btn--ghost" data-pf-panel="bookings" data-pf-query="' . esc_attr( wp_json_encode( array( 'id' => (int) $r['id'] ) ) ) . '">' . esc_html__( 'Open', 'prime-fleet' ) . '</button>';
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
			self::render_dash_pager( count( $rows ), $per_page );
			echo '</div>';
		}
		echo '</section>';
	}

	/**
	 * @param array<string,mixed> $data Data.
	 */
	private static function render_attention( $data ) {
		$rows     = is_array( $data['attention'] ) ? $data['attention'] : array();
		$per_page = 4;
		echo '<section class="prime-fleet-card prime-fleet-card--flush prime-fleet-dash-widget">';
		echo '<header class="prime-fleet-dash-widget__head prime-fleet-dash-widget__head--pad">';
		echo '<h3 class="prime-fleet-dash-widget__title">' . esc_html__( 'Needs attention', 'prime-fleet' );
		echo ' <span class="prime-fleet-dash-count">' . esc_html( (string) count( $rows ) ) . '</span></h3>';
		echo View::panel_button( __( 'Bookings', 'prime-fleet' ), 'bookings', 'ghost', array( 'view' => 'calendar' ) );
		echo '</header>';
		if ( empty( $rows ) ) {
			echo '<div class="prime-fleet-dash-empty">' . esc_html__( 'Queue is clear — nothing pending confirmation or payment.', 'prime-fleet' ) . '</div>';
		} else {
			echo '<div class="prime-fleet-dash-pager" data-pf-dash-pager="1" data-per-page="' . esc_attr( (string) $per_page ) . '">';
			echo '<div class="prime-fleet-dash-table-wrap"><table class="prime-fleet-dash-table">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Ref', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Guest', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Pickup', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Booking', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Payment', 'prime-fleet' ) . '</th>';
			echo '<th></th>';
			echo '</tr></thead><tbody>';
			foreach ( $rows as $r ) {
				$when = ! empty( $r['pickup_datetime'] ) ? date_i18n( 'M j · g:i A', strtotime( $r['pickup_datetime'] ) ) : '—';
				echo '<tr data-pf-dash-row="1">';
				echo '<td><strong>' . esc_html( $r['reference'] ? $r['reference'] : ( '#' . (int) $r['id'] ) ) . '</strong></td>';
				echo '<td>' . esc_html( $r['customer_name'] ? $r['customer_name'] : '—' ) . '</td>';
				echo '<td>' . esc_html( $when ) . '</td>';
				echo '<td>' . self::status_badge( $r['status'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<td>' . self::pay_badge( $r['payment_status'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<td class="prime-fleet-dash-table__act">';
				echo '<button type="button" class="prime-fleet-btn prime-fleet-btn--ghost" data-pf-panel="bookings" data-pf-query="' . esc_attr( wp_json_encode( array( 'id' => (int) $r['id'] ) ) ) . '">' . esc_html__( 'Review', 'prime-fleet' ) . '</button>';
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
			self::render_dash_pager( count( $rows ), $per_page );
			echo '</div>';
		}
		echo '</section>';
	}

	/**
	 * @param array<string,mixed> $data Data.
	 */
	private static function render_week( $data ) {
		$week = is_array( $data['week'] ) ? $data['week'] : array();
		echo '<section class="prime-fleet-card prime-fleet-dash-widget">';
		echo '<header class="prime-fleet-dash-widget__head">';
		echo '<h3 class="prime-fleet-dash-widget__title">' . esc_html__( "This week's jobs", 'prime-fleet' ) . '</h3>';
		echo '<span class="prime-fleet-dash-widget__meta">' . esc_html__( 'Jobs vs active fleet', 'prime-fleet' ) . '</span>';
		echo '</header>';
		echo '<div class="prime-fleet-dash-week">';
		foreach ( $week as $d ) {
			$pct   = (int) $d['pct'];
			$tone  = $pct >= 85 ? 'hot' : ( $pct >= 55 ? 'busy' : ( $pct > 0 ? 'ok' : 'idle' ) );
			$is_today = ( $d['date'] === $data['today'] );
			echo '<article class="prime-fleet-dash-day' . ( $is_today ? ' is-today' : '' ) . '">';
			echo '<div class="prime-fleet-dash-ring prime-fleet-dash-ring--' . esc_attr( $tone ) . '" style="--pf-pct:' . esc_attr( (string) $pct ) . '">';
			echo '<span class="prime-fleet-dash-ring__val">' . esc_html( (int) $d['count'] . '/' . (int) $d['cap'] ) . '</span>';
			echo '</div>';
			echo '<strong>' . esc_html( $d['label'] ) . '</strong>';
			echo '<span>' . esc_html( (string) $d['day'] ) . '</span>';
			echo '</article>';
		}
		echo '</div></section>';
	}

	/**
	 * @param array<string,mixed> $data Data.
	 */
	private static function render_fleet_today( $data ) {
		$rows     = is_array( $data['fleet_load'] ) ? $data['fleet_load'] : array();
		$per_page = 6; /* Fill the row; next page for the rest. */
		echo '<section class="prime-fleet-card prime-fleet-dash-widget">';
		echo '<header class="prime-fleet-dash-widget__head">';
		echo '<h3 class="prime-fleet-dash-widget__title">' . esc_html__( "Today's fleet load", 'prime-fleet' ) . '</h3>';
		echo View::panel_button( __( 'Vehicles', 'prime-fleet' ), 'vehicles', 'ghost' );
		echo '</header>';
		if ( empty( $rows ) ) {
			echo '<div class="prime-fleet-dash-empty">' . esc_html__( 'No vehicles assigned to jobs today.', 'prime-fleet' ) . '</div>';
			echo '</section>';
			return;
		}
		echo '<div class="prime-fleet-dash-pager" data-pf-dash-pager="1" data-per-page="' . esc_attr( (string) $per_page ) . '">';
		echo '<div class="prime-fleet-dash-fleet">';
		foreach ( $rows as $v ) {
			$label = $v['customer_display_name'] ? $v['customer_display_name'] : $v['name'];
			$jobs  = (int) $v['jobs_today'];
			echo '<article class="prime-fleet-dash-fleet__item" data-pf-dash-row="1" title="' . esc_attr( $label ) . '">';
			echo '<strong>' . esc_html( $label ) . '</strong>';
			echo '<span class="prime-fleet-dash-fleet__count">' . esc_html( sprintf( /* translators: %d job count */ _n( '%d job', '%d jobs', $jobs, 'prime-fleet' ), $jobs ) ) . '</span>';
			echo '</article>';
		}
		echo '</div>';
		self::render_dash_pager( count( $rows ), $per_page );
		echo '</div></section>';
	}

	/**
	 * @param array<string,mixed> $data Data.
	 */
	private static function render_activity( $data ) {
		$rows = is_array( $data['activity'] ) ? $data['activity'] : array();
		echo '<section class="prime-fleet-card prime-fleet-dash-widget">';
		echo '<header class="prime-fleet-dash-widget__head">';
		echo '<h3 class="prime-fleet-dash-widget__title">' . esc_html__( 'Latest messages', 'prime-fleet' ) . '</h3>';
		/* Full list lives on Messages → Outbox; no in-card pager. */
		echo View::panel_button( __( 'Outbox', 'prime-fleet' ), 'templates', 'ghost', array( 'mtab' => 'outbox' ) );
		echo '</header>';
		if ( empty( $rows ) ) {
			echo '<p class="prime-fleet-dash-empty">' . esc_html__( 'No recent email / WhatsApp activity.', 'prime-fleet' ) . '</p>';
		} else {
			echo '<ul class="prime-fleet-dash-feed">';
			foreach ( $rows as $r ) {
				$initial = self::initials( $r['customer_name'] ? $r['customer_name'] : $r['recipient'] );
				$preview = wp_html_excerpt( wp_strip_all_tags( (string) $r['body_preview'] ), 72, '…' );
				$when    = ! empty( $r['created_at'] ) ? human_time_diff( strtotime( $r['created_at'] ), current_time( 'timestamp' ) ) : '';
				echo '<li class="prime-fleet-dash-feed__item">';
				echo '<span class="prime-fleet-dash-avatar" aria-hidden="true">' . esc_html( $initial ) . '</span>';
				echo '<div class="prime-fleet-dash-feed__body">';
				echo '<div class="prime-fleet-dash-feed__top">';
				echo '<strong>' . esc_html( $r['customer_name'] ? $r['customer_name'] : ( $r['recipient'] ? $r['recipient'] : '—' ) ) . '</strong>';
				echo self::comm_badge( $r['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '</div>';
				if ( $preview ) {
					echo '<p>' . esc_html( $preview ) . '</p>';
				}
				echo '<span class="prime-fleet-muted">' . esc_html( strtoupper( (string) $r['channel'] ) );
				if ( $r['reference'] ) {
					echo ' · ' . esc_html( $r['reference'] );
				}
				if ( $when ) {
					echo ' · ' . esc_html( sprintf( /* translators: %s human time */ __( '%s ago', 'prime-fleet' ), $when ) );
				}
				echo '</span></div></li>';
			}
			echo '</ul>';
		}
		echo '</section>';
	}

	/**
	 * @param array<string,mixed> $data Data.
	 */
	private static function render_forecast( $data ) {
		$cap   = max( 1, (int) $data['fleet_active'] );
		$jobs  = (int) $data['today_jobs'];
		$pct   = (int) min( 100, round( ( $jobs / $cap ) * 100 ) );
		$free  = max( 0, 100 - $pct );
		echo '<section class="prime-fleet-card prime-fleet-dash-widget prime-fleet-dash-widget--util">';
		echo '<header class="prime-fleet-dash-widget__head">';
		echo '<h3 class="prime-fleet-dash-widget__title">' . esc_html__( 'Today utilization', 'prime-fleet' ) . '</h3>';
		echo '<span class="prime-fleet-dash-widget__meta">' . esc_html__( 'Fleet capacity for today', 'prime-fleet' ) . '</span>';
		echo '</header>';
		echo '<div class="prime-fleet-dash-forecast prime-fleet-dash-forecast--wide">';
		echo '<div class="prime-fleet-dash-forecast__stats">';
		echo '<div><span class="prime-fleet-muted">' . esc_html__( 'Load', 'prime-fleet' ) . '</span><strong>' . esc_html( $pct . '%' ) . '</strong></div>';
		echo '<div><span class="prime-fleet-muted">' . esc_html__( 'Jobs today', 'prime-fleet' ) . '</span><strong>' . esc_html( (string) $jobs ) . '</strong></div>';
		echo '<div><span class="prime-fleet-muted">' . esc_html__( 'Active fleet', 'prime-fleet' ) . '</span><strong>' . esc_html( (string) $cap ) . '</strong></div>';
		echo '<div><span class="prime-fleet-muted">' . esc_html__( 'Confirmed', 'prime-fleet' ) . '</span><strong>' . esc_html( (string) (int) $data['confirmed_today'] ) . '</strong></div>';
		echo '</div>';
		echo '<div class="prime-fleet-dash-forecast__viz">';
		echo '<div class="prime-fleet-dash-donut" style="--pf-pct:' . esc_attr( (string) $pct ) . '" role="img" aria-label="' . esc_attr( sprintf( /* translators: %d percent */ __( '%d percent fleet utilized today', 'prime-fleet' ), $pct ) ) . '">';
		echo '<span class="prime-fleet-dash-donut__hole"><strong>' . esc_html( $pct . '%' ) . '</strong><em>' . esc_html__( 'utilized', 'prime-fleet' ) . '</em></span>';
		echo '</div>';
		echo '<ul class="prime-fleet-dash-legend">';
		echo '<li><i class="is-used"></i> ' . esc_html__( 'Jobs assigned capacity', 'prime-fleet' ) . ' <strong>' . esc_html( $pct . '%' ) . '</strong></li>';
		echo '<li><i class="is-free"></i> ' . esc_html__( 'Remaining headroom', 'prime-fleet' ) . ' <strong>' . esc_html( $free . '%' ) . '</strong></li>';
		echo '</ul>';
		echo '</div></div></section>';
	}

	/**
	 * @param array<string,mixed> $data Data.
	 */
	private static function render_counters( $data ) {
		$month      = (int) $data['month_jobs'];
		$last       = (int) $data['last_month_jobs'];
		$delta_pct  = 0;
		$delta_label = '0%';
		if ( $last > 0 ) {
			$delta_pct   = (int) round( ( ( $month - $last ) / $last ) * 100 );
			$delta_label = ( $delta_pct >= 0 ? '+' : '' ) . $delta_pct . '%';
		} elseif ( $month > 0 ) {
			$delta_label = '+100%';
		}
		echo '<section class="prime-fleet-card prime-fleet-dash-widget">';
		echo '<header class="prime-fleet-dash-widget__head">';
		echo '<h3 class="prime-fleet-dash-widget__title">' . esc_html__( 'Ops counters', 'prime-fleet' ) . '</h3>';
		echo '</header>';
		echo '<div class="prime-fleet-dash-counters">';
		echo '<div><strong>' . esc_html( (string) (int) $data['today_jobs'] ) . '</strong><span>' . esc_html__( 'Jobs today', 'prime-fleet' ) . '</span></div>';
		echo '<div><strong>' . esc_html( (string) $month ) . '</strong><span>' . esc_html__( 'This month', 'prime-fleet' ) . '</span></div>';
		echo '<div><strong>' . esc_html( (string) $last ) . '</strong><span>' . esc_html__( 'Last month', 'prime-fleet' ) . '</span></div>';
		echo '<div><strong class="' . esc_attr( $delta_pct >= 0 ? 'is-up' : 'is-down' ) . '">' . esc_html( $delta_label ) . '</strong><span>' . esc_html__( 'MoM', 'prime-fleet' ) . '</span></div>';
		echo '</div></section>';
	}

	/**
	 * @param array<string,mixed> $data Data.
	 */
	private static function render_catalog( $data ) {
		echo '<section class="prime-fleet-card prime-fleet-card--flush prime-fleet-dash-widget">';
		echo '<header class="prime-fleet-dash-widget__head prime-fleet-dash-widget__head--pad">';
		echo '<h3 class="prime-fleet-dash-widget__title">' . esc_html__( 'Catalog health', 'prime-fleet' ) . '</h3>';
		echo '</header>';
		echo '<ul class="prime-fleet-checklist">';
		self::catalog_row( __( 'Services', 'prime-fleet' ), (int) $data['svc_count'], 'services', (int) $data['svc_count'] > 0 );
		self::catalog_row( __( 'Zones', 'prime-fleet' ), (int) $data['zone_count'], 'zones', (int) $data['zone_count'] > 0 );
		self::catalog_row( __( 'Active fleet', 'prime-fleet' ), (int) $data['fleet_count'], 'vehicles', (int) $data['fleet_count'] > 0 );
		self::catalog_row( __( 'Payments', 'prime-fleet' ), __( 'Review', 'prime-fleet' ), 'payments', false );
		echo '</ul></section>';
	}

	/**
	 * @param string     $label Label.
	 * @param int|string $value Value.
	 * @param string     $panel Panel.
	 * @param bool       $done Done.
	 */
	private static function catalog_row( $label, $value, $panel, $done ) {
		echo '<li' . ( $done ? ' class="is-done"' : '' ) . '>';
		echo '<span>' . esc_html( $label ) . '</span>';
		echo '<em>' . esc_html( is_int( $value ) ? (string) $value : $value ) . '</em>';
		echo View::panel_button( __( 'Open', 'prime-fleet' ), $panel, 'ghost' );
		echo '</li>';
	}

	/**
	 * @param array<string,mixed> $data Data.
	 */
	private static function render_jump( $data ) {
		unset( $data );
		echo '<section class="prime-fleet-card prime-fleet-dash-widget">';
		echo '<header class="prime-fleet-dash-widget__head">';
		echo '<h3 class="prime-fleet-dash-widget__title">' . esc_html__( 'Jump to', 'prime-fleet' ) . '</h3>';
		echo '</header>';
		echo '<div class="prime-fleet-jump">';
		echo View::panel_button( __( 'Airport VIP form', 'prime-fleet' ), 'service_edit', 'ghost', array( 'service_id' => self::airport_id() ) );
		echo View::panel_button( __( 'Global settings', 'prime-fleet' ), 'settings', 'ghost' );
		echo View::panel_button( __( 'Pricing routes', 'prime-fleet' ), 'pricing', 'ghost', array( 'tab' => 'routes' ) );
		echo View::panel_button( __( 'WhatsApp templates', 'prime-fleet' ), 'templates', 'ghost', array( 'mtab' => 'templates' ) );
		echo '</div></section>';
	}

	/**
	 * @param string $status Status.
	 * @return string
	 */
	private static function status_badge( $status ) {
		$map = array(
			'pending_confirmation' => array( 'is-pending', __( 'Pending', 'prime-fleet' ) ),
			'confirmed'            => array( 'is-confirmed', __( 'Confirmed', 'prime-fleet' ) ),
			'cancelled'            => array( 'is-cancelled', __( 'Cancelled', 'prime-fleet' ) ),
			'completed'            => array( 'is-confirmed', __( 'Completed', 'prime-fleet' ) ),
			'no_show'              => array( 'is-cancelled', __( 'No-show', 'prime-fleet' ) ),
		);
		$cfg = isset( $map[ $status ] ) ? $map[ $status ] : array( 'is-pending', ucwords( str_replace( '_', ' ', (string) $status ) ) );
		return '<span class="prime-fleet-dash-badge ' . esc_attr( $cfg[0] ) . '">' . esc_html( $cfg[1] ) . '</span>';
	}

	/**
	 * @param string $status Payment status.
	 * @return string
	 */
	private static function pay_badge( $status ) {
		$map = array(
			'unpaid'                => array( 'is-warn', __( 'Unpaid', 'prime-fleet' ) ),
			'link_sent'             => array( 'is-info', __( 'Link sent', 'prime-fleet' ) ),
			'pending_verification'  => array( 'is-warn', __( 'Verify', 'prime-fleet' ) ),
			'paid'                  => array( 'is-ok', __( 'Paid', 'prime-fleet' ) ),
			'refunded'              => array( 'is-off', __( 'Refunded', 'prime-fleet' ) ),
		);
		$cfg = isset( $map[ $status ] ) ? $map[ $status ] : array( 'is-off', ucwords( str_replace( '_', ' ', (string) $status ) ) );
		return '<span class="prime-fleet-dash-badge ' . esc_attr( $cfg[0] ) . '">' . esc_html( $cfg[1] ) . '</span>';
	}

	/**
	 * @param string $status Comm status.
	 * @return string
	 */
	private static function comm_badge( $status ) {
		$map = array(
			'sent'    => array( 'is-ok', __( 'Sent', 'prime-fleet' ) ),
			'queued'  => array( 'is-info', __( 'Queued', 'prime-fleet' ) ),
			'failed'  => array( 'is-danger', __( 'Failed', 'prime-fleet' ) ),
			'skipped' => array( 'is-off', __( 'Skipped', 'prime-fleet' ) ),
		);
		$cfg = isset( $map[ $status ] ) ? $map[ $status ] : array( 'is-off', ucwords( (string) $status ) );
		return '<span class="prime-fleet-dash-badge ' . esc_attr( $cfg[0] ) . '">' . esc_html( $cfg[1] ) . '</span>';
	}

	/**
	 * @param string $name Name.
	 * @return string
	 */
	private static function initials( $name ) {
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return '?';
		}
		$parts = preg_split( '/\s+/', $name );
		$a     = isset( $parts[0][0] ) ? strtoupper( $parts[0][0] ) : '';
		$b     = ( isset( $parts[1][0] ) ) ? strtoupper( $parts[1][0] ) : '';
		return $a . $b;
	}

	/**
	 * @return int
	 */
	private static function airport_id() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT id FROM " . Schema::table( 'services' ) . " WHERE slug = 'airport-vip-transfer' LIMIT 1" );
	}
}
