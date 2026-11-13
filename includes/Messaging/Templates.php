<?php
/**
 * Message template catalog + merge.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Messaging;

use Prime_Fleet\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class Templates {

	const REV = 8;

	/**
	 * Ensure designed defaults exist. Never overwrite staff edits after insert.
	 */
	public static function ensure() {
		foreach ( self::defaults() as $row ) {
			self::upsert( $row, false );
		}
		$prev = (int) get_option( 'prime_fleet_templates_rev', 0 );
		// One-shot: payment_link body was missing {{payment_link}} CTA.
		if ( $prev < 7 ) {
			foreach ( self::defaults() as $row ) {
				if ( 'payment_link' === $row['template_key'] ) {
					self::upsert( $row, true );
					break;
				}
			}
		}
		update_option( 'prime_fleet_templates_rev', self::REV, false );
	}

	/**
	 * Insert any missing keys without overwriting custom edits.
	 */
	private static function ensure_missing_only() {
		foreach ( self::defaults() as $row ) {
			self::upsert( $row, false );
		}
	}

	/**
	 * @param array $row Row.
	 * @param bool  $overwrite Overwrite body/subject.
	 */
	private static function upsert( array $row, $overwrite ) {
		global $wpdb;
		$table  = Schema::table( 'message_templates' );
		$exists = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE template_key = %s", $row['template_key'] )
		);
		if ( $exists ) {
			if ( ! $overwrite ) {
				return;
			}
			$wpdb->update(
				$table,
				array(
					'label'            => $row['label'],
					'subject'          => $row['subject'],
					'heading'          => $row['heading'],
					'body_html'        => $row['body_html'],
					'email_enabled'    => 1,
					'whatsapp_enabled' => ! empty( $row['whatsapp_enabled'] ) ? 1 : 0,
					'is_enabled'       => 1,
				),
				array( 'id' => (int) $exists ),
				array( '%s', '%s', '%s', '%s', '%d', '%d', '%d' ),
				array( '%d' )
			);
			return;
		}
		$wpdb->insert(
			$table,
			array(
				'template_key'     => $row['template_key'],
				'label'            => $row['label'],
				'is_enabled'       => 1,
				'email_enabled'    => 1,
				'whatsapp_enabled' => ! empty( $row['whatsapp_enabled'] ) ? 1 : 0,
				'subject'          => $row['subject'],
				'heading'          => $row['heading'],
				'body_html'        => $row['body_html'],
			),
			array( '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * @param string $key Key.
	 * @return array|null
	 */
	public static function get( $key ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table( 'message_templates' ) . ' WHERE template_key = %s LIMIT 1',
				sanitize_key( $key )
			),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * @param int $id Template ID.
	 * @return array|null
	 */
	public static function get_by_id( $id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::table( 'message_templates' ) . ' WHERE id = %d LIMIT 1',
				absint( $id )
			),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * All templates, lifecycle order.
	 *
	 * @return array[]
	 */
	public static function all() {
		global $wpdb;
		self::ensure();
		$rows = $wpdb->get_results(
			'SELECT * FROM ' . Schema::table( 'message_templates' ) . ' ORDER BY id ASC',
			ARRAY_A
		);
		if ( ! $rows ) {
			return array();
		}
		$order = array_flip(
			array(
				'booking_received',
				'booking_confirmed',
				'slot_offer',
				'payment_link',
				'payment_confirmed',
				'payment_refunded',
				'reminder_24h',
				'chauffeur_vehicle',
				'reminder_2h',
				'booking_updated',
				'booking_cancelled',
			)
		);
		usort(
			$rows,
			static function ( $a, $b ) use ( $order ) {
				$ai = isset( $order[ $a['template_key'] ] ) ? $order[ $a['template_key'] ] : 999;
				$bi = isset( $order[ $b['template_key'] ] ) ? $order[ $b['template_key'] ] : 999;
				if ( $ai === $bi ) {
					return (int) $a['id'] - (int) $b['id'];
				}
				return $ai - $bi;
			}
		);
		return $rows;
	}

	/**
	 * Documented merge tags for the Messages editor.
	 *
	 * @return array<string,string> tag => description
	 */
	public static function merge_tag_docs() {
		return array(
			'customer_name'     => __( 'Guest full name', 'prime-fleet' ),
			'customer_email'    => __( 'Guest email', 'prime-fleet' ),
			'customer_phone'    => __( 'Guest phone', 'prime-fleet' ),
			'customer_whatsapp' => __( 'Guest WhatsApp (falls back to phone)', 'prime-fleet' ),
			'booking_reference' => __( 'PF- reference', 'prime-fleet' ),
			'service_name'      => __( 'Service name', 'prime-fleet' ),
			'pickup_time'       => __( 'Formatted pickup date/time', 'prime-fleet' ),
			'passenger_count'   => __( 'Passenger count', 'prime-fleet' ),
			'fare_total'        => __( 'Fare total or custom-quote label', 'prime-fleet' ),
			'coupon_code'       => __( 'Applied coupon code', 'prime-fleet' ),
			'coupon_discount'   => __( 'Coupon discount amount', 'prime-fleet' ),
			'coupon_rows'       => __( 'HTML coupon rows (email tables)', 'prime-fleet' ),
			'coupon_note'       => __( 'HTML coupon note', 'prime-fleet' ),
			'vehicle_name'      => __( 'Assigned vehicle display name', 'prime-fleet' ),
			'chauffeur_name'    => __( 'Assigned chauffeur name', 'prime-fleet' ),
			'chauffeur_phone'   => __( 'Assigned chauffeur phone', 'prime-fleet' ),
			'payment_link'      => __( 'Payment URL (staff paste until M4 gateway)', 'prime-fleet' ),
			'offer_datetime'    => __( 'Slot offer proposed pickup', 'prime-fleet' ),
			'offer_accept_url'  => __( 'Slot offer accept URL', 'prime-fleet' ),
			'offer_expires'     => __( 'Slot offer expiry', 'prime-fleet' ),
			'site_name'         => __( 'Site / blog name', 'prime-fleet' ),
		);
	}

	/**
	 * Update a template from admin (never wiped by ensure()).
	 *
	 * @param int   $id ID.
	 * @param array $data Fields.
	 * @return true|\WP_Error
	 */
	public static function update( $id, array $data ) {
		global $wpdb;
		$id  = absint( $id );
		$row = self::get_by_id( $id );
		if ( ! $row ) {
			return new \WP_Error( 'prime_fleet_tpl_missing', __( 'Template not found.', 'prime-fleet' ) );
		}

		$update = array(
			'label'                => sanitize_text_field( $data['label'] ?? $row['label'] ),
			'is_enabled'           => ! empty( $data['is_enabled'] ) ? 1 : 0,
			'email_enabled'        => ! empty( $data['email_enabled'] ) ? 1 : 0,
			'whatsapp_enabled'     => ! empty( $data['whatsapp_enabled'] ) ? 1 : 0,
			'subject'              => sanitize_text_field( $data['subject'] ?? '' ),
			'heading'              => sanitize_text_field( $data['heading'] ?? '' ),
			'body_html'            => wp_kses_post( $data['body_html'] ?? '' ),
			'whatsapp_template_id' => sanitize_text_field( $data['whatsapp_template_id'] ?? '' ),
		);
		$formats = array( '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' );

		$is_reminder = ( 0 === strpos( (string) $row['template_key'], 'reminder_' ) );
		if ( $is_reminder ) {
			$offset = isset( $data['reminder_offset_minutes'] ) ? trim( (string) $data['reminder_offset_minutes'] ) : '';
			// 0 / empty = use Settings reminder offsets at schedule time.
			$update['reminder_offset_minutes'] = ( '' === $offset ) ? 0 : max( 0, absint( $offset ) );
			$formats[]                        = '%d';
		}

		$ok = $wpdb->update(
			Schema::table( 'message_templates' ),
			$update,
			array( 'id' => $id ),
			$formats,
			array( '%d' )
		);
		if ( false === $ok ) {
			return new \WP_Error( 'prime_fleet_tpl_save', __( 'Could not save template.', 'prime-fleet' ) );
		}
		return true;
	}

	/**
	 * Merge tags into HTML/subject. Guest-controlled values are escaped by default
	 * so a name like `<a href=…>` cannot inject markup into email/WhatsApp.
	 *
	 * Allowlist:
	 * - HTML fragments built in Merge_Vars (already escaped internally): coupon_rows, coupon_note
	 * - URLs used in href / link text: payment_link, offer_accept_url
	 *
	 * @param string              $html HTML or plain subject.
	 * @param array<string,mixed> $vars Vars.
	 * @return string
	 */
	public static function merge( $html, array $vars ) {
		$html_keys = array( 'coupon_rows', 'coupon_note' );
		$url_keys  = array( 'payment_link', 'offer_accept_url' );

		$out = (string) $html;
		foreach ( $vars as $k => $v ) {
			$key = (string) $k;
			$raw = (string) $v;
			if ( in_array( $key, $html_keys, true ) ) {
				$safe = wp_kses_post( $raw );
			} elseif ( in_array( $key, $url_keys, true ) ) {
				$safe = esc_url( $raw );
			} else {
				$safe = esc_html( $raw );
			}
			$out = str_replace( '{{' . $key . '}}', $safe, $out );
		}
		return $out;
	}

	/**
	 * Wrap inner HTML in branded email chrome.
	 *
	 * @param string $heading Heading.
	 * @param string $inner Inner HTML (already merged).
	 * @return string
	 */
	public static function wrap( $heading, $inner ) {
		$site = esc_html( get_bloginfo( 'name' ) ? get_bloginfo( 'name' ) : 'Prime Fleet' );
		$year = esc_html( gmdate( 'Y' ) );
		$h    = esc_html( $heading );
		return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
			. '<title>' . $h . '</title></head><body style="margin:0;padding:0;background:#eef1f6;font-family:Segoe UI,Helvetica,Arial,sans-serif;color:#18212f;">'
			. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef1f6;padding:28px 12px;">'
			. '<tr><td align="center">'
			. '<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #dde3ec;">'
			. '<tr><td style="background:linear-gradient(145deg,#0b1d39,#132a4a);padding:22px 28px;color:#fff;">'
			. '<div style="font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:#f0d59a;font-weight:700;">Prime Fleet</div>'
			. '<div style="font-size:22px;font-weight:800;margin-top:8px;line-height:1.25;">' . $h . '</div>'
			. '</td></tr>'
			. '<tr><td style="padding:28px;font-size:15px;line-height:1.55;color:#18212f;">' . $inner . '</td></tr>'
			. '<tr><td style="padding:16px 28px 22px;background:#f7f9fc;border-top:1px solid #dde3ec;font-size:12px;color:#6b778c;line-height:1.45;">'
			. esc_html__( 'Questions? Reply to this email or contact Prime Fleet concierge.', 'prime-fleet' )
			. '<br>' . $site . ' · © ' . $year
			. '</td></tr></table></td></tr></table></body></html>';
	}

	/**
	 * @return array[]
	 */
	public static function defaults() {
		$btn = 'display:inline-block;padding:12px 22px;background:#d7a64a;color:#0b1d39;text-decoration:none;font-weight:800;border-radius:10px;';
		$card = 'margin:18px 0;padding:16px 18px;background:#f7f9fc;border:1px solid #dde3ec;border-radius:12px;';

		return array(
			array(
				'template_key'     => 'booking_received',
				'label'            => 'Booking Received',
				'whatsapp_enabled' => 1,
				'subject'          => 'We received your request {{booking_reference}}',
				'heading'          => 'Request received',
				'body_html'        => '<p>Hello {{customer_name}},</p>'
					. '<p>Thank you for choosing Prime Fleet. We have received your transfer request and our team will confirm shortly.</p>'
					. '<div style="' . $card . '">'
					. '<div style="font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#6b778c;">Reference</div>'
					. '<div style="font-size:20px;font-weight:800;color:#0b1d39;margin:4px 0 12px;">{{booking_reference}}</div>'
					. '<table role="presentation" width="100%" style="font-size:14px;border-collapse:collapse;">'
					. '<tr><td style="padding:4px 0;color:#6b778c;">Service</td><td style="padding:4px 0;text-align:right;font-weight:700;">{{service_name}}</td></tr>'
					. '<tr><td style="padding:4px 0;color:#6b778c;">Pickup</td><td style="padding:4px 0;text-align:right;font-weight:700;">{{pickup_time}}</td></tr>'
					. '<tr><td style="padding:4px 0;color:#6b778c;">Passengers</td><td style="padding:4px 0;text-align:right;font-weight:700;">{{passenger_count}}</td></tr>'
					. '{{coupon_rows}}'
					. '<tr><td style="padding:4px 0;color:#6b778c;">Fare</td><td style="padding:4px 0;text-align:right;font-weight:700;">{{fare_total}}</td></tr>'
					. '</table></div>'
					. '<p style="margin:0;color:#6b778c;font-size:13px;">You can check status anytime with your reference and booking email.</p>',
			),
			array(
				'template_key'     => 'booking_confirmed',
				'label'            => 'Booking Confirmed',
				'whatsapp_enabled' => 1,
				'subject'          => 'Confirmed — {{booking_reference}}',
				'heading'          => 'Booking confirmed',
				'body_html'        => '<p>Hello {{customer_name}},</p>'
					. '<p>Great news — your Prime Fleet transfer <strong>{{booking_reference}}</strong> is confirmed.</p>'
					. '<div style="' . $card . '">'
					. '<div style="font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#6b778c;">Pickup</div>'
					. '<div style="font-size:18px;font-weight:800;color:#0b1d39;margin-top:4px;">{{pickup_time}}</div>'
					. '<div style="margin-top:10px;font-size:14px;"><strong>{{service_name}}</strong> · {{passenger_count}} passengers · {{fare_total}}</div>'
					. '{{coupon_note}}'
					. '</div>'
					. '<p style="margin:0;color:#6b778c;font-size:13px;">We will share chauffeur and vehicle details closer to pickup.</p>',
			),
			array(
				'template_key'     => 'slot_offer',
				'label'            => 'Slot Offer',
				'whatsapp_enabled' => 1,
				'subject'          => 'New pickup time for {{booking_reference}}',
				'heading'          => 'Alternative pickup time',
				'body_html'        => '<p>Hello {{customer_name}},</p>'
					. '<p>Your requested window for <strong>{{booking_reference}}</strong> was full. We can offer this alternative pickup:</p>'
					. '<div style="' . $card . '">'
					. '<div style="font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#6b778c;">Proposed pickup</div>'
					. '<div style="font-size:20px;font-weight:800;color:#0b1d39;margin:6px 0 4px;">{{offer_datetime}}</div>'
					. '<div style="font-size:14px;color:#6b778c;">{{service_name}}</div>'
					. '</div>'
					. '<p style="text-align:center;margin:24px 0;"><a href="{{offer_accept_url}}" style="' . $btn . '">Accept this time</a></p>'
					. '<p style="margin:0;color:#6b778c;font-size:13px;">This link expires {{offer_expires}}. If the button does not work, open:<br><a href="{{offer_accept_url}}" style="color:#0b1d39;">{{offer_accept_url}}</a></p>',
			),
			array(
				'template_key'     => 'payment_link',
				'label'            => 'Payment Link Issued',
				'whatsapp_enabled' => 1,
				'subject'          => 'Payment for {{booking_reference}}',
				'heading'          => 'Payment instructions',
				'body_html'        => '<p>Hello {{customer_name}},</p>'
					. '<p>Please complete payment for booking <strong>{{booking_reference}}</strong> ({{fare_total}}).</p>'
					. '<p style="text-align:center;margin:24px 0;"><a href="{{payment_link}}" style="' . $btn . '">Pay now</a></p>'
					. '<p style="margin:0;color:#6b778c;font-size:13px;">If the button does not work, open:<br><a href="{{payment_link}}" style="color:#0b1d39;">{{payment_link}}</a></p>',
			),
			array(
				'template_key'     => 'payment_confirmed',
				'label'            => 'Payment Confirmed',
				'whatsapp_enabled' => 1,
				'subject'          => 'Payment received — {{booking_reference}}',
				'heading'          => 'Payment confirmed',
				'body_html'        => '<p>Hello {{customer_name}},</p><p>We have received payment for <strong>{{booking_reference}}</strong>. Thank you.</p>',
			),
			array(
				'template_key'     => 'payment_refunded',
				'label'            => 'Payment Refunded',
				'whatsapp_enabled' => 1,
				'subject'          => 'Refund processed — {{booking_reference}}',
				'heading'          => 'Payment refunded',
				'body_html'        => '<p>Hello {{customer_name}},</p><p>A refund has been processed for booking <strong>{{booking_reference}}</strong> ({{fare_total}}).</p><p>If you have questions, reply to this email or contact Prime Fleet concierge with your reference.</p>',
			),
			array(
				'template_key'     => 'reminder_24h',
				'label'            => 'Upcoming Transfer Reminder',
				'whatsapp_enabled' => 1,
				'subject'          => 'Tomorrow: {{booking_reference}}',
				'heading'          => 'Transfer tomorrow',
				'body_html'        => '<p>Hello {{customer_name}},</p><p>Reminder: your transfer <strong>{{booking_reference}}</strong> is scheduled for {{pickup_time}}.</p>',
			),
			array(
				'template_key'     => 'chauffeur_vehicle',
				'label'            => 'Chauffeur & Vehicle Info',
				'whatsapp_enabled' => 1,
				'subject'          => 'Your chauffeur — {{booking_reference}}',
				'heading'          => 'Your chauffeur & vehicle',
				'body_html'        => '<p>Hello {{customer_name}},</p>'
					. '<p>Your Prime Fleet team for <strong>{{booking_reference}}</strong> is confirmed.</p>'
					. '<div style="margin:18px 0;padding:16px 18px;background:#f7f9fc;border:1px solid #dde3ec;border-radius:12px;">'
					. '<table role="presentation" width="100%" style="font-size:14px;border-collapse:collapse;">'
					. '<tr><td style="padding:4px 0;color:#6b778c;">Pickup</td><td style="padding:4px 0;text-align:right;font-weight:700;">{{pickup_time}}</td></tr>'
					. '<tr><td style="padding:4px 0;color:#6b778c;">Service</td><td style="padding:4px 0;text-align:right;font-weight:700;">{{service_name}}</td></tr>'
					. '<tr><td style="padding:4px 0;color:#6b778c;">Chauffeur</td><td style="padding:4px 0;text-align:right;font-weight:700;">{{chauffeur_name}}</td></tr>'
					. '<tr><td style="padding:4px 0;color:#6b778c;">Contact</td><td style="padding:4px 0;text-align:right;font-weight:700;">{{chauffeur_phone}}</td></tr>'
					. '<tr><td style="padding:4px 0;color:#6b778c;">Vehicle</td><td style="padding:4px 0;text-align:right;font-weight:700;">{{vehicle_name}}</td></tr>'
					. '</table></div>'
					. '<p style="margin:0;color:#6b778c;font-size:13px;">Your chauffeur will meet you at the scheduled pickup. Reply to this email if you need anything.</p>',
			),
			array(
				'template_key'     => 'reminder_2h',
				'label'            => 'Pickup Reminder',
				'whatsapp_enabled' => 1,
				'subject'          => 'Pickup soon — {{booking_reference}}',
				'heading'          => 'Pickup soon',
				'body_html'        => '<p>Hello {{customer_name}},</p><p>Your Prime Fleet pickup for <strong>{{booking_reference}}</strong> is at {{pickup_time}}.</p>',
			),
			array(
				'template_key'     => 'booking_updated',
				'label'            => 'Booking Updated',
				'whatsapp_enabled' => 1,
				'subject'          => 'Update — {{booking_reference}}',
				'heading'          => 'Booking updated',
				'body_html'        => '<p>Hello {{customer_name}},</p><p>Your booking <strong>{{booking_reference}}</strong> has been updated. New pickup: {{pickup_time}}.</p>',
			),
			array(
				'template_key'     => 'booking_cancelled',
				'label'            => 'Booking Cancelled',
				'whatsapp_enabled' => 1,
				'subject'          => 'Cancelled — {{booking_reference}}',
				'heading'          => 'Booking cancelled',
				'body_html'        => '<p>Hello {{customer_name}},</p><p>Your booking <strong>{{booking_reference}}</strong> has been cancelled. Contact us if this was unexpected.</p>',
			),
		);
	}
}
