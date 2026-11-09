<?php
/**
 * Canonical book URL + legacy path redirects (M5 Phase A).
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\Frontend;

defined( 'ABSPATH' ) || exit;

final class Book_Paths {

	const OPTION_BOOK_PAGE = 'prime_fleet_book_page_id';
	const OPTION_LOOKUP_PAGE = 'prime_fleet_lookup_page_id';

	/**
	 * Legacy path segments (no leading/trailing slash) → canonical book page.
	 *
	 * @return string[]
	 */
	public static function legacy_book_slugs() {
		return array( 'booking', 'book', 'booknow', 'request-a-quote', 'request-quote' );
	}

	/**
	 * Hooks.
	 */
	public function hooks() {
		add_action( 'template_redirect', array( $this, 'maybe_redirect_legacy' ), 1 );
		add_action( 'init', array( $this, 'maybe_ensure_pages' ), 20 );
	}

	/**
	 * Public book URL (trailing slash).
	 *
	 * @return string
	 */
	public static function book_url() {
		$id = (int) get_option( self::OPTION_BOOK_PAGE, 0 );
		if ( $id ) {
			$url = get_permalink( $id );
			if ( $url ) {
				return trailingslashit( $url );
			}
		}
		$page = get_page_by_path( 'book-now' );
		if ( $page ) {
			return trailingslashit( get_permalink( $page ) );
		}
		return trailingslashit( home_url( '/book-now/' ) );
	}

	/**
	 * Lookup URL if page exists.
	 *
	 * @return string
	 */
	public static function lookup_url() {
		$id = (int) get_option( self::OPTION_LOOKUP_PAGE, 0 );
		if ( $id ) {
			$url = get_permalink( $id );
			if ( $url ) {
				return trailingslashit( $url );
			}
		}
		$page = get_page_by_path( 'booking-status' );
		if ( $page ) {
			return trailingslashit( get_permalink( $page ) );
		}
		return '';
	}

	/**
	 * 301 legacy book paths to canonical /book-now/.
	 */
	public function maybe_redirect_legacy() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( empty( $_SERVER['REQUEST_URI'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return;
		}

		$path = (string) wp_parse_url( home_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = trim( (string) $path, '/' );
		if ( '' === $path ) {
			return;
		}

		// Only first segment (ignore nested).
		$parts = explode( '/', $path );
		$slug  = strtolower( (string) $parts[0] );

		if ( 'book-now' === $slug ) {
			return;
		}

		if ( ! in_array( $slug, self::legacy_book_slugs(), true ) ) {
			return;
		}

		// If this slug is already the canonical page (misconfigured), do not loop.
		$target = self::book_url();
		$target_path = trim( (string) wp_parse_url( $target, PHP_URL_PATH ), '/' );
		if ( $target_path === $path || 0 === strpos( $target_path, $slug . '/' ) || $target_path === $slug ) {
			return;
		}

		wp_safe_redirect( $target, 301 );
		exit;
	}

	/**
	 * Ensure Book Now (+ optional lookup) pages exist once.
	 */
	public function maybe_ensure_pages() {
		if ( get_option( 'prime_fleet_book_paths_ensured' ) === PRIME_FLEET_VERSION ) {
			return;
		}
		self::ensure_pages();
		update_option( 'prime_fleet_book_paths_ensured', PRIME_FLEET_VERSION, false );
	}

	/**
	 * Create/rename canonical pages. Safe to call repeatedly.
	 *
	 * @return array{book_page_id:int,lookup_page_id:int}
	 */
	public static function ensure_pages() {
		$book_id = self::ensure_shortcode_page(
			'book-now',
			__( 'Book Now', 'prime-fleet' ),
			'[prime_fleet_booking]',
			array( 'booking', 'book' )
		);
		if ( $book_id ) {
			update_option( self::OPTION_BOOK_PAGE, $book_id, false );
		}

		$lookup_id = self::ensure_shortcode_page(
			'booking-status',
			__( 'Booking status', 'prime-fleet' ),
			'[prime_fleet_lookup]',
			array( 'lookup', 'status' )
		);
		if ( $lookup_id ) {
			update_option( self::OPTION_LOOKUP_PAGE, $lookup_id, false );
		}

		flush_rewrite_rules( false );

		return array(
			'book_page_id'   => (int) $book_id,
			'lookup_page_id' => (int) $lookup_id,
		);
	}

	/**
	 * Find or create a page with shortcode-only content.
	 *
	 * @param string   $slug Preferred slug.
	 * @param string   $title Title.
	 * @param string   $shortcode Shortcode markup.
	 * @param string[] $alt_slugs Existing pages to adopt/rename.
	 * @return int Page ID.
	 */
	private static function ensure_shortcode_page( $slug, $title, $shortcode, array $alt_slugs = array() ) {
		$page = get_page_by_path( $slug );
		if ( ! $page ) {
			foreach ( $alt_slugs as $alt ) {
				$candidate = get_page_by_path( $alt );
				if ( $candidate && 'publish' === $candidate->post_status ) {
					$page = $candidate;
					break;
				}
			}
		}

		$content = "<!-- wp:shortcode -->\n{$shortcode}\n<!-- /wp:shortcode -->";

		if ( $page ) {
			$update = array(
				'ID'           => (int) $page->ID,
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_status'  => 'publish',
				'post_content' => $content,
			);
			// Keep Elementor canvas template if already set; otherwise default.
			wp_update_post( $update );
			return (int) $page->ID;
		}

		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_status'  => 'publish',
				'post_content' => $content,
			),
			true
		);

		return is_wp_error( $id ) ? 0 : (int) $id;
	}
}
