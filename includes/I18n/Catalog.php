<?php
/**
 * Catalog / DB string resolution for guest locale.
 *
 * UI chrome → gettext. Services/zones/vehicles/addons → DB rows + optional
 * translations stored in option `prime_fleet_catalog_i18n`:
 *
 *   [ 'ur' => [ 'service' => [ '12' => [ 'name' => '…', 'description' => '…' ] ], 'zone' => [ … ] ] ]
 *
 * @package Prime_Fleet
 */

namespace Prime_Fleet\I18n;

defined( 'ABSPATH' ) || exit;

final class Catalog {

	/**
	 * Active guest locale (WP locale without charset).
	 *
	 * @return string
	 */
	public static function locale() {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$locale = str_replace( '-', '_', (string) $locale );
		/**
		 * Filter guest catalog locale (e.g. from ?lang= or Accept-Language).
		 *
		 * @param string $locale Locale.
		 */
		return (string) apply_filters( 'prime_fleet_catalog_locale', $locale );
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function map() {
		$raw = get_option( 'prime_fleet_catalog_i18n', array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Resolve a catalog field for the current locale.
	 *
	 * @param string               $entity  service|zone|vehicle|addon|package.
	 * @param int|string           $id      Entity ID.
	 * @param string               $field   Field key (name, description, …).
	 * @param string               $default Fallback (usually DB English).
	 * @param string|null          $locale  Locale override.
	 * @return string
	 */
	public static function text( $entity, $id, $field, $default, $locale = null ) {
		$default = (string) $default;
		$locale  = $locale ? (string) $locale : self::locale();
		if ( '' === $locale || 0 === strpos( strtolower( $locale ), 'en' ) ) {
			return $default;
		}

		$map    = self::map();
		$entity = sanitize_key( (string) $entity );
		$field  = sanitize_key( (string) $field );
		$id_key = (string) absint( $id );

		/* Exact locale, then language prefix (ur_PK → ur). */
		$candidates = array( $locale );
		if ( false !== strpos( $locale, '_' ) ) {
			$candidates[] = strtok( $locale, '_' );
		}

		foreach ( $candidates as $loc ) {
			if ( empty( $map[ $loc ][ $entity ][ $id_key ][ $field ] ) ) {
				continue;
			}
			$val = trim( (string) $map[ $loc ][ $entity ][ $id_key ][ $field ] );
			if ( '' !== $val ) {
				return $val;
			}
		}

		return $default;
	}
}
