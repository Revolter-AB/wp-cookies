(function (root, factory) {
	var api = factory(root);
	if (typeof module === 'object' && module && module.exports) {
		module.exports = api;
	}
	if (root && typeof root === 'object') {
		root.RevolterCookiesCore = api;
	}
}(typeof window !== 'undefined' ? window : this, function (root) {
	'use strict';

	var COOKIE_NAME = 'rc_consent';
	var CATEGORIES = ['necessary', 'preferences', 'statistics', 'marketing'];
	var MAX_LENGTH = 1024;
	var MAX_AGE = 365 * 86400;
	var ID_RE = /^[0-9a-f-]{36}$/;

	function isInt(n) {
		return typeof n === 'number' && isFinite(n) && Math.floor(n) === n;
	}

	function normalize(list) {
		var out = ['necessary'];
		var i;
		if (!list || typeof list.length !== 'number') {
			return out;
		}
		for (i = 0; i < list.length; i++) {
			if (typeof list[i] === 'string' && CATEGORIES.indexOf(list[i]) !== -1 && out.indexOf(list[i]) === -1) {
				out.push(list[i]);
			}
		}
		return out;
	}

	function parse(raw) {
		try {
			if (typeof raw !== 'string' || raw === '' || raw.length > MAX_LENGTH) {
				return null;
			}
			var data = JSON.parse(decodeURIComponent(raw));
			if (!data || typeof data !== 'object' || Object.prototype.toString.call(data) === '[object Array]') {
				return null;
			}
			if (typeof data.id !== 'string' || !ID_RE.test(data.id)) {
				return null;
			}
			if (!isInt(data.v) || !isInt(data.t)) {
				return null;
			}
			if (Object.prototype.toString.call(data.c) !== '[object Array]') {
				return null;
			}
			for (var i = 0; i < data.c.length; i++) {
				if (typeof data.c[i] !== 'string') {
					return null;
				}
			}
			return { id: data.id, v: data.v, t: data.t, c: normalize(data.c) };
		} catch (e) {
			return null;
		}
	}

	function serialize(c) {
		return encodeURIComponent(JSON.stringify({ id: c.id, v: c.v, t: c.t, c: c.c }));
	}

	function getDoc(doc) {
		if (doc) {
			return doc;
		}
		return typeof document !== 'undefined' ? document : null;
	}

	function readCookie(doc) {
		try {
			var d = getDoc(doc);
			if (!d || typeof d.cookie !== 'string') {
				return null;
			}
			var parts = d.cookie.split(';');
			for (var i = 0; i < parts.length; i++) {
				var part = parts[i].replace(/^\s+/, '');
				if (part.indexOf(COOKIE_NAME + '=') === 0) {
					return parse(part.substring(COOKIE_NAME.length + 1));
				}
			}
		} catch (e) {
			return null;
		}
		return null;
	}

	function writeCookie(c, doc, secure) {
		try {
			var d = getDoc(doc);
			if (!d) {
				return;
			}
			if (typeof secure === 'undefined') {
				secure = typeof location !== 'undefined' && location.protocol === 'https:';
			}
			d.cookie = COOKIE_NAME + '=' + serialize(c) + '; path=/; max-age=' + MAX_AGE + '; SameSite=Lax' + (secure ? '; Secure' : '');
		} catch (e) {
			return;
		}
	}

	function uuid() {
		var cr = root && root.crypto;
		try {
			if (cr && typeof cr.randomUUID === 'function') {
				var u = cr.randomUUID();
				if (typeof u === 'string' && /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/.test(u)) {
					return u;
				}
			}
		} catch (e) {
			// fall through
		}
		var bytes = [];
		var i;
		var filled = false;
		try {
			if (cr && typeof cr.getRandomValues === 'function' && typeof Uint8Array !== 'undefined') {
				var arr = new Uint8Array(16);
				cr.getRandomValues(arr);
				for (i = 0; i < 16; i++) {
					bytes.push(arr[i]);
				}
				filled = true;
			}
		} catch (e2) {
			filled = false;
		}
		if (!filled) {
			bytes = [];
			for (i = 0; i < 16; i++) {
				bytes.push(Math.floor(Math.random() * 256));
			}
		}
		bytes[6] = (bytes[6] & 0x0f) | 0x40;
		bytes[8] = (bytes[8] & 0x3f) | 0x80;
		var hex = [];
		for (i = 0; i < 16; i++) {
			hex.push((bytes[i] + 256).toString(16).substring(1));
		}
		return hex.slice(0, 4).join('') + '-' + hex.slice(4, 6).join('') + '-' + hex.slice(6, 8).join('') + '-' + hex.slice(8, 10).join('') + '-' + hex.slice(10).join('');
	}

	function create(categories, version, now, id) {
		return { id: id || uuid(), v: version, t: now, c: normalize(categories) };
	}

	function isValid(c, version, now) {
		if (!c || typeof c !== 'object') {
			return false;
		}
		if (c.v < version) {
			return false;
		}
		if (now - c.t > MAX_AGE) {
			return false;
		}
		return true;
	}

	function allowed(c, category) {
		if (category === 'necessary') {
			return true;
		}
		return !!(c && c.c && c.c.indexOf(category) !== -1);
	}

	function gcmState(categories) {
		var has = function (name) {
			return categories && categories.indexOf && categories.indexOf(name) !== -1;
		};
		var g = function (name) {
			return has(name) ? 'granted' : 'denied';
		};
		return {
			analytics_storage: g('statistics'),
			ad_storage: g('marketing'),
			ad_user_data: g('marketing'),
			ad_personalization: g('marketing'),
			functionality_storage: g('preferences'),
			personalization_storage: g('preferences')
		};
	}

	function hostOf(url) {
		var m = /^(?:[a-z][a-z0-9+.\-]*:)?\/\/([^\/?#]*)/i.exec(url);
		if (!m) {
			return '';
		}
		var h = m[1];
		var at = h.lastIndexOf('@');
		if (at !== -1) {
			h = h.substring(at + 1);
		}
		h = h.replace(/:\d*$/, '');
		return h.toLowerCase();
	}

	function matchRule(rules, url) {
		try {
			if (!rules || typeof url !== 'string' || !rules.length) {
				return null;
			}
			var host = hostOf(url);
			var stripped = url.replace(/^[a-z][a-z0-9+.\-]*:/i, '').replace(/^\/\//, '').toLowerCase();
			var best = null;
			var bestLen = -1;
			for (var i = 0; i < rules.length; i++) {
				var r = rules[i];
				if (!r || typeof r.p !== 'string' || r.p === '') {
					continue;
				}
				var p = r.p.toLowerCase();
				var hit;
				if (r.k === 'host') {
					hit = host !== '' && (host === p || (host.length > p.length && host.substring(host.length - p.length - 1) === '.' + p));
				} else {
					hit = stripped.indexOf(p) !== -1;
				}
				if (hit && p.length > bestLen) {
					best = r;
					bestLen = p.length;
				}
			}
			return best;
		} catch (e) {
			return null;
		}
	}

	return {
		COOKIE_NAME: COOKIE_NAME,
		CATEGORIES: CATEGORIES,
		parse: parse,
		serialize: serialize,
		readCookie: readCookie,
		writeCookie: writeCookie,
		create: create,
		isValid: isValid,
		allowed: allowed,
		gcmState: gcmState,
		matchRule: matchRule,
		uuid: uuid
	};
}));
