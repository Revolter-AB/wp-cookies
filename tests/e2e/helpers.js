// Shared e2e helpers: third-party stubs, request log, fixture data and admin login.
const fs = require('fs');
const os = require('os');
const path = require('path');
const { test: base, expect } = require('@playwright/test');

const SITE_HOST = 'wptests.test';
const STATE_DIR = process.env.RC_E2E_STATE_DIR || path.join(os.tmpdir(), 'rc-e2e-state');

function fixture() {
	return JSON.parse(fs.readFileSync(path.join(STATE_DIR, 'fixture.json'), 'utf8'));
}

// Stand-in for gtag.js: queue handling plus the first-party _ga cookie the real script sets.
const GTAG_STUB =
	'window.dataLayer=window.dataLayer||[];' +
	'window.gtag=window.gtag||function(){window.dataLayer.push(arguments);};' +
	'document.cookie="_ga=GA1.1.123.456; path=/";' +
	'window.__rcE2eGtagLoaded=true;';

// Stand-in for the YouTube iframe API, enough for Elementor's video widget to build its player iframe.
const YT_API_STUB =
	'(function(){' +
	'function Player(el,o){o=o||{};var t=typeof el==="string"?document.getElementById(el):el;' +
	'var f=document.createElement("iframe");f.src="https://www.youtube.com/embed/"+(o.videoId||"");f.className="rc-e2e-yt-player";' +
	'if(t&&t.parentNode){t.parentNode.replaceChild(f,t);}' +
	'var self=this;["mute","unMute","playVideo","pauseVideo","stopVideo","seekTo","destroy","setSize","loadVideoById","cueVideoById"].forEach(function(m){self[m]=function(){};});' +
	'self.getIframe=function(){return f;};self.getPlayerState=function(){return -1;};' +
	'setTimeout(function(){if(o.events&&typeof o.events.onReady==="function"){o.events.onReady({target:self});}},0);}' +
	'window.YT={loaded:1,Player:Player,PlayerState:{UNSTARTED:-1,ENDED:0,PLAYING:1,PAUSED:2,BUFFERING:3,CUED:5},ready:function(cb){setTimeout(cb,0);}};' +
	'if(typeof window.onYouTubeIframeAPIReady==="function"){setTimeout(window.onYouTubeIframeAPIReady,0);}' +
	'})();';

const HTML_STUB = '<!doctype html><html><head><title>stub</title></head><body>stub</body></html>';

/**
 * Stubs every third-party request in the context and records all request URLs.
 * Site requests pass through; unknown third parties are aborted, so nothing leaves the machine.
 */
async function installStubs(context) {
	const log = [];
	context.on('request', (req) => log.push(req.url()));
	await context.route('**/*', async (route) => {
		const url = new URL(route.request().url());
		if (url.hostname === SITE_HOST || url.protocol === 'data:' || url.protocol === 'blob:') {
			return route.continue();
		}
		const host = url.hostname;
		const p = url.pathname;
		if (/(^|\.)googletagmanager\.com$/.test(host) && p.indexOf('/gtag/js') === 0) {
			return route.fulfill({ status: 200, contentType: 'application/javascript', body: GTAG_STUB });
		}
		if (/(^|\.)youtube(-nocookie)?\.com$/.test(host)) {
			if (p.indexOf('/iframe_api') === 0 || p.indexOf('/player_api') === 0) {
				return route.fulfill({ status: 200, contentType: 'application/javascript', body: YT_API_STUB });
			}
			return route.fulfill({ status: 200, contentType: 'text/html', body: HTML_STUB });
		}
		if ((/(^|\.)google\.com$/.test(host) && p.indexOf('/maps') === 0) || /^maps\.google\./.test(host)) {
			return route.fulfill({ status: 200, contentType: 'text/html', body: HTML_STUB });
		}
		if (/(^|\.)(googletagmanager|google-analytics|gstatic)\.com$/.test(host) || /(^|\.)connect\.facebook\.net$/.test(host)) {
			return route.fulfill({ status: 200, contentType: 'application/javascript', body: '' });
		}
		if (/(^|\.)facebook\.com$/.test(host)) {
			return route.fulfill({ status: 200, contentType: 'image/gif', body: '' });
		}
		return route.abort();
	});
	return log;
}

const TRACKERS = [/googletagmanager\.com/, /connect\.facebook\.net/, /youtube(-nocookie)?\.com/, /google\.com\/maps/, /google-analytics\.com/, /facebook\.com\/tr/];

function trackerRequests(log, patterns) {
	return log.filter((u) => (patterns || TRACKERS).some((re) => re.test(u)));
}

async function cookie(context, name) {
	return (await context.cookies('https://' + SITE_HOST)).find((c) => c.name === name);
}

async function consentCats(context) {
	const c = await cookie(context, 'rc_consent');
	if (!c) {
		return null;
	}
	return JSON.parse(decodeURIComponent(c.value)).c;
}

async function login(page) {
	const f = fixture();
	await page.goto('/wp-login.php');
	await page.fill('#user_login', f.user);
	await page.fill('#user_pass', f.password);
	await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')]);
}

const test = base.extend({
	requestLog: [
		async ({ context }, use) => {
			await use(await installStubs(context));
		},
		{ auto: true },
	],
});

module.exports = { test, expect, fixture, trackerRequests, cookie, consentCats, login, SITE_HOST };
