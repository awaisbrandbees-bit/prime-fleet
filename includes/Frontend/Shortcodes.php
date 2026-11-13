<?php
/**
 * Front-end shortcodes.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Frontend;

defined( 'ABSPATH' ) || exit;

final class Shortcodes {

	/**
	 * Hooks.
	 */
	public function hooks() {
		add_shortcode( 'prime_fleet_booking', array( $this, 'booking' ) );
		add_shortcode( 'prime_fleet_lookup', array( $this, 'lookup' ) );
		add_shortcode( 'prime_fleet_offer', array( $this, 'offer' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render_offer_page' ) );
		add_filter( 'body_class', array( $this, 'body_class' ) );
		add_action( 'wp_head', array( $this, 'early_light_canvas' ), 1 );
	}

	/**
	 * Guest booking form theme (dark|light). Default dark.
	 *
	 * @return string
	 */
	private function form_theme() {
		$settings = get_option( 'prime_fleet_settings', array() );
		$theme    = is_array( $settings ) && isset( $settings['form_theme'] )
			? sanitize_key( (string) $settings['form_theme'] )
			: 'dark';
		return in_array( $theme, array( 'dark', 'light' ), true ) ? $theme : 'dark';
	}

	/**
	 * Early booking-only styles (do not paint header/footer).
	 */
	public function early_light_canvas() {
		if ( ! $this->page_has_booking() ) {
			return;
		}
		$theme = $this->form_theme();
		$bg    = 'light' === $theme ? '#ffffff' : '#0b1220';
		$fg    = 'light' === $theme ? '#0b1220' : '#f8fafc';
		$mark  = 'light' === $theme ? '#ffffff' : '#042f2e';
		echo '<style id="prime-fleet-early">'
			. '.prime-fleet{box-sizing:border-box;background:' . $bg . '!important;color:' . $fg . '!important;min-height:50vh;width:100vw;max-width:100vw;margin-left:calc(50% - 50vw);margin-right:calc(50% - 50vw);padding:28px max(16px,calc((100vw - 1080px)/2)) 56px;}'
			. '.prime-fleet__brand-mark{display:inline-grid;place-items:center;width:1.65rem;height:1.65rem;border-radius:4px;background:#2dd4bf!important;color:' . $mark . '!important;font-size:.58rem;font-weight:600;}'
			. '.prime-fleet__boot--simple{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;min-height:50vh;}'
			. '.prime-fleet__spinner--boot{width:1.35rem;height:1.35rem;border:2px solid rgba(148,163,184,.35);border-top-color:#2dd4bf;border-radius:50%;animation:prime-fleet-spin .7s linear infinite;}'
			. '.prime-fleet__boot-text{margin:0;font-size:.9rem;color:' . ( 'light' === $theme ? '#4b5565' : '#94a3b8' ) . ';}'
			. '@keyframes prime-fleet-spin{to{transform:rotate(360deg)}}'
			. '</style>' . "\n";
	}

	/**
	 * Whether current singular page hosts the booking wizard (classic or Elementor).
	 */
	private function page_has_booking() {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_post();
		if ( ! $post ) {
			return false;
		}
		if ( has_shortcode( (string) $post->post_content, 'prime_fleet_booking' ) ) {
			return true;
		}
		$data = get_post_meta( $post->ID, '_elementor_data', true );
		if ( is_string( $data ) && false !== strpos( $data, 'prime_fleet_booking' ) ) {
			return true;
		}
		if ( is_array( $data ) && false !== strpos( wp_json_encode( $data ), 'prime_fleet_booking' ) ) {
			return true;
		}
		return (int) get_option( 'prime_fleet_book_page_id', 0 ) === (int) $post->ID;
	}

	/**
	 * Mark booking pages (body class only; no chrome paint).
	 *
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public function body_class( $classes ) {
		if ( $this->page_has_booking() ) {
			$classes[] = 'prime-fleet-has-booking';
		}
		return $classes;
	}

	/**
	 * Register assets.
	 */
	public function register_assets() {
		wp_register_style(
			'prime-fleet-fonts',
			'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;1,400&family=Sora:wght@500;600;700;800&display=swap',
			array(),
			null
		);
		$css = \PRIME_FLEET_PATH . 'assets/frontend/css/booking.css';
		$motion = \PRIME_FLEET_PATH . 'assets/frontend/css/motion.css';
		$js  = \PRIME_FLEET_PATH . 'assets/frontend/js/booking.js';
		wp_register_style(
			'prime-fleet',
			\PRIME_FLEET_URL . 'assets/frontend/css/booking.css',
			array( 'prime-fleet-fonts' ),
			file_exists( $css ) ? (string) filemtime( $css ) : \PRIME_FLEET_VERSION
		);
		$skin = \PRIME_FLEET_PATH . 'assets/frontend/css/skin.css';
		wp_register_style(
			'prime-fleet-skin',
			\PRIME_FLEET_URL . 'assets/frontend/css/skin.css',
			array( 'prime-fleet' ),
			file_exists( $skin ) ? (string) filemtime( $skin ) : \PRIME_FLEET_VERSION
		);
		wp_register_style(
			'prime-fleet-motion',
			\PRIME_FLEET_URL . 'assets/frontend/css/motion.css',
			array( 'prime-fleet-skin' ),
			file_exists( $motion ) ? (string) filemtime( $motion ) : \PRIME_FLEET_VERSION
		);
		wp_register_script(
			'prime-fleet',
			\PRIME_FLEET_URL . 'assets/frontend/js/booking.js',
			array(),
			file_exists( $js ) ? (string) filemtime( $js ) : \PRIME_FLEET_VERSION,
			true
		);
		wp_register_script(
			'prime-fleet-lookup',
			\PRIME_FLEET_URL . 'assets/frontend/js/lookup.js',
			array(),
			\PRIME_FLEET_VERSION,
			true
		);
		wp_register_script(
			'prime-fleet-offer',
			\PRIME_FLEET_URL . 'assets/frontend/js/offer.js',
			array(),
			file_exists( \PRIME_FLEET_PATH . 'assets/frontend/js/offer.js' )
				? (string) filemtime( \PRIME_FLEET_PATH . 'assets/frontend/js/offer.js' )
				: \PRIME_FLEET_VERSION,
			true
		);
	}

	/**
	 * Shared REST bootstrap for guest scripts.
	 *
	 * @return array
	 */
	private function rest_boot() {
		return array(
			'restUrl' => esc_url_raw( home_url( '/index.php?rest_route=/prime-fleet/v1' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
		);
	}

	/**
	 * Booking wizard.
	 *
	 * @param array $atts Atts.
	 * @return string
	 */
	public function booking( $atts = array() ) {
		wp_enqueue_style( 'prime-fleet-fonts' );
		wp_enqueue_style( 'prime-fleet' );
		wp_enqueue_style( 'prime-fleet-skin' );
		wp_enqueue_style( 'prime-fleet-motion' );
		wp_enqueue_script( 'prime-fleet' );
		$captcha = \Prime_Fleet\Security\Captcha::public_config();

		wp_localize_script(
			'prime-fleet',
			'primeFleetBooking',
			array_merge(
				$this->rest_boot(),
				array(
					'currency'  => 'USD',
					'formTheme' => $this->form_theme(),
					'captcha'   => $captcha,
					'i18n'      => \Prime_Fleet\I18n\Script_Strings::booking(),
				)
			)
		);

		$theme = $this->form_theme();
		$bg    = 'light' === $theme ? '#ffffff' : '#0b1220';
		$fg    = 'light' === $theme ? '#0b1220' : '#f8fafc';

		$dir = is_rtl() ? 'rtl' : 'ltr';
		return '<div class="prime-fleet" id="prime-fleet" data-pf-booking="1" data-theme="' . esc_attr( $theme ) . '" dir="' . esc_attr( $dir ) . '" aria-live="polite" style="background:' . esc_attr( $bg ) . ';color:' . esc_attr( $fg ) . ';min-height:50vh">'
			. '<div class="prime-fleet__boot prime-fleet__boot--simple" role="status" aria-live="polite">'
			. '<span class="prime-fleet__spinner prime-fleet__spinner--boot" aria-hidden="true"></span>'
			. '<p class="prime-fleet__boot-text">' . esc_html__( 'Loading booking options…', 'prime-fleet' ) . '</p>'
			. '</div></div>';
	}

	/**
	 * Guest status lookup.
	 *
	 * @param array $atts Atts.
	 * @return string
	 */
	public function lookup( $atts = array() ) {
		wp_enqueue_style( 'prime-fleet-fonts' );
		wp_enqueue_style( 'prime-fleet' );
		wp_enqueue_style( 'prime-fleet-skin' );
		wp_enqueue_style( 'prime-fleet-motion' );
		wp_enqueue_script( 'prime-fleet-lookup' );
		wp_localize_script(
			'prime-fleet-lookup',
			'primeFleetLookup',
			array_merge(
				$this->rest_boot(),
				array(
					'i18n' => \Prime_Fleet\I18n\Script_Strings::lookup(),
				)
			)
		);

		$dir = is_rtl() ? 'rtl' : 'ltr';
		return '<div class="prime-fleet prime-fleet-lookup" id="prime-fleet-lookup" data-pf-lookup="1" dir="' . esc_attr( $dir ) . '">'
			. '<p class="prime-fleet__boot">' . esc_html__( 'Loading…', 'prime-fleet' ) . '</p>'
			. '</div>';
	}

	/**
	 * Slot offer accept UI (token from shortcode attr or query).
	 *
	 * @param array $atts Atts.
	 * @return string
	 */
	public function offer( $atts = array() ) {
		$atts  = shortcode_atts( array( 'token' => '' ), $atts, 'prime_fleet_offer' );
		$token = sanitize_text_field( (string) $atts['token'] );
		if ( ! $token && isset( $_GET['prime_fleet_offer'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$token = sanitize_text_field( wp_unslash( $_GET['prime_fleet_offer'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$lookup_url = '';
		$lookup_page = get_option( 'prime_fleet_lookup_page_url', '' );
		if ( is_string( $lookup_page ) && $lookup_page ) {
			$lookup_url = $lookup_page;
		}

		wp_enqueue_style( 'prime-fleet-fonts' );
		wp_enqueue_style( 'prime-fleet' );
		wp_enqueue_style( 'prime-fleet-skin' );
		wp_enqueue_style( 'prime-fleet-motion' );
		wp_enqueue_script( 'prime-fleet-offer' );
		wp_localize_script(
			'prime-fleet-offer',
			'primeFleetOffer',
			array_merge(
				$this->rest_boot(),
				array(
					'token'     => $token,
					'homeUrl'   => home_url( '/' ),
					'lookupUrl' => $lookup_url,
					'i18n'      => array(
						'title'           => __( 'Alternative pickup time', 'prime-fleet' ),
						'intro'           => __( 'Your requested window was full. Accept this alternative pickup if it works for you.', 'prime-fleet' ),
						'accept'          => __( 'Accept this time', 'prime-fleet' ),
						'accepting'       => __( 'Accepting offer…', 'prime-fleet' ),
						'loading'         => __( 'Loading offer…', 'prime-fleet' ),
						'fail'            => __( 'This offer is not available.', 'prime-fleet' ),
						'unavailable'     => __( 'Offer unavailable', 'prime-fleet' ),
						'reference'       => __( 'Reference', 'prime-fleet' ),
						'current'         => __( 'Current pickup', 'prime-fleet' ),
						'proposed'        => __( 'Proposed pickup', 'prime-fleet' ),
						'newPickup'       => __( 'New pickup', 'prime-fleet' ),
						'acceptedEyebrow' => __( 'Offer accepted', 'prime-fleet' ),
						'acceptedTitle'   => __( "You're all set", 'prime-fleet' ),
						'acceptedCopy'    => __( 'Thank you — your new pickup time is confirmed pending Prime Fleet final review.', 'prime-fleet' ),
						'goHome'          => __( 'Back to home', 'prime-fleet' ),
						'checkStatus'     => __( 'Check booking status', 'prime-fleet' ),
						'keepRef'         => __( 'Keep your reference handy for updates.', 'prime-fleet' ),
					),
				)
			)
		);

		return '<div class="prime-fleet prime-fleet-offer" id="prime-fleet-offer" data-pf-offer="1" aria-live="polite"></div>';
	}

	/**
	 * Lightweight public offer page when ?prime_fleet_offer=TOKEN is present.
	 */
	public function maybe_render_offer_page() {
		if ( empty( $_GET['prime_fleet_offer'] ) || is_admin() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( is_singular() && has_shortcode( (string) get_post_field( 'post_content', get_queried_object_id() ), 'prime_fleet_offer' ) ) {
			return;
		}
		status_header( 200 );
		nocache_headers();
		?><!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php esc_html_e( 'Prime Fleet — Slot offer', 'prime-fleet' ); ?></title>
			<?php wp_head(); ?>
		</head>
		<body <?php body_class( 'prime-fleet-offer-page' ); ?>>
			<main class="prime-fleet-offer-page__shell">
				<?php echo do_shortcode( '[prime_fleet_offer]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</main>
			<?php wp_footer(); ?>
		</body>
		</html>
		<?php
		exit;
	}
}
