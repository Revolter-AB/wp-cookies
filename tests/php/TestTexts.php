<?php
/**
 * Texts tests.
 *
 * @package RevolterCookies
 */

use RevolterCookies\Texts;

/**
 * Verifies the central visitor copy.
 */
class TestTexts extends WP_UnitTestCase {

	/**
	 * Banner strings are the fixed English source strings.
	 */
	public function test_banner_strings_english_source() {
		$b = Texts::banner();
		$this->assertSame( 'We use cookies', $b['title'] );
		$this->assertSame( 'We use necessary cookies to make this website work. With your consent, we also use cookies for statistics and marketing. You can change or withdraw your consent at any time.', $b['text'] );
		$this->assertSame( 'Read our cookie policy', $b['policy_link'] );
		$this->assertSame( 'Accept all', $b['accept_all'] );
		$this->assertSame( 'Reject all', $b['reject_all'] );
		$this->assertSame( 'Customise', $b['customize'] );
		$this->assertSame( 'Save choices', $b['save'] );
		$this->assertSame( 'Close', $b['close'] );
		$this->assertSame( 'Cookie settings', $b['settings_button'] );
		$this->assertSame( 'Made with care by', $b['credit'] );
		$this->assertSame( 'Always on', $b['always_on'] );
		$this->assertSame( 'Cookie settings', $b['modal_title'] );
		$this->assertSame( 'Choose which types of cookies you accept. Necessary cookies cannot be switched off because the website does not work without them.', $b['modal_intro'] );
	}

	/**
	 * Category labels, descriptions and counts.
	 */
	public function test_category_texts() {
		$this->assertSame( 'Necessary', Texts::category_label( 'necessary' ) );
		$this->assertSame( 'Preferences', Texts::category_label( 'preferences' ) );
		$this->assertSame( 'Statistics', Texts::category_label( 'statistics' ) );
		$this->assertSame( 'Marketing', Texts::category_label( 'marketing' ) );
		$this->assertSame( 'Unclassified', Texts::category_label( 'unclassified' ) );
		$this->assertSame( 'Required for basic functions such as security and remembering your cookie choice.', Texts::category_description( 'necessary' ) );
		$this->assertSame( 'Used to show relevant ads and embedded content such as YouTube.', Texts::category_description( 'marketing' ) );
		$this->assertSame( '1 cookie', Texts::category_count( 1 ) );
		$this->assertSame( '0 cookies', Texts::category_count( 0 ) );
		$this->assertSame( '3 cookies', Texts::category_count( 3 ) );
		$this->assertSame( 'Used by the website for statistics.', Texts::generic_purpose( 'statistics' ) );
		$this->assertSame( 'Not yet classified.', Texts::generic_purpose( 'unclassified' ) );
	}

	/**
	 * Placeholder copy names the service and the category.
	 */
	public function test_placeholder() {
		$p = Texts::placeholder( 'YouTube', 'marketing' );
		$this->assertSame( 'This content comes from YouTube, which uses cookies for marketing. Accept marketing cookies to show it.', $p['text'] );
		$this->assertSame( 'Accept and show', $p['accept'] );
		$this->assertSame( 'Open on YouTube', $p['open'] );
	}

	/**
	 * Policy sections fill in company, email, org number and count.
	 */
	public function test_sections() {
		$s = Texts::sections(
			array(
				'company'    => 'Acme AB',
				'email'      => 'info@example.com',
				'org_number' => '556000-0000',
				'count'      => 7,
			)
		);
		$this->assertSame( array( 'what', 'how', 'manage', 'legal', 'rights' ), array_keys( $s ) );
		$this->assertSame( 'How Acme AB uses cookies', $s['how']['title'] );
		$this->assertStringContainsString( 'This website uses 7 cookies', $s['how']['body'] );
		$this->assertStringContainsString( 'contact Acme AB (org. no. 556000-0000) at info@example.com.', $s['rights']['body'] );

		$s = Texts::sections(
			array(
				'company' => 'Acme AB',
				'email'   => 'info@example.com',
			)
		);
		$this->assertStringContainsString( 'contact Acme AB at info@example.com.', $s['rights']['body'] );
	}

	/**
	 * The "how" body is singular for one cookie.
	 */
	public function test_sections_how_singular() {
		$s = Texts::sections( array( 'count' => 1 ) );
		$this->assertStringContainsString( 'This website uses 1 cookie, divided', $s['how']['body'] );
		$s = Texts::sections( array( 'count' => 0 ) );
		$this->assertStringContainsString( 'This website uses 0 cookies, divided', $s['how']['body'] );
	}

	/**
	 * Declaration and consent box keys.
	 */
	public function test_declaration_and_consent_box() {
		$d = Texts::declaration();
		$this->assertSame( 'Cookie declaration', $d['title'] );
		$this->assertSame( 'Last updated %s', $d['last_updated'] );
		$this->assertSame( array( 'Name', 'Provider', 'Purpose', 'Duration' ), array( $d['name'], $d['provider'], $d['purpose'], $d['duration'] ) );
		$c = Texts::consent_box();
		$this->assertSame( array( 'title', 'accepted', 'none', 'withdraw', 'change', 'table_link' ), array_keys( $c ) );
		$this->assertSame( 'You have accepted: %1$s. Given %2$s · ID %3$s', $c['accepted'] );
		$this->assertSame( 'Change cookie settings', $c['table_link'] );
	}
}
