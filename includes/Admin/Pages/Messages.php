<?php
/**
 * Messages — template editor + WhatsApp outbox.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Admin\Pages;

use Prime_Fleet\Admin\Table_List;
use Prime_Fleet\Admin\View;
use Prime_Fleet\Database\Schema;
use Prime_Fleet\Install\Capabilities;
use Prime_Fleet\Messaging\Templates;

defined( 'ABSPATH' ) || exit;

final class Messages {

	const NONCE = 'prime_fleet_messages';

	public static function render() {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'prime-fleet' ) );
		}

		Templates::ensure();

		$notice = self::handle_save();
		$edit_id = absint( $_GET['edit'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$mtab    = isset( $_GET['mtab'] ) ? sanitize_key( wp_unslash( $_GET['mtab'] ) ) : 'templates'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $mtab, array( 'templates', 'outbox' ), true ) ) {
			$mtab = 'templates';
		}
		if ( $edit_id ) {
			$mtab = 'templates';
		}

		ob_start();
		if ( $notice ) {
			echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		?>
		<div class="prime-fleet-subpages prime-fleet-settings-layout">
			<nav class="prime-fleet-subpages__nav prime-fleet-settings-nav" aria-label="<?php esc_attr_e( 'Messages pages', 'prime-fleet' ); ?>">
				<p class="prime-fleet-settings-nav__label"><?php esc_html_e( 'Messages', 'prime-fleet' ); ?></p>
				<ul class="prime-fleet-settings-nav__list">
					<li>
						<button
							type="button"
							class="prime-fleet-settings-nav__link<?php echo ( 'templates' === $mtab && ! $edit_id ) ? ' is-active' : ''; ?>"
							data-pf-panel="templates"
							data-pf-query="<?php echo esc_attr( wp_json_encode( array( 'mtab' => 'templates' ) ) ); ?>"
							aria-current="<?php echo ( 'templates' === $mtab && ! $edit_id ) ? 'page' : 'false'; ?>"
						><?php esc_html_e( 'Templates', 'prime-fleet' ); ?></button>
					</li>
					<li>
						<button
							type="button"
							class="prime-fleet-settings-nav__link<?php echo ( 'outbox' === $mtab ) ? ' is-active' : ''; ?>"
							data-pf-panel="templates"
							data-pf-query="<?php echo esc_attr( wp_json_encode( array( 'mtab' => 'outbox' ) ) ); ?>"
							aria-current="<?php echo ( 'outbox' === $mtab ) ? 'page' : 'false'; ?>"
						><?php esc_html_e( 'WhatsApp outbox', 'prime-fleet' ); ?></button>
					</li>
				</ul>
			</nav>
			<div class="prime-fleet-subpages__main prime-fleet-settings-main">
				<?php
				if ( $edit_id ) {
					self::render_edit( $edit_id );
				} elseif ( 'outbox' === $mtab ) {
					self::render_outbox();
				} else {
					self::render_list();
				}
				?>
			</div>
		</div>
		<?php
		$title = ( 'outbox' === $mtab ) ? __( 'WhatsApp outbox', 'prime-fleet' ) : __( 'Message templates', 'prime-fleet' );
		if ( $edit_id ) {
			$title = __( 'Edit template', 'prime-fleet' );
		}
		View::shell( $title, ob_get_clean() );
	}

	/**
	 * Template list.
	 */
	private static function render_list() {
		$rows = Templates::all();

		echo '<div class="prime-fleet-card prime-fleet-section">';
		echo '<header class="prime-fleet-section__head">';
		echo '<h3>' . esc_html__( 'Message templates', 'prime-fleet' ) . '</h3>';
		echo '<p>' . esc_html__( 'Edit wording and channel flags without a developer. Changes apply to the next send.', 'prime-fleet' ) . '</p>';
		echo '</header>';

		if ( empty( $rows ) ) {
			echo View::empty_state(
				__( 'No templates', 'prime-fleet' ),
				__( 'Templates will appear after the plugin seeds the message catalog.', 'prime-fleet' )
			);
			echo '</div>';
			return;
		}

		echo '<div class="prime-fleet-table-wrap"><table class="prime-fleet-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Template', 'prime-fleet' ) . '</th>';
		echo '<th>' . esc_html__( 'Key', 'prime-fleet' ) . '</th>';
		echo '<th>' . esc_html__( 'Email', 'prime-fleet' ) . '</th>';
		echo '<th>' . esc_html__( 'WhatsApp', 'prime-fleet' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'prime-fleet' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$email_on = (int) $row['email_enabled'] ? __( 'On', 'prime-fleet' ) : __( 'Off', 'prime-fleet' );
			$wa_on    = (int) $row['whatsapp_enabled'] ? __( 'On', 'prime-fleet' ) : __( 'Off', 'prime-fleet' );
			$enabled  = (int) $row['is_enabled'];
			echo '<tr>';
			echo '<td><strong>' . esc_html( $row['label'] ) . '</strong>';
			if ( $row['subject'] ) {
				echo '<div class="prime-fleet-muted" style="font-size:12px;margin-top:2px;">' . esc_html( $row['subject'] ) . '</div>';
			}
			echo '</td>';
			echo '<td><code>' . esc_html( $row['template_key'] ) . '</code></td>';
			echo '<td><span class="prime-fleet-badge' . ( (int) $row['email_enabled'] ? '' : ' prime-fleet-badge--off' ) . '">' . esc_html( $email_on ) . '</span></td>';
			echo '<td><span class="prime-fleet-badge' . ( (int) $row['whatsapp_enabled'] ? '' : ' prime-fleet-badge--off' ) . '">' . esc_html( $wa_on ) . '</span></td>';
			echo '<td><span class="prime-fleet-badge' . ( $enabled ? ' prime-fleet-badge--ok' : ' prime-fleet-badge--off' ) . '">' . esc_html( $enabled ? __( 'Enabled', 'prime-fleet' ) : __( 'Disabled', 'prime-fleet' ) ) . '</span></td>';
			echo '<td class="prime-fleet-table__actions"><button type="button" class="prime-fleet-row-link" data-pf-panel="templates" data-pf-query="' . esc_attr( wp_json_encode( array( 'edit' => (int) $row['id'], 'mtab' => 'templates' ) ) ) . '">' . esc_html__( 'Edit', 'prime-fleet' ) . '</button></td>';
			echo '</tr>';
		}
		echo '</tbody></table></div></div>';
	}

	/**
	 * @param int $id Template ID.
	 */
	private static function render_edit( $id ) {
		$row = Templates::get_by_id( $id );
		if ( ! $row ) {
			echo View::notice( __( 'Template not found.', 'prime-fleet' ), 'error' );
			self::render_list();
			return;
		}

		$is_reminder = ( 0 === strpos( (string) $row['template_key'], 'reminder_' ) );
		$back        = '<button type="button" class="prime-fleet-btn prime-fleet-btn--ghost" data-pf-panel="templates" data-pf-query="' . esc_attr( wp_json_encode( array( 'mtab' => 'templates' ) ) ) . '">' . esc_html__( '← Back to templates', 'prime-fleet' ) . '</button>';

		echo '<div class="prime-fleet-msg-edit">';
		echo '<div class="prime-fleet-msg-edit__main">';
		echo '<form method="post" class="prime-fleet-card prime-fleet-section" data-pf-ajax-form="1">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="prime_fleet_messages_action" value="save_template" />';
		echo '<input type="hidden" name="template_id" value="' . esc_attr( (string) (int) $row['id'] ) . '" />';

		echo '<header class="prime-fleet-section__head">';
		echo '<h3>' . esc_html( $row['label'] ) . '</h3>';
		echo '<p><code>' . esc_html( $row['template_key'] ) . '</code> · ' . esc_html__( 'Edits apply to the next queued send.', 'prime-fleet' ) . '</p>';
		echo '</header>';

		echo '<div class="prime-fleet-form-grid prime-fleet-form-grid--2">';
		self::text( 'label', __( 'Label', 'prime-fleet' ), $row['label'] );
		self::text( 'subject', __( 'Email subject', 'prime-fleet' ), $row['subject'] );
		self::text( 'heading', __( 'Email heading', 'prime-fleet' ), $row['heading'] );
		self::text( 'whatsapp_template_id', __( 'Meta WhatsApp template name / ID', 'prime-fleet' ), $row['whatsapp_template_id'] );
		if ( $is_reminder ) {
			self::text(
				'reminder_offset_minutes',
				__( 'Reminder offset (minutes before pickup; 0 = use Settings)', 'prime-fleet' ),
				(string) (int) ( $row['reminder_offset_minutes'] ?? 0 )
			);
		}
		echo '</div>';

		echo '<div class="prime-fleet-form-stack" style="margin-top:14px;">';
		self::textarea( 'body_html', __( 'Email body (HTML)', 'prime-fleet' ), (string) $row['body_html'], 14 );
		echo '<p class="prime-fleet-help">' . esc_html__( 'WhatsApp stub sends a plain-text version of the heading + body. Meta Cloud API will use the mapped template name above.', 'prime-fleet' ) . '</p>';
		echo '</div>';

		echo '<div class="prime-fleet-msg-edit__flags">';
		self::check( 'is_enabled', __( 'Template enabled', 'prime-fleet' ), (int) $row['is_enabled'] );
		self::check( 'email_enabled', __( 'Send email', 'prime-fleet' ), (int) $row['email_enabled'] );
		self::check( 'whatsapp_enabled', __( 'Send WhatsApp', 'prime-fleet' ), (int) $row['whatsapp_enabled'] );
		echo '</div>';

		echo '<div class="prime-fleet-sticky-save">';
		echo $back; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<button type="submit" class="prime-fleet-btn">' . esc_html__( 'Save template', 'prime-fleet' ) . '</button>';
		echo '</div>';
		echo '</form>';
		echo '</div>';

		echo '<aside class="prime-fleet-msg-edit__side prime-fleet-card prime-fleet-section">';
		echo '<header class="prime-fleet-section__head"><h3>' . esc_html__( 'Merge tags', 'prime-fleet' ) . '</h3>';
		echo '<p>' . esc_html__( 'Click to copy. Paste into subject, heading, or body.', 'prime-fleet' ) . '</p></header>';
		echo '<ul class="prime-fleet-merge-tags">';
		foreach ( Templates::merge_tag_docs() as $tag => $desc ) {
			$token = '{{' . $tag . '}}';
			echo '<li>';
			echo '<button type="button" class="prime-fleet-merge-tags__btn" data-pf-copy="' . esc_attr( $token ) . '" title="' . esc_attr( $desc ) . '">';
			echo '<code>' . esc_html( $token ) . '</code>';
			echo '<span>' . esc_html( $desc ) . '</span>';
			echo '</button></li>';
		}
		echo '</ul></aside>';
		echo '</div>';
	}

	/**
	 * WhatsApp outbox.
	 */
	private static function render_outbox() {
		global $wpdb;
		$table    = Schema::table( 'communications' );
		$bookings = Schema::table( 'bookings' );
		$per_page = Table_List::PER_PAGE;
		$paged    = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$total    = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE channel = 'whatsapp'"
		);
		$pages  = max( 1, (int) ceil( $total / $per_page ) );
		$paged  = min( $paged, $pages );
		$offset = ( $paged - 1 ) * $per_page;
		$rows   = $total
			? $wpdb->get_results(
				$wpdb->prepare(
					"SELECT c.*, b.reference
					FROM {$table} c
					LEFT JOIN {$bookings} b ON b.id = c.booking_id
					WHERE c.channel = 'whatsapp'
					ORDER BY c.id DESC
					LIMIT %d OFFSET %d",
					$per_page,
					$offset
				),
				ARRAY_A
			)
			: array();

		$settings = get_option( 'prime_fleet_settings', array() );
		$driver   = is_array( $settings ) && ! empty( $settings['whatsapp_driver'] ) ? $settings['whatsapp_driver'] : 'stub';
		$enabled  = ! is_array( $settings ) || ! array_key_exists( 'whatsapp_channel_enabled', $settings )
			? true
			: ! empty( $settings['whatsapp_channel_enabled'] );

		echo '<div class="prime-fleet-card prime-fleet-section">';
		echo '<header class="prime-fleet-section__head">';
		echo '<h3>' . esc_html__( 'WhatsApp outbox', 'prime-fleet' ) . '</h3>';
		echo '<p>' . esc_html__( 'Stub / Meta messages logged for the booking lifecycle.', 'prime-fleet' ) . '</p>';
		echo '</header>';
		echo '<p class="prime-fleet-help">';
		echo esc_html(
			sprintf(
				/* translators: 1: on/off, 2: driver */
				__( 'Channel: %1$s · Driver: %2$s', 'prime-fleet' ),
				$enabled ? __( 'On', 'prime-fleet' ) : __( 'Off', 'prime-fleet' ),
				$driver
			)
		);
		echo ' — <button type="button" class="button-link" data-pf-panel="settings" data-pf-query="' . esc_attr( wp_json_encode( array( 'stab' => 'whatsapp' ) ) ) . '">' . esc_html__( 'WhatsApp settings', 'prime-fleet' ) . '</button>';
		echo '</p>';

		if ( empty( $rows ) ) {
			echo View::empty_state(
				__( 'No WhatsApp messages yet', 'prime-fleet' ),
				__( 'Submit or confirm a booking with WhatsApp enabled on the template. Stub rows appear here instead of a real Meta send.', 'prime-fleet' )
			);
		} else {
			echo '<div class="prime-fleet-table-wrap"><table class="prime-fleet-table"><thead><tr>';
			echo '<th>' . esc_html__( 'When', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Booking', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Template', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'To', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'prime-fleet' ) . '</th>';
			echo '<th>' . esc_html__( 'Preview', 'prime-fleet' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $rows as $row ) {
				$ref = $row['reference'] ? $row['reference'] : ( '#' . (int) $row['booking_id'] );
				echo '<tr>';
				echo '<td>' . esc_html( date_i18n( 'M j, Y · g:i A', strtotime( $row['created_at'] ) ) ) . '</td>';
				echo '<td>' . esc_html( $ref ) . '</td>';
				echo '<td><code>' . esc_html( $row['template_key'] ) . '</code></td>';
				echo '<td>' . esc_html( $row['recipient'] ) . '</td>';
				echo '<td><span class="prime-fleet-badge">' . esc_html( $row['status'] ) . '</span></td>';
				echo '<td>' . esc_html( mb_substr( (string) $row['body_preview'], 0, 120 ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table></div>';
			echo Table_List::pagination( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				'templates',
				$total,
				array(
					'mtab'     => 'outbox',
					'paged'    => $paged,
					'per_page' => $per_page,
				)
			);
		}
		echo '</div>';
	}

	/**
	 * @return string
	 */
	private static function handle_save() {
		if ( empty( $_POST['prime_fleet_messages_action'] ) || 'save_template' !== sanitize_key( wp_unslash( $_POST['prime_fleet_messages_action'] ) ) ) {
			return '';
		}
		check_admin_referer( self::NONCE );

		$id = absint( $_POST['template_id'] ?? 0 );
		$result = Templates::update(
			$id,
			array(
				'label'                    => wp_unslash( $_POST['label'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				'subject'                  => wp_unslash( $_POST['subject'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				'heading'                  => wp_unslash( $_POST['heading'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				'body_html'                => wp_unslash( $_POST['body_html'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				'whatsapp_template_id'     => wp_unslash( $_POST['whatsapp_template_id'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				'reminder_offset_minutes'  => wp_unslash( $_POST['reminder_offset_minutes'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				'is_enabled'               => ! empty( $_POST['is_enabled'] ),
				'email_enabled'            => ! empty( $_POST['email_enabled'] ),
				'whatsapp_enabled'         => ! empty( $_POST['whatsapp_enabled'] ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return View::notice( $result->get_error_message(), 'error' );
		}

		// Keep editor open after save.
		$_GET['edit'] = $id;
		$_GET['mtab'] = 'templates';

		return View::notice( __( 'Template saved.', 'prime-fleet' ) );
	}

	private static function text( $name, $label, $value ) {
		printf(
			'<div class="prime-fleet-field"><label for="%1$s">%2$s</label><input id="%1$s" type="text" name="%1$s" value="%3$s" /></div>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( (string) $value )
		);
	}

	private static function textarea( $name, $label, $value, $rows = 6 ) {
		printf(
			'<div class="prime-fleet-field prime-fleet-field--block"><label for="%1$s">%2$s</label><textarea id="%1$s" name="%1$s" rows="%4$d" class="prime-fleet-msg-body">%3$s</textarea></div>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_textarea( (string) $value ),
			(int) $rows
		);
	}

	private static function check( $name, $label, $checked ) {
		echo '<label class="prime-fleet-check"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( (int) $checked, 1, false ) . ' />';
		echo '<span>' . esc_html( $label ) . '</span></label>';
	}
}
