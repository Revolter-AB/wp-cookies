<?php
/**
 * Plugin orchestrator.
 *
 * @package RevolterCookies
 */

namespace RevolterCookies;

defined( 'ABSPATH' ) || exit;

/**
 * Boots and holds the plugin modules.
 */
class Plugin {

	/**
	 * Singleton.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Registered modules.
	 *
	 * @var object[]
	 */
	private $modules = array();

	/**
	 * Blocking rules, built lazily.
	 *
	 * @var Blocking_Rules|null
	 */
	private $blocking_rules = null;

	/**
	 * Get the singleton.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Boot on plugins_loaded and register every module.
	 */
	public function boot(): void {
		Installer::maybe_upgrade();
		load_plugin_textdomain( 'revolter-cookies', false, dirname( plugin_basename( REVOLTER_COOKIES_FILE ) ) . '/languages' );

		$plugin = $this;
		$this->register_module(
			new Blocker(
				static function () use ( $plugin ) {
					return new Html_Rewriter( $plugin->blocking_rules() );
				}
			)
		);
		$this->register_module(
			new Head_Script(
				static function () use ( $plugin ) {
					return $plugin->blocking_rules();
				}
			)
		);
		$this->register_module( new Banner() );
		$this->register_module( new Wp_Consent_Api() );
		$this->register_module( new Rest_Consent() );
		$scanner = new Scanner();
		$this->register_module( $scanner );
		$this->register_module( new Rest_Scan( $scanner ) );
		$this->register_module( new Shortcodes() );
		$this->register_module( new Blocks() );
		$this->register_module( new Elementor\Integration() );
		$this->register_module( new Admin\Menu() );
		$this->register_module( new Conflicts() );
		$this->register_module( new Cache_Purge() );
		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			$this->register_module( new Updater() );
		}
	}

	/**
	 * Blocking rules for this request, built on first use.
	 *
	 * @return Blocking_Rules
	 */
	public function blocking_rules(): Blocking_Rules {
		if ( null === $this->blocking_rules ) {
			$data                 = ( new Item_Repository() )->frontend_data();
			$this->blocking_rules = Blocking_Rules::build( Library::from_file(), Settings::all(), $data['domains'] );
		}
		return $this->blocking_rules;
	}

	/**
	 * Register a hook-owning module.
	 *
	 * @param object $module Module exposing register().
	 */
	public function register_module( $module ): void {
		$module->register();
		$this->modules[] = $module;
	}

	/**
	 * Registered modules.
	 *
	 * @return object[]
	 */
	public function modules(): array {
		return $this->modules;
	}
}
