<?php
/**
 * Visitor language detection.
 *
 * @package RevolterCookies
 */

namespace RevolterCookies;

defined( 'ABSPATH' ) || exit;

/**
 * Works out the language to show, across WPML, Polylang, TranslatePress and plain WordPress.
 */
class Language {

	const SUPPORTED = array( 'en', 'sv' );
	const FALLBACK  = 'en';

	/**
	 * Which system decides the language.
	 *
	 * @return string One of wpml, polylang, translatepress or the plain-core source.
	 */
	public static function source(): string {
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return 'wpml';
		}
		if ( function_exists( 'pll_current_language' ) ) {
			return 'polylang';
		}
		if ( class_exists( 'TRP_Translate_Press' ) ) {
			return 'translatepress';
		}
		return 'wordpress'; // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Identifier, not prose.
	}

	/**
	 * Raw two-letter code for the current request.
	 *
	 * @return string
	 */
	public static function current_code(): string {
		$code = '';
		switch ( self::source() ) {
			case 'wpml':
				$code = apply_filters( 'wpml_current_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public API hook.
				break;
			case 'polylang':
				$code = pll_current_language( 'slug' );
				break;
			case 'translatepress':
				// TranslatePress publishes its current language in a global of that name.
				$code = isset( $GLOBALS['TRP_LANGUAGE'] ) ? $GLOBALS['TRP_LANGUAGE'] : '';
				break;
		}
		if ( ! is_string( $code ) || '' === $code ) {
			$code = determine_locale();
		}
		return strtolower( substr( (string) $code, 0, 2 ) );
	}

	/**
	 * Supported visitor language for the current request, else the fallback.
	 *
	 * @return string
	 */
	public static function current(): string {
		$code = self::current_code();
		return in_array( $code, self::SUPPORTED, true ) ? $code : self::FALLBACK;
	}

	/**
	 * Languages the site offers, code => label.
	 *
	 * @return array
	 */
	public static function site_languages(): array {
		$out = array();
		switch ( self::source() ) {
			case 'wpml':
				$active = apply_filters( 'wpml_active_languages', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public API hook.
				if ( is_array( $active ) ) {
					foreach ( $active as $code => $info ) {
						$label = is_array( $info ) && isset( $info['native_name'] ) ? $info['native_name'] : $code;
						$out[ strtolower( substr( (string) $code, 0, 2 ) ) ] = (string) $label;
					}
				}
				break;
			case 'polylang':
				if ( function_exists( 'pll_languages_list' ) ) {
					$slugs = (array) pll_languages_list( array( 'fields' => 'slug' ) );
					$names = (array) pll_languages_list( array( 'fields' => 'name' ) );
					$pairs = ( count( $slugs ) === count( $names ) ) ? array_combine( array_values( $slugs ), array_values( $names ) ) : array_combine( array_values( $slugs ), array_values( $slugs ) );
					foreach ( (array) $pairs as $slug => $name ) {
						$out[ (string) $slug ] = (string) $name;
					}
				}
				break;
			case 'translatepress':
				$settings = get_option( 'trp_settings', array() );
				$locales  = is_array( $settings ) && ! empty( $settings['translation-languages'] ) ? (array) $settings['translation-languages'] : array();
				foreach ( $locales as $locale ) {
					$out[ strtolower( substr( (string) $locale, 0, 2 ) ) ] = (string) $locale;
				}
				break;
		}
		if ( ! $out ) {
			$locale = determine_locale();
			$key    = strtolower( substr( $locale, 0, 2 ) );

			$out[ $key ] = $locale;
		}
		return $out;
	}

	/**
	 * Translate a post id to the current language, or return it unchanged.
	 *
	 * @param int $id Post id.
	 * @return int
	 */
	public static function translate_post_id( int $id ): int {
		$source = self::source();
		if ( 'wpml' === $source ) {
			$translated = apply_filters( 'wpml_object_id', $id, 'page', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public API hook.
			return $translated ? (int) $translated : $id;
		}
		if ( 'polylang' === $source && function_exists( 'pll_get_post' ) ) {
			$translated = pll_get_post( $id );
			return $translated ? (int) $translated : $id;
		}
		return $id;
	}
}
