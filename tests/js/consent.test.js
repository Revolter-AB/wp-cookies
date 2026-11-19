'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const h = require('./helpers/dom.js');

function done(p) {
	assert.deepEqual(p.errors.map(String), []);
	p.window.close();
}

test('shows banner without consent', function () {
	const p = h.page();
	assert.equal(p.document.getElementById('rc-banner').hidden, false);
	assert.equal(p.document.getElementById('rc-modal').hidden, true);
	assert.equal(p.document.getElementById('rc-settings-button').hidden, true);
	assert.equal(h.readConsent(p.window), null, 'never auto-accepts');
	assert.equal(p.window.__fetches.length, 0, 'no log before the visitor acts');
	done(p);
});

test('valid consent hides banner, shows settings button and activates allowed categories', function () {
	const p = h.page({
		cookie: h.consentCookie(['necessary', 'statistics']),
		body: '<script type="text/plain" data-rc-category="statistics" data-rc-label="GA">window.__stats = 1;</script>' +
			'<script type="text/plain" data-rc-category="marketing" data-rc-label="Meta">window.__mkt = 1;</script>'
	});
	assert.equal(p.document.getElementById('rc-banner').hidden, true);
	assert.equal(p.document.getElementById('rc-settings-button').hidden, false);
	assert.equal(p.window.__stats, 1);
	assert.equal(p.window.__mkt, undefined, 'never activates a category that is not allowed');
	done(p);
});

test('stale consent version shows banner', function () {
	const p = h.page({ cookie: h.consentCookie(['necessary', 'statistics'], 1) });
	assert.equal(p.document.getElementById('rc-banner').hidden, false);
	done(p);
});

test('accept-all writes cookie with all categories and activates statistics script', function () {
	const p = h.page({
		body: '<script type="text/plain" data-rc-category="statistics" data-rc-label="GA" id="s1" data-extra="x">window.__stats = (window.__stats || 0) + 1;</script>'
	});
	assert.equal(p.window.__stats, undefined);
	h.click(p.window, p.document.querySelector('#rc-banner [data-rc-action="accept-all"]'));
	const c = h.readConsent(p.window);
	assert.deepEqual(c.c, ['necessary', 'preferences', 'statistics', 'marketing']);
	assert.equal(c.v, h.VERSION);
	assert.match(c.id, /^[0-9a-f-]{36}$/);
	assert.equal(p.window.__stats, 1);
	const s = p.document.getElementById('s1');
	assert.equal(s.getAttribute('type'), null);
	assert.equal(s.getAttribute('data-extra'), 'x');
	assert.equal(s.hasAttribute('data-rc-category'), false);
	assert.equal(p.document.getElementById('rc-banner').hidden, true);
	assert.equal(p.document.getElementById('rc-settings-button').hidden, false);
	done(p);
});

test('activation preserves order external-then-inline', async function () {
	const p = h.page({
		body: '<script type="text/plain" data-rc-category="statistics" data-rc-src="https://www.googletagmanager.com/gtag/js?id=G-1" id="ext"></script>' +
			'<script type="text/plain" data-rc-category="statistics" id="inl">window.__seen = window.__loaded;</script>'
	});
	h.click(p.window, p.document.querySelector('#rc-banner [data-rc-action="accept-all"]'));
	const ext = p.document.getElementById('ext');
	assert.equal(ext.getAttribute('src'), 'https://www.googletagmanager.com/gtag/js?id=G-1');
	assert.equal(p.document.getElementById('inl').getAttribute('type'), 'text/plain', 'inline waits for the external one');
	assert.equal(p.window.__seen, undefined);
	await h.tick(5);
	assert.equal(p.document.getElementById('inl').getAttribute('type'), 'text/plain', 'still waiting');
	p.window.__loaded = 1;
	ext.dispatchEvent(new p.window.Event('load'));
	await h.tick(5);
	assert.equal(p.window.__seen, 1);
	done(p);
});

test('async external script does not hold back later scripts', async function () {
	const p = h.page({
		body: '<script type="text/plain" async data-rc-category="statistics" data-rc-src="https://www.googletagmanager.com/gtm.js?id=GTM-1" id="ext"></script>' +
			'<script type="text/plain" data-rc-category="statistics">window.__after = 1;</script>'
	});
	h.click(p.window, p.document.querySelector('#rc-banner [data-rc-action="accept-all"]'));
	await h.tick(5);
	assert.equal(p.window.__after, 1);
	assert.equal(p.document.getElementById('ext').hasAttribute('async'), true);
	done(p);
});

test('external script that errors still lets the next one run', async function () {
	const p = h.page({
		body: '<script type="text/plain" data-rc-category="statistics" data-rc-src="https://www.googletagmanager.com/x.js" id="ext"></script>' +
			'<script type="text/plain" data-rc-category="statistics">window.__after = 1;</script>'
	});
	h.click(p.window, p.document.querySelector('#rc-banner [data-rc-action="accept-all"]'));
	p.document.getElementById('ext').dispatchEvent(new p.window.Event('error'));
	await h.tick(5);
	assert.equal(p.window.__after, 1);
	done(p);
});

test('module type restored on activation', function () {
	const p = h.page({
		body: '<script type="text/plain" data-rc-type="module" data-rc-category="marketing" data-rc-src="https://connect.facebook.net/m.js" id="mod"></script>'
	});
	h.click(p.window, p.document.querySelector('#rc-banner [data-rc-action="accept-all"]'));
	const s = p.document.getElementById('mod');
	assert.equal(s.getAttribute('type'), 'module');
	assert.equal(s.getAttribute('src'), 'https://connect.facebook.net/m.js');
	assert.equal(s.hasAttribute('data-rc-type'), false);
	done(p);
});

test('reject-all writes necessary only and activates nothing', function () {
	const p = h.page({
		body: '<script type="text/plain" data-rc-category="statistics">window.__stats = 1;</script>'
	});
	h.click(p.window, p.document.querySelector('#rc-banner [data-rc-action="reject-all"]'));
	assert.deepEqual(h.readConsent(p.window).c, ['necessary']);
	assert.equal(p.window.__stats, undefined);
	assert.equal(p.document.getElementById('rc-banner').hidden, true);
	assert.equal(p.document.getElementById('rc-settings-button').hidden, false);
	done(p);
});

test('customise opens the modal with checkboxes reflecting consent, save writes custom', function () {
	const p = h.page();
	h.click(p.window, p.document.querySelector('#rc-banner [data-rc-action="customize"]'));
	const modal = p.document.getElementById('rc-modal');
	assert.equal(modal.hidden, false);
	assert.equal(p.document.getElementById('rc-banner').hidden, true, 'one overlay at a time');
	assert.equal(p.document.getElementById('rc-cat-statistics').checked, false);
	assert.ok(modal.contains(p.document.activeElement), 'focus moves into the modal');
	p.document.getElementById('rc-cat-statistics').checked = true;
	h.click(p.window, modal.querySelector('[data-rc-action="save"]'));
	assert.deepEqual(h.readConsent(p.window).c, ['necessary', 'statistics']);
	assert.equal(modal.hidden, true);
	assert.equal(JSON.parse(p.window.__fetches[0].init.body).action, 'custom');
	done(p);
});

test('modal checkboxes reflect the stored consent', function () {
	const p = h.page({ cookie: h.consentCookie(['necessary', 'marketing']) });
	p.window.RevolterCookies.open();
	assert.equal(p.document.getElementById('rc-cat-marketing').checked, true);
	assert.equal(p.document.getElementById('rc-cat-statistics').checked, false);
	assert.equal(p.document.getElementById('rc-cat-necessary').checked, true);
	done(p);
});

test('escape closes modal without writing cookie', function () {
	const p = h.page();
	const opener = p.document.querySelector('#rc-banner [data-rc-action="customize"]');
	opener.focus();
	h.click(p.window, opener);
	assert.equal(p.document.getElementById('rc-modal').hidden, false);
	h.key(p.window, p.document.activeElement, 'Escape');
	assert.equal(p.document.getElementById('rc-modal').hidden, true);
	assert.equal(h.readConsent(p.window), null);
	assert.equal(p.window.__fetches.length, 0);
	assert.equal(p.document.getElementById('rc-banner').hidden, false, 'banner comes back while no choice is made');
	assert.equal(p.document.activeElement, opener, 'focus returns to the opener');
	done(p);
});

test('close button closes modal without writing cookie', function () {
	const p = h.page({ cookie: h.consentCookie(['necessary']) });
	const btn = p.document.getElementById('rc-settings-button');
	btn.focus();
	h.click(p.window, btn);
	h.click(p.window, p.document.querySelector('#rc-modal [data-rc-action="close"]'));
	assert.equal(p.document.getElementById('rc-modal').hidden, true);
	assert.equal(p.document.getElementById('rc-banner').hidden, true);
	assert.equal(p.document.activeElement, btn);
	assert.equal(p.window.__fetches.length, 0);
	done(p);
});

test('tab is trapped inside the modal', function () {
	const p = h.page();
	p.window.RevolterCookies.open();
	const modal = p.document.getElementById('rc-modal');
	const focusable = Array.prototype.filter.call(modal.querySelectorAll('button, input'), function (el) {
		return !el.disabled;
	});
	const first = focusable[0];
	const last = focusable[focusable.length - 1];
	last.focus();
	let ev = h.key(p.window, last, 'Tab');
	assert.equal(ev.defaultPrevented, true);
	assert.equal(p.document.activeElement, first);
	ev = h.key(p.window, first, 'Tab', true);
	assert.equal(ev.defaultPrevented, true);
	assert.equal(p.document.activeElement, last);
	done(p);
});

test('revoking statistics deletes listed cookies and reloads', function () {
	const p = h.page({
		cookie: [h.consentCookie(['necessary', 'statistics', 'marketing']), '_ga=1', '_ga_ABC123=2', '_gid=3', '_gcl_au=4', '_fbp=5', 'other=6']
	});
	p.window.RevolterCookies.setConsent(['necessary', 'marketing'], 'custom');
	const names = h.cookieNames(p.window);
	['_ga', '_ga_ABC123', '_gid', '_gcl_au'].forEach(function (n) {
		assert.equal(names.indexOf(n), -1, n + ' deleted');
	});
	['_fbp', 'other', 'rc_consent'].forEach(function (n) {
		assert.notEqual(names.indexOf(n), -1, n + ' kept');
	});
	assert.equal(p.navigations.length, 1, 'reloaded');
	done(p);
});

test('withdraw from the consent box revokes everything and reloads', function () {
	const p = h.page({
		cookie: [h.consentCookie(['necessary', 'marketing']), '_fbp=5'],
		body: '<div class="rc-consent-box" data-rc-consent-status><h3>Your consent</h3>' +
			'<button type="button" data-rc-action="withdraw">Withdraw</button>' +
			'<button type="button" data-rc-action="change">Change settings</button></div>'
	});
	const box = p.document.querySelector('[data-rc-consent-status]');
	const text = box.querySelector('[data-rc-consent-text]').textContent;
	assert.match(text, /^You have accepted: Necessary, Marketing\. Given .+ · ID 123e4567$/);
	h.click(p.window, box.querySelector('[data-rc-action="withdraw"]'));
	assert.deepEqual(h.readConsent(p.window).c, ['necessary']);
	assert.equal(h.cookieNames(p.window).indexOf('_fbp'), -1);
	assert.equal(JSON.parse(p.window.__fetches[0].init.body).action, 'withdraw');
	assert.equal(p.navigations.length, 1);
	done(p);
});

test('consent box shows none text and change opens the modal', function () {
	const p = h.page({
		body: '<div class="rc-consent-box" data-rc-consent-status><button type="button" data-rc-action="change">Change</button></div>'
	});
	const box = p.document.querySelector('[data-rc-consent-status]');
	assert.equal(box.querySelector('[data-rc-consent-text]').textContent, 'You have not made a choice yet.');
	h.click(p.window, box.querySelector('[data-rc-action="change"]'));
	assert.equal(p.document.getElementById('rc-modal').hidden, false);
	done(p);
});

test('gcm update pushed on accept', function () {
	const p = h.page();
	assert.equal(h.gcmUpdates(p.window).length, 0);
	h.click(p.window, p.document.querySelector('#rc-banner [data-rc-action="accept-all"]'));
	const ups = h.gcmUpdates(p.window);
	assert.equal(ups.length, 1);
	assert.equal(ups[0][2].analytics_storage, 'granted');
	assert.equal(ups[0][2].ad_storage, 'granted');
	done(p);
});

test('iframe placeholder accept grants marketing only and sets src', function () {
	const p = h.page({
		cookie: h.consentCookie(['necessary', 'preferences']),
		body: '<p><iframe width="560" height="315" class="rc-blocked-embed" data-rc-src="https://www.youtube.com/embed/abc" data-rc-category="marketing" data-rc-label="YouTube"></iframe></p>'
	});
	const iframe = p.document.querySelector('iframe');
	const ph = iframe.parentNode.querySelector('.rc-placeholder');
	assert.ok(ph, 'placeholder rendered');
	assert.equal(ph.querySelector('.rc-ph-text').textContent, 'This content comes from YouTube, which uses cookies for marketing. Accept marketing cookies to show it.');
	const open = ph.querySelector('a.rc-ph-open');
	assert.equal(open.textContent, 'Open on YouTube');
	assert.equal(open.getAttribute('href'), 'https://www.youtube.com/embed/abc');
	assert.equal(ph.style.width, '560px');
	assert.equal(iframe.style.display, 'none');
	h.click(p.window, ph.querySelector('.rc-ph-accept'));
	assert.deepEqual(h.readConsent(p.window).c, ['necessary', 'preferences', 'marketing']);
	assert.equal(iframe.getAttribute('src'), 'https://www.youtube.com/embed/abc');
	assert.equal(iframe.style.display, '');
	assert.equal(iframe.classList.contains('rc-blocked-embed'), false);
	assert.equal(p.document.querySelector('.rc-placeholder'), null);
	assert.equal(p.navigations.length, 0);
	done(p);
});

test('placeholder accept without prior consent hides the banner', function () {
	const p = h.page({
		body: '<iframe class="rc-blocked-embed" data-rc-src="https://www.youtube.com/embed/abc" data-rc-category="marketing" data-rc-label="YouTube"></iframe>'
	});
	h.click(p.window, p.document.querySelector('.rc-ph-accept'));
	assert.deepEqual(h.readConsent(p.window).c, ['necessary', 'marketing']);
	assert.equal(p.document.getElementById('rc-banner').hidden, true);
	done(p);
});

test('elementor video widget gets a placeholder and runs the handler on accept', function () {
	let triggered = 0;
	const p = h.page({
		body: '<div class="elementor-widget-video rc-blocked-widget" data-rc-category="marketing" data-rc-label="YouTube" data-settings=\'{"video_type":"youtube","youtube_url":"https://www.youtube.com/watch?v=abc"}\'><div class="elementor-wrapper"></div></div>',
		before: function (w) {
			w.jQuery = function (el) {
				return { el: el };
			};
			w.elementorFrontend = { elementsHandler: { runReadyTrigger: function ($el) {
				triggered++;
				const f = w.document.createElement('iframe');
				$el.el.querySelector('.elementor-wrapper').appendChild(f);
			} } };
		}
	});
	const widget = p.document.querySelector('.elementor-widget-video');
	const ph = p.document.querySelector('.rc-placeholder');
	assert.ok(ph);
	assert.equal(ph.querySelector('a.rc-ph-open').getAttribute('href'), 'https://www.youtube.com/watch?v=abc');
	assert.equal(widget.style.display, 'none');
	h.click(p.window, ph.querySelector('.rc-ph-accept'));
	assert.equal(triggered, 1);
	assert.equal(widget.style.display, '');
	assert.equal(p.document.querySelector('.rc-placeholder'), null);
	done(p);
});

test('rc-open-settings link opens modal', function () {
	const p = h.page({
		body: '<a href="/cookies/" class="rc-open-settings" id="l1">Cookies</a><a href="#cookie-settings" id="l2">Cookie settings</a>'
	});
	h.click(p.window, p.document.getElementById('l1'));
	assert.equal(p.document.getElementById('rc-modal').hidden, false);
	h.click(p.window, p.document.querySelector('#rc-modal [data-rc-action="close"]'));
	assert.equal(p.document.getElementById('rc-modal').hidden, true);
	h.click(p.window, p.document.getElementById('l2'));
	assert.equal(p.document.getElementById('rc-modal').hidden, false);
	done(p);
});

test('posts log payload with action', function () {
	const p = h.page({ config: { lang: 'sv' } });
	h.click(p.window, p.document.querySelector('#rc-banner [data-rc-action="reject-all"]'));
	assert.equal(p.window.__fetches.length, 1);
	const f = p.window.__fetches[0];
	assert.equal(f.url, 'https://example.test/wp-json/revolter-cookies/v1/consent');
	assert.equal(f.init.method, 'POST');
	assert.equal(f.init.keepalive, true);
	const body = JSON.parse(f.init.body);
	assert.deepEqual(Object.keys(body).sort(), ['action', 'categories', 'id', 'language', 'version']);
	assert.equal(body.action, 'reject_all');
	assert.deepEqual(body.categories, ['necessary']);
	assert.equal(body.version, h.VERSION);
	assert.equal(body.language, 'sv');
	assert.equal(body.id, h.readConsent(p.window).id);
	done(p);
});

test('fetch rejection is ignored', async function () {
	const p = h.page();
	p.window.fetch = function () {
		return Promise.reject(new Error('offline'));
	};
	h.click(p.window, p.document.querySelector('#rc-banner [data-rc-action="accept-all"]'));
	await h.tick(5);
	assert.equal(h.readConsent(p.window).c.length, 4);
	done(p);
});

test('existing consent id is kept on change', function () {
	const p = h.page({ cookie: h.consentCookie(['necessary']) });
	p.window.RevolterCookies.setConsent(['necessary', 'statistics'], 'custom');
	assert.equal(h.readConsent(p.window).id, h.ID);
	done(p);
});

test('calls wp_set_consent when available', function () {
	const calls = [];
	const p = h.page({
		config: { wpConsentApi: true },
		before: function (w) {
			w.wp_set_consent = function (cat, val) {
				calls.push(cat + ':' + val);
			};
		}
	});
	h.click(p.window, p.document.querySelector('#rc-banner [data-rc-action="customize"]'));
	p.document.getElementById('rc-cat-statistics').checked = true;
	h.click(p.window, p.document.querySelector('#rc-modal [data-rc-action="save"]'));
	assert.deepEqual(calls.sort(), [
		'functional:allow',
		'marketing:deny',
		'preferences:deny',
		'statistics-anonymous:allow',
		'statistics:allow'
	]);
	done(p);
});

test('wp_set_consent not called when the integration is off', function () {
	const calls = [];
	const p = h.page({
		before: function (w) {
			w.wp_set_consent = function (cat) {
				calls.push(cat);
			};
		}
	});
	h.click(p.window, p.document.querySelector('#rc-banner [data-rc-action="accept-all"]'));
	assert.deepEqual(calls, []);
	done(p);
});

test('onChange callbacks and rc:consent-changed event fire', function () {
	const p = h.page();
	const seen = [];
	p.window.RevolterCookies.onChange(function (c) {
		seen.push('cb:' + c.c.join(','));
	});
	p.document.addEventListener('rc:consent-changed', function (e) {
		seen.push('ev:' + e.detail.action);
	});
	h.click(p.window, p.document.querySelector('#rc-banner [data-rc-action="reject-all"]'));
	assert.deepEqual(seen.sort(), ['cb:necessary', 'ev:reject_all']);
	assert.deepEqual(Array.from(p.window.RevolterCookies.getConsent().c), ['necessary']);
	done(p);
});

test('garbage cookie shows banner without throwing', function () {
	['%%%', '{"id":1}', 'x'.repeat(3000), encodeURIComponent('{"id":"123e4567-e89b-42d3-a456-426614174000","v":"2","t":1,"c":["marketing"]}')].forEach(function (raw) {
		const p = h.page({
			cookie: 'rc_consent=' + raw,
			body: '<script type="text/plain" data-rc-category="marketing">window.__mkt = 1;</script>'
		});
		assert.equal(p.document.getElementById('rc-banner').hidden, false);
		assert.equal(p.window.__mkt, undefined);
		assert.equal(p.window.RevolterCookies.getConsent(), null);
		done(p);
	});
});

test('missing config does not throw', function () {
	const p = h.page({ noConsent: true });
	p.window.eval(require('node:fs').readFileSync(require('node:path').join(__dirname, '..', '..', 'assets', 'js', 'consent.js'), 'utf8'));
	p.document.dispatchEvent(new p.window.Event('DOMContentLoaded'));
	assert.equal(typeof p.window.RevolterCookies, 'object');
	done(p);
});

test('accept-all grants every category even before a scan lists them', function () {
	const p = h.page({ config: { categories: [{ key: 'necessary', count: 0 }] } });
	h.click(p.window, p.document.querySelector('#rc-banner [data-rc-action="accept-all"]'));
	assert.deepEqual(h.readConsent(p.window).c, ['necessary', 'preferences', 'statistics', 'marketing']);
	done(p);
});

test('queued script whose type was set after its src still activates', function () {
	const p = h.page();
	const s = p.document.createElement('script');
	s.src = 'https://connect.facebook.net/m.js';
	s.type = 'module';
	p.document.head.appendChild(s);
	assert.equal(s.getAttribute('src'), null, 'still inert');
	h.click(p.window, p.document.querySelector('#rc-banner [data-rc-action="accept-all"]'));
	const live = p.document.querySelector('script[src="https://connect.facebook.net/m.js"]');
	assert.ok(live);
	assert.equal(live.getAttribute('type'), 'module');
	assert.equal(s.parentNode, null, 'inert copy replaced');
	done(p);
});

const WIDGET = '<div class="elementor-widget-video rc-blocked-widget" data-rc-category="marketing" data-rc-label="YouTube" data-settings=\'{"video_type":"youtube","youtube_url":"https://www.youtube.com/watch?v=abc"}\'><div class="elementor-wrapper"></div></div>';

function elementorStub(counter) {
	return function (w) {
		w.jQuery = function (el) {
			return { el: el };
		};
		w.elementorFrontend = { elementsHandler: { runReadyTrigger: function () {
			counter.n++;
		} } };
	};
}

test('elementor widget with valid consent at init does not reload', async function () {
	const counter = { n: 0 };
	const p = h.page({ cookie: h.consentCookie(['necessary', 'marketing']), body: WIDGET, before: elementorStub(counter) });
	const widget = p.document.querySelector('.elementor-widget-video');
	assert.equal(widget.classList.contains('rc-blocked-widget'), false, 'class removed');
	assert.equal(p.document.querySelector('.rc-placeholder'), null, 'no placeholder');
	assert.equal(widget.style.display, '');
	assert.equal(counter.n, 0, 'Elementor init runs on its own');
	await h.tick(2200);
	assert.equal(p.navigations.length, 0, 'no reload');
	done(p);
});

test('widget reload fallback fires at most once per session', async function () {
	const counter = { n: 0 };
	const p = h.page({ body: WIDGET + WIDGET, before: elementorStub(counter) });
	h.click(p.window, p.document.querySelector('#rc-banner [data-rc-action="accept-all"]'));
	assert.equal(counter.n, 2);
	await h.tick(2200);
	assert.equal(p.navigations.length, 1, 'one reload for two widgets');
	assert.equal(p.window.sessionStorage.getItem('rc_reloaded'), '1');
	done(p);
});

test('widget reload fallback is skipped while the session flag is set', async function () {
	const counter = { n: 0 };
	const p = h.page({ body: WIDGET, before: elementorStub(counter) });
	p.window.sessionStorage.setItem('rc_reloaded', '1');
	h.click(p.window, p.document.querySelector('.rc-ph-accept'));
	await h.tick(2200);
	assert.equal(p.navigations.length, 0);
	done(p);
});

test('a successful load clears the reload flag', function () {
	const p = h.page({ before: function (w) {
		w.sessionStorage.setItem('rc_reloaded', '1');
	} });
	assert.equal(p.window.sessionStorage.getItem('rc_reloaded'), null);
	done(p);
});

test('reject after stale consent deletes old category cookies', function () {
	const p = h.page({
		cookie: [h.consentCookie(['necessary', 'statistics', 'marketing'], 1), '_ga=1', '_ga_XYZ=2', '_fbp=3', 'other=4']
	});
	assert.equal(p.document.getElementById('rc-banner').hidden, false, 're-asked');
	h.click(p.window, p.document.querySelector('#rc-banner [data-rc-action="reject-all"]'));
	const names = h.cookieNames(p.window);
	['_ga', '_ga_XYZ', '_fbp'].forEach(function (n) {
		assert.equal(names.indexOf(n), -1, n + ' deleted');
	});
	assert.notEqual(names.indexOf('other'), -1);
	assert.deepEqual(h.readConsent(p.window).c, ['necessary']);
	assert.equal(h.readConsent(p.window).id, h.ID, 'id kept');
	assert.equal(p.navigations.length, 0, 'nothing was activated on this page, so no reload');
	done(p);
});

test('stale consent re-granted keeps cookies and activates without reload', function () {
	const p = h.page({
		cookie: [h.consentCookie(['necessary', 'statistics'], 1), '_ga=1'],
		body: '<script type="text/plain" data-rc-category="statistics">window.__stats = 1;</script>'
	});
	h.click(p.window, p.document.querySelector('#rc-banner [data-rc-action="accept-all"]'));
	assert.notEqual(h.cookieNames(p.window).indexOf('_ga'), -1);
	assert.equal(p.window.__stats, 1);
	assert.equal(p.navigations.length, 0);
	done(p);
});

test('queue advances exactly once when replacing a script throws', async function () {
	const p = h.page({
		body: '<div id="wrap"><script type="text/plain" data-rc-category="statistics" data-rc-src="https://www.googletagmanager.com/a.js" id="a"></script></div>' +
			'<script type="text/plain" data-rc-category="statistics" data-rc-src="https://www.googletagmanager.com/b.js" id="b"></script>' +
			'<script type="text/plain" data-rc-category="statistics" id="c">window.__c = 1;</script>'
	});
	p.document.getElementById('wrap').replaceChild = function () {
		throw new Error('boom');
	};
	const origSetTimeout = p.window.setTimeout;
	const timers = [];
	p.window.setTimeout = function (fn, ms) {
		if (ms === 10000) {
			timers.push(fn);
			return 0;
		}
		return origSetTimeout.apply(this, arguments);
	};
	h.click(p.window, p.document.querySelector('#rc-banner [data-rc-action="accept-all"]'));
	assert.equal(p.document.getElementById('b').getAttribute('src'), 'https://www.googletagmanager.com/b.js', 'advanced to b after a failed');
	assert.equal(p.window.__c, undefined, 'c waits for b');
	timers[0]();
	await h.tick(5);
	assert.equal(p.window.__c, undefined, "a's timeout must not walk the queue past b");
	p.document.getElementById('b').dispatchEvent(new p.window.Event('load'));
	await h.tick(5);
	assert.equal(p.window.__c, 1);
	done(p);
});

test('scan mode accepted activates every category without cookie, log or banner', function () {
	const p = h.page({
		config: { scanMode: 'accepted' },
		body: '<script type="text/plain" data-rc-category="statistics">window.__stats = 1;</script>' +
			'<script type="text/plain" data-rc-category="marketing">window.__mkt = 1;</script>' +
			'<script type="text/plain" data-rc-category="preferences">window.__pref = 1;</script>' +
			'<iframe data-rc-src="https://www.youtube.com/embed/x" data-rc-category="marketing" class="rc-blocked-embed"></iframe>'
	});
	assert.equal(p.window.__stats, 1);
	assert.equal(p.window.__mkt, 1);
	assert.equal(p.window.__pref, 1);
	const f = p.document.querySelector('iframe');
	assert.equal(f.getAttribute('src'), 'https://www.youtube.com/embed/x');
	assert.equal(h.readConsent(p.window), null, 'no cookie written');
	assert.equal(p.window.__fetches.length, 0, 'no log posted');
	assert.equal(p.document.getElementById('rc-banner').hidden, true);
	assert.equal(p.document.querySelectorAll('.rc-placeholder').length, 0);
	assert.equal(p.window.RevolterCookies.setConsent(['necessary'], 'reject_all'), null, 'API cannot write a cookie in scan mode');
	assert.equal(h.readConsent(p.window), null);
	done(p);
});

test('scan mode none does nothing: no banner, no activation, no placeholders', function () {
	const p = h.page({
		config: { scanMode: 'none' },
		cookie: h.consentCookie(['necessary', 'statistics', 'marketing']),
		body: '<script type="text/plain" data-rc-category="statistics">window.__stats = 1;</script>' +
			'<iframe data-rc-src="https://www.youtube.com/embed/x" data-rc-category="marketing" class="rc-blocked-embed"></iframe>'
	});
	assert.equal(p.window.__stats, undefined);
	assert.equal(p.document.querySelector('iframe').hasAttribute('src'), false);
	assert.equal(p.document.getElementById('rc-banner').hidden, true);
	assert.equal(p.document.getElementById('rc-settings-button').hidden, true);
	assert.equal(p.document.querySelectorAll('.rc-placeholder').length, 0);
	assert.equal(p.window.__fetches.length, 0);
	done(p);
});

test('scan mode from the head global is honoured too', function () {
	const p = h.page({
		before: function (w) {
			w.RevolterCookiesScanMode = 'accepted';
		},
		body: '<script type="text/plain" data-rc-category="marketing">window.__mkt = 1;</script>'
	});
	assert.equal(p.window.__mkt, 1);
	assert.equal(p.document.getElementById('rc-banner').hidden, true);
	done(p);
});
