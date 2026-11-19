<?php
/**
 * WordPress test-suite configuration.
 *
 * @package RevolterCookies
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound,WordPress.WP.GlobalVariablesOverride.Prohibited

define( 'ABSPATH', dirname( __DIR__ ) . '/.wp-core/' );

define( 'WP_DEBUG', true );

define( 'DB_NAME', getenv( 'RC_DB_NAME' ) ? getenv( 'RC_DB_NAME' ) : 'revolter_cookies_tests' );
define( 'DB_USER', getenv( 'RC_DB_USER' ) ? getenv( 'RC_DB_USER' ) : 'root' );
define( 'DB_PASSWORD', getenv( 'RC_DB_PASS' ) ? getenv( 'RC_DB_PASS' ) : '' );
define( 'DB_HOST', getenv( 'RC_DB_HOST' ) ? getenv( 'RC_DB_HOST' ) : '127.0.0.1' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

define( 'AUTH_KEY', 'rc-tests' );
define( 'SECURE_AUTH_KEY', 'rc-tests' );
define( 'LOGGED_IN_KEY', 'rc-tests' );
define( 'NONCE_KEY', 'rc-tests' );
define( 'AUTH_SALT', 'rc-tests' );
define( 'SECURE_AUTH_SALT', 'rc-tests' );
define( 'LOGGED_IN_SALT', 'rc-tests' );
define( 'NONCE_SALT', 'rc-tests' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
