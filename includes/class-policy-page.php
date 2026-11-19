<?php
/**
 * Cookie policy page resolution.
 *
 * @package RevolterCookies
 */

namespace RevolterCookies;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the page that carries the cookie declaration.
 */
class Policy_Page {

	const TRANSIENT = 'revolter_cookies_policy_page';

	/**
	 * Page id: the setting, else the detected page (cached 12 hours).
	 *
	 * @return int
	 */
	public static function id(): int {
		$set = (int) Settings::get( 'policy_page_id' );
		if ( $set > 0 && self::is_published( $set ) ) {
			return $set;
		}
		$cached = get_transient( self::TRANSIENT );
		if ( false !== $cached ) {
			if ( 0 === (int) $cached || self::is_published( (int) $cached ) ) {
				return (int) $cached;
			}
			delete_transient( self::TRANSIENT );
		}
		$id = self::detect();
		set_transient( self::TRANSIENT, $id, 12 * HOUR_IN_SECONDS );
		return $id;
	}

	/**
	 * Whether the id is a published post.
	 *
	 * @param int $id Post id.
	 * @return bool
	 */
	private static function is_published( int $id ): bool {
		return 'publish' === get_post_status( $id );
	}

	/**
	 * Lowest-ID published page containing the shortcode, block or Elementor widget.
	 *
	 * @return int 0 when none.
	 */
	public static function detect(): int {
		global $wpdb;

		$shortcode = '%' . $wpdb->esc_like( '[revolter_cookies_full_page' ) . '%';
		$block     = '%' . $wpdb->esc_like( '<!-- wp:revolter-cookies/full-page' ) . '%';
		$plain     = '%' . $wpdb->esc_like( '"widgetType":"rc-full-page"' ) . '%';
		$slashed   = '%' . $wpdb->esc_like( '\"widgetType\":\"rc-full-page\"' ) . '%';

		// Direct query: one lookup across content and Elementor meta, result cached by id().
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				WHERE p.post_type = 'page' AND p.post_status = 'publish'
				AND ( p.post_content LIKE %s OR p.post_content LIKE %s
					OR EXISTS (
						SELECT 1 FROM {$wpdb->postmeta} m
						WHERE m.post_id = p.ID AND m.meta_key = '_elementor_data'
						AND ( m.meta_value LIKE %s OR m.meta_value LIKE %s )
					) )
				ORDER BY p.ID ASC LIMIT 1",
				$shortcode,
				$block,
				$plain,
				$slashed
			)
		);
		// phpcs:enable

		return $id ? (int) $id : 0;
	}

	/**
	 * Permalink of the policy page in the current language, or ''.
	 *
	 * @return string
	 */
	public static function url(): string {
		$id = self::id();
		if ( $id <= 0 ) {
			return '';
		}
		$id = Language::translate_post_id( $id );
		if ( ! self::is_published( $id ) ) {
			return '';
		}
		$url = get_permalink( $id );
		return $url ? (string) $url : '';
	}
}
