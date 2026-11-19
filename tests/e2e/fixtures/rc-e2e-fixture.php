<?php
/**
 * Plugin Name: Revolter Cookies e2e fixture
 * Description: Test-only third-party snippets for the end-to-end suite. Installed by tests/e2e/setup.sh and removed by teardown.sh.
 *
 * @package RevolterCookies
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the current request shows the rc-e2e post.
 *
 * @return bool
 */
function revolter_cookies_e2e_is_fixture_post() {
	return is_singular( 'post' ) && 'rc-e2e' === get_post_field( 'post_name', get_queried_object_id() );
}

/**
 * Locale override for the Swedish spec, keyed on a cookie that only the e2e suite sets.
 *
 * @param string $locale Locale.
 * @return string
 */
function revolter_cookies_e2e_locale( $locale ) {
	if ( isset( $_COOKIE['rc_e2e_locale'] ) && 'sv_SE' === $_COOKIE['rc_e2e_locale'] ) {
		return 'sv_SE';
	}
	return $locale;
}
add_filter( 'locale', 'revolter_cookies_e2e_locale' );

add_action(
	'wp_head',
	static function () {
		if ( ! revolter_cookies_e2e_is_fixture_post() ) {
			return;
		}
		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Fixture: the raw snippets site owners paste.
		?>
<script async src="https://www.googletagmanager.com/gtag/js?id=G-E2ETEST000"></script>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());
gtag('config', 'G-E2ETEST000');
</script>
<script>
!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
fbq('init', '000000000000000');
fbq('track', 'PageView');
</script>
		<?php
		// phpcs:enable
	}
);

add_filter(
	'the_content',
	static function ( $content ) {
		if ( ! revolter_cookies_e2e_is_fixture_post() ) {
			return $content;
		}
		return $content
			. '<iframe id="rc-e2e-youtube" width="560" height="315" src="https://www.youtube.com/embed/dQw4w9WgXcQ" title="YouTube video" allowfullscreen></iframe>'
			. '<iframe id="rc-e2e-maps" width="600" height="450" src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d2035.0!2d18.07!3d59.33" title="Map" loading="lazy"></iframe>';
	},
	20
);
