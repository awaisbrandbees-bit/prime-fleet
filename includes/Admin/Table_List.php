<?php
/**
 * Shared admin table filters + pagination.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Admin;

use Prime_Fleet\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class Table_List {

	const PER_PAGE = 10;

	/**
	 * Read list query args from request.
	 *
	 * @return array{s:string,status:string,type:string,payment:string,paged:int,per_page:int}
	 */
	public static function args() {
		return array(
			's'        => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'status'   => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'type'     => isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'payment'  => isset( $_GET['payment'] ) ? sanitize_key( wp_unslash( $_GET['payment'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'paged'    => max( 1, absint( $_GET['paged'] ?? 1 ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'per_page' => self::PER_PAGE,
		);
	}

	/**
	 * Non-empty filter keys for query merging (delete / pagination).
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public static function query_params( array $args ) {
		$out = array();
		if ( '' !== (string) ( $args['s'] ?? '' ) ) {
			$out['s'] = $args['s'];
		}
		if ( '' !== (string) ( $args['status'] ?? '' ) ) {
			$out['status'] = $args['status'];
		}
		if ( '' !== (string) ( $args['type'] ?? '' ) ) {
			$out['type'] = $args['type'];
		}
		if ( '' !== (string) ( $args['payment'] ?? '' ) ) {
			$out['payment'] = $args['payment'];
		}
		if ( '' !== (string) ( $args['tab'] ?? '' ) ) {
			$out['tab'] = sanitize_key( (string) $args['tab'] );
		}
		if ( '' !== (string) ( $args['mtab'] ?? '' ) ) {
			$out['mtab'] = sanitize_key( (string) $args['mtab'] );
		}
		if ( '' !== (string) ( $args['date_from'] ?? '' ) ) {
			$out['date_from'] = (string) $args['date_from'];
		}
		if ( '' !== (string) ( $args['date_to'] ?? '' ) ) {
			$out['date_to'] = (string) $args['date_to'];
		}
		foreach ( array( 'service_id', 'vehicle_id' ) as $id_key ) {
			$id = absint( $args[ $id_key ] ?? 0 );
			if ( $id > 0 ) {
				$out[ $id_key ] = $id;
			}
		}
		$paged = (int) ( $args['paged'] ?? 1 );
		if ( $paged > 1 ) {
			$out['paged'] = $paged;
		}
		return $out;
	}

	/**
	 * Fetch filtered + paginated rows.
	 *
	 * @param string $logical_table Logical table key.
	 * @param array  $config        search_cols, status_col?, type_col?, orderby, args?.
	 * @return array{rows:array,total:int,args:array}
	 */
	public static function fetch( $logical_table, array $config ) {
		global $wpdb;

		$args   = isset( $config['args'] ) && is_array( $config['args'] ) ? $config['args'] : self::args();
		$full   = Schema::table( $logical_table );
		$where  = array( '1=1' );
		$params = array();

		$search = (string) ( $args['s'] ?? '' );
		if ( '' !== $search && ! empty( $config['search_cols'] ) && is_array( $config['search_cols'] ) ) {
			$likes = array();
			foreach ( $config['search_cols'] as $col ) {
				$col = preg_replace( '/[^a-z0-9_]/i', '', (string) $col );
				if ( ! $col ) {
					continue;
				}
				$likes[]  = "`{$col}` LIKE %s";
				$params[] = '%' . $wpdb->esc_like( $search ) . '%';
			}
			if ( $likes ) {
				$where[] = '(' . implode( ' OR ', $likes ) . ')';
			}
		}

		$status_col = ! empty( $config['status_col'] ) ? preg_replace( '/[^a-z0-9_]/i', '', (string) $config['status_col'] ) : '';
		$status     = (string) ( $args['status'] ?? '' );
		if ( $status_col && '' !== $status ) {
			if ( ! empty( $config['status_exact'] ) ) {
				$where[]  = "`{$status_col}` = %s";
				$params[] = $status;
			} elseif ( 'active' === $status ) {
				$where[] = "`{$status_col}` = 1";
			} elseif ( 'inactive' === $status ) {
				$where[] = "`{$status_col}` = 0";
			}
		}

		$type_col = ! empty( $config['type_col'] ) ? preg_replace( '/[^a-z0-9_]/i', '', (string) $config['type_col'] ) : '';
		$type     = (string) ( $args['type'] ?? '' );
		if ( $type_col && '' !== $type ) {
			$where[]  = "`{$type_col}` = %s";
			$params[] = $type;
		}

		$payment_col = ! empty( $config['payment_col'] ) ? preg_replace( '/[^a-z0-9_]/i', '', (string) $config['payment_col'] ) : '';
		$payment     = (string) ( $args['payment'] ?? '' );
		if ( $payment_col && '' !== $payment ) {
			$where[]  = "`{$payment_col}` = %s";
			$params[] = $payment;
		}

		$where_sql = implode( ' AND ', $where );
		$orderby   = ! empty( $config['orderby'] ) ? $config['orderby'] : 'id DESC';
		// Allow only safe order fragments (letters, numbers, commas, spaces, underscores, ASC/DESC).
		if ( ! preg_match( '/^[a-z0-9_`,.\s]+$/i', $orderby ) ) {
			$orderby = 'id DESC';
		}

		$count_sql = "SELECT COUNT(*) FROM {$full} WHERE {$where_sql}";
		$total     = $params
			? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$per_page = max( 1, (int) ( $args['per_page'] ?? self::PER_PAGE ) );
		$pages    = max( 1, (int) ceil( $total / $per_page ) );
		$paged    = min( max( 1, (int) ( $args['paged'] ?? 1 ) ), $pages );
		$offset   = ( $paged - 1 ) * $per_page;

		$sql      = "SELECT * FROM {$full} WHERE {$where_sql} ORDER BY {$orderby} LIMIT %d OFFSET %d";
		$params[] = $per_page;
		$params[] = $offset;
		$rows     = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$args['paged']    = $paged;
		$args['per_page'] = $per_page;

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
			'args'  => $args,
		);
	}

	/**
	 * Filter toolbar HTML.
	 *
	 * @param string $panel Panel key.
	 * @param array  $args Current args.
	 * @param array  $opts placeholder, status, types (assoc).
	 * @return string
	 */
	public static function toolbar( $panel, array $args, array $opts = array() ) {
		$placeholder = $opts['placeholder'] ?? __( 'Search…', 'prime-fleet' );
		$show_status = ! empty( $opts['status'] );
		$types       = isset( $opts['types'] ) && is_array( $opts['types'] ) ? $opts['types'] : array();
		$uid         = 'pf-tools-s-' . sanitize_key( $panel );

		ob_start();
		echo '<form class="prime-fleet-table-tools tablenav top" data-pf-table-filter="1" data-pf-panel="' . esc_attr( $panel ) . '">';

		echo '<div class="alignleft actions bulkactions prime-fleet-table-tools__actions">';
		if ( $show_status ) {
			$statuses = isset( $opts['statuses'] ) && is_array( $opts['statuses'] ) ? $opts['statuses'] : array(
				'active'   => __( 'Active', 'prime-fleet' ),
				'inactive' => __( 'Inactive', 'prime-fleet' ),
			);
			echo '<label class="screen-reader-text" for="pf-tools-status-' . esc_attr( $panel ) . '">' . esc_html__( 'Filter by status', 'prime-fleet' ) . '</label>';
			echo '<select name="status" id="pf-tools-status-' . esc_attr( $panel ) . '" class="prime-fleet-table-tools__select" data-pf-native="1" data-pf-no-search="1">';
			echo '<option value="">' . esc_html__( 'All statuses', 'prime-fleet' ) . '</option>';
			foreach ( $statuses as $value => $label ) {
				printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( $args['status'] ?? '', $value, false ), esc_html( $label ) );
			}
			echo '</select>';
		}

		if ( $types ) {
			echo '<label class="screen-reader-text" for="pf-tools-type-' . esc_attr( $panel ) . '">' . esc_html__( 'Filter by type', 'prime-fleet' ) . '</label>';
			echo '<select name="type" id="pf-tools-type-' . esc_attr( $panel ) . '" class="prime-fleet-table-tools__select" data-pf-native="1" data-pf-no-search="1">';
			echo '<option value="">' . esc_html__( 'All types', 'prime-fleet' ) . '</option>';
			foreach ( $types as $value => $label ) {
				printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( $args['type'] ?? '', $value, false ), esc_html( $label ) );
			}
			echo '</select>';
		}

		if ( ! empty( $opts['payment'] ) ) {
			$payments = isset( $opts['payments'] ) && is_array( $opts['payments'] ) ? $opts['payments'] : array();
			echo '<label class="screen-reader-text" for="pf-tools-payment-' . esc_attr( $panel ) . '">' . esc_html__( 'Filter by payment', 'prime-fleet' ) . '</label>';
			echo '<select name="payment" id="pf-tools-payment-' . esc_attr( $panel ) . '" class="prime-fleet-table-tools__select" data-pf-native="1" data-pf-no-search="1">';
			echo '<option value="">' . esc_html__( 'All payments', 'prime-fleet' ) . '</option>';
			foreach ( $payments as $value => $label ) {
				printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( $args['payment'] ?? '', $value, false ), esc_html( $label ) );
			}
			echo '</select>';
		}

		echo '<input type="submit" class="button action" value="' . esc_attr__( 'Filter', 'prime-fleet' ) . '" />';
		echo '<button type="button" class="button" data-pf-table-reset="1">' . esc_html__( 'Reset', 'prime-fleet' ) . '</button>';
		echo '</div>';

		echo '<p class="search-box prime-fleet-table-tools__search">';
		echo '<label class="screen-reader-text" for="' . esc_attr( $uid ) . '">' . esc_html( $placeholder ) . '</label>';
		echo '<input type="search" id="' . esc_attr( $uid ) . '" name="s" value="' . esc_attr( (string) ( $args['s'] ?? '' ) ) . '" placeholder="' . esc_attr( $placeholder ) . '" autocomplete="off" />';
		echo '<input type="submit" id="search-submit-' . esc_attr( $panel ) . '" class="button" value="' . esc_attr__( 'Search', 'prime-fleet' ) . '" />';
		if ( ! empty( $opts['trailing'] ) && is_string( $opts['trailing'] ) ) {
			echo '<span class="prime-fleet-table-tools__trailing">' . $opts['trailing'] . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</p>';

		echo '<br class="clear" />';
		echo '</form>';
		return ob_get_clean();
	}

	/**
	 * Pagination bar HTML.
	 *
	 * @param string $panel Panel key.
	 * @param int    $total Total rows.
	 * @param array  $args Args.
	 * @return string
	 */
	public static function pagination( $panel, $total, array $args ) {
		$total    = (int) $total;
		$per_page = max( 1, (int) ( $args['per_page'] ?? self::PER_PAGE ) );
		$paged    = max( 1, (int) ( $args['paged'] ?? 1 ) );
		$pages    = max( 1, (int) ceil( $total / $per_page ) );

		if ( $total <= 0 ) {
			return '';
		}

		$from = ( ( $paged - 1 ) * $per_page ) + 1;
		$to   = min( $total, $paged * $per_page );
		$base = self::query_params( $args );
		unset( $base['paged'] );

		ob_start();
		echo '<div class="prime-fleet-table-pager">';
		echo '<p class="prime-fleet-table-pager__meta">' . esc_html(
			sprintf(
				/* translators: 1: from, 2: to, 3: total */
				__( 'Showing %1$d–%2$d of %3$d', 'prime-fleet' ),
				$from,
				$to,
				$total
			)
		) . '</p>';

		if ( $pages > 1 ) {
			echo '<nav class="prime-fleet-table-pager__nav" aria-label="' . esc_attr__( 'Pagination', 'prime-fleet' ) . '">';
			self::page_btn( $panel, $base, $paged - 1, __( 'Prev', 'prime-fleet' ), $paged <= 1 );
			$window = self::page_window( $paged, $pages );
			$prev   = 0;
			foreach ( $window as $p ) {
				if ( $prev && $p > $prev + 1 ) {
					echo '<span class="prime-fleet-table-pager__gap">…</span>';
				}
				$class = 'prime-fleet-table-pager__btn' . ( $p === $paged ? ' is-active' : '' );
				$q     = $base;
				if ( $p > 1 ) {
					$q['paged'] = $p;
				}
				echo '<button type="button" class="' . esc_attr( $class ) . '" data-pf-panel="' . esc_attr( $panel ) . '" data-pf-query="' . esc_attr( wp_json_encode( $q ) ) . '">' . (int) $p . '</button>';
				$prev = $p;
			}
			self::page_btn( $panel, $base, $paged + 1, __( 'Next', 'prime-fleet' ), $paged >= $pages );
			echo '</nav>';
		}
		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * @param int $current Current page.
	 * @param int $pages Total pages.
	 * @return int[]
	 */
	private static function page_window( $current, $pages ) {
		$pages   = max( 1, (int) $pages );
		$current = max( 1, min( (int) $current, $pages ) );
		if ( $pages <= 7 ) {
			return range( 1, $pages );
		}
		$set = array( 1, $pages, $current, $current - 1, $current + 1, $current - 2, $current + 2 );
		$out = array();
		foreach ( $set as $p ) {
			if ( $p >= 1 && $p <= $pages ) {
				$out[ $p ] = $p;
			}
		}
		$vals = array_values( $out );
		sort( $vals, SORT_NUMERIC );
		return $vals;
	}

	/**
	 * @param string $panel Panel.
	 * @param array  $base Base query.
	 * @param int    $page Target page.
	 * @param string $label Label.
	 * @param bool   $disabled Disabled.
	 */
	private static function page_btn( $panel, array $base, $page, $label, $disabled ) {
		if ( $disabled ) {
			echo '<button type="button" class="prime-fleet-table-pager__btn" disabled>' . esc_html( $label ) . '</button>';
			return;
		}
		$q = $base;
		if ( $page > 1 ) {
			$q['paged'] = (int) $page;
		}
		echo '<button type="button" class="prime-fleet-table-pager__btn" data-pf-panel="' . esc_attr( $panel ) . '" data-pf-query="' . esc_attr( wp_json_encode( $q ) ) . '">' . esc_html( $label ) . '</button>';
	}
}
