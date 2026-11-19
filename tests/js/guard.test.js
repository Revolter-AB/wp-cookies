'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const h = require('./helpers/dom.js');

function done(p) {
	assert.deepEqual(p.errors.map(String), []);
	p.window.close();
}

function guardOnly(opts) {
	return h.page(Object.assign({ noConsent: true, noReady: true }, opts || {}));
}

test('guard neuters dynamically appended matching script when not allowed', function () {
	const p = guardOnly();
	const s = p.document.createElement('script');
	s.setAttribute('src', 'https://www.googletagmanager.com/gtag/js?id=G-1');
	p.document.head.appendChild(s);
	assert.equal(s.getAttribute('type'), 'text/plain');
	assert.equal(s.getAttribute('src'), null);
	assert.equal(s.getAttribute('data-rc-src'), 'https://www.googletagmanager.com/gtag/js?id=G-1');
	assert.equal(s.getAttribute('data-rc-category'), 'statistics');
	assert.equal(s.getAttribute('data-rc-label'), 'Google Analytics');
	assert.ok(p.window.RevolterCookiesQueue.indexOf(s) !== -1, 'queued');
	done(p);
});

test('guard lets script through when allowed', function () {
	const p = guardOnly({ cookie: h.consentCookie(['necessary', 'statistics']) });
	const s = p.document.createElement('script');
	s.src = 'https://www.googletagmanager.com/gtag/js?id=G-1';
	p.document.head.appendChild(s);
	assert.equal(s.getAttribute('type'), null);
	assert.equal(s.getAttribute('src'), 'https://www.googletagmanager.com/gtag/js?id=G-1');
	assert.equal(s.hasAttribute('data-rc-src'), false);
	done(p);
});

test('guard ignores scripts that match no rule and necessary rules', function () {
	const p = guardOnly({ rules: h.RULES.concat([{ p: 'cdn.example.test', c: 'necessary', l: 'CDN', k: 'host' }]) });
	const a = p.document.createElement('script');
	a.src = 'https://www.example.test/app.js';
	p.document.body.appendChild(a);
	const b = p.document.createElement('script');
	b.src = 'https://cdn.example.test/lib.js';
	p.document.body.appendChild(b);
	[a, b].forEach(function (s) {
		assert.equal(s.getAttribute('type'), null);
		assert.equal(s.hasAttribute('data-rc-src'), false);
	});
	done(p);
});

test('src property setter on a script is neutered before insertion', function () {
	const p = guardOnly();
	const s = p.document.createElement('script');
	s.src = 'https://connect.facebook.net/en_US/fbevents.js';
	assert.equal(s.getAttribute('src'), null);
	assert.equal(s.getAttribute('type'), 'text/plain');
	assert.equal(s.getAttribute('data-rc-src'), 'https://connect.facebook.net/en_US/fbevents.js');
	assert.equal(s.getAttribute('data-rc-category'), 'marketing');
	p.document.head.insertBefore(s, p.document.head.firstChild);
	assert.equal(s.getAttribute('type'), 'text/plain');
	done(p);
});

test('module script keeps its type for activation', function () {
	const p = guardOnly();
	const s = p.document.createElement('script');
	s.type = 'module';
	s.setAttribute('src', 'https://connect.facebook.net/m.js');
	p.document.body.append(s);
	assert.equal(s.getAttribute('type'), 'text/plain');
	assert.equal(s.getAttribute('data-rc-type'), 'module');
	done(p);
});

test('relative and protocol-relative srcs are resolved before matching', function () {
	const p = guardOnly();
	const s = p.document.createElement('script');
	s.setAttribute('src', '  //www.googletagmanager.com/gtm.js?id=GTM-1  ');
	p.document.body.prepend(s);
	assert.equal(s.getAttribute('data-rc-category'), 'statistics');
	const r = p.document.createElement('script');
	r.setAttribute('src', '/wp-content/themes/x/youtube.com.js');
	p.document.body.appendChild(r);
	assert.equal(r.hasAttribute('data-rc-src'), false, 'host rule does not match a path');
	done(p);
});

test('iframe appended with a tracking src is neutered', function () {
	const p = guardOnly();
	const f = p.document.createElement('iframe');
	f.setAttribute('src', 'https://www.youtube.com/embed/abc');
	const div = p.document.createElement('div');
	p.document.body.appendChild(div);
	div.after(f);
	assert.equal(f.getAttribute('src'), null);
	assert.equal(f.getAttribute('data-rc-src'), 'https://www.youtube.com/embed/abc');
	assert.equal(f.getAttribute('data-rc-category'), 'marketing');
	assert.equal(f.getAttribute('data-rc-label'), 'YouTube');
	assert.ok(f.classList.contains('rc-blocked-embed'));
	done(p);
});

test('lazy src assignment on an attached iframe is caught (property and setAttribute)', function () {
	const p = guardOnly();
	const f = p.document.createElement('iframe');
	f.setAttribute('data-src', 'https://www.youtube.com/embed/abc');
	p.document.body.appendChild(f);
	f.src = f.getAttribute('data-src');
	assert.equal(f.getAttribute('src'), null);
	assert.equal(f.getAttribute('data-rc-src'), 'https://www.youtube.com/embed/abc');
	const g = p.document.createElement('iframe');
	p.document.body.appendChild(g);
	g.setAttribute('SRC', 'https://www.youtube-nocookie.com/embed/x');
	assert.equal(g.getAttribute('src'), null);
	assert.equal(g.getAttribute('data-rc-src'), 'https://www.youtube-nocookie.com/embed/x');
	done(p);
});

test('setAttribute src on other elements is untouched', function () {
	const p = guardOnly();
	const img = p.document.createElement('img');
	img.setAttribute('src', 'https://www.youtube.com/thumb.jpg');
	assert.equal(img.getAttribute('src'), 'https://www.youtube.com/thumb.jpg');
	done(p);
});

test('nested tracking iframe inside an appended fragment is neutered', function () {
	const p = guardOnly();
	const tpl = p.document.createElement('div');
	tpl.innerHTML = '<p><iframe src="https://www.youtube.com/embed/abc"></iframe></p>';
	const frag = p.document.createDocumentFragment();
	frag.appendChild(tpl.firstChild);
	p.document.body.appendChild(frag);
	const f = p.document.querySelector('iframe');
	assert.equal(f.getAttribute('src'), null);
	assert.equal(f.getAttribute('data-rc-src'), 'https://www.youtube.com/embed/abc');
	done(p);
});

test('insertAdjacentElement, replaceWith and before neuter matching embeds synchronously', function () {
	const p = guardOnly();
	const src = 'https://www.youtube.com/embed/abc';
	/* Parsed markup bypasses the src and setAttribute wrappers, so only the insertion wrapper can catch it. */
	const make = function () {
		const tpl = p.document.createElement('div');
		tpl.innerHTML = '<iframe src="' + src + '"></iframe>';
		return tpl.removeChild(tpl.firstChild);
	};
	const anchor = p.document.createElement('div');
	p.document.body.appendChild(anchor);
	const placeholder = p.document.createElement('span');
	p.document.body.appendChild(placeholder);

	const inserted = [];
	let el = make();
	assert.equal(anchor.insertAdjacentElement('afterend', el), el, 'returns the inserted element');
	inserted.push(el);
	el = make();
	placeholder.replaceWith(el);
	inserted.push(el);
	el = make();
	anchor.before(p.document.createTextNode('x'), el);
	inserted.push(el);

	inserted.forEach(function (f, i) {
		assert.equal(f.getAttribute('src'), null, 'case ' + i);
		assert.equal(f.getAttribute('data-rc-src'), src, 'case ' + i);
		assert.ok(f.isConnected, 'case ' + i);
	});
	done(p);
});

test('mutation observer fallback catches innerHTML iframes', async function () {
	const p = guardOnly();
	p.document.body.innerHTML += '<div id="x"><iframe src="https://www.youtube.com/embed/abc"></iframe></div>';
	await h.tick(5);
	const f = p.document.querySelector('#x iframe');
	assert.equal(f.getAttribute('src'), null);
	assert.equal(f.getAttribute('data-rc-src'), 'https://www.youtube.com/embed/abc');
	done(p);
});

test('guard pushes gcm update immediately when the cookie is valid', function () {
	const p = guardOnly({ cookie: h.consentCookie(['necessary', 'statistics']) });
	const ups = h.gcmUpdates(p.window);
	assert.equal(ups.length, 1);
	assert.equal(ups[0][2].analytics_storage, 'granted');
	assert.equal(ups[0][2].ad_storage, 'denied');
	done(p);
});

test('guard pushes no gcm update for a stale or garbage cookie', function () {
	[h.consentCookie(['necessary', 'statistics'], 1), 'rc_consent=%%%'].forEach(function (c) {
		const p = guardOnly({ cookie: c });
		assert.equal(h.gcmUpdates(p.window).length, 0);
		done(p);
	});
});

test('guard does not throw without core or rules', function () {
	const fs = require('node:fs');
	const path = require('node:path');
	const { JSDOM } = require('jsdom');
	const dom = new JSDOM('<!doctype html><html><head></head><body></body></html>', { url: 'https://www.example.test/', runScripts: 'outside-only' });
	dom.window.eval(fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'js', 'guard.js'), 'utf8'));
	const s = dom.window.document.createElement('script');
	s.src = 'https://www.googletagmanager.com/gtag/js';
	dom.window.document.head.appendChild(s);
	assert.equal(s.getAttribute('src'), 'https://www.googletagmanager.com/gtag/js');
	dom.window.close();
});

test('guard source has no closing script tag', function () {
	const src = require('node:fs').readFileSync(require('node:path').join(__dirname, '..', '..', 'assets', 'js', 'guard.js'), 'utf8');
	assert.equal(/<\/script/i.test(src), false);
});

test('front-end scripts stay ES5', function () {
	const fs = require('node:fs');
	const path = require('node:path');
	['guard.js', 'consent.js'].forEach(function (file) {
		const src = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'js', file), 'utf8')
			.replace(/\/\*[\s\S]*?\*\//g, '')
			.replace(/\/\/[^\n]*/g, '');
		[/=>/, /\blet\s/, /\bconst\s/, /`/, /\bclass\s+[A-Za-z_$]/, /\?\./, /\basync\s+function\b/, /\bawait\s/, /\.\.\.[A-Za-z_$]/].forEach(function (re) {
			assert.equal(re.test(src), false, file + ' contains ' + re);
		});
	});
});

test('guard gates every JavaScript MIME type, including parameterised ones', function () {
	const p = guardOnly();
	['text/ecmascript', 'application/ecmascript', 'application/x-javascript', 'text/javascript; charset=utf-8', ' TEXT/JavaScript ;charset=UTF-8'].forEach(function (type) {
		const s = p.document.createElement('script');
		s.setAttribute('type', type);
		s.setAttribute('src', 'https://www.googletagmanager.com/gtag/js?id=G-1');
		p.document.head.appendChild(s);
		assert.equal(s.getAttribute('type'), 'text/plain', type);
		assert.equal(s.getAttribute('src'), null, type);
	});
	const t = p.document.createElement('script');
	t.setAttribute('type', 'text/template');
	t.setAttribute('src', 'https://www.googletagmanager.com/gtag/js?id=G-1');
	p.document.head.appendChild(t);
	assert.equal(t.getAttribute('type'), 'text/template', 'non-JS types untouched');
	done(p);
});

test('scan mode accepted allows every category without a cookie and grants Consent Mode', function () {
	const p = guardOnly({
		before: function (w) {
			w.RevolterCookiesScanMode = 'accepted';
		}
	});
	const s = p.document.createElement('script');
	s.src = 'https://connect.facebook.net/en_US/fbevents.js';
	p.document.head.appendChild(s);
	assert.equal(s.getAttribute('src'), 'https://connect.facebook.net/en_US/fbevents.js');
	assert.equal(s.hasAttribute('data-rc-src'), false);
	const f = p.document.createElement('iframe');
	f.setAttribute('src', 'https://www.youtube.com/embed/x');
	p.document.body.appendChild(f);
	assert.equal(f.hasAttribute('data-rc-src'), false);
	const updates = h.gcmUpdates(p.window);
	assert.equal(updates.length, 1);
	assert.deepEqual(Object.assign({}, updates[0][2]), {
		analytics_storage: 'granted',
		ad_storage: 'granted',
		ad_user_data: 'granted',
		ad_personalization: 'granted',
		functionality_storage: 'granted',
		personalization_storage: 'granted'
	});
	assert.equal(p.document.cookie.indexOf('rc_consent'), -1, 'no cookie written');
	done(p);
});

test('scan mode none blocks every category despite a consent cookie and pushes no Consent Mode update', function () {
	const p = guardOnly({
		cookie: h.consentCookie(['necessary', 'preferences', 'statistics', 'marketing']),
		before: function (w) {
			w.RevolterCookiesScanMode = 'none';
		}
	});
	const s = p.document.createElement('script');
	s.src = 'https://www.googletagmanager.com/gtag/js?id=G-1';
	p.document.head.appendChild(s);
	assert.equal(s.getAttribute('type'), 'text/plain');
	assert.equal(s.getAttribute('data-rc-category'), 'statistics');
	const f = p.document.createElement('iframe');
	f.setAttribute('src', 'https://www.youtube.com/embed/x');
	p.document.body.appendChild(f);
	assert.equal(f.getAttribute('data-rc-category'), 'marketing');
	assert.equal(h.gcmUpdates(p.window).length, 0);
	done(p);
});
