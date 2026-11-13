<?php
/**
 * Global settings admin — section pages via ?stab= (server-rendered).
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Admin\Pages;

use Prime_Fleet\Admin\View;
use Prime_Fleet\Database\Schema;
use Prime_Fleet\Install\Capabilities;
use Prime_Fleet\Services\Config_Compiler;

defined( 'ABSPATH' ) || exit;

final class Settings {

	const NONCE  = 'prime_fleet_settings';
	const OPTION = 'prime_fleet_settings';

	/**
	 * Settings sub-tabs.
	 *
	 * @return array<string,string>
	 */
	private static function tabs() {
		return array(
			'general'  => __( 'General', 'prime-fleet' ),
			'booking'  => __( 'Booking rules', 'prime-fleet' ),
			'guest'    => __( 'Guest copy', 'prime-fleet' ),
			'pricing'  => __( 'Pricing', 'prime-fleet' ),
			'whatsapp' => __( 'WhatsApp', 'prime-fleet' ),
			'security' => __( 'Security', 'prime-fleet' ),
			'website'  => __( 'Website', 'prime-fleet' ),
		);
	}

	public static function render() {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'prime-fleet' ) );
		}

		$notice   = self::handle_save();
		$settings = self::normalized_settings();
		$tabs     = self::tabs();
		$active   = isset( $_GET['stab'] ) ? sanitize_key( wp_unslash( $_GET['stab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $tabs[ $active ] ) ) {
			$active = 'general';
		}

		global $wpdb;
		$svc_list = $wpdb->get_results(
			'SELECT id, name FROM ' . Schema::table( 'services' ) . ' WHERE is_active = 1 ORDER BY display_order ASC, name ASC',
			ARRAY_A
		);

		ob_start();
		if ( $notice ) {
			echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		?>
		<form method="post" class="prime-fleet-settings" data-pf-ajax-form="1">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="prime_fleet_action" value="save_settings" />
			<input type="hidden" name="settings_tab" id="prime-fleet-settings-tab" value="<?php echo esc_attr( $active ); ?>" />

			<div class="prime-fleet-settings-layout">
				<nav class="prime-fleet-settings-nav" aria-label="<?php esc_attr_e( 'Settings pages', 'prime-fleet' ); ?>">
					<p class="prime-fleet-settings-nav__label"><?php esc_html_e( 'Settings', 'prime-fleet' ); ?></p>
					<ul class="prime-fleet-settings-nav__list">
						<?php foreach ( $tabs as $key => $label ) : ?>
							<li>
								<button
									type="button"
									class="prime-fleet-settings-nav__link<?php echo $key === $active ? ' is-active' : ''; ?>"
									id="pf-settings-tab-<?php echo esc_attr( $key ); ?>"
									aria-current="<?php echo $key === $active ? 'page' : 'false'; ?>"
									data-pf-panel="settings"
									data-pf-query="<?php echo esc_attr( wp_json_encode( array( 'stab' => $key ) ) ); ?>"
								><?php echo esc_html( $label ); ?></button>
							</li>
						<?php endforeach; ?>
					</ul>
				</nav>

				<div class="prime-fleet-settings-main">
					<div class="prime-fleet-settings-panels">
						<?php
						if ( 'general' === $active ) {
							self::panel_general( $settings );
						} elseif ( 'booking' === $active ) {
							self::panel_booking( $settings );
						} elseif ( 'guest' === $active ) {
							self::panel_guest( $settings );
						} elseif ( 'pricing' === $active ) {
							self::panel_pricing( $settings, $svc_list );
						} elseif ( 'whatsapp' === $active ) {
							self::panel_whatsapp( $settings );
						} elseif ( 'security' === $active ) {
							self::panel_security( $settings );
						} else {
							self::panel_website( $settings );
						}
						?>
					</div>

					<div class="prime-fleet-sticky-save">
						<button type="submit" class="prime-fleet-btn"><?php esc_html_e( 'Save settings', 'prime-fleet' ); ?></button>
					</div>
				</div>
			</div>
		</form>
		<?php
		$titles = self::tabs();
		$page_title = isset( $titles[ $active ] ) ? $titles[ $active ] : __( 'Settings', 'prime-fleet' );
		View::shell(
			sprintf(
				/* translators: %s: settings section label */
				__( 'Settings · %s', 'prime-fleet' ),
				$page_title
			),
			ob_get_clean()
		);
	}

	/**
	 * @return array
	 */
	private static function normalized_settings() {
		$settings = wp_parse_args(
			get_option( self::OPTION, array() ),
			array(
				'currency'                          => 'USD',
				'timezone'                          => 'UTC',
				'reference_prefix'                  => 'PF-',
				'default_status'                    => 'pending_confirmation',
				'slot_increment'                    => 30,
				'pending_message'                   => '',
				'submit_label'                      => '',
				'custom_quote_message'              => '',
				'phone'                             => '',
				'whatsapp'                          => '',
				'reminder_24h'                      => 1440,
				'reminder_2h'                       => 120,
				'min_advance_hours'                 => '2',
				'same_day_allowed'                  => 1,
				'waiting_complimentary_minutes'     => 30,
				'whatsapp_channel_enabled'          => 1,
				'whatsapp_driver'                   => 'stub',
				'whatsapp_meta_phone_number_id'     => '',
				'whatsapp_meta_api_version'         => 'v21.0',
				'whatsapp_openwa_base_url'          => 'http://127.0.0.1:2785',
				'whatsapp_openwa_session_id'        => '',
				'whatsapp_openwa_api_key'           => '',
				'sticky_cta_enabled'                => 1,
				'sticky_book_url'                   => '',
				'sticky_whatsapp_e164'              => '',
				'sticky_book_label'                 => '',
				'sticky_wa_label'                   => '',
				'form_theme'                        => 'dark',
				'airport_vip_seasonal_discount_pct' => 10,
				'seasonal_discount_pct'             => 10,
				'seasonal_discount_service_ids'     => array(),
				'captcha_enabled'                   => 0,
				'captcha_provider'                  => 'none',
				'captcha_site_key'                  => '',
				'captcha_secret'                    => '',
				'rl_bookings_per_min'               => 20,
				'rl_bookings_email_per_hour'        => 24,
				'rl_coupon_per_min'                 => 40,
				'rl_quote_per_min'                   => 60,
				'rl_lookup_per_min'                  => 30,
			)
		);
		if ( ! isset( $settings['whatsapp_channel_enabled'] ) ) {
			$settings['whatsapp_channel_enabled'] = 1;
		}
		if ( empty( $settings['whatsapp_driver'] ) ) {
			$settings['whatsapp_driver'] = 'stub';
		}
		if ( empty( $settings['whatsapp_meta_api_version'] ) ) {
			$settings['whatsapp_meta_api_version'] = 'v21.0';
		}
		if ( ! isset( $settings['whatsapp_meta_phone_number_id'] ) ) {
			$settings['whatsapp_meta_phone_number_id'] = '';
		}
		if ( empty( $settings['whatsapp_openwa_base_url'] ) ) {
			$settings['whatsapp_openwa_base_url'] = 'http://127.0.0.1:2785';
		}
		if ( ! isset( $settings['whatsapp_openwa_session_id'] ) ) {
			$settings['whatsapp_openwa_session_id'] = '';
		}
		if ( ! isset( $settings['whatsapp_openwa_api_key'] ) ) {
			$settings['whatsapp_openwa_api_key'] = '';
		}
		if ( ! isset( $settings['sticky_cta_enabled'] ) ) {
			$settings['sticky_cta_enabled'] = 1;
		}
		if ( ! isset( $settings['sticky_book_url'] ) ) {
			$settings['sticky_book_url'] = '';
		}
		if ( ! isset( $settings['sticky_whatsapp_e164'] ) ) {
			$settings['sticky_whatsapp_e164'] = '';
		}
		if ( ! isset( $settings['sticky_book_label'] ) ) {
			$settings['sticky_book_label'] = '';
		}
		if ( ! isset( $settings['sticky_wa_label'] ) ) {
			$settings['sticky_wa_label'] = '';
		}
		if ( empty( $settings['form_theme'] ) || ! in_array( $settings['form_theme'], array( 'dark', 'light' ), true ) ) {
			$settings['form_theme'] = 'dark';
		}
		if ( ! isset( $settings['seasonal_discount_pct'] ) && isset( $settings['airport_vip_seasonal_discount_pct'] ) ) {
			$settings['seasonal_discount_pct'] = $settings['airport_vip_seasonal_discount_pct'];
		}
		if ( ! is_array( $settings['seasonal_discount_service_ids'] ) ) {
			$settings['seasonal_discount_service_ids'] = array();
		}
		$settings['seasonal_discount_service_ids'] = array_map( 'intval', $settings['seasonal_discount_service_ids'] );
		return $settings;
	}

	/**
	 * @param array $settings Settings.
	 */
	private static function panel_general( array $settings ) {
		echo '<section class="prime-fleet-card prime-fleet-section prime-fleet-settings-panel" id="pf-settings-panel-general" data-pf-settings-panel="general">';
		echo '<header class="prime-fleet-section__head"><h3>' . esc_html__( 'General', 'prime-fleet' ) . '</h3>';
		echo '<p>' . esc_html__( 'Currency, timezone, references, and public contact details.', 'prime-fleet' ) . '</p></header>';
		echo '<div class="prime-fleet-form-grid prime-fleet-form-grid--2">';
		self::select(
			'currency',
			__( 'Currency', 'prime-fleet' ),
			$settings['currency'],
			array(
				'USD' => 'USD — US Dollar',
				'EUR' => 'EUR — Euro',
				'GBP' => 'GBP — British Pound',
				'CAD' => 'CAD — Canadian Dollar',
				'AUD' => 'AUD — Australian Dollar',
				'XCD' => 'XCD — East Caribbean Dollar',
			)
		);
		self::timezone( 'timezone', __( 'Timezone', 'prime-fleet' ), $settings['timezone'] );
		self::text( 'reference_prefix', __( 'Reference prefix', 'prime-fleet' ), $settings['reference_prefix'] );
		self::select(
			'default_status',
			__( 'Default booking status', 'prime-fleet' ),
			$settings['default_status'],
			array(
				'pending_confirmation' => __( 'Pending confirmation', 'prime-fleet' ),
				'confirmed'            => __( 'Confirmed', 'prime-fleet' ),
				'manual_review'        => __( 'Manual review', 'prime-fleet' ),
			)
		);
		self::select(
			'slot_increment',
			__( 'Slot increment', 'prime-fleet' ),
			(string) $settings['slot_increment'],
			array(
				'15' => __( '15 minutes', 'prime-fleet' ),
				'30' => __( '30 minutes', 'prime-fleet' ),
				'60' => __( '60 minutes', 'prime-fleet' ),
			)
		);
		self::text( 'submit_label', __( 'Submit button label', 'prime-fleet' ), $settings['submit_label'] );
		self::text( 'phone', __( 'Public phone', 'prime-fleet' ), $settings['phone'] );
		self::text( 'whatsapp', __( 'Public WhatsApp', 'prime-fleet' ), $settings['whatsapp'] );
		echo '</div></section>';
	}

	/**
	 * @param array $settings Settings.
	 */
	private static function panel_booking( array $settings ) {
		echo '<section class="prime-fleet-card prime-fleet-section prime-fleet-settings-panel" id="pf-settings-panel-booking" data-pf-settings-panel="booking">';
		echo '<header class="prime-fleet-section__head"><h3>' . esc_html__( 'Booking rules', 'prime-fleet' ) . '</h3>';
		echo '<p>' . esc_html__( 'Advance booking window, same-day rules, waiting time, and reminder offsets.', 'prime-fleet' ) . '</p></header>';
		echo '<div class="prime-fleet-form-grid prime-fleet-form-grid--2">';
		self::text( 'min_advance_hours', __( 'Min advance hours (default 2)', 'prime-fleet' ), $settings['min_advance_hours'] );
		self::text(
			'waiting_complimentary_minutes',
			__( 'Waiting complimentary minutes', 'prime-fleet' ),
			(string) ( $settings['waiting_complimentary_minutes'] ?? 30 )
		);
		self::select(
			'reminder_24h',
			__( 'First reminder offset', 'prime-fleet' ),
			(string) $settings['reminder_24h'],
			array(
				'1440' => __( '24 hours before (1440 min)', 'prime-fleet' ),
				'720'  => __( '12 hours before (720 min)', 'prime-fleet' ),
				'360'  => __( '6 hours before (360 min)', 'prime-fleet' ),
			)
		);
		self::select(
			'reminder_2h',
			__( 'Second reminder offset', 'prime-fleet' ),
			(string) $settings['reminder_2h'],
			array(
				'120' => __( '2 hours before (120 min)', 'prime-fleet' ),
				'60'  => __( '1 hour before (60 min)', 'prime-fleet' ),
				'30'  => __( '30 minutes before', 'prime-fleet' ),
			)
		);
		echo '<div class="prime-fleet-field prime-fleet-field--check">';
		echo '<label class="prime-fleet-check"><input type="checkbox" name="same_day_allowed" value="1" ' . checked( ! empty( $settings['same_day_allowed'] ), true, false ) . ' />';
		echo '<span>' . esc_html__( 'Same-day bookings allowed', 'prime-fleet' ) . '</span></label></div>';
		echo '</div>';
		echo '<p class="prime-fleet-help">' . esc_html__( 'Waiting Time add-on bills after the complimentary window (default 30 minutes). Interval price stays on the add-on ($50 / 30 min).', 'prime-fleet' ) . '</p>';
		echo '</section>';
	}

	/**
	 * @param array $settings Settings.
	 */
	private static function panel_guest( array $settings ) {
		echo '<section class="prime-fleet-card prime-fleet-section prime-fleet-settings-panel" id="pf-settings-panel-guest" data-pf-settings-panel="guest">';
		echo '<header class="prime-fleet-section__head"><h3>' . esc_html__( 'Guest copy', 'prime-fleet' ) . '</h3>';
		echo '<p>' . esc_html__( 'Messages shown after submit and on custom-quote paths.', 'prime-fleet' ) . '</p></header>';
		echo '<div class="prime-fleet-form-stack">';
		self::textarea( 'pending_message', __( 'Pending confirmation message', 'prime-fleet' ), $settings['pending_message'], 4 );
		self::textarea( 'custom_quote_message', __( 'Custom quote message', 'prime-fleet' ), $settings['custom_quote_message'], 4 );
		echo '</div></section>';
	}

	/**
	 * @param array $settings Settings.
	 * @param array $svc_list Services.
	 */
	private static function panel_pricing( array $settings, $svc_list ) {
		echo '<section class="prime-fleet-card prime-fleet-section prime-fleet-settings-panel" id="pf-settings-panel-pricing" data-pf-settings-panel="pricing">';
		echo '<header class="prime-fleet-section__head"><h3>' . esc_html__( 'Seasonal discount', 'prime-fleet' ) . '</h3>';
		echo '<p>' . esc_html__( 'Optional percent off published fares for selected services. Does not change stored route prices. Set % to 0 to turn off.', 'prime-fleet' ) . '</p></header>';
		echo '<div class="prime-fleet-form-grid prime-fleet-form-grid--2">';
		self::text(
			'seasonal_discount_pct',
			__( 'Discount % (0 = off)', 'prime-fleet' ),
			(string) ( $settings['seasonal_discount_pct'] ?? 10 )
		);
		$selected_ids    = array_map( 'intval', (array) $settings['seasonal_discount_service_ids'] );
		$selected_labels = array();
		foreach ( (array) $svc_list as $svc ) {
			if ( in_array( (int) $svc['id'], $selected_ids, true ) ) {
				$selected_labels[] = $svc['name'];
			}
		}
		$trigger_label = ! empty( $selected_labels )
			? implode( ', ', $selected_labels )
			: __( 'Select services…', 'prime-fleet' );
		echo '<div class="prime-fleet-field">';
		echo '<label id="pf-seasonal-svc-label">' . esc_html__( 'Apply to services', 'prime-fleet' ) . '</label>';
		echo '<div class="prime-fleet-ms" data-pf-multiselect="1">';
		echo '<button type="button" class="prime-fleet-ms__trigger" aria-haspopup="listbox" aria-expanded="false" aria-labelledby="pf-seasonal-svc-label">';
		echo '<span class="prime-fleet-ms__label">' . esc_html( $trigger_label ) . '</span>';
		echo '<span class="prime-fleet-ms__chevron" aria-hidden="true"></span></button>';
		echo '<div class="prime-fleet-ms__panel" hidden role="listbox" aria-multiselectable="true">';
		foreach ( (array) $svc_list as $svc ) {
			$sid = (int) $svc['id'];
			$chk = in_array( $sid, $selected_ids, true );
			printf(
				'<label class="prime-fleet-ms__option"><input type="checkbox" name="seasonal_discount_service_ids[]" value="%1$d" %2$s data-pf-ms-label="%3$s" /> <span>%4$s</span></label>',
				$sid,
				checked( $chk, true, false ),
				esc_attr( $svc['name'] ),
				esc_html( $svc['name'] )
			);
		}
		if ( empty( $svc_list ) ) {
			echo '<p class="prime-fleet-help">' . esc_html__( 'No active services found.', 'prime-fleet' ) . '</p>';
		}
		echo '</div></div></div></div></section>';
	}

	/**
	 * @param array $settings Settings.
	 */
	private static function panel_security( array $settings ) {
		echo '<section class="prime-fleet-card prime-fleet-section prime-fleet-settings-panel" id="pf-settings-panel-security" data-pf-settings-panel="security">';
		echo '<header class="prime-fleet-section__head"><h3>' . esc_html__( 'Guest anti-bot', 'prime-fleet' ) . '</h3>';
		echo '<p>' . esc_html__( 'Optional CAPTCHA on booking submit. Keeps existing honeypot + rate limits; use Cloudflare Turnstile or Google reCAPTCHA v2.', 'prime-fleet' ) . '</p></header>';
		echo '<div class="prime-fleet-form-grid prime-fleet-form-grid--2">';
		echo '<div class="prime-fleet-field prime-fleet-field--check">';
		echo '<label class="prime-fleet-check"><input type="checkbox" name="captcha_enabled" value="1" ' . checked( ! empty( $settings['captcha_enabled'] ), true, false ) . ' />';
		echo '<span>' . esc_html__( 'Require CAPTCHA on booking submit', 'prime-fleet' ) . '</span></label></div>';
		self::select(
			'captcha_provider',
			__( 'Provider', 'prime-fleet' ),
			(string) ( $settings['captcha_provider'] ?? 'none' ),
			array(
				'none'         => __( 'None', 'prime-fleet' ),
				'turnstile'    => __( 'Cloudflare Turnstile', 'prime-fleet' ),
				'recaptcha_v2' => __( 'Google reCAPTCHA v2 (checkbox)', 'prime-fleet' ),
			)
		);
		self::text( 'captcha_site_key', __( 'Site key (public)', 'prime-fleet' ), (string) ( $settings['captcha_site_key'] ?? '' ) );
		$secret_placeholder = ! empty( $settings['captcha_secret'] ) || ( defined( 'PRIME_FLEET_CAPTCHA_SECRET' ) && PRIME_FLEET_CAPTCHA_SECRET )
			? __( '•••••••• (leave blank to keep)', 'prime-fleet' )
			: '';
		echo '<div class="prime-fleet-field"><label for="captcha_secret">' . esc_html__( 'Secret key (prefer wp-config PRIME_FLEET_CAPTCHA_SECRET)', 'prime-fleet' ) . '</label>';
		printf(
			'<input id="captcha_secret" type="password" name="captcha_secret" value="" autocomplete="new-password" placeholder="%s" />',
			esc_attr( $secret_placeholder )
		);
		echo '</div></div>';
		echo '<p class="prime-fleet-help">' . esc_html__( 'When enabled, submit fails closed without a valid token. Incomplete CAPTCHA settings also block bookings (fail closed). Prefer PRIME_FLEET_CAPTCHA_SECRET in wp-config.', 'prime-fleet' ) . '</p>';
		echo '</section>';

		echo '<section class="prime-fleet-card prime-fleet-section" style="margin-top:14px;">';
		echo '<header class="prime-fleet-section__head"><h3>' . esc_html__( 'Public REST rate limits', 'prime-fleet' ) . '</h3>';
		echo '<p>' . esc_html__( 'Per-IP buckets (REMOTE_ADDR). Lower values under spam pressure; raise carefully for shared NAT offices.', 'prime-fleet' ) . '</p></header>';
		echo '<div class="prime-fleet-form-grid prime-fleet-form-grid--2">';
		self::text( 'rl_bookings_per_min', __( 'Bookings / minute / IP', 'prime-fleet' ), (string) ( $settings['rl_bookings_per_min'] ?? 20 ) );
		self::text( 'rl_bookings_email_per_hour', __( 'Bookings / hour / email', 'prime-fleet' ), (string) ( $settings['rl_bookings_email_per_hour'] ?? 24 ) );
		self::text( 'rl_coupon_per_min', __( 'Coupon validate / minute / IP', 'prime-fleet' ), (string) ( $settings['rl_coupon_per_min'] ?? 40 ) );
		self::text( 'rl_quote_per_min', __( 'Quote / minute / IP', 'prime-fleet' ), (string) ( $settings['rl_quote_per_min'] ?? 60 ) );
		self::text( 'rl_lookup_per_min', __( 'Lookup / minute / IP', 'prime-fleet' ), (string) ( $settings['rl_lookup_per_min'] ?? 30 ) );
		echo '</div></section>';

		echo '<section class="prime-fleet-card prime-fleet-section" style="margin-top:14px;">';
		echo '<header class="prime-fleet-section__head"><h3>' . esc_html__( 'Payment notify cooldown', 'prime-fleet' ) . '</h3>';
		echo '<p>' . esc_html__( 'Minimum seconds between guest payment-link emails/WhatsApp for the same booking. 0 disables.', 'prime-fleet' ) . '</p></header>';
		echo '<div class="prime-fleet-form-grid prime-fleet-form-grid--2">';
		self::text( 'payment_notify_cooldown_sec', __( 'Re-send cooldown (seconds)', 'prime-fleet' ), (string) ( $settings['payment_notify_cooldown_sec'] ?? 120 ) );
		echo '</div>';
		echo '<p class="prime-fleet-help">' . esc_html__( 'Abuse events (rate-limit 429, honeypot) appear under Logs. Production: OpenWA off; prefer Stripe/CAPTCHA secrets in wp-config.', 'prime-fleet' ) . '</p>';
		echo '</section>';
	}

	/**
	 * @param array $settings Settings.
	 */
	private static function panel_whatsapp( array $settings ) {
		echo '<section class="prime-fleet-card prime-fleet-section prime-fleet-settings-panel" id="pf-settings-panel-whatsapp" data-pf-settings-panel="whatsapp">';
		echo '<header class="prime-fleet-section__head"><h3>' . esc_html__( 'WhatsApp channel', 'prime-fleet' ) . '</h3>';
		echo '<p>' . esc_html__( 'Production path is Meta Cloud API (client Business WhatsApp). Stub / OpenWA are for development only.', 'prime-fleet' ) . '</p></header>';
		echo '<div class="prime-fleet-form-grid prime-fleet-form-grid--2">';
		echo '<div class="prime-fleet-field prime-fleet-field--check">';
		echo '<label class="prime-fleet-check"><input type="checkbox" name="whatsapp_channel_enabled" value="1" ' . checked( ! empty( $settings['whatsapp_channel_enabled'] ), true, false ) . ' />';
		echo '<span>' . esc_html__( 'Enable WhatsApp channel', 'prime-fleet' ) . '</span></label></div>';
		self::select(
			'whatsapp_driver',
			__( 'Driver', 'prime-fleet' ),
			(string) ( $settings['whatsapp_driver'] ?? 'stub' ),
			array(
				'stub'   => __( 'Stub (outbox only)', 'prime-fleet' ),
				'openwa' => __( 'OpenWA — TEMPORARY local test only', 'prime-fleet' ),
				'meta'   => __( 'Meta Cloud API (client Business WhatsApp)', 'prime-fleet' ),
			)
		);
		self::text(
			'whatsapp_meta_phone_number_id',
			__( 'Meta phone number ID (optional)', 'prime-fleet' ),
			(string) ( $settings['whatsapp_meta_phone_number_id'] ?? '' )
		);
		self::text(
			'whatsapp_meta_api_version',
			__( 'Meta Graph API version', 'prime-fleet' ),
			(string) ( $settings['whatsapp_meta_api_version'] ?? 'v21.0' )
		);
		echo '</div>';
		echo '<p class="prime-fleet-help">' . esc_html__( 'Meta: put the access token in wp-config as PRIME_FLEET_WA_ACCESS_TOKEN — never store tokens in the database.', 'prime-fleet' ) . '</p>';

		echo '<div class="prime-fleet-notice prime-fleet-notice--info" style="margin-top:16px;" role="status">';
		echo esc_html__( 'OpenWA is temporary for local testing only. Unofficial / ban risk — use a throwaway number. Never use the client Business WhatsApp number. Switch back to Stub or Meta before staging/client demos.', 'prime-fleet' );
		echo '</div>';
		echo '<div class="prime-fleet-form-grid prime-fleet-form-grid--2" style="margin-top:12px;">';
		self::text(
			'whatsapp_openwa_base_url',
			__( 'OpenWA base URL', 'prime-fleet' ),
			(string) ( $settings['whatsapp_openwa_base_url'] ?? 'http://127.0.0.1:2785' )
		);
		self::text(
			'whatsapp_openwa_session_id',
			__( 'OpenWA session ID', 'prime-fleet' ),
			(string) ( $settings['whatsapp_openwa_session_id'] ?? '' )
		);
		self::text(
			'whatsapp_openwa_api_key',
			__( 'OpenWA API key (prefer wp-config PRIME_FLEET_OPENWA_API_KEY)', 'prime-fleet' ),
			(string) ( $settings['whatsapp_openwa_api_key'] ?? '' )
		);
		echo '</div>';
		echo '<p class="prime-fleet-help">' . esc_html__( 'Run OpenWA locally (Docker → http://127.0.0.1:2785). Base URL must be localhost / 127.0.0.1 only (SSRF guard). Driver works only with WP_DEBUG or PRIME_FLEET_ALLOW_OPENWA in wp-config — otherwise settings fall back to Stub.', 'prime-fleet' ) . '</p>';
		echo '</section>';
	}

	/**
	 * Website / sticky CTA (M5 Phase C).
	 *
	 * @param array $settings Settings.
	 */
	private static function panel_website( array $settings ) {
		echo '<section class="prime-fleet-card prime-fleet-section prime-fleet-settings-panel" id="pf-settings-panel-website" data-pf-settings-panel="website">';

		echo '<header class="prime-fleet-section__head"><h3>' . esc_html__( 'Booking form theme', 'prime-fleet' ) . '</h3>';
		echo '<p>' . esc_html__( 'Guest-facing wizard appearance. Dark is the current navy look; light uses a white section.', 'prime-fleet' ) . '</p></header>';
		echo '<div class="prime-fleet-form-grid prime-fleet-form-grid--2">';
		self::select(
			'form_theme',
			__( 'Form theme', 'prime-fleet' ),
			(string) ( $settings['form_theme'] ?? 'dark' ),
			array(
				'dark'  => __( 'Dark (navy)', 'prime-fleet' ),
				'light' => __( 'Light (white)', 'prime-fleet' ),
			)
		);
		echo '</div>';

		echo '<header class="prime-fleet-section__head" style="margin-top:1.5rem"><h3>' . esc_html__( 'Sticky Book + WhatsApp', 'prime-fleet' ) . '</h3>';
		echo '<p>' . esc_html__( 'Mobile bottom bar (plugin-owned). Reserves page space to avoid layout jump. Desktop header is unchanged.', 'prime-fleet' ) . '</p></header>';
		echo '<div class="prime-fleet-form-grid prime-fleet-form-grid--2">';
		echo '<div class="prime-fleet-field prime-fleet-field--check">';
		echo '<label class="prime-fleet-check"><input type="checkbox" name="sticky_cta_enabled" value="1" ' . checked( ! empty( $settings['sticky_cta_enabled'] ), true, false ) . ' />';
		echo '<span>' . esc_html__( 'Enable sticky CTA bar', 'prime-fleet' ) . '</span></label></div>';
		self::text(
			'sticky_book_url',
			__( 'Book URL (blank = /book-now/)', 'prime-fleet' ),
			(string) ( $settings['sticky_book_url'] ?? '' )
		);
		self::text(
			'sticky_whatsapp_e164',
			__( 'WhatsApp E.164 (blank = Public WhatsApp)', 'prime-fleet' ),
			(string) ( $settings['sticky_whatsapp_e164'] ?? '' )
		);
		self::text(
			'sticky_book_label',
			__( 'Book button label', 'prime-fleet' ),
			(string) ( $settings['sticky_book_label'] ?? '' )
		);
		self::text(
			'sticky_wa_label',
			__( 'WhatsApp button label', 'prime-fleet' ),
			(string) ( $settings['sticky_wa_label'] ?? '' )
		);
		echo '</div>';
		echo '<p class="prime-fleet-help">' . esc_html__( 'Uses https://wa.me/ deep links until Meta Cloud API is live. Empty labels fall back to “Book Now” / “WhatsApp”.', 'prime-fleet' ) . '</p>';
		echo '</section>';
	}

	/**
	 * Save only the active settings section (page), merge into stored option.
	 *
	 * @return string
	 */
	private static function handle_save() {
		if ( empty( $_POST['prime_fleet_action'] ) || 'save_settings' !== sanitize_key( wp_unslash( $_POST['prime_fleet_action'] ) ) ) {
			return '';
		}
		check_admin_referer( self::NONCE );

		$tabs = self::tabs();
		$tab  = isset( $_POST['settings_tab'] ) ? sanitize_key( wp_unslash( $_POST['settings_tab'] ) ) : 'general';
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'general';
		}

		$prev = get_option( self::OPTION, array() );
		if ( ! is_array( $prev ) ) {
			$prev = array();
		}

		$patch = array();
		if ( 'general' === $tab ) {
			$allowed_currency = array( 'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'XCD' );
			$currency         = strtoupper( sanitize_text_field( wp_unslash( $_POST['currency'] ?? 'USD' ) ) );
			if ( ! in_array( $currency, $allowed_currency, true ) ) {
				$currency = 'USD';
			}
			$slot = absint( $_POST['slot_increment'] ?? 30 );
			if ( ! in_array( $slot, array( 15, 30, 60 ), true ) ) {
				$slot = 30;
			}
			$status = sanitize_key( wp_unslash( $_POST['default_status'] ?? 'pending_confirmation' ) );
			if ( ! in_array( $status, array( 'pending_confirmation', 'confirmed', 'manual_review' ), true ) ) {
				$status = 'pending_confirmation';
			}
			$tz = sanitize_text_field( wp_unslash( $_POST['timezone'] ?? 'UTC' ) );
			if ( ! in_array( $tz, timezone_identifiers_list(), true ) ) {
				$tz = 'UTC';
			}
			$patch = array(
				'currency'         => $currency,
				'timezone'         => $tz,
				'reference_prefix' => sanitize_text_field( wp_unslash( $_POST['reference_prefix'] ?? 'PF-' ) ),
				'default_status'   => $status,
				'slot_increment'   => $slot,
				'submit_label'     => sanitize_text_field( wp_unslash( $_POST['submit_label'] ?? '' ) ),
				'phone'            => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
				'whatsapp'         => sanitize_text_field( wp_unslash( $_POST['whatsapp'] ?? '' ) ),
			);
		} elseif ( 'booking' === $tab ) {
			$min_advance = isset( $_POST['min_advance_hours'] ) ? sanitize_text_field( wp_unslash( $_POST['min_advance_hours'] ) ) : '';
			$patch       = array(
				'min_advance_hours'             => '' === $min_advance ? '' : $min_advance,
				'same_day_allowed'              => ! empty( $_POST['same_day_allowed'] ) ? 1 : 0,
				'waiting_complimentary_minutes' => max( 0, absint( $_POST['waiting_complimentary_minutes'] ?? 30 ) ),
				'reminder_24h'                  => absint( $_POST['reminder_24h'] ?? 1440 ),
				'reminder_2h'                   => absint( $_POST['reminder_2h'] ?? 120 ),
			);
		} elseif ( 'guest' === $tab ) {
			$patch = array(
				'pending_message'      => sanitize_textarea_field( wp_unslash( $_POST['pending_message'] ?? '' ) ),
				'custom_quote_message' => sanitize_textarea_field( wp_unslash( $_POST['custom_quote_message'] ?? '' ) ),
			);
		} elseif ( 'pricing' === $tab ) {
			$season_pct = isset( $_POST['seasonal_discount_pct'] )
				? (float) sanitize_text_field( wp_unslash( $_POST['seasonal_discount_pct'] ) )
				: 0;
			if ( $season_pct < 0 ) {
				$season_pct = 0;
			}
			if ( $season_pct > 50 ) {
				$season_pct = 50;
			}
			$season_svc_ids = array();
			if ( ! empty( $_POST['seasonal_discount_service_ids'] ) && is_array( $_POST['seasonal_discount_service_ids'] ) ) {
				foreach ( wp_unslash( $_POST['seasonal_discount_service_ids'] ) as $raw_id ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$sid = absint( $raw_id );
					if ( $sid ) {
						$season_svc_ids[] = $sid;
					}
				}
				$season_svc_ids = array_values( array_unique( $season_svc_ids ) );
			}
			$patch = array(
				'seasonal_discount_pct'             => $season_pct,
				'seasonal_discount_service_ids'     => $season_svc_ids,
				'airport_vip_seasonal_discount_pct' => $season_pct,
			);
		} elseif ( 'whatsapp' === $tab ) {
			$driver = sanitize_key( wp_unslash( $_POST['whatsapp_driver'] ?? 'stub' ) );
			if ( ! in_array( $driver, array( 'stub', 'openwa', 'meta' ), true ) ) {
				$driver = 'stub';
			}
			$openwa_base = \Prime_Fleet\Messaging\WhatsApp\OpenWA_Channel::sanitize_base_url(
				wp_unslash( $_POST['whatsapp_openwa_base_url'] ?? 'http://127.0.0.1:2785' ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			);
			/* OpenWA is dev-only — force Stub when selected without WP_DEBUG / allow constant. */
			if ( 'openwa' === $driver && ! \Prime_Fleet\Messaging\WhatsApp\OpenWA_Channel::is_dev_allowed() ) {
				$driver = 'stub';
			}
			$patch = array(
				'whatsapp_channel_enabled'      => ! empty( $_POST['whatsapp_channel_enabled'] ) ? 1 : 0,
				'whatsapp_driver'               => $driver,
				'whatsapp_meta_phone_number_id' => sanitize_text_field( wp_unslash( $_POST['whatsapp_meta_phone_number_id'] ?? '' ) ),
				'whatsapp_meta_api_version'     => sanitize_text_field( wp_unslash( $_POST['whatsapp_meta_api_version'] ?? 'v21.0' ) ),
				'whatsapp_openwa_base_url'      => $openwa_base,
				'whatsapp_openwa_session_id'    => sanitize_text_field( wp_unslash( $_POST['whatsapp_openwa_session_id'] ?? '' ) ),
				'whatsapp_openwa_api_key'       => sanitize_text_field( wp_unslash( $_POST['whatsapp_openwa_api_key'] ?? '' ) ),
			);
		} elseif ( 'security' === $tab ) {
			$provider = sanitize_key( wp_unslash( $_POST['captcha_provider'] ?? 'none' ) );
			if ( ! in_array( $provider, array( 'none', 'turnstile', 'recaptcha_v2' ), true ) ) {
				$provider = 'none';
			}
			$clamp = static function ( $v, $min, $max, $fallback ) {
				$n = absint( $v );
				if ( $n < $min || $n > $max ) {
					return $fallback;
				}
				return $n;
			};
			$new_secret = sanitize_text_field( wp_unslash( $_POST['captcha_secret'] ?? '' ) );
			/* Blank password field keeps the previously saved secret. */
			if ( '' === $new_secret && ! empty( $prev['captcha_secret'] ) ) {
				$new_secret = (string) $prev['captcha_secret'];
			}
			$patch = array(
				'captcha_enabled'            => ! empty( $_POST['captcha_enabled'] ) ? 1 : 0,
				'captcha_provider'           => $provider,
				'captcha_site_key'           => sanitize_text_field( wp_unslash( $_POST['captcha_site_key'] ?? '' ) ),
				'captcha_secret'             => $new_secret,
				'rl_bookings_per_min'        => $clamp( $_POST['rl_bookings_per_min'] ?? 20, 1, 500, 20 ),
				'rl_bookings_email_per_hour' => $clamp( $_POST['rl_bookings_email_per_hour'] ?? 24, 1, 200, 24 ),
				'rl_coupon_per_min'           => $clamp( $_POST['rl_coupon_per_min'] ?? 40, 1, 500, 40 ),
				'rl_quote_per_min'            => $clamp( $_POST['rl_quote_per_min'] ?? 60, 1, 500, 60 ),
				'rl_lookup_per_min'           => $clamp( $_POST['rl_lookup_per_min'] ?? 30, 1, 500, 30 ),
				'payment_notify_cooldown_sec'=> $clamp( $_POST['payment_notify_cooldown_sec'] ?? 120, 0, HOUR_IN_SECONDS, 120 ),
			);
		} else {
			$form_theme = sanitize_key( wp_unslash( $_POST['form_theme'] ?? 'dark' ) );
			if ( ! in_array( $form_theme, array( 'dark', 'light' ), true ) ) {
				$form_theme = 'dark';
			}
			$patch = array(
				'form_theme'           => $form_theme,
				'sticky_cta_enabled'   => ! empty( $_POST['sticky_cta_enabled'] ) ? 1 : 0,
				'sticky_book_url'      => esc_url_raw( wp_unslash( $_POST['sticky_book_url'] ?? '' ) ),
				'sticky_whatsapp_e164' => sanitize_text_field( wp_unslash( $_POST['sticky_whatsapp_e164'] ?? '' ) ),
				'sticky_book_label'    => sanitize_text_field( wp_unslash( $_POST['sticky_book_label'] ?? '' ) ),
				'sticky_wa_label'      => sanitize_text_field( wp_unslash( $_POST['sticky_wa_label'] ?? '' ) ),
			);
		}

		$data = array_merge( $prev, $patch );
		update_option( self::OPTION, $data );
		Config_Compiler::invalidate();

		/* Keep SPA on the same settings page after save. */
		$_GET['stab'] = $tab; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return View::notice( __( 'Settings saved.', 'prime-fleet' ) );
	}

	private static function text( $name, $label, $value ) {
		printf(
			'<div class="prime-fleet-field"><label for="%1$s">%2$s</label><input id="%1$s" type="text" name="%1$s" value="%3$s" /></div>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( (string) $value )
		);
	}

	/**
	 * @param string               $name Name.
	 * @param string               $label Label.
	 * @param string               $value Value.
	 * @param array<string,string> $options Options.
	 */
	private static function select( $name, $label, $value, array $options ) {
		echo '<div class="prime-fleet-field"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label>';
		echo '<select id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">';
		foreach ( $options as $opt_value => $opt_label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( (string) $opt_value ),
				selected( (string) $value, (string) $opt_value, false ),
				esc_html( $opt_label )
			);
		}
		echo '</select></div>';
	}

	/**
	 * @param string $name Name.
	 * @param string $label Label.
	 * @param string $value Value.
	 */
	private static function timezone( $name, $label, $value ) {
		echo '<div class="prime-fleet-field"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label>';
		echo '<select id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" data-pf-search="1">';
		echo wp_timezone_choice( $value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WP core helper.
		echo '</select></div>';
	}

	private static function textarea( $name, $label, $value, $rows = 3 ) {
		printf(
			'<div class="prime-fleet-field prime-fleet-field--block"><label for="%1$s">%2$s</label><textarea id="%1$s" name="%1$s" rows="%4$d">%3$s</textarea></div>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_textarea( (string) $value ),
			(int) $rows
		);
	}
}
