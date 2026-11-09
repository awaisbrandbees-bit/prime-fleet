<?php
/**
 * Premium marketing homepage shortcode [prime_fleet_home] (M5 Phase B).
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Frontend;

defined( 'ABSPATH' ) || exit;

final class Home_Landing {

	/**
	 * Hooks.
	 */
	public function hooks() {
		add_shortcode( 'prime_fleet_home', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Register CSS/JS; enqueue early when this page uses the shortcode.
	 */
	public function register_assets() {
		wp_register_style(
			'prime-fleet-fonts',
			'https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,500;0,600;0,700;1,500&family=Outfit:wght@400;500;600;700&display=swap',
			array(),
			null
		);
		$css = \PRIME_FLEET_PATH . 'assets/frontend/css/home.css';
		$js  = \PRIME_FLEET_PATH . 'assets/frontend/js/home.js';
		wp_register_style(
			'prime-fleet-home',
			\PRIME_FLEET_URL . 'assets/frontend/css/home.css',
			array( 'prime-fleet-fonts' ),
			file_exists( $css ) ? (string) filemtime( $css ) : \PRIME_FLEET_VERSION
		);
		wp_register_script(
			'prime-fleet-home',
			\PRIME_FLEET_URL . 'assets/frontend/js/home.js',
			array(),
			file_exists( $js ) ? (string) filemtime( $js ) : \PRIME_FLEET_VERSION,
			true
		);

		if ( $this->should_enqueue() ) {
			wp_enqueue_style( 'prime-fleet-home' );
			wp_enqueue_script( 'prime-fleet-home' );
		}
	}

	/**
	 * @return bool
	 */
	private function should_enqueue() {
		if ( is_admin() ) {
			return false;
		}
		if ( is_front_page() || is_home() ) {
			$post = get_queried_object();
			if ( $post instanceof \WP_Post && has_shortcode( (string) $post->post_content, 'prime_fleet_home' ) ) {
				return true;
			}
		}
		if ( is_singular() ) {
			$post = get_post();
			if ( $post && has_shortcode( (string) $post->post_content, 'prime_fleet_home' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function render( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'whatsapp' => '',
			),
			is_array( $atts ) ? $atts : array(),
			'prime_fleet_home'
		);

		wp_enqueue_style( 'prime-fleet-home' );
		wp_enqueue_script( 'prime-fleet-home' );

		$book_url = Book_Paths::book_url();
		$lookup   = Book_Paths::lookup_url();
		$wa       = self::whatsapp_url( (string) $atts['whatsapp'] );
		$hero     = self::img_url( 'hero-coast.jpg' );
		$vehicle  = self::img_url( 'hero-vehicle.jpg' );
		$escalade = self::img_url( 'fleet-escalade.jpg' );
		$sprinter = self::img_url( 'fleet-sprinter.jpg' );
		$trust    = self::img_url( 'trust-texture.jpg' );

		ob_start();
		?>
		<div class="prime-fleet-home" data-pf-home="1">
			<section class="prime-fleet-home__hero" aria-label="<?php echo esc_attr__( 'Prime Fleet', 'prime-fleet' ); ?>">
				<div class="prime-fleet-home__hero-visual" aria-hidden="true">
					<img
						class="prime-fleet-home__hero-bg"
						src="<?php echo esc_url( $hero ); ?>"
						alt=""
						width="2400"
						height="1350"
						decoding="async"
						fetchpriority="high"
					/>
					<div class="prime-fleet-home__hero-veil"></div>
					<div class="prime-fleet-home__hero-grain"></div>
					<img
						class="prime-fleet-home__hero-car"
						src="<?php echo esc_url( $vehicle ); ?>"
						alt=""
						width="1800"
						height="1200"
						decoding="async"
					/>
				</div>
				<div class="prime-fleet-home__hero-inner">
					<p class="prime-fleet-home__brand">Prime Fleet</p>
					<h1 class="prime-fleet-home__headline"><?php echo esc_html__( 'Arrive relaxed. Travel in style.', 'prime-fleet' ); ?></h1>
					<p class="prime-fleet-home__lede"><?php echo esc_html__( 'Private chauffeur transfers — airport to villa, resort, or marina.', 'prime-fleet' ); ?></p>
					<div class="prime-fleet-home__cta">
						<a class="prime-fleet-home__btn prime-fleet-home__btn--primary" href="<?php echo esc_url( $book_url ); ?>"><?php echo esc_html__( 'Book Now', 'prime-fleet' ); ?></a>
						<?php if ( $wa ) : ?>
							<a class="prime-fleet-home__btn prime-fleet-home__btn--ghost" href="<?php echo esc_url( $wa ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'WhatsApp', 'prime-fleet' ); ?></a>
						<?php endif; ?>
					</div>
				</div>
			</section>

			<section class="prime-fleet-home__section prime-fleet-home__how" data-pf-reveal>
				<div class="prime-fleet-home__wrap prime-fleet-home__how-intro">
					<p class="prime-fleet-home__eyebrow"><?php echo esc_html__( 'How it works', 'prime-fleet' ); ?></p>
					<h2 class="prime-fleet-home__title"><?php echo esc_html__( 'Three steps to your chauffeur', 'prime-fleet' ); ?></h2>
					<p class="prime-fleet-home__sub"><?php echo esc_html__( 'Request online. Prime Fleet confirms. Your driver meets you on time.', 'prime-fleet' ); ?></p>
				</div>
				<div class="prime-fleet-home__wrap">
					<ol class="prime-fleet-home__steps">
						<li class="prime-fleet-home__step">
							<span class="prime-fleet-home__step-num" aria-hidden="true">01</span>
							<span class="prime-fleet-home__step-label"><?php echo esc_html__( 'Request', 'prime-fleet' ); ?></span>
							<span class="prime-fleet-home__step-copy"><?php echo esc_html__( 'Choose service, route, and schedule in the booking form.', 'prime-fleet' ); ?></span>
						</li>
						<li class="prime-fleet-home__step">
							<span class="prime-fleet-home__step-num" aria-hidden="true">02</span>
							<span class="prime-fleet-home__step-label"><?php echo esc_html__( 'Confirm', 'prime-fleet' ); ?></span>
							<span class="prime-fleet-home__step-copy"><?php echo esc_html__( 'Our team confirms your transfer and sends payment details.', 'prime-fleet' ); ?></span>
						</li>
						<li class="prime-fleet-home__step">
							<span class="prime-fleet-home__step-num" aria-hidden="true">03</span>
							<span class="prime-fleet-home__step-label"><?php echo esc_html__( 'Ride', 'prime-fleet' ); ?></span>
							<span class="prime-fleet-home__step-copy"><?php echo esc_html__( 'Your chauffeur arrives — discreet, punctual, and ready.', 'prime-fleet' ); ?></span>
						</li>
					</ol>
				</div>
			</section>

			<section class="prime-fleet-home__section prime-fleet-home__fleet" data-pf-reveal>
				<div class="prime-fleet-home__wrap prime-fleet-home__fleet-intro">
					<p class="prime-fleet-home__eyebrow"><?php echo esc_html__( 'Fleet', 'prime-fleet' ); ?></p>
					<h2 class="prime-fleet-home__title"><?php echo esc_html__( 'Luxury vehicles, cleaned daily', 'prime-fleet' ); ?></h2>
					<p class="prime-fleet-home__sub"><?php echo esc_html__( 'Select the Escalade or Sprinter when you book — published fares, no guesswork.', 'prime-fleet' ); ?></p>
				</div>
				<div class="prime-fleet-home__fleet-strip">
					<article class="prime-fleet-home__fleet-item">
						<figure class="prime-fleet-home__fleet-art">
							<img src="<?php echo esc_url( $escalade ); ?>" alt="" width="1600" height="900" loading="lazy" decoding="async" />
						</figure>
						<div class="prime-fleet-home__fleet-copy">
							<p class="prime-fleet-home__fleet-meta"><?php echo esc_html__( 'Up to 6 guests', 'prime-fleet' ); ?></p>
							<h3><?php echo esc_html__( 'Cadillac Escalade ESV', 'prime-fleet' ); ?></h3>
							<p><?php echo esc_html__( 'Executive comfort for airport runs and villa transfers — quiet cabin, room for luggage.', 'prime-fleet' ); ?></p>
						</div>
					</article>
					<article class="prime-fleet-home__fleet-item prime-fleet-home__fleet-item--flip">
						<figure class="prime-fleet-home__fleet-art">
							<img src="<?php echo esc_url( $sprinter ); ?>" alt="" width="1600" height="900" loading="lazy" decoding="async" />
						</figure>
						<div class="prime-fleet-home__fleet-copy">
							<p class="prime-fleet-home__fleet-meta"><?php echo esc_html__( 'Up to 13 guests', 'prime-fleet' ); ?></p>
							<h3><?php echo esc_html__( 'Mercedes-Benz Sprinter', 'prime-fleet' ); ?></h3>
							<p><?php echo esc_html__( 'Spacious for families and groups — bookable online with published group fares.', 'prime-fleet' ); ?></p>
						</div>
					</article>
				</div>
			</section>

			<section class="prime-fleet-home__section prime-fleet-home__trust" data-pf-reveal>
				<div class="prime-fleet-home__trust-grid">
					<figure class="prime-fleet-home__trust-photo" aria-hidden="true">
						<img src="<?php echo esc_url( $trust ); ?>" alt="" width="1600" height="900" loading="lazy" decoding="async" />
					</figure>
					<div class="prime-fleet-home__trust-copy">
						<p class="prime-fleet-home__eyebrow"><?php echo esc_html__( 'Guest voices', 'prime-fleet' ); ?></p>
						<h2 class="prime-fleet-home__title"><?php echo esc_html__( 'Trusted island hospitality', 'prime-fleet' ); ?></h2>
						<blockquote class="prime-fleet-home__quote">
							<p><?php echo esc_html__( 'Fantastic service. The driver was punctual, polite, and the Escalade was spotless.', 'prime-fleet' ); ?></p>
							<footer>— Sarah M.</footer>
						</blockquote>
						<ul class="prime-fleet-home__trust-points">
							<li><?php echo esc_html__( 'Licensed, discreet chauffeurs', 'prime-fleet' ); ?></li>
							<li><?php echo esc_html__( '24/7 availability', 'prime-fleet' ); ?></li>
							<li><?php echo esc_html__( 'On-time airport & villa transfers', 'prime-fleet' ); ?></li>
						</ul>
					</div>
				</div>
			</section>

			<section class="prime-fleet-home__finale" data-pf-reveal>
				<div class="prime-fleet-home__finale-bg" aria-hidden="true">
					<img src="<?php echo esc_url( $hero ); ?>" alt="" width="2400" height="1350" loading="lazy" decoding="async" />
				</div>
				<div class="prime-fleet-home__wrap prime-fleet-home__finale-inner">
					<p class="prime-fleet-home__brand prime-fleet-home__brand--light">Prime Fleet</p>
					<h2 class="prime-fleet-home__finale-title"><?php echo esc_html__( 'Ready for your transfer?', 'prime-fleet' ); ?></h2>
					<p class="prime-fleet-home__finale-copy"><?php echo esc_html__( 'Book online in minutes. Concierge confirmation follows.', 'prime-fleet' ); ?></p>
					<div class="prime-fleet-home__cta">
						<a class="prime-fleet-home__btn prime-fleet-home__btn--primary" href="<?php echo esc_url( $book_url ); ?>"><?php echo esc_html__( 'Book Now', 'prime-fleet' ); ?></a>
						<?php if ( $lookup ) : ?>
							<a class="prime-fleet-home__btn prime-fleet-home__btn--ghost" href="<?php echo esc_url( $lookup ); ?>"><?php echo esc_html__( 'Check booking status', 'prime-fleet' ); ?></a>
						<?php endif; ?>
					</div>
				</div>
			</section>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @param string $file Filename under assets/frontend/img/.
	 * @return string
	 */
	private static function img_url( $file ) {
		return \PRIME_FLEET_URL . 'assets/frontend/img/' . ltrim( $file, '/' );
	}

	/**
	 * @param string $override Optional number from shortcode.
	 * @return string WhatsApp deep link or empty.
	 */
	private static function whatsapp_url( $override = '' ) {
		$settings = get_option( 'prime_fleet_settings', array() );
		$raw      = $override ? $override : ( isset( $settings['whatsapp'] ) ? (string) $settings['whatsapp'] : '' );
		if ( '' === trim( $raw ) ) {
			$raw = '16493316340'; // Staging concierge default until settings filled.
		}
		$digits = preg_replace( '/\D+/', '', $raw );
		if ( ! $digits || strlen( $digits ) < 8 ) {
			return '';
		}
		$text = rawurlencode( __( 'Hello Prime Fleet — I would like to book a transfer.', 'prime-fleet' ) );
		return 'https://wa.me/' . $digits . '?text=' . $text;
	}
}
