<?php
/**
 * Visitor-facing copy.
 *
 * @package RevolterCookies
 */

namespace RevolterCookies;

defined( 'ABSPATH' ) || exit;

/**
 * Every visitor string, in one place. English strings are the gettext source; Swedish lives in the .po file.
 */
class Texts {

	/**
	 * Banner and preferences strings.
	 *
	 * @return array<string, string>
	 */
	public static function banner(): array {
		return array(
			'title'           => __( 'We use cookies', 'revolter-cookies' ),
			'text'            => __( 'We use necessary cookies to make this website work. With your consent, we also use cookies for statistics and marketing. You can change or withdraw your consent at any time.', 'revolter-cookies' ),
			'policy_link'     => __( 'Read our cookie policy', 'revolter-cookies' ),
			'accept_all'      => __( 'Accept all', 'revolter-cookies' ),
			'reject_all'      => __( 'Reject all', 'revolter-cookies' ),
			'customize'       => __( 'Customise', 'revolter-cookies' ),
			'save'            => __( 'Save choices', 'revolter-cookies' ),
			'close'           => __( 'Close', 'revolter-cookies' ),
			'settings_button' => __( 'Cookie settings', 'revolter-cookies' ),
			'credit'          => __( 'Made with care by', 'revolter-cookies' ),
			'always_on'       => __( 'Always on', 'revolter-cookies' ),
			'modal_title'     => __( 'Cookie settings', 'revolter-cookies' ),
			'modal_intro'     => __( 'Choose which types of cookies you accept. Necessary cookies cannot be switched off because the website does not work without them.', 'revolter-cookies' ),
		);
	}

	/**
	 * Category name.
	 *
	 * @param string $cat Category key.
	 * @return string
	 */
	public static function category_label( string $cat ): string {
		switch ( $cat ) {
			case 'necessary':
				return __( 'Necessary', 'revolter-cookies' );
			case 'preferences':
				return __( 'Preferences', 'revolter-cookies' );
			case 'statistics':
				return __( 'Statistics', 'revolter-cookies' );
			case 'marketing':
				return __( 'Marketing', 'revolter-cookies' );
			default:
				return __( 'Unclassified', 'revolter-cookies' );
		}
	}

	/**
	 * Category description for the preferences modal.
	 *
	 * @param string $cat Category key.
	 * @return string
	 */
	public static function category_description( string $cat ): string {
		switch ( $cat ) {
			case 'necessary':
				return __( 'Required for basic functions such as security and remembering your cookie choice.', 'revolter-cookies' );
			case 'preferences':
				return __( 'Remember your choices, such as language.', 'revolter-cookies' );
			case 'statistics':
				return __( 'Help us understand how the website is used, through anonymised statistics.', 'revolter-cookies' );
			case 'marketing':
				return __( 'Used to show relevant ads and embedded content such as YouTube.', 'revolter-cookies' );
			default:
				return __( 'Cookies that have not yet been classified.', 'revolter-cookies' );
		}
	}

	/**
	 * "N cookies".
	 *
	 * @param int $n Count.
	 * @return string
	 */
	public static function category_count( int $n ): string {
		/* translators: %d: number of cookies. */
		return sprintf( _n( '%d cookie', '%d cookies', $n, 'revolter-cookies' ), $n );
	}

	/**
	 * Category as used inside a sentence ("cookies for marketing").
	 *
	 * @param string $cat Category key.
	 * @return string
	 */
	private static function category_in_sentence( string $cat ): string {
		switch ( $cat ) {
			case 'preferences':
				return _x( 'preferences', 'category in a sentence', 'revolter-cookies' );
			case 'statistics':
				return _x( 'statistics', 'category in a sentence', 'revolter-cookies' );
			default:
				// Blocked content is never necessary; anything else is treated as marketing.
				return _x( 'marketing', 'category in a sentence', 'revolter-cookies' );
		}
	}

	/**
	 * Blocked-embed placeholder copy.
	 *
	 * @param string $label    Service name, e.g. YouTube.
	 * @param string $category Category key.
	 * @return array{text:string, accept:string, open:string}
	 */
	public static function placeholder( string $label, string $category ): array {
		$cat = self::category_in_sentence( $category );
		return array(
			/* translators: 1: service name, 2: category in a sentence, e.g. marketing. */
			'text'   => sprintf( __( 'This content comes from %1$s, which uses cookies for %2$s. Accept %2$s cookies to show it.', 'revolter-cookies' ), $label, $cat ),
			'accept' => __( 'Accept and show', 'revolter-cookies' ),
			/* translators: %s: service name. */
			'open'   => sprintf( __( 'Open on %s', 'revolter-cookies' ), $label ),
		);
	}

	/**
	 * Purpose for a cookie with no known purpose.
	 *
	 * @param string $cat Category key.
	 * @return string
	 */
	public static function generic_purpose( string $cat ): string {
		switch ( $cat ) {
			case 'necessary':
				return __( 'Used by the website to provide basic functionality.', 'revolter-cookies' );
			case 'preferences':
				return __( 'Used by the website to remember your choices.', 'revolter-cookies' );
			case 'statistics':
				return __( 'Used by the website for statistics.', 'revolter-cookies' );
			case 'marketing':
				return __( 'Used by the website for marketing.', 'revolter-cookies' );
			default:
				return __( 'Not yet classified.', 'revolter-cookies' );
		}
	}

	/**
	 * Cookie policy page sections, plain text.
	 *
	 * @param array $ctx Keys company, email, org_number, count.
	 * @return array<string, array{title:string, body:string}>
	 */
	public static function sections( array $ctx ): array {
		$company = isset( $ctx['company'] ) ? (string) $ctx['company'] : '';
		$email   = isset( $ctx['email'] ) ? (string) $ctx['email'] : '';
		$org     = isset( $ctx['org_number'] ) ? trim( (string) $ctx['org_number'] ) : '';
		$count   = isset( $ctx['count'] ) ? (int) $ctx['count'] : 0;

		$company_full = $company;
		if ( '' !== $org ) {
			/* translators: 1: company name, 2: organisation number. */
			$company_full = sprintf( __( '%1$s (org. no. %2$s)', 'revolter-cookies' ), $company, $org );
		}

		return array(
			'what'   => array(
				'title' => __( 'What are cookies?', 'revolter-cookies' ),
				'body'  => __( 'Cookies are small text files that are stored on your device when you visit a website. Some are necessary for the website to work; others are used for statistics or marketing and are only set if you accept them.', 'revolter-cookies' ),
			),
			'how'    => array(
				/* translators: %1$s: company name. */
				'title' => sprintf( __( 'How %1$s uses cookies', 'revolter-cookies' ), $company ),
				/* translators: %d: number of cookies. */
				'body'  => sprintf( _n( 'This website uses %d cookie, divided into the categories below. The list is updated automatically when the website is scanned.', 'This website uses %d cookies, divided into the categories below. The list is updated automatically when the website is scanned.', $count, 'revolter-cookies' ), $count ),
			),
			'manage' => array(
				'title' => __( 'How to manage cookies', 'revolter-cookies' ),
				'body'  => __( 'You can change your choice at any time using the button below or the cookie settings icon on the website. You can also set your browser to block or delete cookies. Note that some parts of the website may not work correctly if you block necessary cookies.', 'revolter-cookies' ),
			),
			'legal'  => array(
				'title' => __( 'Legal basis', 'revolter-cookies' ),
				'body'  => __( 'Necessary cookies are used under the Swedish Electronic Communications Act (2022:482), chapter 9, section 28, and do not require consent. All other cookies are only used with your consent under Article 6(1)(a) of the GDPR.', 'revolter-cookies' ),
			),
			'rights' => array(
				'title' => __( 'Your rights', 'revolter-cookies' ),
				/* translators: 1: company name, 2: contact email. */
				'body'  => sprintf( __( 'You can change or withdraw your consent at any time. If you have questions about how we use cookies, contact %1$s at %2$s. You also have the right to lodge a complaint with the Swedish Authority for Privacy Protection (IMY).', 'revolter-cookies' ), $company_full, $email ),
			),
		);
	}

	/**
	 * Declaration table strings. last_updated keeps its %s for the date.
	 *
	 * @return array<string, string>
	 */
	public static function declaration(): array {
		return array(
			'title'        => __( 'Cookie declaration', 'revolter-cookies' ),
			/* translators: %s: date. */
			'last_updated' => __( 'Last updated %s', 'revolter-cookies' ),
			'name'         => __( 'Name', 'revolter-cookies' ),
			'provider'     => __( 'Provider', 'revolter-cookies' ),
			'purpose'      => __( 'Purpose', 'revolter-cookies' ),
			'duration'     => __( 'Duration', 'revolter-cookies' ),
		);
	}

	/**
	 * "Your consent" box strings. accepted keeps its placeholders for the browser to fill.
	 *
	 * @return array<string, string>
	 */
	public static function consent_box(): array {
		return array(
			'title'      => __( 'Your consent', 'revolter-cookies' ),
			/* translators: 1: accepted categories, 2: date given, 3: consent ID. */
			'accepted'   => __( 'You have accepted: %1$s. Given %2$s · ID %3$s', 'revolter-cookies' ),
			'none'       => __( 'You have not made a choice yet.', 'revolter-cookies' ),
			'withdraw'   => __( 'Withdraw', 'revolter-cookies' ),
			'change'     => __( 'Change settings', 'revolter-cookies' ),
			'table_link' => __( 'Change cookie settings', 'revolter-cookies' ),
		);
	}
}
