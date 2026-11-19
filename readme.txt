=== Revolter Cookies ===
Contributors: revolterab
Tags: cookies, consent, gdpr, cookie banner, privacy
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-contained cookie consent for WordPress: local scan, real cookie declaration, editable banner and blocking of scripts and embeds until consent.

== Description ==

Revolter Cookies scans your site locally, builds a real cookie declaration in Swedish and English, shows an editable consent banner and blocks scripts and embeds until the visitor has consented. Nothing visitor-facing depends on an external service.

== Credits ==

The bundled cookie library was compiled with reference to the Open Cookie Database (Apache-2.0), https://github.com/jkwakman/Open-Cookie-Database.

== Installation ==

Upload, activate, Cookies → Rescan website, add the Full page widget to your cookie page.

== Frequently Asked Questions ==

= How do I open the cookie settings from a link? =

Give any link or button the class `rc-open-settings`, or link to `#cookie-settings`. A page loaded with `#cookie-settings` in its address opens the settings too.

= What happens if another consent plugin is active? =

Revolter Cookies shows a warning on the Plugins screen and on its own pages until the other consent solution is deactivated. Two consent banners on one site give visitors conflicting choices, so use only one.

= Where is the consent log? =

Under Cookies → Consent log. Search by the start of a consent ID, export a date range as CSV, or download a single entry with the CSV link on its row.

== Developers ==

= Markup =

* `data-rc-category="preferences|statistics|marketing"` on a `<script>` or `<iframe>` makes it wait for consent in that category. Matching ignores case; any other value is treated as if the attribute were absent. Add `data-rc-label` to name the service in the placeholder (iframes fall back to the host name).
* `data-rc-ignore` on a `<script>` or `<iframe>` leaves it untouched.
* The class `rc-open-settings`, or `href="#cookie-settings"`, opens the cookie settings.

= JavaScript =

* `window.RevolterCookies.open()` opens the settings.
* `window.RevolterCookies.getConsent()` returns the current consent (`{ id, v, t, c }`, where `c` lists the allowed categories) or `null`.
* `window.RevolterCookies.onChange( callback )` calls `callback` after every consent change.
* The `rc:consent-changed` event fires on `document` after every consent change; `event.detail.categories` lists the allowed categories.

= Filters =

* `revolter_cookies_show_credit` (bool): show the credit row in the banner. Default true.
* `revolter_cookies_update_repo` (string): GitHub repository URL the updater checks.
* `revolter_cookies_scan_sslverify` (bool): verify TLS when the scanner fetches the site's own pages. Default true outside local environments.
* `revolter_cookies_scan_post_types` (string[]): post types the scanner samples (the latest three published entries of each).

= Actions =

* `revolter_cookies_rules_changed`: fires when blocking rules, categories or banner settings change. Common page caches are purged on it.

== Changelog ==

= 0.1.0 =
* Initial release.
