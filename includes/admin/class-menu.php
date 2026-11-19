<?php
/**
 * Admin menu.
 *
 * @package RevolterCookies
 */

namespace RevolterCookies\Admin;

use RevolterCookies\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Cookies menu, its pages and their assets.
 */
class Menu {

	const SLUG    = 'revolter-cookies';
	const BANNER  = 'revolter-cookies-banner';
	const COMPANY = 'revolter-cookies-company';
	const LOG     = 'revolter-cookies-log';

	/**
	 * Screen hook suffixes by page slug.
	 *
	 * @var string[]
	 */
	private $hooks = array();

	/**
	 * Screen ids of all four pages, filled when the menu is added.
	 *
	 * @var string[]
	 */
	private static $screen_ids = array();

	/**
	 * Page objects.
	 *
	 * @var object[]
	 */
	private $pages = array();

	/**
	 * Overview page.
	 *
	 * @var Overview_Page
	 */
	private $overview;

	/**
	 * Settings pages.
	 *
	 * @var Settings_Pages
	 */
	private $settings;

	/**
	 * Log page.
	 *
	 * @var Log_Page
	 */
	private $log;

	/**
	 * Hook everything.
	 */
	public function register(): void {
		$this->overview = new Overview_Page();
		$this->settings = new Settings_Pages();
		$this->log      = new Log_Page();
		$this->overview->register();
		$this->settings->register();
		$this->log->register();
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Add the top-level menu and subpages.
	 */
	public function add_menu(): void {
		$cap                       = 'manage_options';
		self::$screen_ids          = array();
		$this->hooks[ self::SLUG ] = (string) add_menu_page( __( 'Cookies', 'revolter-cookies' ), __( 'Cookies', 'revolter-cookies' ), $cap, self::SLUG, array( $this->overview, 'render' ), 'dashicons-shield', 80 );
		self::$screen_ids[]        = $this->hooks[ self::SLUG ];

		$subpages = array(
			self::SLUG    => array( __( 'Overview', 'revolter-cookies' ), array( $this->overview, 'render' ) ),
			self::BANNER  => array( __( 'Banner', 'revolter-cookies' ), array( $this->settings, 'render_banner' ) ),
			self::COMPANY => array( __( 'Company details', 'revolter-cookies' ), array( $this->settings, 'render_company' ) ),
			self::LOG     => array( __( 'Consent log', 'revolter-cookies' ), array( $this->log, 'render' ) ),
		);
		foreach ( $subpages as $slug => $page ) {
			$hook = add_submenu_page( self::SLUG, $page[0], $page[0], $cap, $slug, $page[1] );
			if ( self::SLUG !== $slug ) {
				$this->hooks[ $slug ] = (string) $hook;
				self::$screen_ids[]   = (string) $hook;
			}
		}
	}

	/**
	 * Screen ids of this plugin's pages (empty before admin_menu has run).
	 *
	 * @return string[]
	 */
	public static function screen_ids(): array {
		return self::$screen_ids;
	}

	/**
	 * Load assets on this plugin's screens only.
	 *
	 * @param string $hook_suffix Current screen hook suffix.
	 */
	public function enqueue( $hook_suffix ): void {
		$slug = array_search( (string) $hook_suffix, $this->hooks, true );
		if ( false === $slug ) {
			return;
		}
		wp_enqueue_style( 'rc-admin', REVOLTER_COOKIES_URL . 'assets/css/admin.css', array(), REVOLTER_COOKIES_VERSION );

		if ( self::SLUG === $slug ) {
			Scanner::register_admin_script();
			wp_enqueue_script( 'rc-admin-scan' );
			wp_add_inline_script( 'rc-admin-scan', $this->scan_init_script(), 'after' );
		}
		if ( self::BANNER === $slug ) {
			wp_enqueue_style( 'wp-color-picker' );
			wp_enqueue_script( 'wp-color-picker' );
			wp_add_inline_script( 'wp-color-picker', 'jQuery(function($){$(".rc-color-field").wpColorPicker();});', 'after' );
		}
	}

	/**
	 * Inline init that mounts the scan runner on the scan buttons.
	 */
	private function scan_init_script(): string {
		return '(function(){'
			. 'var L=window.RevolterCookiesScanL10n,S=window.RevolterCookiesScan;if(!L||!S){return;}'
			. 'var box=document.getElementById("rc-scan-progress");'
			. 'var btns=document.querySelectorAll("[data-rc-scan]");'
			. 'Array.prototype.forEach.call(btns,function(b){b.addEventListener("click",function(e){'
			. 'e.preventDefault();'
			. 'Array.prototype.forEach.call(btns,function(x){x.disabled=true;});'
			. 'if(box){box.hidden=false;}'
			. 'S.run({rest:L.rest,nonce:L.nonce,l10n:L.strings,container:box,onDone:function(s){'
			. 'if(s&&!s.all_failed){window.setTimeout(function(){window.location.reload();},2000);}'
			. 'else{Array.prototype.forEach.call(btns,function(x){x.disabled=false;});}'
			. '}});});});'
			. '})();';
	}

	/**
	 * Page URL.
	 *
	 * @param string $slug  Page slug.
	 * @param array  $args  Extra query args.
	 * @return string
	 */
	public static function url( string $slug, array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . $slug ) );
	}

	/**
	 * Capability and nonce gate for admin-post handlers; dies with 403 when it fails.
	 *
	 * @param string $action Nonce action.
	 */
	public static function authorize( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'revolter-cookies' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirect back to a page and stop.
	 *
	 * @param string $slug Page slug.
	 * @param array  $args Query args.
	 */
	public static function redirect( string $slug, array $args = array( 'updated' => 1 ) ): void {
		wp_safe_redirect( self::url( $slug, $args ) );
		exit;
	}

	/**
	 * Render an admin template.
	 *
	 * @param string $name Template name without extension.
	 * @param array  $view Variables for the template.
	 */
	public static function render( string $name, array $view ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $view is read by the template.
		include REVOLTER_COOKIES_PATH . 'templates/admin/' . $name . '.php';
	}
}
