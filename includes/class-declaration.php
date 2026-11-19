<?php
/**
 * Cookie declaration renderer.
 *
 * @package RevolterCookies
 */

namespace RevolterCookies;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the declaration table and the full cookie policy page.
 *
 * Output is identical for every visitor, so full-page caches stay correct.
 */
class Declaration {

	const SECTIONS = array( 'what', 'how', 'manage', 'legal', 'rights', 'consent' );

	/**
	 * Groups for the declaration table.
	 *
	 * @param string       $lang    sv or en.
	 * @param Library|null $library Library, default the bundled file.
	 * @return array[] Each: key, label, description, count, rows (name, provider, purpose, duration).
	 */
	public static function groups( string $lang, ?Library $library = null ): array {
		$library = $library ? $library : Library::from_file();
		$lang    = in_array( $lang, Language::SUPPORTED, true ) ? $lang : Language::FALLBACK;
		$out     = array();
		foreach ( ( new Item_Repository() )->declared_grouped() as $category => $items ) {
			$rows = array();
			foreach ( $items as $item ) {
				$rows[] = array(
					'name'     => (string) $item['name'],
					'provider' => isset( $item['provider'] ) ? (string) $item['provider'] : '',
					'purpose'  => self::purpose( $item, $category, $lang, $library ),
					'duration' => Duration::label( Duration::decode( isset( $item['duration'] ) ? $item['duration'] : null ) ),
				);
			}
			$out[] = array(
				'key'         => $category,
				'label'       => Texts::category_label( $category ),
				'description' => Texts::category_description( $category ),
				'count'       => count( $rows ),
				'rows'        => $rows,
			);
		}
		return $out;
	}

	/**
	 * Purpose: override in language, override in English, library, generic.
	 *
	 * @param array   $item     Item row.
	 * @param string  $category Category key.
	 * @param string  $lang     Language.
	 * @param Library $library  Library.
	 * @return string
	 */
	private static function purpose( array $item, string $category, string $lang, Library $library ): string {
		$override = isset( $item['purpose_override'] ) && is_array( $item['purpose_override'] ) ? $item['purpose_override'] : array();
		foreach ( array( $lang, 'en' ) as $code ) {
			if ( ! empty( $override[ $code ] ) && is_string( $override[ $code ] ) ) {
				return $override[ $code ];
			}
		}
		$cookie = null;
		if ( ! empty( $item['service_id'] ) ) {
			$service = $library->service( (string) $item['service_id'] );
			if ( $service ) {
				foreach ( $service['cookies'] as $candidate ) {
					if ( isset( $candidate['name'] ) && $candidate['name'] === $item['name'] ) {
						$cookie = $candidate;
						break;
					}
				}
			}
		}
		if ( null === $cookie ) {
			$hit    = $library->match_cookie( (string) $item['name'], (string) $item['type'] );
			$cookie = $hit ? $hit['cookie'] : null;
		}
		if ( $cookie ) {
			$purpose = $library->purpose( $cookie, $lang );
			if ( '' !== $purpose ) {
				return $purpose;
			}
		}
		return Texts::generic_purpose( $category );
	}

	/**
	 * Full cookie policy page.
	 *
	 * @param array $args sections: map of section key => bool (default all true).
	 * @return string HTML.
	 */
	public static function render_full_page( array $args = array() ): string {
		$show = array();
		foreach ( self::SECTIONS as $key ) {
			$show[ $key ] = ! isset( $args['sections'][ $key ] ) || (bool) $args['sections'][ $key ];
		}
		$lang   = Language::current();
		$groups = self::groups( $lang );
		$count  = 0;
		foreach ( $groups as $group ) {
			$count += $group['count'];
		}
		$sections = Texts::sections(
			array(
				'company'    => (string) Settings::get( 'company_name' ),
				'email'      => (string) Settings::get( 'contact_email' ),
				'org_number' => (string) Settings::get( 'org_number' ),
				'count'      => $count,
			)
		);

		$rc_data = array(
			'show'        => $show,
			'sections'    => $sections,
			'bodies'      => array(),
			'table_html'  => self::table_html( $groups, false ),
			'consent'     => Texts::consent_box(),
			'declaration' => Texts::declaration(),
			'style'       => Banner::accent_vars(),
		);
		foreach ( $sections as $key => $section ) {
			$rc_data['bodies'][ $key ] = self::body_html( $key, $section['body'] );
		}

		return self::capture( 'full-page.php', $rc_data );
	}

	/**
	 * Declaration table only.
	 *
	 * @param array $args settings_link: bool.
	 * @return string HTML.
	 */
	public static function render_table( array $args = array() ): string {
		return self::capture(
			'cookies-table.php',
			array(
				'table_html' => self::table_html( self::groups( Language::current() ), ! empty( $args['settings_link'] ) ),
				'style'      => Banner::accent_vars(),
			)
		);
	}

	/**
	 * Escaped section body. The rights body gets a mailto link; nothing else is allowed through.
	 *
	 * @param string $key  Section key.
	 * @param string $body Plain text body.
	 * @return string
	 */
	private static function body_html( string $key, string $body ): string {
		$html  = esc_html( $body );
		$email = trim( (string) Settings::get( 'contact_email' ) );
		if ( 'rights' === $key && is_email( $email ) ) {
			$link = sprintf( '<a href="%s">%s</a>', esc_url( 'mailto:' . $email, array( 'mailto' ) ), esc_html( $email ) );
			$pos  = strpos( $html, esc_html( $email ) );
			if ( false !== $pos ) {
				$html = substr_replace( $html, $link, $pos, strlen( esc_html( $email ) ) );
			}
		}
		return wp_kses(
			$html,
			array(
				'a'      => array( 'href' => true ),
				'strong' => array(),
				'code'   => array(),
			)
		);
	}

	/**
	 * Declaration markup: heading, last updated and one table per group.
	 *
	 * @param array $groups        Groups.
	 * @param bool  $settings_link Whether to add the settings link.
	 * @return string Escaped HTML.
	 */
	private static function table_html( array $groups, bool $settings_link ): string {
		$rc_data = array(
			'groups'        => $groups,
			'texts'         => Texts::declaration(),
			'updated'       => self::last_updated(),
			'settings_link' => $settings_link ? Texts::consent_box()['table_link'] : '',
		);
		return self::capture( 'cookies-table-inner.php', $rc_data, false );
	}

	/**
	 * "Last updated" label, or '' when never scanned.
	 *
	 * @return string
	 */
	private static function last_updated(): string {
		$scan = Settings::get( 'last_scan' );
		$time = is_array( $scan ) && ! empty( $scan['time'] ) ? strtotime( (string) $scan['time'] ) : false;
		if ( ! $time ) {
			return '';
		}
		return sprintf( Texts::declaration()['last_updated'], date_i18n( get_option( 'date_format' ), $time ) );
	}

	/**
	 * Render a template with data, enqueueing the stylesheet.
	 *
	 * @param string $file    Template file under templates/.
	 * @param array  $rc_data Data.
	 * @param bool   $enqueue Whether to enqueue the stylesheet.
	 * @return string
	 */
	private static function capture( string $file, array $rc_data, bool $enqueue = true ): string {
		if ( $enqueue ) {
			wp_enqueue_style( 'rc-declaration', REVOLTER_COOKIES_URL . 'assets/css/declaration.css', array(), REVOLTER_COOKIES_VERSION );
		}
		ob_start();
		include REVOLTER_COOKIES_PATH . 'templates/' . $file;
		return (string) ob_get_clean();
	}
}
