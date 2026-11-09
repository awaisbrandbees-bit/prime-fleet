<?php
/**
 * InnoDB schema for Prime Fleet.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Database;

defined( 'ABSPATH' ) || exit;

final class Schema {

	/**
	 * Install all tables via dbDelta.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix . 'prime_fleet_';

		$sql = array();

		$sql[] = "CREATE TABLE {$p}services (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL DEFAULT '',
			internal_name varchar(191) NOT NULL DEFAULT '',
			slug varchar(191) NOT NULL DEFAULT '',
			short_description text NULL,
			full_description longtext NULL,
			icon_attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			booking_type varchar(32) NOT NULL DEFAULT 'route',
			allow_one_way tinyint(1) NOT NULL DEFAULT 1,
			allow_round_trip tinyint(1) NOT NULL DEFAULT 0,
			allow_multiple_stops tinyint(1) NOT NULL DEFAULT 0,
			require_pickup tinyint(1) NOT NULL DEFAULT 1,
			require_destination tinyint(1) NOT NULL DEFAULT 1,
			require_passenger_count tinyint(1) NOT NULL DEFAULT 1,
			require_luggage tinyint(1) NOT NULL DEFAULT 0,
			require_vehicle_matching tinyint(1) NOT NULL DEFAULT 1,
			customer_vehicle_choice varchar(32) NOT NULL DEFAULT 'recommend',
			requires_manual_confirmation tinyint(1) NOT NULL DEFAULT 1,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			customer_visible tinyint(1) NOT NULL DEFAULT 1,
			display_order int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY slug (slug),
			KEY is_active_visible (is_active, customer_visible, display_order)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}fields (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			field_key varchar(100) NOT NULL DEFAULT '',
			label varchar(191) NOT NULL DEFAULT '',
			field_type varchar(64) NOT NULL DEFAULT 'text',
			placeholder varchar(191) NOT NULL DEFAULT '',
			help_text text NULL,
			default_value text NULL,
			options_json longtext NULL,
			validation_json longtext NULL,
			is_system tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY field_key (field_key)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}service_fields (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			service_id bigint(20) unsigned NOT NULL,
			field_id bigint(20) unsigned NOT NULL,
			enabled tinyint(1) NOT NULL DEFAULT 1,
			required tinyint(1) NOT NULL DEFAULT 0,
			label_override varchar(191) NOT NULL DEFAULT '',
			help_override text NULL,
			placeholder_override varchar(191) NOT NULL DEFAULT '',
			step_key varchar(64) NOT NULL DEFAULT 'trip',
			display_order int(11) NOT NULL DEFAULT 0,
			display_width varchar(16) NOT NULL DEFAULT 'full',
			customer_visible tinyint(1) NOT NULL DEFAULT 1,
			admin_visible tinyint(1) NOT NULL DEFAULT 1,
			read_only tinyint(1) NOT NULL DEFAULT 0,
			conditions_json longtext NULL,
			required_conditions_json longtext NULL,
			validation_json longtext NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY service_field (service_id, field_id),
			KEY service_step (service_id, step_key, display_order),
			KEY field_id (field_id)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}zones (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL DEFAULT '',
			customer_label varchar(191) NOT NULL DEFAULT '',
			short_description text NULL,
			pickup_instructions text NULL,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			display_order int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY is_active_order (is_active, display_order)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}routes (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			service_id bigint(20) unsigned NOT NULL DEFAULT 0,
			origin_zone_id bigint(20) unsigned NOT NULL DEFAULT 0,
			dest_zone_id bigint(20) unsigned NOT NULL DEFAULT 0,
			applies_both_directions tinyint(1) NOT NULL DEFAULT 1,
			one_way_price decimal(12,2) NOT NULL DEFAULT 0.00,
			round_trip_price decimal(12,2) NOT NULL DEFAULT 0.00,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			notes text NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY service_origin_dest (service_id, origin_zone_id, dest_zone_id, is_active)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}hourly_rates (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			service_id bigint(20) unsigned NOT NULL DEFAULT 0,
			label varchar(191) NOT NULL DEFAULT '',
			hourly_rate decimal(12,2) NOT NULL DEFAULT 0.00,
			minimum_hours decimal(6,2) DEFAULT NULL,
			maximum_hours decimal(6,2) DEFAULT NULL,
			billing_increment varchar(32) NOT NULL DEFAULT 'hour',
			customer_can_choose tinyint(1) NOT NULL DEFAULT 1,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY service_id (service_id, is_active)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}packages (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL DEFAULT '',
			customer_description text NULL,
			minimum_duration varchar(64) NOT NULL DEFAULT '',
			maximum_duration varchar(64) NOT NULL DEFAULT '',
			fixed_price decimal(12,2) NOT NULL DEFAULT 0.00,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			applicable_services_json longtext NULL,
			display_order int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}pricing_rules (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL DEFAULT '',
			is_active tinyint(1) NOT NULL DEFAULT 1,
			priority int(11) NOT NULL DEFAULT 10,
			match_mode varchar(8) NOT NULL DEFAULT 'all',
			conditions_json longtext NULL,
			action_type varchar(64) NOT NULL DEFAULT 'add_percent',
			action_value decimal(12,2) NOT NULL DEFAULT 0.00,
			notes text NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY is_active_priority (is_active, priority)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}vehicles (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL DEFAULT '',
			vehicle_type varchar(191) NOT NULL DEFAULT '',
			customer_display_name varchar(191) NOT NULL DEFAULT '',
			description text NULL,
			passenger_capacity int(11) NOT NULL DEFAULT 0,
			checked_bag_capacity int(11) DEFAULT NULL,
			carry_on_bag_capacity int(11) DEFAULT NULL,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			available_for_booking tinyint(1) NOT NULL DEFAULT 1,
			customer_visible tinyint(1) NOT NULL DEFAULT 1,
			image_attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			display_order int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY booking_visible (available_for_booking, customer_visible, is_active)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}chauffeurs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL DEFAULT '',
			phone varchar(64) NOT NULL DEFAULT '',
			whatsapp varchar(64) NOT NULL DEFAULT '',
			email varchar(191) NOT NULL DEFAULT '',
			default_vehicle_id bigint(20) unsigned NOT NULL DEFAULT 0,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			internal_notes text NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY is_active (is_active)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}availability_blocks (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			resource_type varchar(32) NOT NULL DEFAULT 'vehicle',
			resource_id bigint(20) unsigned NOT NULL DEFAULT 0,
			start_datetime datetime NOT NULL,
			end_datetime datetime NOT NULL,
			reason varchar(191) NOT NULL DEFAULT '',
			notes text NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY resource_window (resource_type, resource_id, start_datetime, end_datetime)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}addons (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL DEFAULT '',
			customer_description text NULL,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			pricing_type varchar(32) NOT NULL DEFAULT 'fixed',
			price decimal(12,2) NOT NULL DEFAULT 0.00,
			unit_label varchar(64) NOT NULL DEFAULT '',
			min_qty int(11) NOT NULL DEFAULT 0,
			max_qty int(11) NOT NULL DEFAULT 1,
			applicable_services_json longtext NULL,
			display_order int(11) NOT NULL DEFAULT 0,
			is_included_info tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY is_active_order (is_active, display_order)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}surcharges (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL DEFAULT '',
			is_active tinyint(1) NOT NULL DEFAULT 1,
			surcharge_type varchar(32) NOT NULL DEFAULT 'percent',
			value decimal(12,2) NOT NULL DEFAULT 0.00,
			start_date date DEFAULT NULL,
			end_date date DEFAULT NULL,
			notes text NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}coupons (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			code varchar(64) NOT NULL DEFAULT '',
			discount_type varchar(16) NOT NULL DEFAULT 'percent',
			discount_value decimal(12,2) NOT NULL DEFAULT 0.00,
			use_type varchar(32) NOT NULL DEFAULT 'multi',
			max_uses int(11) DEFAULT NULL,
			max_per_email int(11) DEFAULT NULL,
			bound_email varchar(191) NOT NULL DEFAULT '',
			min_fare decimal(12,2) DEFAULT NULL,
			starts_at datetime DEFAULT NULL,
			ends_at datetime DEFAULT NULL,
			applicable_services_json longtext NULL,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}coupon_redemptions (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			coupon_id bigint(20) unsigned NOT NULL,
			booking_id bigint(20) unsigned NOT NULL DEFAULT 0,
			email varchar(191) NOT NULL DEFAULT '',
			used_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY coupon_email (coupon_id, email)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}slot_offers (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			booking_id bigint(20) unsigned NOT NULL DEFAULT 0,
			proposed_datetime datetime NOT NULL,
			vehicle_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(32) NOT NULL DEFAULT 'draft',
			token varchar(64) NOT NULL DEFAULT '',
			expires_at datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY booking_status (booking_id, status)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}bookings (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			reference varchar(32) NOT NULL DEFAULT '',
			status varchar(32) NOT NULL DEFAULT 'pending_confirmation',
			payment_status varchar(32) NOT NULL DEFAULT 'unpaid',
			service_id bigint(20) unsigned NOT NULL DEFAULT 0,
			customer_name varchar(191) NOT NULL DEFAULT '',
			customer_email varchar(191) NOT NULL DEFAULT '',
			customer_phone varchar(64) NOT NULL DEFAULT '',
			customer_whatsapp varchar(64) NOT NULL DEFAULT '',
			pickup_datetime datetime DEFAULT NULL,
			assigned_vehicle_id bigint(20) unsigned NOT NULL DEFAULT 0,
			assigned_chauffeur_id bigint(20) unsigned NOT NULL DEFAULT 0,
			passenger_count int(11) NOT NULL DEFAULT 0,
			manual_review tinyint(1) NOT NULL DEFAULT 0,
			fare_snapshot_json longtext NULL,
			fare_total decimal(12,2) NOT NULL DEFAULT 0.00,
			currency varchar(8) NOT NULL DEFAULT 'USD',
			coupon_code varchar(64) NOT NULL DEFAULT '',
			internal_notes longtext NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY reference (reference),
			KEY status_pickup (status, pickup_datetime),
			KEY customer_email (customer_email),
			KEY payment_status (payment_status),
			KEY occupancy (pickup_datetime, assigned_vehicle_id, status)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}booking_legs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			booking_id bigint(20) unsigned NOT NULL,
			leg_type varchar(32) NOT NULL DEFAULT 'outbound',
			pickup_zone_id bigint(20) unsigned NOT NULL DEFAULT 0,
			dest_zone_id bigint(20) unsigned NOT NULL DEFAULT 0,
			pickup_detail varchar(255) NOT NULL DEFAULT '',
			dest_detail varchar(255) NOT NULL DEFAULT '',
			scheduled_datetime datetime DEFAULT NULL,
			meta_json longtext NULL,
			PRIMARY KEY  (id),
			KEY booking_leg (booking_id, leg_type)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}booking_items (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			booking_id bigint(20) unsigned NOT NULL,
			item_type varchar(32) NOT NULL DEFAULT 'addon',
			item_id bigint(20) unsigned NOT NULL DEFAULT 0,
			label varchar(191) NOT NULL DEFAULT '',
			qty decimal(12,2) NOT NULL DEFAULT 1.00,
			unit_price decimal(12,2) NOT NULL DEFAULT 0.00,
			line_total decimal(12,2) NOT NULL DEFAULT 0.00,
			PRIMARY KEY  (id),
			KEY booking_id (booking_id)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}booking_meta (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			booking_id bigint(20) unsigned NOT NULL,
			meta_key varchar(191) NOT NULL DEFAULT '',
			meta_value longtext NULL,
			PRIMARY KEY  (id),
			KEY booking_meta (booking_id, meta_key)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}booking_adjustments (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			booking_id bigint(20) unsigned NOT NULL,
			adjustment_type varchar(32) NOT NULL DEFAULT 'manual',
			qty decimal(12,2) NOT NULL DEFAULT 1.00,
			amount decimal(12,2) NOT NULL DEFAULT 0.00,
			reason text NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY booking_id (booking_id)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}payment_methods (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			method_key varchar(64) NOT NULL DEFAULT '',
			label varchar(191) NOT NULL DEFAULT '',
			is_enabled tinyint(1) NOT NULL DEFAULT 0,
			display_order int(11) NOT NULL DEFAULT 0,
			settings_json longtext NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY method_key (method_key)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}payments (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			booking_id bigint(20) unsigned NOT NULL DEFAULT 0,
			method_id bigint(20) unsigned NOT NULL DEFAULT 0,
			amount decimal(12,2) NOT NULL DEFAULT 0.00,
			currency varchar(8) NOT NULL DEFAULT 'USD',
			token varchar(64) NOT NULL DEFAULT '',
			status varchar(32) NOT NULL DEFAULT 'unpaid',
			external_id varchar(191) NOT NULL DEFAULT '',
			custom_url text NULL,
			failure_code varchar(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY booking_id (booking_id),
			KEY status (status)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}payment_events (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			payment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			idempotency_key varchar(191) NOT NULL DEFAULT '',
			event_type varchar(64) NOT NULL DEFAULT '',
			payload_json longtext NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY payment_id (payment_id)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}jobs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_type varchar(64) NOT NULL DEFAULT '',
			payload_json longtext NULL,
			status varchar(32) NOT NULL DEFAULT 'pending',
			run_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			attempts int(11) NOT NULL DEFAULT 0,
			last_error text NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY status_run (status, run_at)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}communications (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			booking_id bigint(20) unsigned NOT NULL DEFAULT 0,
			channel varchar(32) NOT NULL DEFAULT 'email',
			template_key varchar(64) NOT NULL DEFAULT '',
			recipient varchar(191) NOT NULL DEFAULT '',
			provider_id varchar(191) NOT NULL DEFAULT '',
			status varchar(32) NOT NULL DEFAULT 'queued',
			retry_count int(11) NOT NULL DEFAULT 0,
			body_preview text NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY booking_created (booking_id, created_at)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}booking_events (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			booking_id bigint(20) unsigned NOT NULL DEFAULT 0,
			event_type varchar(64) NOT NULL DEFAULT '',
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			message text NULL,
			meta_json longtext NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY booking_created (booking_id, created_at)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}system_logs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			level varchar(16) NOT NULL DEFAULT 'error',
			context varchar(64) NOT NULL DEFAULT '',
			message text NULL,
			meta_json longtext NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY level_created (level, created_at)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}message_templates (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			template_key varchar(64) NOT NULL DEFAULT '',
			label varchar(191) NOT NULL DEFAULT '',
			is_enabled tinyint(1) NOT NULL DEFAULT 1,
			email_enabled tinyint(1) NOT NULL DEFAULT 1,
			whatsapp_enabled tinyint(1) NOT NULL DEFAULT 0,
			subject varchar(255) NOT NULL DEFAULT '',
			heading varchar(255) NOT NULL DEFAULT '',
			body_html longtext NULL,
			whatsapp_template_id varchar(191) NOT NULL DEFAULT '',
			reminder_offset_minutes int(11) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY template_key (template_key)
		) $charset;";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}

	/**
	 * Prefixed table name helper.
	 *
	 * @param string $name Logical table name without prefix.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'prime_fleet_' . $name;
	}
}
