<?php
/**
 * Reports — filterable summaries + CSV (plan: export + operational reporting).
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Admin\Pages;

use Prime_Fleet\Admin\Table_List;
use Prime_Fleet\Admin\View;
use Prime_Fleet\Bookings\Amendments;
use Prime_Fleet\Bookings\Occupancy;
use Prime_Fleet\Database\Schema;
use Prime_Fleet\Install\Capabilities;

defined( 'ABSPATH' ) || exit;

final class Reports {

	const PER_PAGE = 15;

	/** CSV download nonce action. */
	const CSV_NONCE = 'prime_fleet_reports_csv';

	/** Assumed shift length when computing available chauffeur hours. */
	const SHIFT_HOURS_PER_DAY = 8.0;

	/**
	 * Admin hooks (CSV download on full page request).
	 */
	public static function hooks() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_export_csv' ) );
	}

	/**
	 * CSV via full admin request (not AJAX panel).
	 */
	public static function maybe_export_csv() {
		if ( empty( $_GET['prime_fleet_reports_csv'] ) || empty( $_GET['page'] ) || 'prime-fleet' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! Capabilities::current_user_can( Capabilities::DISPATCH ) ) {
			wp_die( esc_html__( 'Forbidden.', 'prime-fleet' ) );
		}
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! wp_verify_nonce( $nonce, self::CSV_NONCE ) ) {
			wp_die( esc_html__( 'Invalid security token. Re-open Reports and export again.', 'prime-fleet' ) );
		}
		self::export_csv( self::filters_from_request() );
	}

	/**
	 * Render Reports panel.
	 */
	public static function render() {
		if ( ! Capabilities::current_user_can( Capabilities::DISPATCH ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'prime-fleet' ) );
		}

		$filters = self::filters_from_request();
		$tab     = $filters['tab'];
		$data    = self::gather( $filters );
		$csv_url = self::csv_url( $filters );

		$export = '<a class="prime-fleet-btn" href="' . esc_url( $csv_url ) . '" download="prime-fleet-reports.csv">' . esc_html__( 'Export CSV', 'prime-fleet' ) . '</a>';

		ob_start();
		self::render_tabs( $tab, $filters );
		self::render_filters( $filters, $data, $csv_url );
		self::render_kpis( $data );

		if ( 'revenue' === $tab ) {
			self::render_revenue( $data );
		} elseif ( 'fleet' === $tab ) {
			self::render_fleet( $data );
		} elseif ( 'coupons' === $tab ) {
			self::render_coupons( $data );
		} elseif ( 'comms' === $tab ) {
			self::render_comms( $data );
		} elseif ( 'amendments' === $tab ) {
			self::render_amendments( $data, $filters );
		} elseif ( 'chauffeurs' === $tab ) {
			self::render_chauffeurs( $data, $filters );
		} elseif ( 'noshows' === $tab ) {
			self::render_noshows( $data, $filters );
		} else {
			self::render_overview( $data, $filters );
		}

		View::shell(
			__( 'Reports', 'prime-fleet' ),
			ob_get_clean(),
			array( $export )
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function filters_from_request() {
		$today = current_time( 'Y-m-d' );
		$from  = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$to    = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $from && '' === $to ) {
			$from = gmdate( 'Y-m-01', strtotime( $today . ' 12:00:00' ) );
			$to   = $today;
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$allowed_tabs = array( 'overview', 'revenue', 'fleet', 'chauffeurs', 'noshows', 'coupons', 'comms', 'amendments' );
		if ( ! in_array( $tab, $allowed_tabs, true ) ) {
			$tab = 'overview';
		}

		return array(
			'date_from'  => self::normalize_date( $from, $today ),
			'date_to'    => self::normalize_date( $to, $today ),
			'status'     => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'payment'    => isset( $_GET['payment'] ) ? sanitize_key( wp_unslash( $_GET['payment'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'service_id' => isset( $_GET['service_id'] ) ? absint( $_GET['service_id'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'vehicle_id' => isset( $_GET['vehicle_id'] ) ? absint( $_GET['vehicle_id'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'tab'        => $tab,
			'paged'      => max( 1, absint( $_GET['paged'] ?? 1 ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);
	}

	/**
	 * @param string $value Raw date.
	 * @param string $fallback Y-m-d.
	 * @return string
	 */
	private static function normalize_date( $value, $fallback ) {
		$value = substr( (string) $value, 0, 10 );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return $fallback;
		}
		return $value;
	}

	/**
	 * @param array<string,mixed> $filters Filters.
	 * @return array{sql:string,params:array}
	 */
	private static function booking_where( array $filters ) {
		$where  = array( "b.status NOT IN ('cancelled')" );
		$params = array();

		if ( ! empty( $filters['date_from'] ) ) {
			$where[]  = 'b.pickup_datetime >= %s';
			$params[] = $filters['date_from'] . ' 00:00:00';
		}
		if ( ! empty( $filters['date_to'] ) ) {
			$where[]  = 'b.pickup_datetime <= %s';
			$params[] = $filters['date_to'] . ' 23:59:59';
		}
		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'b.status = %s';
			$params[] = $filters['status'];
		}
		if ( ! empty( $filters['payment'] ) ) {
			$where[]  = 'b.payment_status = %s';
			$params[] = $filters['payment'];
		}
		if ( ! empty( $filters['service_id'] ) ) {
			$where[]  = 'b.service_id = %d';
			$params[] = (int) $filters['service_id'];
		}
		if ( ! empty( $filters['vehicle_id'] ) ) {
			$where[]  = 'b.assigned_vehicle_id = %d';
			$params[] = (int) $filters['vehicle_id'];
		}

		return array(
			'sql'    => implode( ' AND ', $where ),
			'params' => $params,
		);
	}

	/**
	 * @param array<string,mixed> $filters Filters.
	 * @return array<string,mixed>
	 */
	private static function gather( array $filters ) {
		global $wpdb;

		$bookings = Schema::table( 'bookings' );
		$services = Schema::table( 'services' );
		$vehicles = Schema::table( 'vehicles' );
		$comms    = Schema::table( 'communications' );
		$coupons  = Schema::table( 'coupons' );
		$redeems  = Schema::table( 'coupon_redemptions' );

		$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bookings ) );
		$w      = self::booking_where( $filters );

		$out = array(
			'filters'         => $filters,
			'currency'        => 'USD',
			'bookings_count'  => 0,
			'revenue_total'   => 0.0,
			'avg_fare'        => 0.0,
			'unpaid_count'    => 0,
			'unpaid_total'    => 0.0,
			'pending_count'   => 0,
			'no_show_count'   => 0,
			'by_status'       => array(),
			'by_payment'      => array(),
			'by_service'      => array(),
			'fleet_load'      => array(),
			'fleet_active'    => 0,
			'comms_sent'      => 0,
			'comms_failed'      => 0,
			'comms_by'          => array(),
			'coupon_rows'       => array(),
			'preview_rows'      => array(),
			'preview_total'     => 0,
			'services'          => array(),
			'vehicles'          => array(),
			'amendments_count'       => 0,
			'amendments_total'       => 0.0,
			'amendments_by'          => array(),
			'amendment_rows'         => array(),
			'amendments_paged'       => 0,
			'chauffeur_rows'         => array(),
			'chauffeur_active'       => 0,
			'chauffeur_jobs'         => 0,
			'chauffeur_hours'        => 0.0,
			'chauffeur_available'    => 0.0,
			'chauffeur_utilization'  => 0.0,
			'chauffeur_shift_hours'  => self::SHIFT_HOURS_PER_DAY,
			'chauffeur_days'         => 1,
			'noshow_count'           => 0,
			'noshow_fare'            => 0.0,
			'noshow_unpaid'          => 0,
			'noshow_unpaid_fare'     => 0.0,
			'noshow_rate'            => 0.0,
			'noshow_pool'            => 0,
			'noshow_by_service'      => array(),
			'noshow_by_vehicle'      => array(),
			'noshow_rows'            => array(),
			'noshow_paged'           => 0,
		);

		$out['services'] = $wpdb->get_results( "SELECT id, name FROM {$services} WHERE is_active = 1 ORDER BY name ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out['vehicles'] = $wpdb->get_results( "SELECT id, name, customer_display_name FROM {$vehicles} WHERE is_active = 1 ORDER BY display_order ASC, name ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out['fleet_active'] = count( $out['vehicles'] );

		if ( ! $exists ) {
			return $out;
		}

		$sum_sql = "SELECT
			COUNT(*) AS bookings_count,
			COALESCE(SUM(b.fare_total),0) AS revenue_total,
			COALESCE(AVG(b.fare_total),0) AS avg_fare,
			SUM(CASE WHEN b.payment_status IN ('unpaid','link_sent','pending_verification') THEN 1 ELSE 0 END) AS unpaid_count,
			COALESCE(SUM(CASE WHEN b.payment_status IN ('unpaid','link_sent','pending_verification') THEN b.fare_total ELSE 0 END),0) AS unpaid_total,
			SUM(CASE WHEN b.status = 'pending_confirmation' THEN 1 ELSE 0 END) AS pending_count,
			SUM(CASE WHEN b.status = 'no_show' THEN 1 ELSE 0 END) AS no_show_count,
			MAX(b.currency) AS currency
		FROM {$bookings} b
		WHERE {$w['sql']}";

		$summary = $w['params']
			? $wpdb->get_row( $wpdb->prepare( $sum_sql, $w['params'] ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_row( $sum_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( is_array( $summary ) ) {
			$out['bookings_count'] = (int) $summary['bookings_count'];
			$out['revenue_total']  = (float) $summary['revenue_total'];
			$out['avg_fare']       = (float) $summary['avg_fare'];
			$out['unpaid_count']   = (int) $summary['unpaid_count'];
			$out['unpaid_total']   = (float) $summary['unpaid_total'];
			$out['pending_count']  = (int) $summary['pending_count'];
			$out['no_show_count']  = (int) $summary['no_show_count'];
			if ( ! empty( $summary['currency'] ) ) {
				$out['currency'] = (string) $summary['currency'];
			}
		}

		$status_sql = "SELECT b.status AS k, COUNT(*) AS c, COALESCE(SUM(b.fare_total),0) AS t
			FROM {$bookings} b WHERE {$w['sql']} GROUP BY b.status ORDER BY c DESC";
		$out['by_status'] = $w['params']
			? $wpdb->get_results( $wpdb->prepare( $status_sql, $w['params'] ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_results( $status_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$pay_sql = "SELECT b.payment_status AS k, COUNT(*) AS c, COALESCE(SUM(b.fare_total),0) AS t
			FROM {$bookings} b WHERE {$w['sql']} GROUP BY b.payment_status ORDER BY c DESC";
		$out['by_payment'] = $w['params']
			? $wpdb->get_results( $wpdb->prepare( $pay_sql, $w['params'] ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_results( $pay_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$svc_sql = "SELECT COALESCE(NULLIF(s.name,''), CONCAT('#', b.service_id)) AS k, COUNT(*) AS c, COALESCE(SUM(b.fare_total),0) AS t
			FROM {$bookings} b
			LEFT JOIN {$services} s ON s.id = b.service_id
			WHERE {$w['sql']}
			GROUP BY b.service_id, s.name
			ORDER BY t DESC
			LIMIT 12";
		$out['by_service'] = $w['params']
			? $wpdb->get_results( $wpdb->prepare( $svc_sql, $w['params'] ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_results( $svc_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$fleet_sql = "SELECT
				v.id,
				COALESCE(NULLIF(v.customer_display_name,''), v.name, CONCAT('#', v.id)) AS label,
				COUNT(b.id) AS jobs,
				COALESCE(SUM(b.fare_total),0) AS revenue
			FROM {$vehicles} v
			LEFT JOIN {$bookings} b ON b.assigned_vehicle_id = v.id AND {$w['sql']}
			WHERE v.is_active = 1
			GROUP BY v.id, v.customer_display_name, v.name
			ORDER BY jobs DESC, label ASC
			LIMIT 20";
		$out['fleet_load'] = $w['params']
			? $wpdb->get_results( $wpdb->prepare( $fleet_sql, $w['params'] ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_results( $fleet_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		/* Comms in same pickup window via booking join when possible; else by created_at. */
		$comms_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $comms ) );
		if ( $comms_exists ) {
			$c_where  = array( '1=1' );
			$c_params = array();
			if ( ! empty( $filters['date_from'] ) ) {
				$c_where[]  = 'c.created_at >= %s';
				$c_params[] = $filters['date_from'] . ' 00:00:00';
			}
			if ( ! empty( $filters['date_to'] ) ) {
				$c_where[]  = 'c.created_at <= %s';
				$c_params[] = $filters['date_to'] . ' 23:59:59';
			}
			$c_sql = implode( ' AND ', $c_where );
			$out['comms_sent'] = (int) $wpdb->get_var(
				$c_params
					? $wpdb->prepare( "SELECT COUNT(*) FROM {$comms} c WHERE {$c_sql} AND c.status IN ('sent','delivered','queued')", $c_params ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					: "SELECT COUNT(*) FROM {$comms} c WHERE {$c_sql} AND c.status IN ('sent','delivered','queued')" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			);
			$out['comms_failed'] = (int) $wpdb->get_var(
				$c_params
					? $wpdb->prepare( "SELECT COUNT(*) FROM {$comms} c WHERE {$c_sql} AND c.status = 'failed'", $c_params ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					: "SELECT COUNT(*) FROM {$comms} c WHERE {$c_sql} AND c.status = 'failed'" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			);
			$by_sql = "SELECT c.channel AS channel, c.status AS status, COUNT(*) AS c
				FROM {$comms} c WHERE {$c_sql}
				GROUP BY c.channel, c.status
				ORDER BY c DESC
				LIMIT 24";
			$out['comms_by'] = $c_params
				? $wpdb->get_results( $wpdb->prepare( $by_sql, $c_params ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				: $wpdb->get_results( $by_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$redeems_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $redeems ) );
		if ( $redeems_exists ) {
			$r_where  = array( '1=1' );
			$r_params = array();
			if ( ! empty( $filters['date_from'] ) ) {
				$r_where[]  = 'r.used_at >= %s';
				$r_params[] = $filters['date_from'] . ' 00:00:00';
			}
			if ( ! empty( $filters['date_to'] ) ) {
				$r_where[]  = 'r.used_at <= %s';
				$r_params[] = $filters['date_to'] . ' 23:59:59';
			}
			$r_sql = implode( ' AND ', $r_where );
			$coupon_sql = "SELECT COALESCE(NULLIF(co.code,''), CONCAT('#', r.coupon_id)) AS code, COUNT(*) AS uses
				FROM {$redeems} r
				LEFT JOIN {$coupons} co ON co.id = r.coupon_id
				WHERE {$r_sql}
				GROUP BY r.coupon_id, co.code
				ORDER BY uses DESC
				LIMIT 20";
			$out['coupon_rows'] = $r_params
				? $wpdb->get_results( $wpdb->prepare( $coupon_sql, $r_params ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				: $wpdb->get_results( $coupon_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$out['preview_total'] = $out['bookings_count'];
		$offset               = ( max( 1, (int) $filters['paged'] ) - 1 ) * self::PER_PAGE;
		$preview_sql          = "SELECT b.id, b.reference, b.status, b.payment_status, b.customer_name,
				b.pickup_datetime, b.fare_total, b.currency, b.coupon_code,
				s.name AS service_name,
				COALESCE(NULLIF(v.customer_display_name,''), v.name, '') AS vehicle_label
			FROM {$bookings} b
			LEFT JOIN {$services} s ON s.id = b.service_id
			LEFT JOIN {$vehicles} v ON v.id = b.assigned_vehicle_id
			WHERE {$w['sql']}
			ORDER BY b.pickup_datetime DESC
			LIMIT %d OFFSET %d";
		$preview_params = array_merge( $w['params'], array( self::PER_PAGE, $offset ) );
		$out['preview_rows'] = $wpdb->get_results( $wpdb->prepare( $preview_sql, $preview_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$adj_table = Schema::table( 'booking_adjustments' );
		$adj_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $adj_table ) );
		if ( $adj_exists ) {
			$a_where  = array( '1=1' );
			$a_params = array();
			if ( ! empty( $filters['date_from'] ) ) {
				$a_where[]  = 'a.created_at >= %s';
				$a_params[] = $filters['date_from'] . ' 00:00:00';
			}
			if ( ! empty( $filters['date_to'] ) ) {
				$a_where[]  = 'a.created_at <= %s';
				$a_params[] = $filters['date_to'] . ' 23:59:59';
			}
			if ( ! empty( $filters['service_id'] ) ) {
				$a_where[]  = 'b.service_id = %d';
				$a_params[] = (int) $filters['service_id'];
			}
			if ( ! empty( $filters['vehicle_id'] ) ) {
				$a_where[]  = 'b.assigned_vehicle_id = %d';
				$a_params[] = (int) $filters['vehicle_id'];
			}
			if ( ! empty( $filters['status'] ) ) {
				$a_where[]  = 'b.status = %s';
				$a_params[] = $filters['status'];
			}
			$a_sql = implode( ' AND ', $a_where );

			$sum_adj_sql = "SELECT COUNT(*) AS c, COALESCE(SUM(a.amount),0) AS t
				FROM {$adj_table} a
				INNER JOIN {$bookings} b ON b.id = a.booking_id
				WHERE {$a_sql}";
			$sum_adj = $a_params
				? $wpdb->get_row( $wpdb->prepare( $sum_adj_sql, $a_params ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				: $wpdb->get_row( $sum_adj_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( is_array( $sum_adj ) ) {
				$out['amendments_count'] = (int) $sum_adj['c'];
				$out['amendments_total'] = (float) $sum_adj['t'];
			}

			$by_adj_sql = "SELECT a.adjustment_type AS k, COUNT(*) AS c, COALESCE(SUM(a.amount),0) AS t
				FROM {$adj_table} a
				INNER JOIN {$bookings} b ON b.id = a.booking_id
				WHERE {$a_sql}
				GROUP BY a.adjustment_type
				ORDER BY t DESC";
			$out['amendments_by'] = $a_params
				? $wpdb->get_results( $wpdb->prepare( $by_adj_sql, $a_params ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				: $wpdb->get_results( $by_adj_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			$out['amendments_paged'] = $out['amendments_count'];
			$a_offset = ( max( 1, (int) $filters['paged'] ) - 1 ) * self::PER_PAGE;
			$list_sql = "SELECT a.id, a.adjustment_type, a.qty, a.amount, a.reason, a.created_at, a.user_id,
					b.id AS booking_id, b.reference, b.currency, b.customer_name, b.fare_total,
					s.name AS service_name
				FROM {$adj_table} a
				INNER JOIN {$bookings} b ON b.id = a.booking_id
				LEFT JOIN {$services} s ON s.id = b.service_id
				WHERE {$a_sql}
				ORDER BY a.created_at DESC
				LIMIT %d OFFSET %d";
			$list_params = array_merge( $a_params, array( self::PER_PAGE, $a_offset ) );
			$out['amendment_rows'] = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$out = array_merge( $out, self::gather_chauffeur_hours( $filters ) );
		$out = array_merge( $out, self::gather_noshows( $filters ) );

		return $out;
	}

	/**
	 * No-show bookings in the filter range (status forced to no_show).
	 *
	 * @param array<string,mixed> $filters Filters.
	 * @return array<string,mixed>
	 */
	private static function gather_noshows( array $filters ) {
		global $wpdb;

		$bookings = Schema::table( 'bookings' );
		$services = Schema::table( 'services' );
		$vehicles = Schema::table( 'vehicles' );

		$empty = array(
			'noshow_count'       => 0,
			'noshow_fare'        => 0.0,
			'noshow_unpaid'      => 0,
			'noshow_unpaid_fare' => 0.0,
			'noshow_rate'        => 0.0,
			'noshow_pool'        => 0,
			'noshow_by_service'  => array(),
			'noshow_by_vehicle'  => array(),
			'noshow_rows'        => array(),
			'noshow_paged'       => 0,
		);

		$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bookings ) );
		if ( ! $exists ) {
			return $empty;
		}

		/* Rate denominator: same filters, any status (still excludes cancelled). */
		$base_filters           = $filters;
		$base_filters['status'] = '';
		$w_base                 = self::booking_where( $base_filters );
		$pool_sql               = "SELECT COUNT(*) FROM {$bookings} b WHERE {$w_base['sql']}";
		$pool                   = (int) ( $w_base['params']
			? $wpdb->get_var( $wpdb->prepare( $pool_sql, $w_base['params'] ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_var( $pool_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$ns_filters           = $filters;
		$ns_filters['status'] = 'no_show';
		$w                    = self::booking_where( $ns_filters );

		$sum_sql = "SELECT
				COUNT(*) AS c,
				COALESCE(SUM(b.fare_total),0) AS fare,
				SUM(CASE WHEN b.payment_status IN ('unpaid','link_sent','pending_verification') THEN 1 ELSE 0 END) AS unpaid_c,
				COALESCE(SUM(CASE WHEN b.payment_status IN ('unpaid','link_sent','pending_verification') THEN b.fare_total ELSE 0 END),0) AS unpaid_fare,
				MAX(b.currency) AS currency
			FROM {$bookings} b
			WHERE {$w['sql']}";
		$summary = $w['params']
			? $wpdb->get_row( $wpdb->prepare( $sum_sql, $w['params'] ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_row( $sum_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$count = is_array( $summary ) ? (int) $summary['c'] : 0;
		$fare  = is_array( $summary ) ? (float) $summary['fare'] : 0.0;
		$rate  = $pool > 0 ? round( ( $count / $pool ) * 100, 1 ) : 0.0;

		$svc_sql = "SELECT COALESCE(NULLIF(s.name,''), CONCAT('#', b.service_id)) AS k, COUNT(*) AS c, COALESCE(SUM(b.fare_total),0) AS t
			FROM {$bookings} b
			LEFT JOIN {$services} s ON s.id = b.service_id
			WHERE {$w['sql']}
			GROUP BY b.service_id, s.name
			ORDER BY c DESC, t DESC
			LIMIT 12";
		$by_svc = $w['params']
			? $wpdb->get_results( $wpdb->prepare( $svc_sql, $w['params'] ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_results( $svc_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$veh_sql = "SELECT
				COALESCE(NULLIF(v.customer_display_name,''), NULLIF(v.name,''), IF(b.assigned_vehicle_id > 0, CONCAT('#', b.assigned_vehicle_id), %s)) AS k,
				COUNT(*) AS c,
				COALESCE(SUM(b.fare_total),0) AS t
			FROM {$bookings} b
			LEFT JOIN {$vehicles} v ON v.id = b.assigned_vehicle_id
			WHERE {$w['sql']}
			GROUP BY b.assigned_vehicle_id, v.customer_display_name, v.name
			ORDER BY c DESC, t DESC
			LIMIT 12";
		$unassigned = __( 'Unassigned', 'prime-fleet' );
		$veh_params = array_merge( array( $unassigned ), $w['params'] );
		$by_veh     = $wpdb->get_results( $wpdb->prepare( $veh_sql, $veh_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$offset = ( max( 1, (int) $filters['paged'] ) - 1 ) * self::PER_PAGE;
		$list_sql = "SELECT b.id, b.reference, b.payment_status, b.customer_name, b.customer_phone,
				b.pickup_datetime, b.fare_total, b.currency,
				s.name AS service_name,
				COALESCE(NULLIF(v.customer_display_name,''), v.name, '') AS vehicle_label
			FROM {$bookings} b
			LEFT JOIN {$services} s ON s.id = b.service_id
			LEFT JOIN {$vehicles} v ON v.id = b.assigned_vehicle_id
			WHERE {$w['sql']}
			ORDER BY b.pickup_datetime DESC
			LIMIT %d OFFSET %d";
		$list_params = array_merge( $w['params'], array( self::PER_PAGE, $offset ) );
		$rows        = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'noshow_count'       => $count,
			'noshow_fare'        => $fare,
			'noshow_unpaid'      => is_array( $summary ) ? (int) $summary['unpaid_c'] : 0,
			'noshow_unpaid_fare' => is_array( $summary ) ? (float) $summary['unpaid_fare'] : 0.0,
			'noshow_rate'        => $rate,
			'noshow_pool'        => $pool,
			'noshow_by_service'  => is_array( $by_svc ) ? $by_svc : array(),
			'noshow_by_vehicle'  => is_array( $by_veh ) ? $by_veh : array(),
			'noshow_rows'        => is_array( $rows ) ? $rows : array(),
			'noshow_paged'       => $count,
		);
	}

	/**
	 * Chauffeur hours committed vs available for the filter range.
	 *
	 * @param array<string,mixed> $filters Filters.
	 * @return array<string,mixed>
	 */
	private static function gather_chauffeur_hours( array $filters ) {
		global $wpdb;

		$chauffeurs_t = Schema::table( 'chauffeurs' );
		$bookings_t   = Schema::table( 'bookings' );
		$adj_t        = Schema::table( 'booking_adjustments' );
		$meta_t = Schema::table( 'booking_meta' );

		$days  = self::inclusive_days( (string) $filters['date_from'], (string) $filters['date_to'] );
		$shift = self::SHIFT_HOURS_PER_DAY;
		$range_start = (string) $filters['date_from'] . ' 00:00:00';
		$range_end   = (string) $filters['date_to'] . ' 23:59:59';

		$active = $wpdb->get_results(
			"SELECT id, name FROM {$chauffeurs_t} WHERE is_active = 1 ORDER BY name ASC",
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! is_array( $active ) ) {
			$active = array();
		}

		$blocked = self::chauffeur_blocked_hours( $range_start, $range_end );

		$buckets = array();
		foreach ( $active as $c ) {
			$cid   = (int) $c['id'];
			$base  = $days * $shift;
			$minus = isset( $blocked[ $cid ] ) ? (float) $blocked[ $cid ] : 0.0;
			$buckets[ $cid ] = array(
				'id'        => $cid,
				'name'      => (string) $c['name'],
				'jobs'      => 0,
				'hours'     => 0.0,
				'revenue'   => 0.0,
				'available' => round( max( 0, $base - $minus ), 2 ),
			);
		}

		$w = self::booking_where( $filters );
		$ch_sql = "SELECT b.id, b.assigned_chauffeur_id, b.fare_total, b.fare_snapshot_json
			FROM {$bookings_t} b
			WHERE {$w['sql']}
			AND b.assigned_chauffeur_id > 0
			ORDER BY b.pickup_datetime ASC
			LIMIT 5000";
		$assigned = $w['params']
			? $wpdb->get_results( $wpdb->prepare( $ch_sql, $w['params'] ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_results( $ch_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! is_array( $assigned ) ) {
			$assigned = array();
		}

		$extra_hours = array();
		$meta_hours  = array();
		$ids         = array();
		if ( $assigned ) {
			$ids = array_filter(
				array_unique(
					array_map(
						static function ( $r ) {
							return (int) $r['id'];
						},
						$assigned
					)
				)
			);
		}

		$adj_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $adj_t ) );
		if ( $adj_exists && $ids ) {
			$in   = implode( ',', array_map( 'absint', $ids ) );
			$rows = $wpdb->get_results(
				"SELECT booking_id, COALESCE(SUM(qty),0) AS hrs
				FROM {$adj_t}
				WHERE booking_id IN ({$in}) AND adjustment_type = 'extra_hours'
				GROUP BY booking_id",
				ARRAY_A
			); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ( (array) $rows as $row ) {
				$extra_hours[ (int) $row['booking_id'] ] = (float) $row['hrs'];
			}
		}

		$meta_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $meta_t ) );
		if ( $meta_exists && $ids ) {
			$in   = implode( ',', array_map( 'absint', $ids ) );
			$rows = $wpdb->get_results(
				"SELECT booking_id, meta_value
				FROM {$meta_t}
				WHERE booking_id IN ({$in}) AND meta_key = 'hours'",
				ARRAY_A
			); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ( (array) $rows as $row ) {
				$val = (float) $row['meta_value'];
				if ( $val > 0 ) {
					$meta_hours[ (int) $row['booking_id'] ] = $val;
				}
			}
		}

		$default_h  = Occupancy::DEFAULT_DURATION_MIN / 60.0;
		$total_jobs = 0;
		$total_hrs  = 0.0;

		foreach ( $assigned as $b ) {
			$cid = (int) $b['assigned_chauffeur_id'];
			if ( ! isset( $buckets[ $cid ] ) ) {
				$base  = $days * $shift;
				$minus = isset( $blocked[ $cid ] ) ? (float) $blocked[ $cid ] : 0.0;
				$buckets[ $cid ] = array(
					'id'        => $cid,
					'name'      => sprintf( /* translators: %d chauffeur id */ __( 'Chauffeur #%d', 'prime-fleet' ), $cid ),
					'jobs'      => 0,
					'hours'     => 0.0,
					'revenue'   => 0.0,
					'available' => round( max( 0, $base - $minus ), 2 ),
				);
			}
			$bid = (int) $b['id'];
			$meta = isset( $meta_hours[ $bid ] ) ? (float) $meta_hours[ $bid ] : null;
			$hrs  = self::booking_hours( (string) ( $b['fare_snapshot_json'] ?? '' ), $default_h, $meta );
			$hrs += isset( $extra_hours[ $bid ] ) ? (float) $extra_hours[ $bid ] : 0.0;
			$buckets[ $cid ]['jobs']++;
			$buckets[ $cid ]['hours']   = round( $buckets[ $cid ]['hours'] + $hrs, 2 );
			$buckets[ $cid ]['revenue'] = round( $buckets[ $cid ]['revenue'] + (float) $b['fare_total'], 2 );
			$total_jobs++;
			$total_hrs += $hrs;
		}

		$rows = array_values( $buckets );
		usort(
			$rows,
			static function ( $a, $b ) {
				if ( $a['hours'] === $b['hours'] ) {
					return strcasecmp( (string) $a['name'], (string) $b['name'] );
				}
				return ( $a['hours'] < $b['hours'] ) ? 1 : -1;
			}
		);

		foreach ( $rows as &$row ) {
			$avail = (float) $row['available'];
			$row['utilization'] = $avail > 0 ? round( min( 999, ( (float) $row['hours'] / $avail ) * 100 ), 1 ) : 0.0;
		}
		unset( $row );

		$active_n = count( $active );
		$available_total = 0.0;
		foreach ( $active as $c ) {
			$cid = (int) $c['id'];
			$available_total += isset( $buckets[ $cid ] ) ? (float) $buckets[ $cid ]['available'] : ( $days * $shift );
		}
		$available_total = round( $available_total, 2 );
		$util = $available_total > 0 ? round( min( 999, ( $total_hrs / $available_total ) * 100 ), 1 ) : 0.0;

		return array(
			'chauffeur_rows'        => $rows,
			'chauffeur_active'      => $active_n,
			'chauffeur_jobs'        => $total_jobs,
			'chauffeur_hours'       => round( $total_hrs, 2 ),
			'chauffeur_available'   => $available_total,
			'chauffeur_utilization' => $util,
			'chauffeur_shift_hours' => $shift,
			'chauffeur_days'        => $days,
		);
	}

	/**
	 * Inclusive calendar days between two Y-m-d dates.
	 *
	 * @param string $from From.
	 * @param string $to To.
	 * @return int
	 */
	private static function inclusive_days( $from, $to ) {
		$a = strtotime( $from . ' 12:00:00' );
		$b = strtotime( $to . ' 12:00:00' );
		if ( ! $a || ! $b ) {
			return 1;
		}
		if ( $b < $a ) {
			$tmp = $a;
			$a   = $b;
			$b   = $tmp;
		}
		return max( 1, (int) floor( ( $b - $a ) / DAY_IN_SECONDS ) + 1 );
	}

	/**
	 * Trip hours: booking_meta hours → fare snapshot → default.
	 *
	 * @param string     $snapshot_json Snapshot JSON.
	 * @param float      $default Default hours.
	 * @param float|null $meta_hours Meta hours if known.
	 * @return float
	 */
	private static function booking_hours( $snapshot_json, $default, $meta_hours = null ) {
		if ( null !== $meta_hours && $meta_hours > 0 ) {
			return max( 0.25, (float) $meta_hours );
		}
		$snap = json_decode( (string) $snapshot_json, true );
		if ( ! is_array( $snap ) ) {
			return (float) $default;
		}
		if ( ! empty( $snap['breakdown']['hours'] ) ) {
			return max( 0.25, (float) $snap['breakdown']['hours'] );
		}
		if ( ! empty( $snap['hours'] ) ) {
			return max( 0.25, (float) $snap['hours'] );
		}
		return (float) $default;
	}

	/**
	 * Overlapping chauffeur unavailability (hours) keyed by chauffeur id.
	 *
	 * @param string $range_start Y-m-d H:i:s.
	 * @param string $range_end Y-m-d H:i:s.
	 * @return array<int,float>
	 */
	private static function chauffeur_blocked_hours( $range_start, $range_end ) {
		global $wpdb;

		$table = Schema::table( 'availability_blocks' );
		$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return array();
		}

		$rs = strtotime( $range_start );
		$re = strtotime( $range_end );
		if ( ! $rs || ! $re || $re <= $rs ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT resource_id, start_datetime, end_datetime
				FROM {$table}
				WHERE resource_type = 'chauffeur'
				AND start_datetime < %s AND end_datetime > %s",
				$range_end,
				$range_start
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$out = array();
		foreach ( (array) $rows as $row ) {
			$cid = (int) $row['resource_id'];
			$bs  = strtotime( (string) $row['start_datetime'] );
			$be  = strtotime( (string) $row['end_datetime'] );
			if ( ! $cid || ! $bs || ! $be || $be <= $bs ) {
				continue;
			}
			$overlap_start = max( $rs, $bs );
			$overlap_end   = min( $re, $be );
			if ( $overlap_end <= $overlap_start ) {
				continue;
			}
			$hrs = ( $overlap_end - $overlap_start ) / HOUR_IN_SECONDS;
			if ( ! isset( $out[ $cid ] ) ) {
				$out[ $cid ] = 0.0;
			}
			$out[ $cid ] += $hrs;
		}
		foreach ( $out as $cid => $hrs ) {
			$out[ $cid ] = round( $hrs, 2 );
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $filters Filters.
	 */
	private static function export_csv( array $filters ) {
		global $wpdb;

		$bookings = Schema::table( 'bookings' );
		$services = Schema::table( 'services' );
		$vehicles = Schema::table( 'vehicles' );

		if ( 'amendments' === ( $filters['tab'] ?? '' ) ) {
			self::export_amendments_csv( $filters );
			return;
		}
		if ( 'chauffeurs' === ( $filters['tab'] ?? '' ) ) {
			self::export_chauffeurs_csv( $filters );
			return;
		}
		if ( 'noshows' === ( $filters['tab'] ?? '' ) ) {
			self::export_noshows_csv( $filters );
			return;
		}

		$w = self::booking_where( $filters );

		$sql = "SELECT b.reference, b.status, b.payment_status, b.customer_name, b.customer_email, b.customer_phone,
				b.pickup_datetime, b.fare_total, b.currency, b.coupon_code, b.manual_review, b.created_at,
				s.name AS service_name,
				COALESCE(NULLIF(v.customer_display_name,''), v.name, '') AS vehicle_label
			FROM {$bookings} b
			LEFT JOIN {$services} s ON s.id = b.service_id
			LEFT JOIN {$vehicles} v ON v.id = b.assigned_vehicle_id
			WHERE {$w['sql']}
			ORDER BY b.pickup_datetime DESC
			LIMIT 5000";

		$rows = $w['params']
			? $wpdb->get_results( $wpdb->prepare( $sql, $w['params'] ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$filename = 'prime-fleet-reports-' . gmdate( 'Ymd-His' ) . '.csv';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Transfer-Encoding: binary' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode( $filename ) );
		$out = fopen( 'php://output', 'w' );
		fputcsv(
			$out,
			array(
				'reference',
				'status',
				'payment_status',
				'service',
				'vehicle',
				'customer_name',
				'customer_email',
				'customer_phone',
				'pickup_datetime',
				'fare_total',
				'currency',
				'coupon_code',
				'manual_review',
				'created_at',
			)
		);
		foreach ( (array) $rows as $r ) {
			fputcsv(
				$out,
				array(
					$r['reference'],
					$r['status'],
					$r['payment_status'],
					$r['service_name'],
					$r['vehicle_label'],
					$r['customer_name'],
					$r['customer_email'],
					$r['customer_phone'],
					$r['pickup_datetime'],
					$r['fare_total'],
					$r['currency'],
					$r['coupon_code'],
					$r['manual_review'],
					$r['created_at'],
				)
			);
		}
		fclose( $out );
		exit;
	}

	/**
	 * @param array<string,mixed> $filters Filters.
	 */
	private static function export_amendments_csv( array $filters ) {
		global $wpdb;

		$adj      = Schema::table( 'booking_adjustments' );
		$bookings = Schema::table( 'bookings' );
		$services = Schema::table( 'services' );

		$a_where  = array( '1=1' );
		$a_params = array();
		if ( ! empty( $filters['date_from'] ) ) {
			$a_where[]  = 'a.created_at >= %s';
			$a_params[] = $filters['date_from'] . ' 00:00:00';
		}
		if ( ! empty( $filters['date_to'] ) ) {
			$a_where[]  = 'a.created_at <= %s';
			$a_params[] = $filters['date_to'] . ' 23:59:59';
		}
		if ( ! empty( $filters['service_id'] ) ) {
			$a_where[]  = 'b.service_id = %d';
			$a_params[] = (int) $filters['service_id'];
		}
		if ( ! empty( $filters['vehicle_id'] ) ) {
			$a_where[]  = 'b.assigned_vehicle_id = %d';
			$a_params[] = (int) $filters['vehicle_id'];
		}
		if ( ! empty( $filters['status'] ) ) {
			$a_where[]  = 'b.status = %s';
			$a_params[] = $filters['status'];
		}
		$a_sql = implode( ' AND ', $a_where );

		$sql = "SELECT a.created_at, a.adjustment_type, a.qty, a.amount, a.reason, a.user_id,
				b.reference, b.currency, b.customer_name, b.fare_total, b.status,
				s.name AS service_name
			FROM {$adj} a
			INNER JOIN {$bookings} b ON b.id = a.booking_id
			LEFT JOIN {$services} s ON s.id = b.service_id
			WHERE {$a_sql}
			ORDER BY a.created_at DESC
			LIMIT 5000";

		$rows = $a_params
			? $wpdb->get_results( $wpdb->prepare( $sql, $a_params ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$filename = 'prime-fleet-amendments-' . gmdate( 'Ymd-His' ) . '.csv';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Transfer-Encoding: binary' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode( $filename ) );
		$out = fopen( 'php://output', 'w' );
		fputcsv(
			$out,
			array(
				'created_at',
				'reference',
				'type',
				'qty',
				'amount',
				'currency',
				'reason',
				'service',
				'customer_name',
				'booking_status',
				'fare_total',
				'user_id',
			)
		);
		foreach ( (array) $rows as $r ) {
			fputcsv(
				$out,
				array(
					$r['created_at'],
					$r['reference'],
					$r['adjustment_type'],
					$r['qty'],
					$r['amount'],
					$r['currency'],
					$r['reason'],
					$r['service_name'],
					$r['customer_name'],
					$r['status'],
					$r['fare_total'],
					$r['user_id'],
				)
			);
		}
		fclose( $out );
		exit;
	}

	/**
	 * @param array<string,mixed> $filters Filters.
	 */
	private static function export_chauffeurs_csv( array $filters ) {
		$data = self::gather_chauffeur_hours( $filters );
		$rows = is_array( $data['chauffeur_rows'] ) ? $data['chauffeur_rows'] : array();

		$filename = 'prime-fleet-chauffeurs-' . gmdate( 'Ymd-His' ) . '.csv';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Transfer-Encoding: binary' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode( $filename ) );
		$out = fopen( 'php://output', 'w' );
		fputcsv(
			$out,
			array(
				'chauffeur',
				'jobs',
				'hours_committed',
				'hours_available',
				'utilization_pct',
				'revenue',
			)
		);
		foreach ( $rows as $r ) {
			fputcsv(
				$out,
				array(
					$r['name'],
					$r['jobs'],
					$r['hours'],
					$r['available'],
					$r['utilization'],
					$r['revenue'],
				)
			);
		}
		fclose( $out );
		exit;
	}

	/**
	 * @param array<string,mixed> $filters Filters.
	 */
	private static function export_noshows_csv( array $filters ) {
		global $wpdb;

		$bookings = Schema::table( 'bookings' );
		$services = Schema::table( 'services' );
		$vehicles = Schema::table( 'vehicles' );

		$ns_filters           = $filters;
		$ns_filters['status'] = 'no_show';
		$w                    = self::booking_where( $ns_filters );

		$sql = "SELECT b.reference, b.payment_status, b.customer_name, b.customer_email, b.customer_phone,
				b.pickup_datetime, b.fare_total, b.currency, b.coupon_code, b.created_at,
				s.name AS service_name,
				COALESCE(NULLIF(v.customer_display_name,''), v.name, '') AS vehicle_label
			FROM {$bookings} b
			LEFT JOIN {$services} s ON s.id = b.service_id
			LEFT JOIN {$vehicles} v ON v.id = b.assigned_vehicle_id
			WHERE {$w['sql']}
			ORDER BY b.pickup_datetime DESC
			LIMIT 5000";

		$rows = $w['params']
			? $wpdb->get_results( $wpdb->prepare( $sql, $w['params'] ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$filename = 'prime-fleet-noshows-' . gmdate( 'Ymd-His' ) . '.csv';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Transfer-Encoding: binary' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode( $filename ) );
		$out = fopen( 'php://output', 'w' );
		fputcsv(
			$out,
			array(
				'reference',
				'payment_status',
				'service',
				'vehicle',
				'customer_name',
				'customer_email',
				'customer_phone',
				'pickup_datetime',
				'fare_total',
				'currency',
				'coupon_code',
				'created_at',
			)
		);
		foreach ( (array) $rows as $r ) {
			fputcsv(
				$out,
				array(
					$r['reference'],
					$r['payment_status'],
					$r['service_name'],
					$r['vehicle_label'],
					$r['customer_name'],
					$r['customer_email'],
					$r['customer_phone'],
					$r['pickup_datetime'],
					$r['fare_total'],
					$r['currency'],
					$r['coupon_code'],
					$r['created_at'],
				)
			);
		}
		fclose( $out );
		exit;
	}

	/**
	 * @param string              $active Active tab.
	 * @param array<string,mixed> $filters Filters.
	 */
	private static function render_tabs( $active, array $filters ) {
		$tabs = array(
			'overview'    => __( 'Overview', 'prime-fleet' ),
			'revenue'     => __( 'Revenue', 'prime-fleet' ),
			'fleet'       => __( 'Fleet', 'prime-fleet' ),
			'chauffeurs'  => __( 'Chauffeurs', 'prime-fleet' ),
			'noshows'     => __( 'No-shows', 'prime-fleet' ),
			'coupons'     => __( 'Coupons', 'prime-fleet' ),
			'comms'       => __( 'Comms', 'prime-fleet' ),
			'amendments'  => __( 'Amendments', 'prime-fleet' ),
		);
		$base = $filters;
		unset( $base['paged'] );

		echo '<div class="prime-fleet-reports__tabs" role="tablist" aria-label="' . esc_attr__( 'Report views', 'prime-fleet' ) . '">';
		foreach ( $tabs as $key => $label ) {
			$q       = array_merge( $base, array( 'tab' => $key ) );
			$q       = array_filter(
				$q,
				static function ( $v ) {
					return '' !== (string) $v && null !== $v && 0 !== $v && '0' !== (string) $v;
				}
			);
			$current = ( $key === $active );
			printf(
				'<button type="button" class="prime-fleet-reports__tab%s" role="tab" aria-selected="%s" data-pf-panel="reports" data-pf-query="%s">%s</button>',
				$current ? ' is-active' : '',
				$current ? 'true' : 'false',
				esc_attr( wp_json_encode( $q ) ),
				esc_html( $label )
			);
		}
		echo '</div>';
	}

	/**
	 * Build filtered CSV download URL.
	 *
	 * @param array<string,mixed> $filters Filters.
	 * @return string
	 */
	private static function csv_url( array $filters ) {
		$q = array();
		foreach ( $filters as $k => $v ) {
			if ( 'paged' === $k ) {
				continue;
			}
			if ( null === $v || '' === (string) $v || 0 === $v || '0' === (string) $v ) {
				continue;
			}
			$q[ $k ] = $v;
		}
		$q['prime_fleet_reports_csv'] = '1';
		return wp_nonce_url(
			admin_url( 'admin.php?page=prime-fleet&panel=reports&' . http_build_query( $q ) ),
			self::CSV_NONCE
		);
	}

	/**
	 * @param array<string,mixed> $filters Filters.
	 * @param array<string,mixed> $data Gathered data (services/vehicles).
	 * @param string              $csv_url Export URL.
	 */
	private static function render_filters( array $filters, array $data, $csv_url ) {
		$statuses = array(
			''                     => __( 'All statuses', 'prime-fleet' ),
			'pending_confirmation' => __( 'Pending', 'prime-fleet' ),
			'confirmed'            => __( 'Confirmed', 'prime-fleet' ),
			'completed'            => __( 'Completed', 'prime-fleet' ),
			'no_show'              => __( 'No-show', 'prime-fleet' ),
		);
		$payments = array(
			''                     => __( 'All payments', 'prime-fleet' ),
			'unpaid'               => __( 'Unpaid', 'prime-fleet' ),
			'link_sent'            => __( 'Link sent', 'prime-fleet' ),
			'pending_verification' => __( 'Pending verification', 'prime-fleet' ),
			'paid'                 => __( 'Paid', 'prime-fleet' ),
		);
		$services = is_array( $data['services'] ?? null ) ? $data['services'] : array();
		$vehicles = is_array( $data['vehicles'] ?? null ) ? $data['vehicles'] : array();

		echo '<form class="prime-fleet-reports__filters prime-fleet-table-tools tablenav top" method="get" data-pf-table-filter="1" data-pf-panel="reports">';
		echo '<input type="hidden" name="tab" value="' . esc_attr( $filters['tab'] ) . '" />';

		echo '<div class="alignleft actions bulkactions prime-fleet-table-tools__actions">';
		echo '<div class="prime-fleet-reports__filter-fields">';
		echo '<label class="screen-reader-text" for="pf-rep-from">' . esc_html__( 'From', 'prime-fleet' ) . '</label>';
		echo '<input id="pf-rep-from" type="date" class="prime-fleet-table-tools__select" name="date_from" value="' . esc_attr( $filters['date_from'] ) . '" />';

		echo '<label class="screen-reader-text" for="pf-rep-to">' . esc_html__( 'To', 'prime-fleet' ) . '</label>';
		echo '<input id="pf-rep-to" type="date" class="prime-fleet-table-tools__select" name="date_to" value="' . esc_attr( $filters['date_to'] ) . '" />';

		echo '<label class="screen-reader-text" for="pf-rep-status">' . esc_html__( 'Filter by status', 'prime-fleet' ) . '</label>';
		echo '<select id="pf-rep-status" class="prime-fleet-table-tools__select" name="status" data-pf-native="1" data-pf-no-search="1">';
		foreach ( $statuses as $val => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( $filters['status'], $val, false ), esc_html( $label ) );
		}
		echo '</select>';

		echo '<label class="screen-reader-text" for="pf-rep-pay">' . esc_html__( 'Filter by payment', 'prime-fleet' ) . '</label>';
		echo '<select id="pf-rep-pay" class="prime-fleet-table-tools__select" name="payment" data-pf-native="1" data-pf-no-search="1">';
		foreach ( $payments as $val => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( $filters['payment'], $val, false ), esc_html( $label ) );
		}
		echo '</select>';

		echo '<label class="screen-reader-text" for="pf-rep-svc">' . esc_html__( 'Filter by service', 'prime-fleet' ) . '</label>';
		echo '<select id="pf-rep-svc" class="prime-fleet-table-tools__select" name="service_id" data-pf-native="1" data-pf-no-search="1">';
		echo '<option value="">' . esc_html__( 'All services', 'prime-fleet' ) . '</option>';
		foreach ( $services as $svc ) {
			$id = (int) ( $svc['id'] ?? 0 );
			printf(
				'<option value="%d"%s>%s</option>',
				$id,
				selected( (int) $filters['service_id'], $id, false ),
				esc_html( (string) ( $svc['name'] ?? ( '#' . $id ) ) )
			);
		}
		echo '</select>';

		echo '<label class="screen-reader-text" for="pf-rep-veh">' . esc_html__( 'Filter by vehicle', 'prime-fleet' ) . '</label>';
		echo '<select id="pf-rep-veh" class="prime-fleet-table-tools__select" name="vehicle_id" data-pf-native="1" data-pf-no-search="1">';
		echo '<option value="">' . esc_html__( 'All vehicles', 'prime-fleet' ) . '</option>';
		foreach ( $vehicles as $veh ) {
			$id    = (int) ( $veh['id'] ?? 0 );
			$label = (string) ( $veh['customer_display_name'] ?: ( $veh['name'] ?? ( '#' . $id ) ) );
			printf(
				'<option value="%d"%s>%s</option>',
				$id,
				selected( (int) $filters['vehicle_id'], $id, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		echo '</div>';

		echo '<div class="prime-fleet-reports__actions">';
		echo '<input type="submit" class="button action" value="' . esc_attr__( 'Filter', 'prime-fleet' ) . '" />';
		echo '<button type="button" class="button" data-pf-table-reset="1">' . esc_html__( 'Reset', 'prime-fleet' ) . '</button>';
		echo '<a class="prime-fleet-table-tools__link" href="' . esc_url( $csv_url ) . '" download="prime-fleet-reports.csv">' . esc_html__( 'Export CSV', 'prime-fleet' ) . '</a>';
		echo '</div>';
		echo '</div>';
		echo '</form>';
	}

	/**
	 * @param array<string,mixed> $data Data.
	 */
	private static function render_kpis( array $data ) {
		$cur = (string) $data['currency'];
		$items = array(
			array( __( 'Bookings', 'prime-fleet' ), number_format_i18n( (int) $data['bookings_count'] ), __( 'In selected range', 'prime-fleet' ), 'navy' ),
			array( __( 'Revenue', 'prime-fleet' ), $cur . ' ' . number_format_i18n( (float) $data['revenue_total'], 2 ), __( 'Sum of fare totals', 'prime-fleet' ), 'pay' ),
			array( __( 'Unpaid', 'prime-fleet' ), (string) (int) $data['unpaid_count'], $cur . ' ' . number_format_i18n( (float) $data['unpaid_total'], 2 ), 'warn' ),
			array( __( 'Pending', 'prime-fleet' ), (string) (int) $data['pending_count'], __( 'Awaiting confirmation', 'prime-fleet' ), 'danger' ),
		);
		echo '<div class="prime-fleet-stats prime-fleet-reports__kpis">';
		foreach ( $items as $item ) {
			printf(
				'<article class="prime-fleet-stat prime-fleet-stat--%s"><span class="prime-fleet-stat__label">%s</span><strong class="prime-fleet-stat__value">%s</strong><span class="prime-fleet-stat__hint">%s</span></article>',
				esc_attr( $item[3] ),
				esc_html( $item[0] ),
				esc_html( $item[1] ),
				esc_html( $item[2] )
			);
		}
		echo '</div>';
	}

	/**
	 * @param array<string,mixed> $data Data.
	 * @param array<string,mixed> $filters Filters.
	 */
	private static function render_overview( array $data, array $filters ) {
		echo '<div class="prime-fleet-reports__grid prime-fleet-reports__grid--3">';
		self::render_breakdown_card( __( 'By status', 'prime-fleet' ), $data['by_status'], $data['currency'] );
		self::render_breakdown_card( __( 'By payment', 'prime-fleet' ), $data['by_payment'], $data['currency'] );
		self::render_breakdown_card( __( 'By service', 'prime-fleet' ), $data['by_service'], $data['currency'] );
		echo '</div>';
		self::render_preview( $data, $filters );
	}

	/**
	 * @param array<string,mixed> $data Data.
	 */
	private static function render_revenue( array $data ) {
		echo '<div class="prime-fleet-reports__grid">';
		self::render_breakdown_card( __( 'Revenue by service', 'prime-fleet' ), $data['by_service'], $data['currency'] );
		self::render_breakdown_card( __( 'Revenue by payment status', 'prime-fleet' ), $data['by_payment'], $data['currency'] );
		echo '</div>';
		echo '<p class="prime-fleet-reports__note">' . esc_html__( 'Totals use recorded fare_total on each booking (includes coupons already applied, plus post-job amendments). See the Amendments tab for extra hours / wait / stops.', 'prime-fleet' ) . '</p>';
	}

	/**
	 * @param array<string,mixed> $data Data.
	 */
	private static function render_fleet( array $data ) {
		$rows = is_array( $data['fleet_load'] ) ? $data['fleet_load'] : array();
		$max  = 1;
		foreach ( $rows as $r ) {
			$max = max( $max, (int) $r['jobs'] );
		}

		echo '<section class="prime-fleet-card prime-fleet-reports__card">';
		echo '<header class="prime-fleet-section__head"><h3>' . esc_html__( 'Fleet load', 'prime-fleet' ) . '</h3>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %d: active vehicle count */
				__( '%d active vehicles · jobs assigned in range', 'prime-fleet' ),
				(int) $data['fleet_active']
			)
		) . '</p></header>';

		if ( empty( $rows ) ) {
			echo '<p class="prime-fleet-muted">' . esc_html__( 'No fleet activity in this range.', 'prime-fleet' ) . '</p>';
		} else {
			$per_page = 6;
			echo '<div class="prime-fleet-dash-pager" data-pf-card-pager="1" data-per-page="' . esc_attr( (string) $per_page ) . '">';
			echo '<ul class="prime-fleet-reports__bars">';
			foreach ( $rows as $r ) {
				$jobs = (int) $r['jobs'];
				$pct  = (int) round( ( $jobs / $max ) * 100 );
				echo '<li class="prime-fleet-reports__bar" data-pf-dash-row="1">';
				echo '<div class="prime-fleet-reports__bar-meta">';
				echo '<strong>' . esc_html( (string) $r['label'] ) . '</strong>';
				echo '<span>' . esc_html( (string) $jobs ) . ' · ' . esc_html( (string) $data['currency'] ) . ' ' . esc_html( number_format_i18n( (float) $r['revenue'], 2 ) ) . '</span>';
				echo '</div>';
				echo '<div class="prime-fleet-reports__bar-track" aria-hidden="true"><span style="width:' . esc_attr( (string) $pct ) . '%"></span></div>';
				echo '</li>';
			}
			echo '</ul>';
			View::render_card_pager( count( $rows ), $per_page );
			echo '</div>';
		}
		echo '</section>';
		echo '<p class="prime-fleet-reports__note">' . esc_html__( 'Vehicle job counts only. See the Chauffeurs tab for hours committed vs available.', 'prime-fleet' ) . '</p>';
	}

	/**
	 * @param array<string,mixed> $data Data.
	 * @param array<string,mixed> $filters Filters.
	 */
	private static function render_chauffeurs( array $data, array $filters ) {
		$cur   = (string) $data['currency'];
		$rows  = is_array( $data['chauffeur_rows'] ) ? $data['chauffeur_rows'] : array();
		$days  = (int) $data['chauffeur_days'];
		$shift = (float) $data['chauffeur_shift_hours'];
		$hours = (float) $data['chauffeur_hours'];
		$avail = (float) $data['chauffeur_available'];
		$util  = (float) $data['chauffeur_utilization'];
		$jobs  = (int) $data['chauffeur_jobs'];
		$active = (int) $data['chauffeur_active'];

		echo '<div class="prime-fleet-stats prime-fleet-reports__kpis prime-fleet-reports__kpis--tight">';
		printf(
			'<article class="prime-fleet-stat prime-fleet-stat--navy"><span class="prime-fleet-stat__label">%s</span><strong class="prime-fleet-stat__value">%s</strong><span class="prime-fleet-stat__hint">%s</span></article>',
			esc_html__( 'Hours committed', 'prime-fleet' ),
			esc_html( number_format_i18n( $hours, 1 ) ),
			esc_html(
				sprintf(
					/* translators: %d job count */
					_n( '%d assigned job', '%d assigned jobs', $jobs, 'prime-fleet' ),
					$jobs
				)
			)
		);
		printf(
			'<article class="prime-fleet-stat prime-fleet-stat--pay"><span class="prime-fleet-stat__label">%s</span><strong class="prime-fleet-stat__value">%s</strong><span class="prime-fleet-stat__hint">%s</span></article>',
			esc_html__( 'Hours available', 'prime-fleet' ),
			esc_html( number_format_i18n( $avail, 1 ) ),
			esc_html(
				sprintf(
					/* translators: 1: active chauffeurs, 2: days, 3: shift hours */
					__( '%1$d chauffeurs × %2$d days × %3$sh', 'prime-fleet' ),
					$active,
					$days,
					rtrim( rtrim( number_format( $shift, 1, '.', '' ), '0' ), '.' )
				)
			)
		);
		printf(
			'<article class="prime-fleet-stat prime-fleet-stat--warn"><span class="prime-fleet-stat__label">%s</span><strong class="prime-fleet-stat__value">%s</strong><span class="prime-fleet-stat__hint">%s</span></article>',
			esc_html__( 'Utilization', 'prime-fleet' ),
			esc_html( number_format_i18n( $util, 1 ) . '%' ),
			esc_html__( 'Committed ÷ available', 'prime-fleet' )
		);
		echo '</div>';

		echo '<section class="prime-fleet-card prime-fleet-card--list prime-fleet-reports__preview">';
		echo '<header class="prime-fleet-section__head"><h3>' . esc_html__( 'By chauffeur', 'prime-fleet' ) . '</h3>';
		echo '<p>' . esc_html__( 'Assigned jobs in the selected pickup range.', 'prime-fleet' ) . '</p></header>';

		if ( empty( $rows ) ) {
			echo '<p class="prime-fleet-muted">' . esc_html__( 'No active chauffeurs configured.', 'prime-fleet' ) . '</p>';
		} else {
			$max_h = 0.01;
			foreach ( $rows as $r ) {
				$max_h = max( $max_h, (float) $r['hours'] );
			}
			$per_page = 8;
			echo '<div class="prime-fleet-dash-pager" data-pf-card-pager="1" data-per-page="' . esc_attr( (string) $per_page ) . '">';
			echo '<div class="prime-fleet-table-wrap"><table class="prime-fleet-table"><thead><tr>';
			echo '<th>' . esc_html__( 'Chauffeur', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Jobs', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Committed', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Available', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Util.', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Revenue', 'prime-fleet' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $rows as $r ) {
				$pct = (int) round( ( (float) $r['hours'] / $max_h ) * 100 );
				echo '<tr data-pf-dash-row="1">';
				echo '<td><strong>' . esc_html( (string) $r['name'] ) . '</strong>';
				echo '<div class="prime-fleet-reports__bar-track" aria-hidden="true" style="margin-top:6px"><span style="width:' . esc_attr( (string) $pct ) . '%"></span></div></td>';
				echo '<td>' . esc_html( (string) (int) $r['jobs'] ) . '</td>';
				echo '<td>' . esc_html( number_format_i18n( (float) $r['hours'], 1 ) ) . ' h</td>';
				echo '<td>' . esc_html( number_format_i18n( (float) $r['available'], 1 ) ) . ' h</td>';
				echo '<td>' . esc_html( number_format_i18n( (float) $r['utilization'], 1 ) ) . '%</td>';
				echo '<td>' . esc_html( $cur . ' ' . number_format_i18n( (float) $r['revenue'], 2 ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table></div>';
			View::render_card_pager( count( $rows ), $per_page );
			echo '</div>';
		}
		echo '</section>';

		echo '<p class="prime-fleet-reports__note">' . esc_html__( 'Committed hours: booking hours meta → fare snapshot → 2h default, plus Extra hours amendments. Available: 8h/day per active chauffeur minus overlapping chauffeur availability blocks.', 'prime-fleet' ) . '</p>';
	}

	/**
	 * @param array<string,mixed> $data Data.
	 * @param array<string,mixed> $filters Filters.
	 */
	private static function render_noshows( array $data, array $filters ) {
		$cur   = (string) $data['currency'];
		$count = (int) $data['noshow_count'];
		$fare  = (float) $data['noshow_fare'];
		$rate  = (float) $data['noshow_rate'];
		$pool  = (int) $data['noshow_pool'];
		$unpaid = (int) $data['noshow_unpaid'];
		$unpaid_fare = (float) $data['noshow_unpaid_fare'];

		echo '<div class="prime-fleet-stats prime-fleet-reports__kpis prime-fleet-reports__kpis--tight">';
		printf(
			'<article class="prime-fleet-stat prime-fleet-stat--danger"><span class="prime-fleet-stat__label">%s</span><strong class="prime-fleet-stat__value">%s</strong><span class="prime-fleet-stat__hint">%s</span></article>',
			esc_html__( 'No-shows', 'prime-fleet' ),
			esc_html( number_format_i18n( $count ) ),
			esc_html__( 'Status = no-show in range', 'prime-fleet' )
		);
		printf(
			'<article class="prime-fleet-stat prime-fleet-stat--warn"><span class="prime-fleet-stat__label">%s</span><strong class="prime-fleet-stat__value">%s</strong><span class="prime-fleet-stat__hint">%s</span></article>',
			esc_html__( 'Lost fare', 'prime-fleet' ),
			esc_html( $cur . ' ' . number_format_i18n( $fare, 2 ) ),
			esc_html__( 'Sum of no-show fare totals', 'prime-fleet' )
		);
		printf(
			'<article class="prime-fleet-stat prime-fleet-stat--navy"><span class="prime-fleet-stat__label">%s</span><strong class="prime-fleet-stat__value">%s</strong><span class="prime-fleet-stat__hint">%s</span></article>',
			esc_html__( 'No-show rate', 'prime-fleet' ),
			esc_html( number_format_i18n( $rate, 1 ) . '%' ),
			esc_html(
				sprintf(
					/* translators: 1: no-shows, 2: bookings in pool */
					__( '%1$d of %2$d bookings (excl. cancelled)', 'prime-fleet' ),
					$count,
					$pool
				)
			)
		);
		printf(
			'<article class="prime-fleet-stat prime-fleet-stat--pay"><span class="prime-fleet-stat__label">%s</span><strong class="prime-fleet-stat__value">%s</strong><span class="prime-fleet-stat__hint">%s</span></article>',
			esc_html__( 'Still unpaid', 'prime-fleet' ),
			esc_html( (string) $unpaid ),
			esc_html( $cur . ' ' . number_format_i18n( $unpaid_fare, 2 ) )
		);
		echo '</div>';

		echo '<div class="prime-fleet-reports__grid">';
		self::render_breakdown_card( __( 'By service', 'prime-fleet' ), $data['noshow_by_service'], $cur );
		self::render_breakdown_card( __( 'By vehicle', 'prime-fleet' ), $data['noshow_by_vehicle'], $cur );
		echo '</div>';

		$rows  = is_array( $data['noshow_rows'] ) ? $data['noshow_rows'] : array();
		$paged = max( 1, (int) $filters['paged'] );

		echo '<section class="prime-fleet-card prime-fleet-card--list prime-fleet-reports__preview">';
		echo '<header class="prime-fleet-section__head"><h3>' . esc_html__( 'No-show bookings', 'prime-fleet' ) . '</h3>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %d: no-show count */
				_n( '%d no-show in range', '%d no-shows in range', $count, 'prime-fleet' ),
				$count
			)
		) . '</p></header>';

		if ( empty( $rows ) ) {
			echo '<p class="prime-fleet-muted">' . esc_html__( 'No no-show bookings in this range.', 'prime-fleet' ) . '</p>';
		} else {
			echo '<div class="prime-fleet-table-wrap"><table class="prime-fleet-table"><thead><tr>';
			echo '<th>' . esc_html__( 'Reference', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Pickup', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Service', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Vehicle', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Customer', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Fare', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Payment', 'prime-fleet' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $rows as $r ) {
				$pickup = $r['pickup_datetime'] ? wp_date( 'M j, Y g:i A', strtotime( (string) $r['pickup_datetime'] ) ) : '—';
				echo '<tr>';
				echo '<td><button type="button" class="prime-fleet-row-link" data-pf-panel="bookings" data-pf-query="' . esc_attr( wp_json_encode( array( 'id' => (int) $r['id'] ) ) ) . '">' . esc_html( (string) $r['reference'] ) . '</button></td>';
				echo '<td>' . esc_html( $pickup ) . '</td>';
				echo '<td>' . esc_html( $r['service_name'] ? (string) $r['service_name'] : '—' ) . '</td>';
				echo '<td>' . esc_html( $r['vehicle_label'] ? (string) $r['vehicle_label'] : '—' ) . '</td>';
				echo '<td>' . esc_html( $r['customer_name'] ? (string) $r['customer_name'] : '—' ) . '</td>';
				echo '<td>' . esc_html( (string) $r['currency'] . ' ' . number_format_i18n( (float) $r['fare_total'], 2 ) ) . '</td>';
				echo '<td>' . esc_html( self::human_key( (string) $r['payment_status'] ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table></div>';
		}

		$pager_args             = $filters;
		$pager_args['per_page'] = self::PER_PAGE;
		$pager_args['paged']    = $paged;
		echo Table_List::pagination( 'reports', $count, $pager_args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</section>';

		echo '<p class="prime-fleet-reports__note">' . esc_html__( 'This tab always filters to status = no-show (the Status dropdown is ignored here). Rate uses all non-cancelled bookings in the same date / payment / service / vehicle filters.', 'prime-fleet' ) . '</p>';
	}

	/**
	 * @param array<string,mixed> $data Data.
	 */
	private static function render_coupons( array $data ) {
		$rows = is_array( $data['coupon_rows'] ) ? $data['coupon_rows'] : array();
		echo '<section class="prime-fleet-card prime-fleet-reports__card">';
		echo '<header class="prime-fleet-section__head"><h3>' . esc_html__( 'Coupon redemptions', 'prime-fleet' ) . '</h3>';
		echo '<p>' . esc_html__( 'By used_at in the selected range.', 'prime-fleet' ) . '</p></header>';
		if ( empty( $rows ) ) {
			echo '<p class="prime-fleet-muted">' . esc_html__( 'No redemptions in this range.', 'prime-fleet' ) . '</p>';
		} else {
			$per_page = 6;
			echo '<div class="prime-fleet-dash-pager" data-pf-card-pager="1" data-per-page="' . esc_attr( (string) $per_page ) . '">';
			echo '<div class="prime-fleet-table-wrap"><table class="prime-fleet-table"><thead><tr><th>' . esc_html__( 'Code', 'prime-fleet' ) . '</th><th>' . esc_html__( 'Uses', 'prime-fleet' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				echo '<tr data-pf-dash-row="1"><td><strong>' . esc_html( (string) $r['code'] ) . '</strong></td><td>' . esc_html( (string) (int) $r['uses'] ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
			View::render_card_pager( count( $rows ), $per_page );
			echo '</div>';
		}
		echo '</section>';
	}

	/**
	 * @param array<string,mixed> $data Data.
	 * @param array<string,mixed> $filters Filters.
	 */
	private static function render_amendments( array $data, array $filters ) {
		$cur   = (string) $data['currency'];
		$count = (int) $data['amendments_count'];
		$total = (float) $data['amendments_total'];
		$labels = Amendments::type_labels();

		echo '<div class="prime-fleet-stats prime-fleet-reports__kpis prime-fleet-reports__kpis--tight">';
		printf(
			'<article class="prime-fleet-stat prime-fleet-stat--navy"><span class="prime-fleet-stat__label">%s</span><strong class="prime-fleet-stat__value">%s</strong><span class="prime-fleet-stat__hint">%s</span></article>',
			esc_html__( 'Amendments', 'prime-fleet' ),
			esc_html( number_format_i18n( $count ) ),
			esc_html__( 'In selected range', 'prime-fleet' )
		);
		printf(
			'<article class="prime-fleet-stat prime-fleet-stat--pay"><span class="prime-fleet-stat__label">%s</span><strong class="prime-fleet-stat__value">%s</strong><span class="prime-fleet-stat__hint">%s</span></article>',
			esc_html__( 'Extra revenue', 'prime-fleet' ),
			esc_html( $cur . ' ' . number_format_i18n( $total, 2 ) ),
			esc_html__( 'Sum of amendment amounts', 'prime-fleet' )
		);
		echo '</div>';

		echo '<div class="prime-fleet-reports__grid">';
		self::render_breakdown_card( __( 'By type', 'prime-fleet' ), $data['amendments_by'], $cur );
		echo '</div>';

		$rows  = is_array( $data['amendment_rows'] ) ? $data['amendment_rows'] : array();
		$paged = max( 1, (int) $filters['paged'] );

		echo '<section class="prime-fleet-card prime-fleet-card--list prime-fleet-reports__preview">';
		echo '<header class="prime-fleet-section__head"><h3>' . esc_html__( 'Amendment log', 'prime-fleet' ) . '</h3>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %d: amendment count */
				_n( '%d amendment in range', '%d amendments in range', $count, 'prime-fleet' ),
				$count
			)
		) . '</p></header>';

		if ( empty( $rows ) ) {
			echo '<p class="prime-fleet-muted">' . esc_html__( 'No amendments in this range.', 'prime-fleet' ) . '</p>';
		} else {
			echo '<div class="prime-fleet-table-wrap"><table class="prime-fleet-table"><thead><tr>';
			echo '<th>' . esc_html__( 'When', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Reference', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Type', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Qty', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Amount', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Reason', 'prime-fleet' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $rows as $r ) {
				$t = (string) ( $r['adjustment_type'] ?? '' );
				echo '<tr>';
				echo '<td>' . esc_html( $r['created_at'] ? wp_date( 'M j, Y g:i A', strtotime( (string) $r['created_at'] ) ) : '—' ) . '</td>';
				echo '<td><button type="button" class="prime-fleet-row-link" data-pf-panel="bookings" data-pf-query="' . esc_attr( wp_json_encode( array( 'id' => (int) $r['booking_id'] ) ) ) . '">' . esc_html( (string) $r['reference'] ) . '</button></td>';
				echo '<td>' . esc_html( isset( $labels[ $t ] ) ? $labels[ $t ] : self::human_key( $t ) ) . '</td>';
				echo '<td>' . esc_html( rtrim( rtrim( number_format( (float) $r['qty'], 2, '.', '' ), '0' ), '.' ) ) . '</td>';
				echo '<td>+' . esc_html( (string) $r['currency'] . ' ' . number_format_i18n( (float) $r['amount'], 2 ) ) . '</td>';
				echo '<td>' . esc_html( $r['reason'] ? (string) $r['reason'] : '—' ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table></div>';
		}

		$pager_args             = $filters;
		$pager_args['per_page'] = self::PER_PAGE;
		$pager_args['paged']    = $paged;
		echo Table_List::pagination( 'reports', $count, $pager_args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</section>';
	}

	/**
	 * @param array<string,mixed> $data Data.
	 */
	private static function render_comms( array $data ) {
		echo '<div class="prime-fleet-stats prime-fleet-reports__kpis prime-fleet-reports__kpis--tight">';
		printf(
			'<article class="prime-fleet-stat prime-fleet-stat--navy"><span class="prime-fleet-stat__label">%s</span><strong class="prime-fleet-stat__value">%s</strong><span class="prime-fleet-stat__hint">%s</span></article>',
			esc_html__( 'Sent / queued', 'prime-fleet' ),
			esc_html( (string) (int) $data['comms_sent'] ),
			esc_html__( 'Email & WhatsApp', 'prime-fleet' )
		);
		printf(
			'<article class="prime-fleet-stat prime-fleet-stat--danger"><span class="prime-fleet-stat__label">%s</span><strong class="prime-fleet-stat__value">%s</strong><span class="prime-fleet-stat__hint">%s</span></article>',
			esc_html__( 'Failed', 'prime-fleet' ),
			esc_html( (string) (int) $data['comms_failed'] ),
			esc_html__( 'Needs retry', 'prime-fleet' )
		);
		echo '</div>';

		$rows = is_array( $data['comms_by'] ) ? $data['comms_by'] : array();
		echo '<section class="prime-fleet-card prime-fleet-reports__card">';
		echo '<header class="prime-fleet-section__head"><h3>' . esc_html__( 'By channel & status', 'prime-fleet' ) . '</h3></header>';
		if ( empty( $rows ) ) {
			echo '<p class="prime-fleet-muted">' . esc_html__( 'No communication rows in this range.', 'prime-fleet' ) . '</p>';
		} else {
			echo '<table class="prime-fleet-table"><thead><tr><th>' . esc_html__( 'Channel', 'prime-fleet' ) . '</th><th>' . esc_html__( 'Status', 'prime-fleet' ) . '</th><th>' . esc_html__( 'Count', 'prime-fleet' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				echo '<tr><td>' . esc_html( (string) $r['channel'] ) . '</td><td>' . esc_html( (string) $r['status'] ) . '</td><td>' . esc_html( (string) (int) $r['c'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</section>';
	}

	/**
	 * @param string              $title Title.
	 * @param array<int,array>    $rows Rows with k,c,t.
	 * @param string              $currency Currency.
	 */
	private static function render_breakdown_card( $title, $rows, $currency ) {
		$rows     = is_array( $rows ) ? $rows : array();
		$per_page = 5;
		echo '<section class="prime-fleet-card prime-fleet-reports__card">';
		echo '<header class="prime-fleet-section__head"><h3>' . esc_html( $title ) . '</h3></header>';
		if ( empty( $rows ) ) {
			echo '<p class="prime-fleet-muted">' . esc_html__( 'Nothing in this range.', 'prime-fleet' ) . '</p>';
		} else {
			echo '<div class="prime-fleet-dash-pager" data-pf-card-pager="1" data-per-page="' . esc_attr( (string) $per_page ) . '">';
			echo '<div class="prime-fleet-table-wrap"><table class="prime-fleet-table"><thead><tr><th>' . esc_html__( 'Label', 'prime-fleet' ) . '</th><th>' . esc_html__( 'Count', 'prime-fleet' ) . '</th><th>' . esc_html__( 'Total', 'prime-fleet' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				$label = self::human_key( (string) ( $r['k'] ?? '' ) );
				echo '<tr data-pf-dash-row="1">';
				echo '<td>' . esc_html( $label ) . '</td>';
				echo '<td>' . esc_html( (string) (int) ( $r['c'] ?? 0 ) ) . '</td>';
				echo '<td>' . esc_html( $currency . ' ' . number_format_i18n( (float) ( $r['t'] ?? 0 ), 2 ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table></div>';
			View::render_card_pager( count( $rows ), $per_page, true );
			echo '</div>';
		}
		echo '</section>';
	}

	/**
	 * @param array<string,mixed> $data Data.
	 * @param array<string,mixed> $filters Filters.
	 */
	private static function render_preview( array $data, array $filters ) {
		$rows  = is_array( $data['preview_rows'] ) ? $data['preview_rows'] : array();
		$total = (int) $data['preview_total'];
		$paged = max( 1, (int) $filters['paged'] );

		echo '<section class="prime-fleet-card prime-fleet-card--list prime-fleet-reports__preview">';
		echo '<header class="prime-fleet-section__head"><h3>' . esc_html__( 'Matching bookings', 'prime-fleet' ) . '</h3>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %d: booking count */
				_n( '%d booking in range', '%d bookings in range', $total, 'prime-fleet' ),
				$total
			)
		) . '</p></header>';

		if ( empty( $rows ) ) {
			echo '<p class="prime-fleet-muted">' . esc_html__( 'No bookings match these filters.', 'prime-fleet' ) . '</p>';
		} else {
			echo '<div class="prime-fleet-table-wrap"><table class="prime-fleet-table"><thead><tr>';
			echo '<th>' . esc_html__( 'Reference', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Pickup', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Service', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Fare', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Payment', 'prime-fleet' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $rows as $r ) {
				$pickup = $r['pickup_datetime'] ? wp_date( 'M j, Y g:i A', strtotime( (string) $r['pickup_datetime'] ) ) : '—';
				echo '<tr>';
				echo '<td><button type="button" class="prime-fleet-row-link" data-pf-panel="bookings" data-pf-query="' . esc_attr( wp_json_encode( array( 'id' => (int) $r['id'] ) ) ) . '">' . esc_html( (string) $r['reference'] ) . '</button></td>';
				echo '<td>' . esc_html( $pickup ) . '</td>';
				echo '<td>' . esc_html( $r['service_name'] ? (string) $r['service_name'] : '—' ) . '</td>';
				echo '<td>' . esc_html( (string) $r['currency'] . ' ' . number_format_i18n( (float) $r['fare_total'], 2 ) ) . '</td>';
				echo '<td>' . esc_html( self::human_key( (string) $r['payment_status'] ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table></div>';
		}

		$pager_args               = $filters;
		$pager_args['per_page']   = self::PER_PAGE;
		$pager_args['paged']      = $paged;
		echo Table_List::pagination( 'reports', $total, $pager_args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</section>';
	}

	/**
	 * @param string $key Raw key.
	 * @return string
	 */
	private static function human_key( $key ) {
		$map = array(
			'pending_confirmation' => __( 'Pending', 'prime-fleet' ),
			'confirmed'            => __( 'Confirmed', 'prime-fleet' ),
			'completed'            => __( 'Completed', 'prime-fleet' ),
			'cancelled'            => __( 'Cancelled', 'prime-fleet' ),
			'no_show'              => __( 'No-show', 'prime-fleet' ),
			'unpaid'               => __( 'Unpaid', 'prime-fleet' ),
			'link_sent'            => __( 'Link sent', 'prime-fleet' ),
			'pending_verification' => __( 'Pending verification', 'prime-fleet' ),
			'paid'                 => __( 'Paid', 'prime-fleet' ),
			'extra_hours'          => __( 'Extra hours', 'prime-fleet' ),
			'waiting'              => __( 'Waiting time', 'prime-fleet' ),
			'extra_stop'           => __( 'Extra stop', 'prime-fleet' ),
			'manual'               => __( 'Manual adjustment', 'prime-fleet' ),
		);
		if ( isset( $map[ $key ] ) ) {
			return $map[ $key ];
		}
		return $key ? ucwords( str_replace( '_', ' ', $key ) ) : '—';
	}
}
