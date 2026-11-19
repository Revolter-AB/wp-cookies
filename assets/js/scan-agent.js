/**
 * Revolter Cookies scan agent: runs only in scan mode, inside the admin's hidden scan iframe.
 *
 * Reports cookie names, storage keys and resources ({url, type}) to the parent admin page. ES5 only.
 */
(function (w, d) {
	'use strict';

	var DELAY_MS = 4000;
	var MAX_ITEMS = 500;
	var SCAN_PARAMS = ['rc_scan', 'rc_scan_mode'];

	function mode() {
		var cfg = w.RevolterCookiesConfig && typeof w.RevolterCookiesConfig === 'object' ? w.RevolterCookiesConfig : {};
		var m = w.RevolterCookiesScanMode || cfg.scanMode;
		return m === 'none' || m === 'accepted' ? m : '';
	}

	function delay() {
		var ms = w.RevolterCookiesScanAgentDelay;
		return typeof ms === 'number' && ms >= 0 && ms < DELAY_MS ? ms : DELAY_MS;
	}

	function push(list, value) {
		if (typeof value === 'string' && value !== '' && list.length < MAX_ITEMS) {
			for (var i = 0; i < list.length; i++) {
				if (list[i] === value) {
					return;
				}
			}
			list.push(value);
		}
	}

	/* Remove the scan token and mode from the address bar so later scripts do not see or send them. */
	function stripParams() {
		try {
			var loc = w.location;
			if (!w.history || typeof w.history.replaceState !== 'function' || !loc.search) {
				return;
			}
			var kept = [];
			var pairs = loc.search.substring(1).split('&');
			for (var i = 0; i < pairs.length; i++) {
				var name = pairs[i].split('=')[0];
				var plain = name;
				try {
					plain = decodeURIComponent(name.replace(/\+/g, ' '));
				} catch (e) {
					plain = name;
				}
				if (pairs[i] !== '' && SCAN_PARAMS.indexOf(plain) === -1) {
					kept.push(pairs[i]);
				}
			}
			w.history.replaceState(w.history.state, '', loc.pathname + (kept.length ? '?' + kept.join('&') : '') + loc.hash);
		} catch (e) {
			return;
		}
	}

	function absolute(value) {
		try {
			if (typeof value !== 'string' || value.replace(/^\s+|\s+$/g, '') === '') {
				return '';
			}
			var a = d.createElement('a');
			a.href = value.replace(/^\s+|\s+$/g, '');
			return /^https?:\/\//i.test(a.href) ? a.href : '';
		} catch (e) {
			return '';
		}
	}

	function cookies() {
		var out = [];
		try {
			var parts = String(d.cookie || '').split(';');
			for (var i = 0; i < parts.length; i++) {
				push(out, parts[i].replace(/^\s+/, '').split('=')[0]);
			}
		} catch (e) {
			return out;
		}
		return out;
	}

	function storageKeys(out, name) {
		try {
			var store = w[name];
			if (!store || typeof store.length !== 'number') {
				return;
			}
			for (var i = 0; i < store.length; i++) {
				push(out, store.key(i));
			}
		} catch (e) {
			return;
		}
	}

	/* Resource kind from a Resource Timing initiatorType: script, iframe or what the browser reported. */
	function kindOf(initiator) {
		var t = typeof initiator === 'string' ? initiator.toLowerCase() : '';
		if (t === 'script') {
			return 'script';
		}
		if (t === 'iframe' || t === 'subdocument' || t === 'frame') {
			return 'iframe';
		}
		return t === '' ? 'other' : t;
	}

	/* Add a resource; a script or iframe sighting upgrades an earlier sighting of another kind. */
	function pushResource(list, url, type) {
		if (url === '') {
			return;
		}
		for (var i = 0; i < list.length; i++) {
			if (list[i].url === url) {
				if (type === 'script' || type === 'iframe') {
					list[i].type = type;
				}
				return;
			}
		}
		if (list.length < MAX_ITEMS) {
			list.push({ url: url, type: type });
		}
	}

	function resources() {
		var out = [];
		var i;
		try {
			var els = d.querySelectorAll('script[src], iframe[src], [data-rc-src]');
			for (i = 0; i < els.length; i++) {
				var type = String(els[i].tagName).toLowerCase() === 'iframe' ? 'iframe' : 'script';
				pushResource(out, absolute(els[i].getAttribute('src')), type);
				pushResource(out, absolute(els[i].getAttribute('data-rc-src')), type);
			}
		} catch (e) {
			// keep going with what Resource Timing tells us
		}
		try {
			var perf = w.performance;
			if (perf && typeof perf.getEntriesByType === 'function') {
				var entries = perf.getEntriesByType('resource') || [];
				for (i = 0; i < entries.length; i++) {
					if (entries[i]) {
						pushResource(out, absolute(entries[i].name), kindOf(entries[i].initiatorType));
					}
				}
			}
		} catch (e) {
			return out;
		}
		return out;
	}

	function report(m) {
		try {
			var storage = [];
			storageKeys(storage, 'localStorage');
			storageKeys(storage, 'sessionStorage');
			w.parent.postMessage({
				type: 'rc-scan-result',
				url: w.location.href,
				mode: m,
				cookies: cookies(),
				storage: storage,
				resources: resources()
			}, w.location.origin);
		} catch (e) {
			return;
		}
	}

	try {
		var m = mode();
		if (!m || !w.parent || w.parent === w) {
			return;
		}
		stripParams();
		var sent = false;
		var schedule = function () {
			if (sent) {
				return;
			}
			sent = true;
			w.setTimeout(function () {
				report(m);
			}, delay());
		};
		if (d.readyState === 'complete') {
			schedule();
		} else {
			w.addEventListener('load', schedule);
		}
	} catch (e) {
		return;
	}
}(typeof window !== 'undefined' ? window : this, typeof document !== 'undefined' ? document : null));
