'use strict';
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM, VirtualConsole } = require('jsdom');

const ROOT = path.join(__dirname, '..', '..', '..');
const read = function (file) {
	return fs.readFileSync(path.join(ROOT, 'assets', 'js', file), 'utf8');
};
const CORE = read('consent-core.js');
const GUARD = read('guard.js');
const CONSENT = read('consent.js');

const ID = '123e4567-e89b-42d3-a456-426614174000';
const VERSION = 2;

const RULES = [
	{ p: 'googletagmanager.com', c: 'statistics', l: 'Google Analytics', k: 'host' },
	{ p: 'youtube.com', c: 'marketing', l: 'YouTube', k: 'host' },
	{ p: 'youtube-nocookie.com', c: 'marketing', l: 'YouTube', k: 'host' },
	{ p: 'connect.facebook.net', c: 'marketing', l: 'Meta Pixel', k: 'host' }
];

const CONSENT_DEFAULT = "window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}\n" +
	"gtag('consent','default',{ad_storage:'denied',analytics_storage:'denied'});";

function category(key) {
	return '<div class="rc-cat"><label for="rc-cat-' + key + '">' + key + '</label>' +
		'<input type="checkbox" id="rc-cat-' + key + '" class="rc-switch-input" data-rc-category="' + key + '"' +
		(key === 'necessary' ? ' checked disabled' : '') + '></div>';
}

/* Mirrors the markup of templates/banner.php. */
const BANNER = '<div id="rc-root" class="rc-root">' +
	'<div id="rc-banner" class="rc-banner" role="region" hidden><div class="rc-card">' +
	'<button type="button" data-rc-action="accept-all">Accept all</button>' +
	'<button type="button" data-rc-action="reject-all">Reject all</button>' +
	'<button type="button" data-rc-action="customize">Customise</button>' +
	'</div></div>' +
	'<div id="rc-modal" class="rc-modal" role="dialog" aria-modal="true" hidden><div class="rc-dialog">' +
	'<button type="button" class="rc-close" data-rc-action="close">x</button>' +
	category('necessary') + category('preferences') + category('statistics') + category('marketing') +
	'<button type="button" data-rc-action="save">Save choices</button>' +
	'<button type="button" data-rc-action="reject-all">Reject all</button>' +
	'<button type="button" data-rc-action="accept-all">Accept all</button>' +
	'</div></div>' +
	'<button type="button" id="rc-settings-button" class="rc-settings-button" hidden>s</button>' +
	'</div>';

function config(overrides) {
	const placeholder = {};
	['preferences', 'statistics', 'marketing'].forEach(function (cat) {
		placeholder[cat] = {
			text: 'This content comes from {service}, which uses cookies for ' + cat + '. Accept ' + cat + ' cookies to show it.',
			accept: 'Accept and show',
			open: 'Open on {service}'
		};
	});
	return Object.assign({
		version: VERSION,
		rest: 'https://example.test/wp-json/revolter-cookies/v1/consent',
		policyUrl: '',
		lang: 'en',
		categories: [
			{ key: 'necessary', count: 2 },
			{ key: 'preferences', count: 1 },
			{ key: 'statistics', count: 3 },
			{ key: 'marketing', count: 4 }
		],
		cookieNames: { statistics: ['_ga', '_gid', '_gcl_'], marketing: ['_fbp'] },
		strings: {
			placeholder: placeholder,
			consentBox: {
				title: 'Your consent',
				accepted: 'You have accepted: %1$s. Given %2$s · ID %3$s',
				none: 'You have not made a choice yet.',
				withdraw: 'Withdraw',
				change: 'Change settings',
				table_link: 'Change cookie settings'
			},
			categoryLabels: { necessary: 'Necessary', preferences: 'Preferences', statistics: 'Statistics', marketing: 'Marketing', unclassified: 'Unclassified' }
		},
		position: 'left',
		wpConsentApi: false
	}, overrides || {});
}

function consentCookie(categories, version, t, id) {
	return 'rc_consent=' + encodeURIComponent(JSON.stringify({
		id: id || ID,
		v: typeof version === 'number' ? version : VERSION,
		t: typeof t === 'number' ? t : Math.floor(Date.now() / 1000),
		c: categories
	}));
}

/**
 * Build a page: head script (core, rules, consent default, guard), body markup, config and consent.js.
 *
 * opts: body (html), cookie (string or array), config (overrides), noConsent (skip consent.js),
 * before(window) hook before scripts run.
 */
function page(opts) {
	opts = opts || {};
	const errors = [];
	const navigations = [];
	const vc = new VirtualConsole();
	vc.on('jsdomError', function (e) {
		if (/navigation/i.test(String(e && e.message))) {
			navigations.push(e.message);
			return;
		}
		errors.push(e);
	});
	vc.on('error', function (e) {
		errors.push(e);
	});
	const dom = new JSDOM('<!doctype html><html><head></head><body>' + (opts.body || '') + BANNER + '</body></html>', {
		url: 'https://www.example.test/page/',
		runScripts: 'dangerously',
		pretendToBeVisual: true,
		virtualConsole: vc
	});
	const w = dom.window;
	const cookies = [].concat(opts.cookie || []);
	cookies.forEach(function (c) {
		w.document.cookie = c + '; path=/';
	});
	w.__fetches = [];
	w.fetch = function (url, init) {
		w.__fetches.push({ url: url, init: init });
		return Promise.resolve({ ok: true });
	};
	if (opts.before) {
		opts.before(w);
	}
	w.eval(CORE);
	w.eval('window.RevolterCookiesRules = ' + JSON.stringify(opts.rules || RULES) + ';');
	w.eval('window.RevolterCookiesVersion = ' + (opts.config && typeof opts.config.version === 'number' ? opts.config.version : VERSION) + ';');
	w.eval(CONSENT_DEFAULT);
	w.eval(GUARD);
	if (!opts.noConsent) {
		w.eval('window.RevolterCookiesConfig = ' + JSON.stringify(config(opts.config)) + ';');
		w.eval(CONSENT);
	}
	if (!opts.noReady) {
		w.document.dispatchEvent(new w.Event('DOMContentLoaded'));
	}
	return { dom: dom, window: w, document: w.document, errors: errors, navigations: navigations };
}

function click(w, el) {
	el.dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true }));
}

function key(w, el, k, shift) {
	const ev = new w.KeyboardEvent('keydown', { key: k, shiftKey: !!shift, bubbles: true, cancelable: true });
	el.dispatchEvent(ev);
	return ev;
}

function readConsent(w) {
	const m = /(?:^|;\s*)rc_consent=([^;]*)/.exec(w.document.cookie);
	return m ? JSON.parse(decodeURIComponent(m[1])) : null;
}

function cookieNames(w) {
	return w.document.cookie.split(';').map(function (p) {
		return p.trim().split('=')[0];
	}).filter(Boolean);
}

function gcmUpdates(w) {
	return (w.dataLayer || []).map(function (a) {
		return Array.prototype.slice.call(a);
	}).filter(function (a) {
		return a[0] === 'consent' && a[1] === 'update';
	});
}

function tick(ms) {
	return new Promise(function (resolve) {
		setTimeout(resolve, ms || 0);
	});
}

module.exports = { page, click, key, readConsent, cookieNames, gcmUpdates, tick, consentCookie, config, ID, VERSION, RULES };
