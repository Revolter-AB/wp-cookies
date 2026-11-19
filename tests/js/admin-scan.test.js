'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM, VirtualConsole } = require('jsdom');

const JS = path.join(__dirname, '..', '..', 'assets', 'js');
const ADMIN_SCAN = fs.readFileSync(path.join(JS, 'admin-scan.js'), 'utf8');
const AGENT = fs.readFileSync(path.join(JS, 'scan-agent.js'), 'utf8');

const ORIGIN = 'https://www.example.test';
const REST = ORIGIN + '/wp-json/revolter-cookies/v1/';
const URLS = [ORIGIN + '/', ORIGIN + '/about/'];
const CONSENT_RAW = encodeURIComponent(JSON.stringify({ id: '123e4567-e89b-42d3-a456-426614174000', v: 1, t: 1700000000, c: ['necessary', 'statistics'] }));

const L10N = {
	progress: 'Scanning page %1$d of %2$d (%3$s)',
	phaseNone: 'checking without consent',
	phaseAccepted: 'checking with consent',
	phaseServer: 'checking server cookies',
	finishing: 'Saving the results',
	pageFailed: 'Could not load %s. It is recorded as failed.',
	saveFailed: 'Could not save the result for %s.',
	startFailed: 'The scan could not be started.',
	finishFailed: 'The scan could not be finished.',
	summary: 'Scan complete. Pages scanned: %1$d (failed: %2$d). Cookies declared: %3$d. Needs review: %4$d.',
	allFailed: 'No page could be scanned, so the cookie list was not changed.'
};

const SUMMARY = { pages: 2, failed: 0, all_failed: false, declared: 3, needs_review: 1, leaks: 0 };

function sleep(ms) {
	return new Promise(function (resolve) {
		setTimeout(resolve, ms);
	});
}

/**
 * Admin page with admin-scan.js loaded. Stubs fetch; answers iframes through onFrame(frame, ctx).
 *
 * opts: routes {start, page, server, finish} → function(body, ctx) returning {status, data}; cookies; onFrame.
 */
function admin(opts) {
	opts = opts || {};
	const errors = [];
	const vc = new VirtualConsole();
	vc.on('jsdomError', function (e) {
		errors.push(e);
	});
	const dom = new JSDOM('<!doctype html><html><body><div id="c"></div></body></html>', {
		url: (opts.origin || ORIGIN) + '/wp-admin/admin.php?page=revolter-cookies',
		runScripts: 'dangerously',
		pretendToBeVisual: true,
		virtualConsole: vc
	});
	const w = dom.window;
	const ctx = { w: w, calls: [], frames: [], maxFrames: 0, errors: errors };
	(opts.cookies || []).forEach(function (c) {
		w.document.cookie = c;
	});
	const routes = Object.assign({
		start: function () {
			return { status: 200, data: { token: 'tok123', urls: URLS.slice(0), resumed: false } };
		},
		page: function () {
			return { status: 200, data: { ok: true } };
		},
		server: function () {
			return { status: 200, data: { cookies: [] } };
		},
		finish: function () {
			return { status: 200, data: SUMMARY };
		}
	}, opts.routes || {});
	w.fetch = function (url, init) {
		const route = String(url).substring(REST.length).replace(/^scan\//, '');
		const body = init && init.body ? JSON.parse(init.body) : null;
		ctx.calls.push({ route: route, url: url, body: body, init: init });
		const res = routes[route] ? routes[route](body, ctx) : { status: 404, data: { message: 'nope' } };
		return Promise.resolve({
			ok: res.status >= 200 && res.status < 300,
			status: res.status,
			json: function () {
				return Promise.resolve(res.data);
			}
		});
	};
	const onFrame = opts.onFrame || function (frame) {
		reply(w, frame, { cookies: ['c_' + modeOf(frame)], storage: ['k'], resources: ['https://cdn.other.test/a.js'] });
	};
	const mo = new w.MutationObserver(function (records) {
		records.forEach(function (r) {
			Array.prototype.forEach.call(r.addedNodes, function (n) {
				if (n.tagName === 'IFRAME') {
					ctx.frames.push(n);
					ctx.maxFrames = Math.max(ctx.maxFrames, w.document.querySelectorAll('iframe').length);
					onFrame(n, ctx);
				}
			});
		});
	});
	mo.observe(w.document.documentElement, { childList: true, subtree: true });
	w.eval(ADMIN_SCAN);
	ctx.run = function (extra) {
		return new Promise(function (resolve) {
			w.RevolterCookiesScan.run(Object.assign({
				rest: REST,
				nonce: 'n0nce',
				l10n: L10N,
				container: w.document.getElementById('c'),
				timeout: 80,
				onDone: function (summary) {
					resolve(summary);
				}
			}, extra || {}));
		});
	};
	return ctx;
}

/* Copy out of the jsdom realm so deepEqual compares plain values. */
function plain(value) {
	return JSON.parse(JSON.stringify(value));
}

function modeOf(frame) {
	return new URL(frame.getAttribute('src')).searchParams.get('rc_scan_mode');
}

function listedOf(frame) {
	const u = new URL(frame.getAttribute('src'));
	u.searchParams.delete('rc_scan');
	u.searchParams.delete('rc_scan_mode');
	return u.href;
}

function reply(w, frame, data, extra) {
	setTimeout(function () {
		if (!frame.contentWindow) {
			return;
		}
		const ev = new w.MessageEvent('message', Object.assign({
			data: Object.assign({ type: 'rc-scan-result', url: 'https://ignored.test/', mode: modeOf(frame), cookies: [], storage: [], resources: [] }, data || {}),
			origin: w.location.origin,
			source: frame.contentWindow
		}, extra || {}));
		w.dispatchEvent(ev);
	}, 5);
}

function names(w) {
	return w.document.cookie.split(';').map(function (p) {
		return p.trim().split('=')[0];
	}).filter(Boolean);
}

function pages(ctx) {
	return ctx.calls.filter(function (c) {
		return c.route === 'page';
	}).map(function (c) {
		return c.body;
	});
}

function text(ctx) {
	return ctx.w.document.getElementById('c').textContent;
}

test('withScanParams appends token and mode', function () {
	const S = admin().w.RevolterCookiesScan;
	assert.equal(S.withScanParams('https://a.test/', 't0k', 'none'), 'https://a.test/?rc_scan=t0k&rc_scan_mode=none');
	assert.equal(S.withScanParams('https://a.test/p/?x=1#top', 't0k', 'accepted'), 'https://a.test/p/?x=1&rc_scan=t0k&rc_scan_mode=accepted#top');
	assert.equal(S.withScanParams('https://a.test/?', 'a b', 'none'), 'https://a.test/?rc_scan=a%20b&rc_scan_mode=none');
});

test('thirdPartyHosts excludes site host and www twin', function () {
	const S = admin().w.RevolterCookiesScan;
	const hosts = plain(S.thirdPartyHosts([
		'https://www.example.test/wp-content/a.js',
		'https://example.test/b.js',
		'https://WWW.Example.test:443/c.js',
		'https://www.googletagmanager.com/gtag/js?id=G-1',
		'https://www.googletagmanager.com/gtm.js',
		'//connect.facebook.net/en_US/fbevents.js',
		'/relative.js',
		'data:text/plain,x',
		'',
		null
	], 'www.example.test'));
	assert.deepEqual(hosts, ['www.googletagmanager.com', 'connect.facebook.net']);
	assert.deepEqual(plain(S.thirdPartyHosts(['https://example.test/a', 'https://www.example.test/b'], 'example.test')), []);
});

test('run completes two passes and finish', async function () {
	const ctx = admin();
	const summary = await ctx.run();
	assert.deepEqual(summary, SUMMARY);
	assert.deepEqual(ctx.calls.map(function (c) {
		return c.route;
	}), ['start', 'page', 'page', 'page', 'page', 'server', 'server', 'finish']);
	ctx.calls.forEach(function (c) {
		assert.equal(c.init.method, 'POST');
		assert.equal(c.init.headers['X-WP-Nonce'], 'n0nce');
		assert.equal(c.init.credentials, 'same-origin');
	});
	const p = pages(ctx);
	assert.deepEqual(p.map(function (b) {
		return [b.url, b.mode, b.status];
	}), [[URLS[0], 'none', 'ok'], [URLS[1], 'none', 'ok'], [URLS[0], 'accepted', 'ok'], [URLS[1], 'accepted', 'ok']]);
	assert.deepEqual(p[0].cookies, ['c_none']);
	assert.deepEqual(p[2].cookies, ['c_accepted']);
	assert.deepEqual(p[0].storage, ['k']);
	assert.deepEqual(p[0].resources, ['https://cdn.other.test/a.js']);
	assert.deepEqual(ctx.calls.filter(function (c) {
		return c.route === 'server';
	}).map(function (c) {
		return c.body.url;
	}), URLS);

	assert.equal(ctx.frames.length, 4);
	assert.equal(ctx.maxFrames, 1, 'one page at a time');
	assert.equal(listedOf(ctx.frames[0]), URLS[0]);
	assert.equal(ctx.frames[0].getAttribute('src'), URLS[0] + '?rc_scan=tok123&rc_scan_mode=none');
	ctx.frames.forEach(function (f) {
		assert.equal(f.getAttribute('referrerpolicy'), 'no-referrer');
		assert.equal(f.hasAttribute('sandbox'), false);
		assert.equal(f.style.width, '1280px');
		assert.equal(f.style.height, '800px');
		assert.equal(f.style.position, 'absolute');
		assert.equal(f.style.left, '-10000px');
	});
	assert.equal(ctx.w.document.querySelectorAll('iframe').length, 0, 'iframes removed');
	assert.ok(ctx.w.document.querySelector('#c progress'), 'progress element');
	assert.match(text(ctx), /Scan complete\. Pages scanned: 2 \(failed: 0\)\. Cookies declared: 3\. Needs review: 1\./);
	assert.deepEqual(ctx.errors.map(String), []);
});

test('typed resources from the agent are forwarded as {url, type}; junk is dropped', async function () {
	const ctx = admin({
		onFrame: function (frame) {
			reply(ctx.w, frame, { resources: [{ url: 'https://cdn.other.test/a.js', type: 'script' }, { url: 'https://secure.gravatar.com/x', type: 'img' }, 'https://legacy.test/b.js', { type: 'script' }, 7, null] });
		}
	});
	await ctx.run();
	assert.deepEqual(plain(pages(ctx)[0].resources), [
		{ url: 'https://cdn.other.test/a.js', type: 'script' },
		{ url: 'https://secure.gravatar.com/x', type: 'img' },
		'https://legacy.test/b.js'
	]);
});

test('progress shows page, total and phase', async function () {
	const seen = [];
	const ctx = admin({
		onFrame: function (frame, c) {
			const prog = c.w.document.querySelector('#c progress');
			seen.push([text(c).match(/Scanning page \d+ of \d+ \([a-z ]+\)/)[0], prog.value, prog.max]);
			reply(c.w, frame, {});
		}
	});
	await ctx.run();
	assert.deepEqual(seen, [
		['Scanning page 1 of 2 (checking without consent)', 0, 4],
		['Scanning page 2 of 2 (checking without consent)', 1, 4],
		['Scanning page 1 of 2 (checking with consent)', 2, 4],
		['Scanning page 2 of 2 (checking with consent)', 3, 4]
	]);
});

test('timeout marks page failed and continues', async function () {
	const ctx = admin({
		onFrame: function (frame, c) {
			if (listedOf(frame) === URLS[0] && modeOf(frame) === 'none') {
				return; // never answers
			}
			reply(c.w, frame, { cookies: ['x'] });
		}
	});
	const summary = await ctx.run();
	assert.deepEqual(summary, SUMMARY);
	const p = pages(ctx);
	assert.equal(p.length, 4);
	assert.deepEqual(p[0], { url: URLS[0], mode: 'none', status: 'failed' });
	assert.equal(p[1].status, 'ok');
	assert.equal(p[2].status, 'ok');
	assert.equal(ctx.calls[ctx.calls.length - 1].route, 'finish');
	assert.match(text(ctx), /Could not load https:\/\/www\.example\.test\/\. It is recorded as failed\./);
	assert.equal(ctx.w.document.querySelectorAll('iframe').length, 0);
});

test('iframe error and mismatched or foreign messages mark page failed', async function () {
	const ctx = admin({
		onFrame: function (frame, c) {
			const url = listedOf(frame);
			const mode = modeOf(frame);
			if (url === URLS[0] && mode === 'none') {
				setTimeout(function () {
					frame.dispatchEvent(new c.w.Event('error'));
				}, 5);
			} else if (url === URLS[1] && mode === 'none') {
				reply(c.w, frame, { cookies: ['wrong-origin'] }, { origin: 'https://evil.test' });
				reply(c.w, frame, { cookies: ['wrong-source'] }, { source: null });
				reply(c.w, frame, { type: 'other', cookies: ['wrong-type'] });
			} else if (url === URLS[0] && mode === 'accepted') {
				reply(c.w, frame, { mode: 'none', cookies: ['wrong-mode'] });
			} else {
				reply(c.w, frame, { cookies: ['good'] });
			}
		}
	});
	await ctx.run();
	assert.deepEqual(pages(ctx).map(function (b) {
		return [b.status, b.cookies];
	}), [['failed', undefined], ['failed', undefined], ['failed', undefined], ['ok', ['good']]]);
	assert.equal(ctx.calls[ctx.calls.length - 1].route, 'finish');
});

test('REST error for one page is logged and the run continues', async function () {
	let n = 0;
	const ctx = admin({
		routes: {
			page: function () {
				n++;
				return n === 1 ? { status: 400, data: { message: 'URL is not part of this scan.' } } : { status: 200, data: { ok: true } };
			},
			server: function () {
				throw new Error('network down');
			}
		}
	});
	const summary = await ctx.run();
	assert.deepEqual(summary, SUMMARY);
	assert.equal(pages(ctx).length, 4);
	assert.equal(ctx.calls[ctx.calls.length - 1].route, 'finish');
	assert.match(text(ctx), /Could not save the result for https:\/\/www\.example\.test\/\./);
});

test('start failure stops with a visible error', async function () {
	const ctx = admin({
		cookies: ['rc_consent=' + CONSENT_RAW + '; path=/', '_ga=GA1; path=/'],
		routes: {
			start: function () {
				return { status: 403, data: { message: 'Sorry, you are not allowed to do that.' } };
			}
		}
	});
	const summary = await ctx.run();
	assert.equal(summary, null);
	assert.equal(ctx.frames.length, 0);
	assert.deepEqual(ctx.calls.map(function (c) {
		return c.route;
	}), ['start']);
	assert.match(text(ctx), /The scan could not be started\. Sorry, you are not allowed to do that\./);
	assert.ok(names(ctx.w).indexOf('_ga') !== -1, 'nothing touched before start succeeded');
	assert.match(ctx.w.document.cookie, new RegExp('rc_consent=' + CONSENT_RAW.replace(/[%.]/g, '\\$&')));
});

test('finish failure stops with a visible error and still restores rc_consent', async function () {
	const ctx = admin({
		cookies: ['rc_consent=' + CONSENT_RAW + '; path=/'],
		routes: {
			finish: function () {
				return { status: 409, data: { message: 'No scan in progress.' } };
			}
		},
		onFrame: function (frame, c) {
			c.w.document.cookie = 'rc_consent=tampered; path=/';
			reply(c.w, frame, {});
		}
	});
	const summary = await ctx.run();
	assert.equal(summary, null);
	assert.match(text(ctx), /The scan could not be finished\. No scan in progress\./);
	assert.doesNotMatch(text(ctx), /Scan complete/);
	assert.ok(ctx.w.document.cookie.indexOf('rc_consent=' + CONSENT_RAW) !== -1);
});

test('restores rc_consent after run', async function () {
	const seen = {};
	const ctx = admin({
		cookies: ['rc_consent=' + CONSENT_RAW + '; path=/', '_ga=GA1; path=/', 'wp-settings-1=x; path=/', 'wordpress_test_cookie=WP; path=/'],
		onFrame: function (frame, c) {
			const mode = modeOf(frame);
			seen[mode + ':' + listedOf(frame)] = names(c.w);
			if (mode === 'none') {
				c.w.document.cookie = 'set_in_none=1; path=/';
			} else {
				c.w.document.cookie = 'set_in_accepted=1; path=/';
				c.w.document.cookie = 'rc_consent=changed; path=/';
			}
			reply(c.w, frame, {});
		}
	});
	await ctx.run();
	const before = seen['none:' + URLS[0]];
	assert.equal(before.indexOf('_ga'), -1, 'site cookies cleared before the run');
	assert.ok(before.indexOf('wp-settings-1') !== -1);
	assert.ok(before.indexOf('wordpress_test_cookie') !== -1);
	assert.ok(before.indexOf('rc_consent') !== -1);
	assert.equal(seen['accepted:' + URLS[0]].indexOf('set_in_none'), -1, 'none-pass cookies cleared between passes');
	const after = names(ctx.w);
	assert.ok(ctx.w.document.cookie.indexOf('rc_consent=' + CONSENT_RAW) !== -1, 'restored exactly');
	assert.equal(after.indexOf('set_in_none'), -1);
	assert.equal(after.indexOf('set_in_accepted'), -1);
	assert.ok(after.indexOf('wp-settings-1') !== -1);
});

test('rc_consent set during the run is removed when there was none before', async function () {
	const ctx = admin({
		onFrame: function (frame, c) {
			c.w.document.cookie = 'rc_consent=sneaky; path=/';
			reply(c.w, frame, {});
		}
	});
	await ctx.run();
	assert.equal(names(ctx.w).indexOf('rc_consent'), -1);
});

test('resume skips completed urls', async function () {
	const third = ORIGIN + '/contact/';
	const ctx = admin({
		routes: {
			start: function () {
				return { status: 200, data: { token: 'tok123', urls: [URLS[0], URLS[1], third], resumed: true, done: [URLS[0]] } };
			}
		}
	});
	await ctx.run();
	const p = pages(ctx);
	assert.deepEqual(p.map(function (b) {
		return b.url + ' ' + b.mode;
	}), [URLS[1] + ' none', third + ' none', URLS[1] + ' accepted', third + ' accepted']);
	assert.deepEqual(ctx.calls.filter(function (c) {
		return c.route === 'server';
	}).map(function (c) {
		return c.body.url;
	}), [URLS[0], URLS[1], third], 'server step covers done urls too');
});

test('resume still runs server step for every url', async function () {
	const ctx = admin({
		routes: {
			start: function () {
				return { status: 200, data: { token: 'tok123', urls: URLS.slice(0), resumed: true, done: URLS.slice(0) } };
			}
		}
	});
	const summary = await ctx.run();
	assert.deepEqual(summary, SUMMARY);
	assert.equal(pages(ctx).length, 0, 'no page reloaded');
	assert.equal(ctx.frames.length, 0);
	assert.deepEqual(ctx.calls.map(function (c) {
		return c.route + (c.body && c.body.url ? ' ' + c.body.url : '');
	}), ['start', 'server ' + URLS[0], 'server ' + URLS[1], 'finish']);
});

test('cookies are cleared before each page so a leak is attributed only to its page', async function () {
	const ctx = admin({
		onFrame: function (frame, c) {
			if (modeOf(frame) === 'none' && listedOf(frame) === URLS[0]) {
				c.w.document.cookie = 'leak1=1; path=/';
			}
			reply(c.w, frame, { cookies: names(c.w) });
		}
	});
	await ctx.run();
	const p = pages(ctx);
	assert.ok(p[0].cookies.indexOf('leak1') !== -1, 'page 1 reports its own cookie');
	assert.equal(p[1].cookies.indexOf('leak1'), -1, 'page 2 does not inherit it');
	assert.equal(p[2].cookies.indexOf('leak1'), -1);
	assert.equal(p[3].cookies.indexOf('leak1'), -1);
});

test('clearing expires cookies on every parent domain (multi-label public suffix)', async function () {
	const seen = [];
	const ctx = admin({
		origin: 'https://a.b.example.co.uk',
		onFrame: function (frame, c) {
			seen.push(names(c.w));
			reply(c.w, frame, {});
		}
	});
	ctx.w.document.cookie = '_ga=GA1; domain=.example.co.uk; path=/';
	ctx.w.document.cookie = '_mid=1; domain=.b.example.co.uk; path=/';
	ctx.w.document.cookie = '_host=1; path=/';
	ctx.w.document.cookie = 'wp-settings-1=x; domain=.example.co.uk; path=/';
	const before = names(ctx.w);
	['_ga', '_mid', '_host', 'wp-settings-1'].forEach(function (n) {
		assert.ok(before.indexOf(n) !== -1, n + ' set');
	});
	await ctx.run();
	assert.deepEqual(plain(seen[0]), ['wp-settings-1']);
	assert.deepEqual(plain(names(ctx.w)), ['wp-settings-1']);
});

test('a fresh start ignores done', async function () {
	const ctx = admin({
		routes: {
			start: function () {
				return { status: 200, data: { token: 'tok123', urls: URLS.slice(0), resumed: false, done: [URLS[0]] } };
			}
		}
	});
	await ctx.run();
	assert.equal(pages(ctx).length, 4);
});

test('all_failed shows the failure message instead of a summary', async function () {
	const failed = { pages: 2, failed: 2, all_failed: true, declared: 0, needs_review: 0, leaks: 0 };
	const ctx = admin({
		routes: {
			finish: function () {
				return { status: 200, data: failed };
			}
		},
		onFrame: function () {}
	});
	const summary = await ctx.run();
	assert.deepEqual(summary, failed);
	assert.match(text(ctx), /No page could be scanned, so the cookie list was not changed\./);
	assert.doesNotMatch(text(ctx), /Scan complete/);
});

test('without onDone the page reloads after the delay, but not after a total failure', async function () {
	const ok = admin();
	let reloads = 0;
	ok.w.RevolterCookiesScan.run({
		rest: REST, nonce: 'n', l10n: L10N, container: ok.w.document.getElementById('c'), timeout: 80, reloadDelay: 20,
		reload: function () {
			reloads++;
		}
	});
	await sleep(400);
	assert.equal(reloads, 1);

	const bad = admin({
		routes: {
			finish: function () {
				return { status: 200, data: { pages: 2, failed: 2, all_failed: true, declared: 0, needs_review: 0, leaks: 0 } };
			}
		}
	});
	let badReloads = 0;
	bad.w.RevolterCookiesScan.run({
		rest: REST, nonce: 'n', l10n: L10N, container: bad.w.document.getElementById('c'), timeout: 80, reloadDelay: 20,
		reload: function () {
			badReloads++;
		}
	});
	await sleep(400);
	assert.equal(badReloads, 0);
});

test('a second run call while running is ignored', async function () {
	const ctx = admin();
	const first = ctx.run();
	const second = ctx.w.RevolterCookiesScan.run({ rest: REST, nonce: 'n', l10n: L10N, container: ctx.w.document.getElementById('c') });
	assert.ok(second && typeof second.then === 'function', 'always returns a promise');
	assert.equal(await second, null);
	await first;
	assert.equal(ctx.calls.filter(function (c) {
		return c.route === 'start';
	}).length, 1);
});

test('run falls back to the localized object', async function () {
	const ctx = admin();
	ctx.w.RevolterCookiesScanL10n = { rest: REST, nonce: 'loc', strings: L10N };
	await new Promise(function (resolve) {
		ctx.w.RevolterCookiesScan.run({ container: ctx.w.document.getElementById('c'), timeout: 80, onDone: resolve });
	});
	assert.equal(ctx.calls[0].init.headers['X-WP-Nonce'], 'loc');
	assert.match(text(ctx), /Scan complete/);
});

/* ---------- scan agent ---------- */

function agentPage(opts) {
	opts = opts || {};
	const errors = [];
	const vc = new VirtualConsole();
	vc.on('jsdomError', function (e) {
		errors.push(e);
	});
	const dom = new JSDOM('<!doctype html><html><head><script src="https://www.googletagmanager.com/gtag/js?id=G-1"></script></head><body>' +
		'<iframe src="https://www.youtube.com/embed/x"></iframe>' +
		'<script type="text/plain" data-rc-src="https://connect.facebook.net/en_US/fbevents.js" data-rc-category="marketing"></script>' +
		'<iframe data-rc-src="/local/embed" data-rc-category="marketing"></iframe>' +
		'</body></html>', {
		url: opts.url || ORIGIN + '/about/?x=1&rc_scan=tok123&rc_scan_mode=none#frag',
		runScripts: 'dangerously',
		pretendToBeVisual: true,
		virtualConsole: vc
	});
	const w = dom.window;
	const posted = [];
	if (!opts.top) {
		Object.defineProperty(w, 'parent', {
			configurable: true,
			value: {
				postMessage: function (data, origin) {
					posted.push({ data: data, origin: origin });
				}
			}
		});
	}
	w.document.cookie = 'a=1; path=/';
	w.document.cookie = 'b=2; path=/';
	w.localStorage.setItem('ls1', 'x');
	w.sessionStorage.setItem('ss1', 'y');
	if (opts.mode !== undefined) {
		w.eval('window.RevolterCookiesScanMode = ' + JSON.stringify(opts.mode) + ';');
	}
	if (opts.config) {
		w.eval('window.RevolterCookiesConfig = ' + JSON.stringify(opts.config) + ';');
	}
	if (opts.perf) {
		w.performance.getEntriesByType = function (type) {
			return type === 'resource' ? [
				{ name: 'https://cdn.other.test/lib.js', initiatorType: 'script' },
				{ name: ORIGIN + '/wp-content/x.css', initiatorType: 'link' },
				{ name: 'https://secure.gravatar.com/avatar/a', initiatorType: 'img' },
				{ name: 'https://frames.other.test/f', initiatorType: 'subdocument' },
				{ name: 'https://www.youtube.com/embed/x', initiatorType: 'other' }
			] : [];
		};
	}
	w.eval('window.RevolterCookiesScanAgentDelay = 20;');
	w.eval(AGENT);
	return { w: w, posted: posted, errors: errors };
}

test('scan agent strips scan params at once and posts a report after load and the delay', async function () {
	const a = agentPage({ mode: 'none', perf: true });
	assert.equal(a.w.location.href, ORIGIN + '/about/?x=1#frag', 'scan params stripped before the wait');
	a.w.dispatchEvent(new a.w.Event('load'));
	assert.equal(a.posted.length, 0, 'waits after load');
	await sleep(60);
	assert.equal(a.posted.length, 1);
	const m = plain(a.posted[0]);
	assert.equal(m.origin, ORIGIN);
	assert.equal(m.data.type, 'rc-scan-result');
	assert.equal(m.data.mode, 'none');
	assert.equal(m.data.url, ORIGIN + '/about/?x=1#frag');
	assert.deepEqual(m.data.cookies, ['a', 'b']);
	assert.deepEqual(m.data.storage, ['ls1', 'ss1']);
	assert.deepEqual(m.data.resources.map(function (r) {
		return r.url + ' ' + r.type;
	}).sort(), [
		'https://cdn.other.test/lib.js script',
		'https://connect.facebook.net/en_US/fbevents.js script',
		'https://frames.other.test/f iframe',
		'https://secure.gravatar.com/avatar/a img',
		'https://www.example.test/local/embed iframe',
		'https://www.example.test/wp-content/x.css link',
		'https://www.googletagmanager.com/gtag/js?id=G-1 script',
		'https://www.youtube.com/embed/x iframe'
	], 'DOM scripts and iframes keep their kind; Resource Timing entries use initiatorType');
	await sleep(60);
	assert.equal(a.posted.length, 1, 'reports once');
	assert.deepEqual(a.errors.map(String), []);
});

test('scan agent reads the mode from the config and does nothing outside scan mode or a frame', async function () {
	const fromCfg = agentPage({ config: { scanMode: 'accepted' } });
	fromCfg.w.dispatchEvent(new fromCfg.w.Event('load'));
	await sleep(60);
	assert.equal(fromCfg.posted.length, 1);
	assert.equal(fromCfg.posted[0].data.mode, 'accepted');

	const off = agentPage({ url: ORIGIN + '/about/' });
	off.w.dispatchEvent(new off.w.Event('load'));
	await sleep(60);
	assert.equal(off.posted.length, 0);

	const bogus = agentPage({ mode: 'everything' });
	bogus.w.dispatchEvent(new bogus.w.Event('load'));
	await sleep(60);
	assert.equal(bogus.posted.length, 0);

	const top = agentPage({ mode: 'none', top: true });
	top.w.dispatchEvent(new top.w.Event('load'));
	await sleep(60);
	assert.deepEqual(top.errors.map(String), []);
});

test('scan agent survives broken storage and performance', async function () {
	const a = agentPage({ mode: 'none' });
	a.w.eval('Object.defineProperty(window, "localStorage", { get: function () { throw new Error("denied"); } });');
	a.w.performance.getEntriesByType = function () {
		throw new Error('nope');
	};
	a.w.dispatchEvent(new a.w.Event('load'));
	await sleep(60);
	assert.equal(a.posted.length, 1);
	assert.deepEqual(plain(a.posted[0].data.storage), ['ss1']);
	assert.deepEqual(a.errors.map(String), []);
});
