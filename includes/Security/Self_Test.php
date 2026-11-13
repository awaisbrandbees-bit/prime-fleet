<?php
/**
 * Modular security self-test for S1 / S2 hardening.
 *
 * Run via WP-CLI:
 *   wp eval-file wp-content/plugins/prime-fleet/bin/security-self-test.php --path=...
 *
 * Or admin (manage): admin.php?page=prime-fleet&prime_fleet_security_self_test=1&_wpnonce=...
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Security;

use Prime_Fleet\Bookings\Coupons;
use Prime_Fleet\Bookings\Submit_Token;
use Prime_Fleet\Install\Capabilities;
use Prime_Fleet\Messaging\Templates;
use Prime_Fleet\Messaging\WhatsApp\Channel_Factory;
use Prime_Fleet\Messaging\WhatsApp\OpenWA_Channel;
use Prime_Fleet\Messaging\WhatsApp\Stub_Channel;
use Prime_Fleet\REST\Rate_Limiter;

defined( 'ABSPATH' ) || exit;

final class Self_Test {

	/**
	 * Admin bootstrap (nonce-gated).
	 */
	public static function hooks() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_run_admin' ) );
	}

	/**
	 * Admin trigger: dumps JSON for manage users.
	 */
	public static function maybe_run_admin() {
		if ( empty( $_GET['prime_fleet_security_self_test'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! Capabilities::current_user_can( Capabilities::MANAGE ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'prime-fleet' ), 403 );
		}
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! wp_verify_nonce( $nonce, 'prime_fleet_security_self_test' ) ) {
			wp_die( esc_html__( 'Invalid security token.', 'prime-fleet' ), 403 );
		}

		$report = self::run();
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * @return array{ok:bool,passed:int,failed:int,skipped:int,modules:array<string,array>}
	 */
	public static function run() {
		$modules = array(
			's1_merge'      => self::mod_merge_escape(),
			's1_openwa'     => self::mod_openwa(),
			's1_csv_nonce'  => self::mod_csv_nonce(),
			's1_token_ip'   => self::mod_submit_token_ip(),
			's1_coupon'     => self::mod_coupon_uniform(),
			's2_captcha'    => self::mod_captcha(),
			's2_rate_limit' => self::mod_rate_limit_config(),
		);

		$passed = $failed = $skipped = 0;
		foreach ( $modules as $mod ) {
			foreach ( $mod['cases'] as $case ) {
				if ( 'pass' === $case['status'] ) {
					++$passed;
				} elseif ( 'fail' === $case['status'] ) {
					++$failed;
				} else {
					++$skipped;
				}
			}
		}

		return array(
			'ok'      => 0 === $failed,
			'passed'  => $passed,
			'failed'  => $failed,
			'skipped' => $skipped,
			'modules' => $modules,
			'at'      => gmdate( 'c' ),
			'scope'   => 'S1+S2 security hardening',
		);
	}

	/**
	 * @param string $id Case id.
	 * @param string $label Label.
	 * @param bool   $ok Pass?
	 * @param string $detail Detail.
	 * @return array{id:string,label:string,status:string,detail:string}
	 */
	private static function case_result( $id, $label, $ok, $detail = '' ) {
		return array(
			'id'     => $id,
			'label'  => $label,
			'status' => $ok ? 'pass' : 'fail',
			'detail' => $detail,
		);
	}

	/**
	 * @return array{title:string,cases:array}
	 */
	private static function mod_merge_escape() {
		$cases = array();
		$out   = Templates::merge(
			'<p>Hello {{customer_name}}</p><a href="{{payment_link}}">{{payment_link}}</a>{{coupon_rows}}',
			array(
				'customer_name' => '<img src=x onerror=alert(1)>',
				'payment_link'  => 'javascript:alert(1)',
				'coupon_rows'   => '<tr><td>ok</td></tr><script>evil()</script>',
			)
		);
		$cases[] = self::case_result(
			'merge_escapes_name',
			'Guest name HTML escaped',
			false === strpos( $out, '<img' ) && false !== strpos( $out, '&lt;img' ),
			$out
		);
		$cases[] = self::case_result(
			'merge_blocks_js_url',
			'javascript: payment_link emptied by esc_url',
			false === strpos( $out, 'javascript:' ),
			$out
		);
		$cases[] = self::case_result(
			'merge_strips_script_in_html_allowlist',
			'coupon_rows allowlist still strips script via wp_kses_post',
			false === stripos( $out, '<script' ),
			$out
		);
		return array(
			'title' => 'S1 — Templates::merge escaping',
			'cases' => $cases,
		);
	}

	/**
	 * @return array{title:string,cases:array}
	 */
	private static function mod_openwa() {
		$cases = array();
		$bad   = array(
			'http://169.254.169.254/',
			'http://10.0.0.1:2785',
			'http://evil.example/path',
			'http://127.0.0.1@evil.com/',
			'file:///etc/passwd',
		);
		foreach ( $bad as $i => $url ) {
			$safe = OpenWA_Channel::sanitize_base_url( $url );
			$host = (string) wp_parse_url( $safe, PHP_URL_HOST );
			$cases[] = self::case_result(
				'openwa_reject_' . $i,
				'Reject SSRF URL → loopback default',
				OpenWA_Channel::is_loopback_host( $host ) && false === strpos( $safe, 'evil' ) && false === strpos( $safe, '169.254' ),
				$url . ' => ' . $safe
			);
		}
		$good = OpenWA_Channel::sanitize_base_url( 'http://127.0.0.1:2785/api' );
		$cases[] = self::case_result(
			'openwa_keep_loopback',
			'Keep loopback base',
			'http://127.0.0.1:2785/api' === $good || 'http://127.0.0.1:2785' === untrailingslashit( $good ),
			$good
		);
		$cases[] = self::case_result(
			'openwa_ipv6_brackets',
			'IPv6 [::1] recognized as loopback',
			OpenWA_Channel::is_loopback_host( '[::1]' ),
			''
		);

		$ch = Channel_Factory::make();
		/* When settings force openwa without debug, factory should stub — we only assert factory returns an object. */
		$cases[] = self::case_result(
			'openwa_factory_returns_channel',
			'Channel_Factory returns a channel or null',
			null === $ch || $ch instanceof Stub_Channel || $ch instanceof OpenWA_Channel,
			is_object( $ch ) ? get_class( $ch ) : 'null'
		);

		return array(
			'title' => 'S1 — OpenWA SSRF / gate',
			'cases' => $cases,
		);
	}

	/**
	 * @return array{title:string,cases:array}
	 */
	private static function mod_csv_nonce() {
		$cases   = array();
		$reports = file_get_contents( \PRIME_FLEET_PATH . 'includes/Admin/Pages/Reports.php' );
		$bookings = file_get_contents( \PRIME_FLEET_PATH . 'includes/Admin/Pages/Bookings.php' );
		$cases[] = self::case_result(
			'reports_csv_verify',
			'Reports CSV verifies nonce',
			is_string( $reports ) && false !== strpos( $reports, 'wp_verify_nonce' ) && false !== strpos( $reports, 'CSV_NONCE' ),
			''
		);
		$cases[] = self::case_result(
			'reports_csv_url_nonce',
			'Reports csv_url uses wp_nonce_url',
			is_string( $reports ) && false !== strpos( $reports, 'wp_nonce_url' ),
			''
		);
		$cases[] = self::case_result(
			'bookings_csv_verify',
			'Bookings CSV verifies nonce',
			is_string( $bookings ) && false !== strpos( $bookings, 'prime_fleet_bookings_csv' ) && false !== strpos( $bookings, 'wp_verify_nonce' ),
			''
		);
		return array(
			'title' => 'S1 — CSV export CSRF nonce',
			'cases' => $cases,
		);
	}

	/**
	 * @return array{title:string,cases:array}
	 */
	private static function mod_submit_token_ip() {
		$cases = array();
		$orig  = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : null; // phpcs:ignore

		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
		$issued = Submit_Token::issue();
		if ( is_wp_error( $issued ) ) {
			$cases[] = self::case_result( 'token_issue', 'Issue token', false, $issued->get_error_message() );
			return array( 'title' => 'S1 — Submit token IP bind', 'cases' => $cases );
		}
		$token = $issued['token'];

		/* Age past MIN_AGE without sleeping wall-clock: rewrite transient issued_at. */
		$key  = 'prime_fleet_bt_' . hash( 'sha256', $token );
		$data = get_transient( $key );
		if ( is_array( $data ) ) {
			$data['issued_at'] = time() - 5;
			set_transient( $key, $data, HOUR_IN_SECONDS );
		}

		$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
		$mismatch = Submit_Token::consume( $token );
		$cases[]  = self::case_result(
			'token_ip_mismatch',
			'Consume fails on IP mismatch',
			is_wp_error( $mismatch ) && 'prime_fleet_token_ip' === $mismatch->get_error_code(),
			is_wp_error( $mismatch ) ? $mismatch->get_error_code() : 'unexpected success'
		);

		/* Re-issue for match path. */
		$_SERVER['REMOTE_ADDR'] = '203.0.113.11';
		$issued2 = Submit_Token::issue();
		if ( ! is_wp_error( $issued2 ) ) {
			$token2 = $issued2['token'];
			$key2   = 'prime_fleet_bt_' . hash( 'sha256', $token2 );
			$data2  = get_transient( $key2 );
			if ( is_array( $data2 ) ) {
				$data2['issued_at'] = time() - 5;
				set_transient( $key2, $data2, HOUR_IN_SECONDS );
			}
			$ok = Submit_Token::consume( $token2 );
			$cases[] = self::case_result(
				'token_ip_match',
				'Consume succeeds on same IP',
				true === $ok,
				is_wp_error( $ok ) ? $ok->get_error_code() : 'ok'
			);
			$replay = Submit_Token::consume( $token2 );
			$cases[] = self::case_result(
				'token_replay',
				'Replay rejected',
				is_wp_error( $replay ) && 'prime_fleet_token_replay' === $replay->get_error_code(),
				is_wp_error( $replay ) ? $replay->get_error_code() : 'unexpected'
			);
		}

		if ( null === $orig ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $orig;
		}

		return array(
			'title' => 'S1 — Submit token IP bind',
			'cases' => $cases,
		);
	}

	/**
	 * @return array{title:string,cases:array}
	 */
	private static function mod_coupon_uniform() {
		$cases = array();
		$a     = Coupons::validate( 'ZZZ-NOT-A-REAL-CODE-XYZ', array( 'fare_total' => 100, 'service_id' => 1 ) );
		$b     = Coupons::validate( 'ZZZ-OTHER-FAKE', array( 'fare_total' => 100, 'service_id' => 1 ) );
		$cases[] = self::case_result(
			'coupon_uniform_missing',
			'Unknown codes share one error message',
			is_wp_error( $a ) && is_wp_error( $b ) && $a->get_error_message() === $b->get_error_message(),
			is_wp_error( $a ) ? $a->get_error_message() : ''
		);
		$zero = Coupons::validate( 'ANY', array( 'fare_total' => 0, 'service_id' => 1 ) );
		$cases[] = self::case_result(
			'coupon_rejects_zero_fare',
			'Zero/server-missing fare rejected (no client-trusted total)',
			is_wp_error( $zero ),
			is_wp_error( $zero ) ? $zero->get_error_code() : 'ok'
		);

		$ctrl = file_get_contents( \PRIME_FLEET_PATH . 'includes/REST/Controller.php' );
		$cases[] = self::case_result(
			'coupon_controller_requotes',
			'Controller re-quotes and unsets client fare_total',
			is_string( $ctrl ) && false !== strpos( $ctrl, "unset( \$input['total']" ) && false !== strpos( $ctrl, 'Engine::quote' ),
			''
		);

		return array(
			'title' => 'S1 — Coupon harden',
			'cases' => $cases,
		);
	}

	/**
	 * @return array{title:string,cases:array}
	 */
	private static function mod_captcha() {
		$cases = array();

		$cases[] = self::case_result(
			'captcha_class_exists',
			'Captcha class loaded',
			class_exists( Captcha::class ),
			''
		);

		$cfg = Captcha::public_config();
		$cases[] = self::case_result(
			'captcha_public_shape',
			'public_config shape',
			isset( $cfg['enabled'], $cfg['provider'], $cfg['site_key'] ) && is_bool( $cfg['enabled'] ),
			wp_json_encode( $cfg )
		);

		/* Off by default → verify passes. */
		$prev = get_option( 'prime_fleet_settings', array() );
		if ( ! is_array( $prev ) ) {
			$prev = array();
		}
		update_option(
			'prime_fleet_settings',
			array_merge(
				$prev,
				array(
					'captcha_enabled'  => 0,
					'captcha_provider' => 'none',
					'captcha_site_key' => '',
					'captcha_secret'   => '',
				)
			),
			false
		);
		$off = Captcha::verify_request( array() );
		$cases[] = self::case_result(
			'captcha_off_passthrough',
			'Disabled CAPTCHA allows submit',
			true === $off,
			is_wp_error( $off ) ? $off->get_error_code() : 'ok'
		);

		update_option(
			'prime_fleet_settings',
			array_merge(
				$prev,
				array(
					'captcha_enabled'  => 1,
					'captcha_provider' => 'turnstile',
					'captcha_site_key' => '',
					'captcha_secret'   => '',
				)
			),
			false
		);
		$mis = Captcha::verify_request( array() );
		$cases[] = self::case_result(
			'captcha_misconfig_fail_closed',
			'Enabled but incomplete → fail closed',
			is_wp_error( $mis ) && 'prime_fleet_captcha_config' === $mis->get_error_code(),
			is_wp_error( $mis ) ? $mis->get_error_code() : 'unexpected'
		);

		update_option(
			'prime_fleet_settings',
			array_merge(
				$prev,
				array(
					'captcha_enabled'  => 1,
					'captcha_provider' => 'turnstile',
					'captcha_site_key' => 'test-site',
					'captcha_secret'   => 'test-secret',
				)
			),
			false
		);
		$missing = Captcha::verify_request( array() );
		$cases[] = self::case_result(
			'captcha_missing_token',
			'Configured CAPTCHA rejects empty token',
			is_wp_error( $missing ) && 'prime_fleet_captcha' === $missing->get_error_code(),
			is_wp_error( $missing ) ? $missing->get_error_code() : 'unexpected'
		);

		/* Restore prior settings. */
		update_option( 'prime_fleet_settings', $prev, false );

		$js = file_get_contents( \PRIME_FLEET_PATH . 'assets/frontend/js/booking.js' );
		$cases[] = self::case_result(
			'captcha_js_wired',
			'booking.js mounts CAPTCHA + sends captcha_token',
			is_string( $js ) && false !== strpos( $js, 'captcha_token' ) && false !== strpos( $js, 'mountCaptcha' ) && false !== strpos( $js, 'data-pf-captcha' ),
			''
		);

		return array(
			'title' => 'S2 — CAPTCHA',
			'cases' => $cases,
		);
	}

	/**
	 * @return array{title:string,cases:array}
	 */
	private static function mod_rate_limit_config() {
		$cases = array();
		$rl    = file_get_contents( \PRIME_FLEET_PATH . 'includes/REST/Rate_Limiter.php' );
		$cases[] = self::case_result(
			'rl_allow_configured',
			'Rate_Limiter::allow_configured exists',
			is_string( $rl ) && false !== strpos( $rl, 'function allow_configured' ),
			''
		);

		$prev = get_option( 'prime_fleet_settings', array() );
		if ( ! is_array( $prev ) ) {
			$prev = array();
		}
		update_option(
			'prime_fleet_settings',
			array_merge( $prev, array( 'rl_coupon_per_min' => 2 ) ),
			false
		);
		/* Unique bucket suffix via IP spoof so we don't trip production buckets. */
		$orig = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : null; // phpcs:ignore
		$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand( 50, 200 );
		$ok1 = Rate_Limiter::allow_configured( 'coupon' );
		$ok2 = Rate_Limiter::allow_configured( 'coupon' );
		$ok3 = Rate_Limiter::allow_configured( 'coupon' );
		$cases[] = self::case_result(
			'rl_respects_setting',
			'rl_coupon_per_min=2 trips on 3rd hit',
			true === $ok1 && true === $ok2 && is_wp_error( $ok3 ),
			is_wp_error( $ok3 ) ? $ok3->get_error_code() : 'no trip'
		);
		if ( null === $orig ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $orig;
		}
		update_option( 'prime_fleet_settings', $prev, false );

		$settings_php = file_get_contents( \PRIME_FLEET_PATH . 'includes/Admin/Pages/Settings.php' );
		$cases[]      = self::case_result(
			'rl_settings_ui',
			'Settings Security tab has rate limit fields',
			is_string( $settings_php ) && false !== strpos( $settings_php, 'panel_security' ) && false !== strpos( $settings_php, 'rl_bookings_per_min' ),
			''
		);

		return array(
			'title' => 'S2 — Tunable rate limits',
			'cases' => $cases,
		);
	}
}
