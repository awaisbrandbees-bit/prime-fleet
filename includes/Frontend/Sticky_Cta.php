<?php
/**
 * Mobile sticky Book Now + WhatsApp CTAs (M5 Phase C).
 *
 * Plugin-owned (not Elementor): settings-driven, reserved bottom space for CLS.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Frontend;

defined( 'ABSPATH' ) || exit;

final class Sticky_Cta {

	/** Reserved bar height (matches CSS). */
	const HEIGHT_CSS = '4.25rem';

	/**
	 * Hooks.
	 */
	public function hooks() {
		add_filter( 'body_class', array( $this, 'body_class' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_footer', array( $this, 'render' ), 20 );
	}

	/**
	 * @param string[] $classes Classes.
	 * @return string[]
	 */
	public function body_class( $classes ) {
		if ( $this->should_show() ) {
			$classes[] = 'prime-fleet-has-sticky';
		}
		return $classes;
	}

	/**
	 * Minimal site-wide CSS when sticky is on (mobile only via media query).
	 */
	public function enqueue() {
		if ( ! $this->should_show() ) {
			return;
		}
		$css = \PRIME_FLEET_PATH . 'assets/frontend/css/sticky-cta.css';
		wp_enqueue_style(
			'prime-fleet-sticky-cta',
			\PRIME_FLEET_URL . 'assets/frontend/css/sticky-cta.css',
			array(),
			file_exists( $css ) ? (string) filemtime( $css ) : \PRIME_FLEET_VERSION
		);
	}

	/**
	 * Footer markup.
	 */
	public function render() {
		if ( ! $this->should_show() ) {
			return;
		}

		$cfg      = self::config();
		$book_url = $cfg['book_url'];
		$wa_url   = $cfg['wa_url'];
		if ( ! $book_url && ! $wa_url ) {
			return;
		}
		?>
		<nav class="prime-fleet-sticky" aria-label="<?php echo esc_attr__( 'Quick actions', 'prime-fleet' ); ?>" data-pf-sticky="1">
			<div class="prime-fleet-sticky__inner">
				<?php if ( $book_url ) : ?>
					<a class="prime-fleet-sticky__btn prime-fleet-sticky__btn--book" href="<?php echo esc_url( $book_url ); ?>">
						<?php echo esc_html( $cfg['book_label'] ); ?>
					</a>
				<?php endif; ?>
				<?php if ( $wa_url ) : ?>
					<a class="prime-fleet-sticky__btn prime-fleet-sticky__btn--wa" href="<?php echo esc_url( $wa_url ); ?>" target="_blank" rel="noopener noreferrer">
						<?php echo esc_html( $cfg['wa_label'] ); ?>
					</a>
				<?php endif; ?>
			</div>
		</nav>
		<?php
	}

	/**
	 * Resolved sticky config from settings.
	 *
	 * @return array{enabled:bool,book_url:string,wa_url:string,book_label:string,wa_label:string}
	 */
	public static function config() {
		$settings = get_option( 'prime_fleet_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$enabled = ! array_key_exists( 'sticky_cta_enabled', $settings ) || ! empty( $settings['sticky_cta_enabled'] );

		$book_url = isset( $settings['sticky_book_url'] ) ? trim( (string) $settings['sticky_book_url'] ) : '';
		if ( '' === $book_url ) {
			$book_url = Book_Paths::book_url();
		} else {
			$book_url = esc_url_raw( $book_url );
		}

		$wa_raw = isset( $settings['sticky_whatsapp_e164'] ) ? trim( (string) $settings['sticky_whatsapp_e164'] ) : '';
		if ( '' === $wa_raw && ! empty( $settings['whatsapp'] ) ) {
			$wa_raw = (string) $settings['whatsapp'];
		}
		$wa_url = self::whatsapp_url( $wa_raw );

		$book_label = isset( $settings['sticky_book_label'] ) ? trim( (string) $settings['sticky_book_label'] ) : '';
		if ( '' === $book_label ) {
			$book_label = __( 'Book Now', 'prime-fleet' );
		}
		$wa_label = isset( $settings['sticky_wa_label'] ) ? trim( (string) $settings['sticky_wa_label'] ) : '';
		if ( '' === $wa_label ) {
			$wa_label = __( 'WhatsApp', 'prime-fleet' );
		}

		return array(
			'enabled'     => $enabled,
			'book_url'    => $book_url,
			'wa_url'      => $wa_url,
			'book_label'  => $book_label,
			'wa_label'    => $wa_label,
		);
	}

	/**
	 * @return bool
	 */
	private function should_show() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() ) {
			return false;
		}
		// Don't cover pay / slot-offer guest surfaces.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['prime_fleet_pay'] ) || ! empty( $_GET['prime_fleet_offer'] ) ) {
			return false;
		}
		$cfg = self::config();
		if ( ! $cfg['enabled'] ) {
			return false;
		}
		if ( ! $cfg['book_url'] && ! $cfg['wa_url'] ) {
			return false;
		}
		return true;
	}

	/**
	 * @param string $raw Phone / E.164 digits.
	 * @return string
	 */
	private static function whatsapp_url( $raw ) {
		if ( '' === trim( (string) $raw ) ) {
			$raw = '16493316340'; // Staging concierge default until settings filled.
		}
		$digits = preg_replace( '/\D+/', '', (string) $raw );
		if ( ! $digits || strlen( $digits ) < 8 ) {
			return '';
		}
		$text = rawurlencode( __( 'Hello Prime Fleet — I would like to book a transfer.', 'prime-fleet' ) );
		return 'https://wa.me/' . $digits . '?text=' . $text;
	}
}
