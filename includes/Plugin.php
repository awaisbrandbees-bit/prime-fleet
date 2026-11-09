<?php
/**
 * Main plugin bootstrap.
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet;

use Prime_Fleet\Admin\Menu;
use Prime_Fleet\Admin\Assets;
use Prime_Fleet\Admin\Ajax;
use Prime_Fleet\Admin\Notices_Guard;
use Prime_Fleet\Frontend\Shortcodes;
use Prime_Fleet\Frontend\Pay_Page;
use Prime_Fleet\Frontend\Book_Paths;
use Prime_Fleet\Frontend\Home_Landing;
use Prime_Fleet\Frontend\Sticky_Cta;
use Prime_Fleet\Messaging\Smtp;
use Prime_Fleet\Messaging\Job_Runner;
use Prime_Fleet\Messaging\Templates;
use Prime_Fleet\Payments\Registry as Payment_Registry;
use Prime_Fleet\Payments\Methods\Instructions_Method;
use Prime_Fleet\Payments\Methods\Custom_Link_Method;
use Prime_Fleet\Payments\Methods\Stripe_Method;
use Prime_Fleet\Payments\Methods\Paypal_Method;
use Prime_Fleet\Payments\Methods\Authorize_Net_Method;
use Prime_Fleet\REST\Controller as Rest_Controller;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** @var self|null */
	private static $instance = null;

	/** @var Payment_Registry|null */
	private $payments = null;

	/**
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Boot hooks.
	 */
	public function boot() {
		load_plugin_textdomain( 'prime-fleet', false, dirname( PRIME_FLEET_BASENAME ) . '/languages' );

		$this->init_payments();

		( new Rest_Controller() )->hooks();
		( new Shortcodes() )->hooks();
		( new Pay_Page() )->hooks();
		( new Book_Paths() )->hooks();
		( new Home_Landing() )->hooks();
		( new Sticky_Cta() )->hooks();
		( new Smtp() )->hooks();
		( new Job_Runner() )->hooks();

		if ( is_admin() ) {
			( new Menu() )->hooks();
			( new Assets() )->hooks();
			( new Ajax() )->hooks();
			( new Notices_Guard() )->hooks();
			\Prime_Fleet\Admin\Pages\Bookings::hooks();
		}

		add_action( 'init', array( $this, 'maybe_upgrade' ) );
	}

	/**
	 * Register payment providers.
	 */
	private function init_payments() {
		if ( $this->payments instanceof Payment_Registry ) {
			return;
		}
		$this->payments = new Payment_Registry();
		$this->payments->register( new Instructions_Method() );
		$this->payments->register( new Custom_Link_Method() );
		$this->payments->register( new Stripe_Method() );
		$this->payments->register( new Paypal_Method() );
		$this->payments->register( new Authorize_Net_Method() );

		/**
		 * Register additional payment methods (WooCommerce-style extensions).
		 *
		 * @param \Prime_Fleet\Payments\Registry $registry Registry.
		 */
		do_action( 'prime_fleet_register_payment_methods', $this->payments );
	}

	/**
	 * @return Payment_Registry
	 */
	public function payments() {
		$this->init_payments();
		return $this->payments;
	}

	/**
	 * Run migrations if version changed.
	 */
	public function maybe_upgrade() {
		$installed = get_option( 'prime_fleet_db_version', '' );
		if ( $installed !== PRIME_FLEET_VERSION ) {
			Activator::migrate();
			\Prime_Fleet\Install\Seeder::run();
			update_option( 'prime_fleet_db_version', PRIME_FLEET_VERSION );
			if ( class_exists( '\\Prime_Fleet\\Services\\Config_Compiler' ) ) {
				Services\Config_Compiler::invalidate();
			}
		}
		Templates::ensure();
		if ( class_exists( '\\Prime_Fleet\\Frontend\\Book_Paths' ) ) {
			\Prime_Fleet\Frontend\Book_Paths::ensure_pages();
		}
	}
}
