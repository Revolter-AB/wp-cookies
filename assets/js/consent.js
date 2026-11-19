/**
 * Revolter Cookies: banner, preferences, activation of blocked content and the public API.
 *
 * ES5 only: this runs on every visitor's page.
 */
(function (w, d) {
	'use strict';

	var Core = w.RevolterCookiesCore;
	var cfg = w.RevolterCookiesConfig && typeof w.RevolterCookiesConfig === 'object' ? w.RevolterCookiesConfig : {};
	var strings = cfg.strings && typeof cfg.strings === 'object' ? cfg.strings : {};
	var CATS = Core && Core.CATEGORIES ? Core.CATEGORIES : ['necessary', 'preferences', 'statistics', 'marketing'];
	var WIDGET_SELECTOR = '.rc-blocked-widget[data-rc-category]:not(.rc-placeholder)';
	var SCRIPT_WAIT_MS = 10000;
	var WIDGET_WAIT_MS = 2000;
	var RELOAD_FLAG = 'rc_reloaded';
	var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

	var listeners = [];
	var queue = [];
	var running = false;
	var opener = null;
	var ready = false;

	function noop() {}

	function now() {
		return Math.floor(new Date().getTime() / 1000);
	}

	function version() {
		return typeof cfg.version === 'number' ? cfg.version : 0;
	}

	/* 'none' or 'accepted' on scanner requests (config, or the head global), '' otherwise. */
	function scanMode() {
		var m = cfg.scanMode || w.RevolterCookiesScanMode;
		return m === 'none' || m === 'accepted' ? m : '';
	}

	function byId(id) {
		return d.getElementById(id);
	}

	function each(list, fn) {
		for (var i = 0; i < list.length; i++) {
			fn(list[i], i);
		}
	}

	function toArray(list) {
		var out = [];
		each(list || [], function (x) {
			out.push(x);
		});
		return out;
	}

	function contains(list, x) {
		for (var i = 0; i < list.length; i++) {
			if (list[i] === x) {
				return true;
			}
		}
		return false;
	}

	function matches(el, selector) {
		if (!el || el.nodeType !== 1) {
			return false;
		}
		var fn = el.matches || el.msMatchesSelector || el.webkitMatchesSelector;
		return fn ? fn.call(el, selector) : false;
	}

	function closest(el, selector) {
		while (el && el.nodeType === 1) {
			if (matches(el, selector)) {
				return el;
			}
			el = el.parentNode;
		}
		return null;
	}

	function fill(text, service) {
		return String(text || '').split('{service}').join(service);
	}

	/* Current valid consent, or null. */
	function current() {
		try {
			if (!Core) {
				return null;
			}
			var c = Core.readCookie(d);
			return Core.isValid(c, version(), now()) ? c : null;
		} catch (e) {
			return null;
		}
	}

	function allowedCats(c) {
		return c && c.c ? c.c.slice(0) : ['necessary'];
	}

	function isAllowed(cat) {
		var mode = scanMode();
		if (mode) {
			return mode === 'accepted' && contains(CATS, cat);
		}
		return Core ? Core.allowed(current(), cat) : false;
	}

	function show(el) {
		if (el) {
			el.hidden = false;
		}
	}

	function hide(el) {
		if (el) {
			el.hidden = true;
		}
	}

	/* ---------- Activation ---------- */

	function copyScript(old) {
		var s = d.createElement('script');
		each(toArray(old.attributes), function (attr) {
			var name = attr.name.toLowerCase();
			if (name === 'type' || name === 'src' || name.indexOf('data-rc-') === 0) {
				return;
			}
			s.setAttribute(attr.name, attr.value);
		});
		var type = old.getAttribute('data-rc-type');
		var own = old.getAttribute('type');
		if (!type && own && own.toLowerCase() !== 'text/plain') {
			// The page set a type after the guard neutered the src (e.g. src first, then type = 'module').
			type = own;
		}
		if (type) {
			s.setAttribute('type', type);
		}
		var src = old.getAttribute('data-rc-src');
		if (src) {
			s.src = src;
		} else {
			s.text = old.text;
		}
		return s;
	}

	function runNext() {
		if (!queue.length) {
			running = false;
			return;
		}
		running = true;
		var old = queue.shift();
		var waiting = false;
		var finished = false;
		/* Advance the queue once per script, whichever of load, error, timeout or failure comes first. */
		var next = function () {
			if (finished) {
				return;
			}
			finished = true;
			runNext();
		};
		try {
			var cat = old.getAttribute('data-rc-category');
			var inert = old.getAttribute('type') === 'text/plain' || (old.hasAttribute('data-rc-src') && !old.hasAttribute('src'));
			if (!inert || !cat || cat === 'necessary' || !isAllowed(cat)) {
				runNext();
				return;
			}
			var s = copyScript(old);
			var external = s.hasAttribute('src');
			if (external && !s.hasAttribute('async')) {
				// Dynamic scripts are async by default; keep document order by waiting for each one.
				s.async = false;
				waiting = true;
				s.addEventListener('load', next);
				s.addEventListener('error', next);
				w.setTimeout(next, SCRIPT_WAIT_MS);
			}
			if (old.parentNode) {
				old.parentNode.replaceChild(s, old);
			} else {
				(d.head || d.documentElement).appendChild(s);
			}
		} catch (e) {
			waiting = false;
		}
		if (!waiting) {
			next();
		}
	}

	function activateScripts(cats) {
		var found = toArray(d.querySelectorAll('script[type="text/plain"][data-rc-category]'));
		var extra = w.RevolterCookiesQueue && w.RevolterCookiesQueue.length ? toArray(w.RevolterCookiesQueue) : [];
		each(extra, function (s) {
			if (!contains(found, s)) {
				found.push(s);
			}
		});
		each(found, function (s) {
			if (s.__rcQueued || !contains(cats, s.getAttribute('data-rc-category'))) {
				return;
			}
			s.__rcQueued = true;
			queue.push(s);
		});
		if (!running) {
			runNext();
		}
	}

	function unhide(el) {
		if (el.__rcPlaceholder && el.__rcPlaceholder.parentNode) {
			el.__rcPlaceholder.parentNode.removeChild(el.__rcPlaceholder);
		}
		el.__rcPlaceholder = null;
		if (el.__rcHidden) {
			el.style.display = el.__rcDisplay || '';
			el.__rcHidden = false;
		}
	}

	function activateIframes(cats) {
		each(toArray(d.querySelectorAll('iframe[data-rc-src][data-rc-category]')), function (el) {
			if (!contains(cats, el.getAttribute('data-rc-category'))) {
				return;
			}
			var src = el.getAttribute('data-rc-src');
			el.removeAttribute('data-rc-src');
			if (el.classList) {
				el.classList.remove('rc-blocked-embed');
			}
			unhide(el);
			el.src = src;
		});
	}

	/* Elementor's own init runs on page load; only a choice made in this page view needs the handler and the fallback. */
	function activateWidgets(cats, byVisitor) {
		each(toArray(d.querySelectorAll(WIDGET_SELECTOR)), function (el) {
			if (!contains(cats, el.getAttribute('data-rc-category'))) {
				return;
			}
			unhide(el);
			if (el.classList) {
				el.classList.remove('rc-blocked-widget');
			}
			if (!byVisitor) {
				return;
			}
			try {
				var ef = w.elementorFrontend;
				if (ef && ef.elementsHandler && typeof ef.elementsHandler.runReadyTrigger === 'function' && typeof w.jQuery === 'function') {
					ef.elementsHandler.runReadyTrigger(w.jQuery(el));
				}
			} catch (e) {
				// fall back to the reload below
			}
			w.setTimeout(function () {
				if (!el.querySelector('iframe, video')) {
					reloadOnce();
				}
			}, WIDGET_WAIT_MS);
		});
	}

	/* Activate blocked content for these categories, in document order. Necessary is never touched. */
	function activate(categories, byVisitor) {
		try {
			var list = [].concat(categories || []);
			var cats = [];
			each(list, function (cat) {
				if (cat !== 'necessary' && contains(CATS, cat) && isAllowed(cat) && !contains(cats, cat)) {
					cats.push(cat);
				}
			});
			if (!cats.length) {
				return;
			}
			activateScripts(cats);
			activateIframes(cats);
			activateWidgets(cats, !!byVisitor);
		} catch (e) {
			return;
		}
	}

	/* ---------- Placeholders ---------- */

	function safeUrl(value) {
		try {
			var url = new w.URL(String(value).replace(/^\s+|\s+$/g, ''), d.baseURI).href;
			return /^https?:\/\//i.test(url) ? url : '';
		} catch (e) {
			return '';
		}
	}

	function hostLabel(url) {
		var m = /^https?:\/\/([^\/?#:]+)/i.exec(url || '');
		return m ? m[1].replace(/^www\./i, '') : '';
	}

	function widgetUrl(el) {
		try {
			var s = JSON.parse(el.getAttribute('data-settings') || '{}');
			var type = s && typeof s.video_type === 'string' ? s.video_type : '';
			var url = type && typeof s[type + '_url'] === 'string' ? s[type + '_url'] : '';
			return url ? safeUrl(url) : '';
		} catch (e) {
			return '';
		}
	}

	function icon() {
		var ns = 'http://www.w3.org/2000/svg';
		var svg = d.createElementNS(ns, 'svg');
		svg.setAttribute('class', 'rc-ph-icon');
		svg.setAttribute('viewBox', '0 0 24 24');
		svg.setAttribute('fill', 'none');
		svg.setAttribute('stroke', 'currentColor');
		svg.setAttribute('stroke-width', '1.6');
		svg.setAttribute('stroke-linecap', 'round');
		svg.setAttribute('stroke-linejoin', 'round');
		svg.setAttribute('aria-hidden', 'true');
		svg.setAttribute('focusable', 'false');
		var rect = d.createElementNS(ns, 'rect');
		rect.setAttribute('x', '3');
		rect.setAttribute('y', '5');
		rect.setAttribute('width', '18');
		rect.setAttribute('height', '14');
		rect.setAttribute('rx', '3');
		var path = d.createElementNS(ns, 'path');
		path.setAttribute('d', 'M10 9.5v5l4.5-2.5z');
		svg.appendChild(rect);
		svg.appendChild(path);
		return svg;
	}

	function placeholderFor(el, isWidget) {
		if (el.__rcPlaceholder || !el.parentNode) {
			return;
		}
		var cat = el.getAttribute('data-rc-category');
		if (!cat || cat === 'necessary' || isAllowed(cat)) {
			return;
		}
		var texts = strings.placeholder && strings.placeholder[cat];
		if (!texts) {
			return;
		}
		var url = isWidget ? widgetUrl(el) : safeUrl(el.getAttribute('data-rc-src') || '');
		var label = el.getAttribute('data-rc-label') || hostLabel(url);

		var ph = d.createElement('div');
		ph.className = 'rc-placeholder ' + (isWidget ? 'rc-blocked-widget' : 'rc-blocked-embed');
		ph.setAttribute('data-rc-placeholder', cat);
		if (!isWidget) {
			var wd = parseInt(el.getAttribute('width'), 10);
			var ht = parseInt(el.getAttribute('height'), 10);
			if (wd > 0 && String(wd) === String(el.getAttribute('width')).replace(/px$/i, '')) {
				ph.style.width = wd + 'px';
				if (ht > 0) {
					ph.style.aspectRatio = wd + ' / ' + ht;
				}
			}
		}
		ph.appendChild(icon());

		var p = d.createElement('p');
		p.className = 'rc-ph-text';
		p.textContent = fill(texts.text, label);
		ph.appendChild(p);

		var actions = d.createElement('div');
		actions.className = 'rc-ph-actions';
		var btn = d.createElement('button');
		btn.type = 'button';
		btn.className = 'rc-ph-accept';
		btn.textContent = fill(texts.accept, label);
		btn.addEventListener('click', function (e) {
			e.preventDefault();
			var cats = allowedCats(current());
			if (!contains(cats, cat)) {
				cats.push(cat);
			}
			setConsent(cats, 'custom');
		});
		actions.appendChild(btn);
		if (url) {
			var a = d.createElement('a');
			a.className = 'rc-ph-open';
			a.href = url;
			a.target = '_blank';
			a.rel = 'noopener noreferrer';
			a.textContent = fill(texts.open, label);
			actions.appendChild(a);
		}
		ph.appendChild(actions);

		el.parentNode.insertBefore(ph, el.nextSibling);
		el.__rcPlaceholder = ph;
		el.__rcDisplay = el.style.display;
		el.__rcHidden = true;
		el.style.display = 'none';
	}

	function renderPlaceholders() {
		each(toArray(d.querySelectorAll('iframe[data-rc-src][data-rc-category]')), function (el) {
			placeholderFor(el, false);
		});
		each(toArray(d.querySelectorAll(WIDGET_SELECTOR)), function (el) {
			placeholderFor(el, true);
		});
	}

	/* ---------- Consent box ---------- */

	function formatDate(t) {
		var date = new Date(t * 1000);
		try {
			return date.toLocaleDateString(cfg.lang === 'sv' ? 'sv-SE' : 'en-GB');
		} catch (e) {
			return date.getFullYear() + '-' + ('0' + (date.getMonth() + 1)).slice(-2) + '-' + ('0' + date.getDate()).slice(-2);
		}
	}

	function statusText(c) {
		var box = strings.consentBox || {};
		if (!c) {
			return String(box.none || '');
		}
		var labels = [];
		var names = strings.categoryLabels || {};
		each(c.c, function (cat) {
			labels.push(names[cat] || cat);
		});
		return String(box.accepted || '%1$s')
			.split('%1$s').join(labels.join(', '))
			.split('%2$s').join(formatDate(c.t))
			.split('%3$s').join(c.id.substring(0, 8));
	}

	function renderStatus() {
		var c = current();
		var text = statusText(c);
		each(toArray(d.querySelectorAll('[data-rc-consent-status]')), function (box) {
			var target = box.querySelector('[data-rc-consent-text]');
			if (!target) {
				target = d.createElement('p');
				target.className = 'rc-consent-text';
				target.setAttribute('data-rc-consent-text', '');
				var heading = box.querySelector('h1, h2, h3, h4, h5, h6');
				if (heading && heading.parentNode === box) {
					box.insertBefore(target, heading.nextSibling);
				} else {
					box.insertBefore(target, box.firstChild);
				}
			}
			target.textContent = text;
			box.setAttribute('data-rc-state', c ? 'given' : 'none');
		});
	}

	/* ---------- Banner and modal ---------- */

	function focusables(root) {
		var out = [];
		each(toArray(root.querySelectorAll(FOCUSABLE)), function (el) {
			if (!el.hidden && !closest(el, '[hidden]')) {
				out.push(el);
			}
		});
		return out;
	}

	function syncCheckboxes() {
		var modal = byId('rc-modal');
		if (!modal) {
			return;
		}
		var cats = allowedCats(current());
		each(toArray(modal.querySelectorAll('input[type="checkbox"][data-rc-category]')), function (box) {
			var cat = box.getAttribute('data-rc-category');
			box.checked = cat === 'necessary' || contains(cats, cat);
		});
	}

	function open() {
		try {
			var modal = byId('rc-modal');
			if (!modal) {
				return;
			}
			if (modal.hidden) {
				opener = d.activeElement && d.activeElement !== d.body ? d.activeElement : null;
			}
			syncCheckboxes();
			hide(byId('rc-banner'));
			show(modal);
			var list = focusables(modal);
			if (list.length) {
				list[0].focus();
			}
		} catch (e) {
			return;
		}
	}

	function restoreFocus() {
		var target = opener;
		opener = null;
		if (target && target.focus && !closest(target, '[hidden]') && d.documentElement.contains(target)) {
			target.focus();
			return;
		}
		var btn = byId('rc-settings-button');
		if (btn && !btn.hidden) {
			btn.focus();
		}
	}

	function closeModal() {
		var modal = byId('rc-modal');
		if (!modal || modal.hidden) {
			return;
		}
		hide(modal);
		if (!current()) {
			show(byId('rc-banner'));
		}
		restoreFocus();
	}

	function chosenUi() {
		hide(byId('rc-banner'));
		var modal = byId('rc-modal');
		var wasOpen = modal && !modal.hidden;
		hide(modal);
		show(byId('rc-settings-button'));
		renderStatus();
		if (wasOpen) {
			restoreFocus();
		}
	}

	function trapTab(e, modal) {
		var list = focusables(modal);
		if (!list.length) {
			e.preventDefault();
			return;
		}
		var first = list[0];
		var last = list[list.length - 1];
		var active = d.activeElement;
		if (e.shiftKey && (active === first || !modal.contains(active))) {
			e.preventDefault();
			last.focus();
		} else if (!e.shiftKey && (active === last || !modal.contains(active))) {
			e.preventDefault();
			first.focus();
		}
	}

	function onKeydown(e) {
		try {
			var modal = byId('rc-modal');
			if (!modal || modal.hidden) {
				return;
			}
			var key = e.key || '';
			if (key === 'Escape' || key === 'Esc' || e.keyCode === 27) {
				e.preventDefault();
				closeModal();
			} else if (key === 'Tab' || e.keyCode === 9) {
				trapTab(e, modal);
			}
		} catch (err) {
			return;
		}
	}

	function checkedCategories() {
		var out = ['necessary'];
		var modal = byId('rc-modal');
		if (modal) {
			each(toArray(modal.querySelectorAll('input[type="checkbox"][data-rc-category]')), function (box) {
				var cat = box.getAttribute('data-rc-category');
				if (box.checked && !contains(out, cat)) {
					out.push(cat);
				}
			});
		}
		return out;
	}

	function onClick(e) {
		try {
			var target = e.target;
			var el = closest(target, '[data-rc-action]');
			if (el) {
				var action = el.getAttribute('data-rc-action');
				if (action === 'accept-all') {
					e.preventDefault();
					// Every category, not only those in use: blocking rules can cover categories a scan has not found yet.
					setConsent(CATS.slice(0), 'accept_all');
				} else if (action === 'reject-all') {
					e.preventDefault();
					setConsent(['necessary'], 'reject_all');
				} else if (action === 'save') {
					e.preventDefault();
					setConsent(checkedCategories(), 'custom');
				} else if (action === 'withdraw') {
					e.preventDefault();
					setConsent(['necessary'], 'withdraw');
				} else if (action === 'customize' || action === 'change') {
					e.preventDefault();
					open();
				} else if (action === 'close') {
					e.preventDefault();
					closeModal();
				}
				return;
			}
			if (closest(target, '#rc-settings-button, .rc-open-settings, a[href="#cookie-settings"]')) {
				e.preventDefault();
				open();
			}
		} catch (err) {
			return;
		}
	}

	/* ---------- Consent changes ---------- */

	function registrableDomain(host) {
		var labels = host.split('.');
		if (labels.length < 2 || /^[0-9.]+$/.test(host) || host.indexOf(':') !== -1) {
			return '';
		}
		return labels.slice(-2).join('.');
	}

	function expire(name) {
		var base = name + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; max-age=0; path=/';
		var host = w.location && w.location.hostname ? w.location.hostname : '';
		d.cookie = base;
		if (host) {
			d.cookie = base + '; domain=' + host;
			var reg = registrableDomain(host);
			if (reg) {
				d.cookie = base + '; domain=.' + reg;
			}
		}
	}

	function presentCookies() {
		var out = [];
		each(String(d.cookie || '').split(';'), function (part) {
			var name = part.replace(/^\s+/, '').split('=')[0];
			if (name) {
				out.push(name);
			}
		});
		return out;
	}

	/* Delete the declared cookies of revoked categories, including prefix families such as _ga_<ID>. */
	function deleteCookies(cats) {
		var names = cfg.cookieNames && typeof cfg.cookieNames === 'object' ? cfg.cookieNames : {};
		var exact = [];
		var prefixes = [];
		each(cats, function (cat) {
			each(names[cat] && names[cat].length ? names[cat] : [], function (name) {
				if (typeof name !== 'string' || name === '') {
					return;
				}
				var lastChar = name.charAt(name.length - 1);
				if (lastChar === '_' || lastChar === '-') {
					prefixes.push(name);
				} else {
					exact.push(name);
					if (name === '_ga') {
						prefixes.push('_ga_');
					}
				}
			});
		});
		var targets = exact.slice(0);
		each(presentCookies(), function (name) {
			each(prefixes, function (prefix) {
				if (name.indexOf(prefix) === 0 && !contains(targets, name)) {
					targets.push(name);
				}
			});
		});
		each(targets, function (name) {
			if (Core && name === Core.COOKIE_NAME) {
				return;
			}
			try {
				expire(name);
			} catch (e) {
				noop();
			}
		});
	}

	function wpConsentApi(cats) {
		if (!cfg.wpConsentApi || typeof w.wp_set_consent !== 'function') {
			return;
		}
		var map = [
			['necessary', 'functional'],
			['preferences', 'preferences'],
			['statistics', 'statistics'],
			['statistics', 'statistics-anonymous'],
			['marketing', 'marketing']
		];
		each(map, function (pair) {
			try {
				w.wp_set_consent(pair[1], contains(cats, pair[0]) ? 'allow' : 'deny');
			} catch (e) {
				noop();
			}
		});
	}

	function postLog(c, action) {
		if (typeof cfg.rest !== 'string' || cfg.rest === '') {
			return;
		}
		var body = JSON.stringify({ id: c.id, categories: c.c, version: c.v, action: action, language: cfg.lang === 'sv' ? 'sv' : 'en' });
		try {
			if (typeof w.fetch === 'function') {
				var req = w.fetch(cfg.rest, {
					method: 'POST',
					keepalive: true,
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json' },
					body: body
				});
				if (req && typeof req['catch'] === 'function') {
					req['catch'](noop);
				}
				return;
			}
			if (typeof w.XMLHttpRequest === 'function') {
				var xhr = new w.XMLHttpRequest();
				xhr.open('POST', cfg.rest, true);
				xhr.setRequestHeader('Content-Type', 'application/json');
				xhr.send(body);
			}
		} catch (e) {
			noop();
		}
	}

	function dispatch(c, action, previous) {
		var detail = { consent: c, categories: c.c.slice(0), action: action, previous: previous };
		var ev;
		try {
			ev = new w.CustomEvent('rc:consent-changed', { detail: detail });
		} catch (e) {
			ev = d.createEvent('CustomEvent');
			ev.initCustomEvent('rc:consent-changed', false, false, detail);
		}
		d.dispatchEvent(ev);
		each(listeners.slice(0), function (cb) {
			try {
				cb(c, action);
			} catch (e) {
				noop();
			}
		});
	}

	/* Widget fallback reload: at most once until a page view loads without needing it. */
	function reloadOnce() {
		var store = null;
		try {
			store = w.sessionStorage;
			if (store && store.getItem(RELOAD_FLAG)) {
				return;
			}
			if (store) {
				store.setItem(RELOAD_FLAG, '1');
			}
		} catch (e) {
			// No storage: a reload without the flag could loop, so do not reload.
			return;
		}
		if (store) {
			reload();
		}
	}

	function clearReloadFlag() {
		try {
			if (w.sessionStorage) {
				w.sessionStorage.removeItem(RELOAD_FLAG);
			}
		} catch (e) {
			noop();
		}
	}

	function reload() {
		try {
			w.location.reload();
		} catch (e) {
			noop();
		}
	}

	/* Store a choice. Called only from a visitor action or the public API. */
	function setConsent(categories, action) {
		try {
			if (!Core || scanMode()) {
				// A scan never writes or logs consent.
				return null;
			}
			var kinds = ['accept_all', 'reject_all', 'custom', 'withdraw'];
			action = contains(kinds, action) ? action : 'custom';
			var before = current();
			var prevCats = allowedCats(before);
			var existing = Core.readCookie(d);
			// Categories in the stored cookie even when it is stale (version bump or expiry): their cookies may still exist.
			var storedCats = existing && existing.c ? existing.c : [];
			var c = Core.create([].concat(categories || []), version(), now(), existing && existing.id ? existing.id : Core.uuid());
			Core.writeCookie(c, d);

			try {
				if (typeof w.gtag === 'function') {
					w.gtag('consent', 'update', Core.gcmState(c.c));
				}
			} catch (e) {
				noop();
			}
			wpConsentApi(c.c);
			postLog(c, action);
			chosenUi();
			dispatch(c, action, prevCats);

			var revoked = [];
			each(prevCats, function (cat) {
				if (!contains(c.c, cat)) {
					revoked.push(cat);
				}
			});
			var stale = [];
			each(storedCats, function (cat) {
				if (!contains(c.c, cat) && !contains(revoked, cat)) {
					stale.push(cat);
				}
			});
			if (stale.length) {
				// Nothing of these was activated on this page, so deleting needs no reload.
				deleteCookies(stale);
			}
			if (revoked.length) {
				deleteCookies(revoked);
				reload();
				return c;
			}
			var granted = [];
			each(c.c, function (cat) {
				if (!contains(prevCats, cat)) {
					granted.push(cat);
				}
			});
			activate(granted, true);
			return c;
		} catch (e) {
			return null;
		}
	}

	function onChange(cb) {
		if (typeof cb === 'function') {
			listeners.push(cb);
		}
	}

	function getConsent() {
		var c = current();
		return c ? { id: c.id, v: c.v, t: c.t, c: c.c.slice(0) } : null;
	}

	/* ---------- Boot ---------- */

	function init() {
		if (ready) {
			return;
		}
		ready = true;
		try {
			var mode = scanMode();
			if (mode) {
				// Scanner request: no banner, no placeholders; 'accepted' runs everything without writing a cookie.
				if (mode === 'accepted') {
					activate(CATS.slice(0), false);
				}
				return;
			}
			// This page view loaded; a later choice may use the widget fallback once more.
			clearReloadFlag();
			var c = current();
			if (c) {
				activate(c.c, false);
				show(byId('rc-settings-button'));
			} else {
				show(byId('rc-banner'));
			}
			renderPlaceholders();
			renderStatus();
			if (w.location && w.location.hash === '#cookie-settings') {
				open();
			}
		} catch (e) {
			noop();
		}
	}

	w.RevolterCookies = {
		open: open,
		getConsent: getConsent,
		setConsent: setConsent,
		activate: function (category) {
			activate([category], false);
		},
		onChange: onChange
	};

	try {
		d.addEventListener('click', onClick);
		d.addEventListener('keydown', onKeydown);
		d.addEventListener('rc:blocked', function (e) {
			try {
				var el = e.detail && e.detail.element;
				if (ready && !scanMode() && el && el.tagName === 'IFRAME') {
					placeholderFor(el, false);
				}
			} catch (err) {
				noop();
			}
		});
		if (d.readyState === 'loading') {
			d.addEventListener('DOMContentLoaded', init);
		} else {
			init();
		}
	} catch (e) {
		noop();
	}
}(typeof window !== 'undefined' ? window : this, typeof document !== 'undefined' ? document : null));
