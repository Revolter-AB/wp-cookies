'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const core = require('../../assets/js/consent-core.js');

const ID = '123e4567-e89b-42d3-a456-426614174000';
const NOW = 1800000000;

test('parse round-trips serialize', function () {
	const c = core.create(['preferences', 'marketing'], 3, NOW, ID);
	assert.deepEqual(c, { id: ID, v: 3, t: NOW, c: ['necessary', 'preferences', 'marketing'] });
	assert.deepEqual(core.parse(core.serialize(c)), c);
});

test('parse returns null for garbage', function () {
	['%%%', 'null', '[]', '{"id":1}', 'x'.repeat(5000), '', undefined, 42].forEach(function (raw) {
		assert.equal(core.parse(raw), null, String(raw).slice(0, 20));
	});
	const bad = [
		{ id: ID, v: 1.5, t: NOW, c: ['necessary'] },
		{ id: ID, v: 1, t: 'x', c: ['necessary'] },
		{ id: ID, v: 1, t: NOW, c: 'necessary' },
		{ id: ID, v: 1, t: NOW, c: [1, 2] },
		{ id: 'not-a-uuid', v: 1, t: NOW, c: ['necessary'] },
		{ id: ID.toUpperCase(), v: 1, t: NOW, c: ['necessary'] }
	];
	bad.forEach(function (o) {
		assert.equal(core.parse(encodeURIComponent(JSON.stringify(o))), null, JSON.stringify(o));
	});
	const ok = core.parse(encodeURIComponent(JSON.stringify({ id: ID, v: 1, t: NOW, c: ['marketing', 'bogus', 'marketing'] })));
	assert.deepEqual(ok.c, ['necessary', 'marketing']);
});

test('isValid false when version bumped', function () {
	const c = core.create([], 1, NOW, ID);
	assert.equal(core.isValid(c, 1, NOW), true);
	assert.equal(core.isValid(c, 2, NOW), false);
	assert.equal(core.isValid(null, 1, NOW), false);
});

test('isValid false after 366 days', function () {
	const c = core.create([], 1, NOW, ID);
	assert.equal(core.isValid(c, 1, NOW + 365 * 86400), true);
	assert.equal(core.isValid(c, 1, NOW + 366 * 86400), false);
});

test('allowed necessary always', function () {
	assert.equal(core.allowed(null, 'necessary'), true);
	assert.equal(core.allowed(null, 'marketing'), false);
	assert.equal(core.allowed(core.create(['marketing'], 1, NOW, ID), 'marketing'), true);
	assert.equal(core.allowed(core.create(['marketing'], 1, NOW, ID), 'statistics'), false);
});

test('gcmState maps statistics only', function () {
	const s = core.gcmState(['necessary', 'statistics']);
	assert.equal(s.analytics_storage, 'granted');
	['ad_storage', 'ad_user_data', 'ad_personalization', 'functionality_storage', 'personalization_storage'].forEach(function (k) {
		assert.equal(s[k], 'denied', k);
	});
	const m = core.gcmState(['marketing', 'preferences']);
	assert.equal(m.ad_storage, 'granted');
	assert.equal(m.ad_user_data, 'granted');
	assert.equal(m.ad_personalization, 'granted');
	assert.equal(m.functionality_storage, 'granted');
	assert.equal(m.personalization_storage, 'granted');
	assert.equal(m.analytics_storage, 'denied');
});

test('matchRule case-insensitive', function () {
	const rules = [{ p: 'Google-Analytics.com', c: 'statistics', l: 'GA' }];
	assert.equal(core.matchRule(rules, 'https://WWW.google-analytics.com/analytics.js'), rules[0]);
	assert.equal(core.matchRule(rules, 'https://example.com/'), null);
	assert.equal(core.matchRule(null, 'x'), null);
});

test('matchRule host rule needs host boundary', function () {
	const rules = [{ p: 'facebook.com', c: 'marketing', l: 'FB', k: 'host' }];
	assert.equal(core.matchRule(rules, 'https://notfacebook.com/x'), null);
	assert.equal(core.matchRule(rules, 'https://www.facebook.com/x'), rules[0]);
	assert.equal(core.matchRule(rules, 'https://FACEBOOK.com/x'), rules[0]);
	assert.equal(core.matchRule(rules, 'https://example.com/facebook.com'), null);
	assert.equal(core.matchRule(rules, '/relative/facebook.com'), null);
	assert.equal(core.matchRule(rules, '//www.facebook.com/x'), rules[0]);
	assert.equal(core.matchRule(rules, 'http://[bad'), null);
});

test('matchRule sub rule strips scheme and matches substring', function () {
	const rules = [{ p: 'connect.facebook.net', c: 'marketing', l: 'FB', k: 'sub' }, { p: '/gtag/js', c: 'statistics', l: 'G' }];
	assert.equal(core.matchRule(rules, 'https://connect.facebook.net/en/fbevents.js'), rules[0]);
	assert.equal(core.matchRule(rules, '//cdn.x.com/gtag/js?id=1'), rules[1]);
	assert.equal(core.matchRule(rules, 'https://https.example.com/'), null);
});

test('matchRule longest pattern wins', function () {
	const rules = [
		{ p: 'google.com', c: 'marketing', l: 'short', k: 'host' },
		{ p: 'maps.google.com', c: 'preferences', l: 'long', k: 'host' }
	];
	assert.equal(core.matchRule(rules, 'https://maps.google.com/x').l, 'long');
	assert.equal(core.matchRule(rules, 'https://www.google.com/x').l, 'short');
});

test('uuid is a valid v4 and parse accepts it', function () {
	const u = core.uuid();
	assert.match(u, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
	assert.notEqual(u, core.uuid());
	assert.ok(core.parse(core.serialize(core.create([], 1, NOW))));
});

test('cookie read/write via document object', function () {
	const doc = { cookie: '' };
	const c = core.create(['statistics'], 1, NOW, ID);
	core.writeCookie(c, doc, true);
	assert.match(doc.cookie, /^rc_consent=.+; path=\/; max-age=31536000; SameSite=Lax; Secure$/);
	core.writeCookie(c, doc, false);
	assert.ok(!/Secure/.test(doc.cookie));
	const jar = { cookie: 'a=b; rc_consent=' + core.serialize(c) + '; z=1' };
	assert.deepEqual(core.readCookie(jar), c);
	assert.equal(core.readCookie({ cookie: 'rc_consent=%%%' }), null);
	assert.equal(core.readCookie({}), null);
});
