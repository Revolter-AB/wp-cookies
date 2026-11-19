<?php
/**
 * Plugin Name:       Revolter Cookies
 * Description:       Self-contained cookie consent for WordPress: local scan, real cookie declaration, editable banner and real blocking of scripts and embeds until consent.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Revolter AB
 * Author URI:        https://revolter.se
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       revolter-cookies
 * Domain Path:       /languages
 *
 * @package RevolterCookies
 */

use RevolterCookies\Autoloader;
use RevolterCookies\Installer;
use RevolterCookies\Plugin;

defined( 'ABSPATH' ) || exit;

define( 'REVOLTER_COOKIES_VERSION', '0.1.0' );
define( 'REVOLTER_COOKIES_FILE', __FILE__ );
define( 'REVOLTER_COOKIES_PATH', plugin_dir_path( __FILE__ ) );
define( 'REVOLTER_COOKIES_URL', plugin_dir_url( __FILE__ ) );
define( 'REVOLTER_COOKIES_UPDATE_REPO', 'https://github.com/Revolter-AB/wp-cookies/' );

if ( file_exists( REVOLTER_COOKIES_PATH . 'vendor/autoload.php' ) ) {
	require_once REVOLTER_COOKIES_PATH . 'vendor/autoload.php';
}

require_once REVOLTER_COOKIES_PATH . 'includes/class-autoloader.php';
Autoloader::register();

register_activation_hook( REVOLTER_COOKIES_FILE, array( Installer::class, 'activate' ) );
register_deactivation_hook( REVOLTER_COOKIES_FILE, array( Installer::class, 'deactivate' ) );
add_action( 'plugins_loaded', array( Plugin::instance(), 'boot' ) );
