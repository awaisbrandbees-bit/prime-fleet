<?php
/**
 * System logs admin.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Admin\Pages;

use Prime_Fleet\Admin\Table_List;
use Prime_Fleet\Admin\View;
use Prime_Fleet\Install\Capabilities;

defined( 'ABSPATH' ) || exit;

final class Logs {

	public static function render() {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'prime-fleet' ) );
		}

		$list = Table_List::fetch(
			'system_logs',
			array(
				'search_cols' => array( 'message', 'context' ),
				'type_col'    => 'level',
				'orderby'     => 'id DESC',
			)
		);
		$rows = $list['rows'];
		$args = $list['args'];

		ob_start();
		echo '<div class="prime-fleet-card prime-fleet-card--list">';
		echo Table_List::toolbar(
			'logs',
			$args,
			array(
				'placeholder' => __( 'Search logs…', 'prime-fleet' ),
				'types'       => array(
					'error'   => __( 'Error', 'prime-fleet' ),
					'warning' => __( 'Warning', 'prime-fleet' ),
					'info'    => __( 'Info', 'prime-fleet' ),
					'debug'   => __( 'Debug', 'prime-fleet' ),
				),
			)
		);

		if ( empty( $rows ) ) {
			$filtered = '' !== (string) ( $args['s'] ?? '' ) || '' !== (string) ( $args['type'] ?? '' );
			echo View::empty_state(
				$filtered ? __( 'No matching logs', 'prime-fleet' ) : __( 'No logs yet', 'prime-fleet' ),
				$filtered ? __( 'Try another search or reset filters.', 'prime-fleet' ) : __( 'System errors and operational events will appear here.', 'prime-fleet' )
			);
		} else {
			echo '<div class="prime-fleet-table-wrap"><table class="prime-fleet-table"><thead><tr>';
			echo '<th>' . esc_html__( 'When', 'prime-fleet' ) . '</th><th>' . esc_html__( 'Level', 'prime-fleet' ) . '</th><th>' . esc_html__( 'Context', 'prime-fleet' ) . '</th><th>' . esc_html__( 'Message', 'prime-fleet' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $rows as $row ) {
				echo '<tr>';
				echo '<td>' . esc_html( $row['created_at'] ) . '</td>';
				echo '<td><span class="prime-fleet-badge">' . esc_html( $row['level'] ) . '</span></td>';
				echo '<td>' . esc_html( $row['context'] ) . '</td>';
				echo '<td>' . esc_html( $row['message'] ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table></div>';
			echo Table_List::pagination( 'logs', $list['total'], $args );
		}
		echo '</div>';

		View::shell( __( 'Logs', 'prime-fleet' ), ob_get_clean() );
	}
}
