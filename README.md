# Revolter Cookies

Self-hosted cookie consent for WordPress, built by [Revolter](https://revolter.se).

Revolter Cookies scans your site locally, builds a real cookie declaration in Swedish and English, shows a consent banner, and blocks scripts and embeds until the visitor has agreed. Nothing visitor-facing depends on an external service.

## Features

- **Local scan.** Crawls the site's own pages in the browser, before and after consent, and finds cookies, local storage and third-party domains. No data leaves the site.
- **Cookie declaration.** A bundled library of common services (Google Analytics, Tag Manager, Meta, LinkedIn, TikTok, Hotjar, Clarity, YouTube, Vimeo, Google Maps, reCAPTCHA, WooCommerce and more) fills in purpose, provider and duration in Swedish and English. Anything unknown is listed for review.
- **Real blocking.** Scripts, iframes and embeds stay inactive until the visitor consents to their category, both in the server-rendered HTML and for scripts added later by JavaScript. Embeds show a placeholder with a button to allow them.
- **Banner and preferences.** Accept all, reject all, or choose per category (necessary, preferences, statistics, marketing). The texts are written in plain Swedish and English, the accent colour and button position can be changed, and a settings button lets visitors change their mind.
- **Google Consent Mode v2** defaults and the **WP Consent API** are supported.
- **Gutenberg blocks and Elementor widgets** for the full cookie policy and the cookie table.
- **Multilingual sites.** WPML, Polylang and TranslatePress are detected; visitors get Swedish or English.
- **Consent log.** An anonymous record of each choice (no IP address or user agent), kept for 24 months and exportable as CSV.
- **Cache friendly.** Output is the same for every visitor, and common page caches are purged when the rules change.
- **Updates from GitHub releases.**

## Requirements

- WordPress 6.5 or later
- PHP 7.4 or later

## Installation

1. Download `revolter-cookies.zip` from the [latest release](https://github.com/Revolter-AB/wp-cookies/releases/latest).
2. In WordPress, go to Plugins → Add New → Upload Plugin and upload the zip, then activate it.
3. Go to Cookies → Rescan website.
4. Add the Full page block or widget to your cookie policy page.

Later versions show up as normal plugin updates.

## For developers

Markup, JavaScript API, filters and actions are documented in the Developers section of [`readme.txt`](readme.txt).

```sh
composer install && npm install
bash bin/install-wp-tests.sh   # test WordPress and database
composer test                  # PHPUnit
composer lint                  # WordPress Coding Standards
npm test                       # JavaScript unit tests
bash bin/build-zip.sh          # build/revolter-cookies.zip
```

Releases are built by GitHub Actions when a `v*` tag matching the plugin version is pushed.

## Credits

The bundled cookie library was compiled with reference to the [Open Cookie Database](https://github.com/jkwakman/Open-Cookie-Database) (Apache-2.0).

## License

[GPL-2.0-or-later](LICENSE)
