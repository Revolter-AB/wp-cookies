/* Revolter Cookies guard: blocks dynamically inserted scripts and iframes until consent. */
(function (w, d) {
	'use strict';

	var Core = w.RevolterCookiesCore;
	if (!Core || !d || typeof Core.matchRule !== 'function') {
		return;
	}

	var JS_TYPES = ['', 'text/javascript', 'application/javascript', 'text/ecmascript', 'application/ecmascript', 'application/x-javascript', 'module'];
	var origSetAttribute = null;
	var origIframeSrc = null;

	if (!w.RevolterCookiesQueue || typeof w.RevolterCookiesQueue.push !== 'function') {
		w.RevolterCookiesQueue = [];
	}

	function now() {
		return Math.floor(new Date().getTime() / 1000);
	}

	function version() {
		var cfg = w.RevolterCookiesConfig;
		if (cfg && typeof cfg.version === 'number') {
			return cfg.version;
		}
		return typeof w.RevolterCookiesVersion === 'number' ? w.RevolterCookiesVersion : 0;
	}

	/* Set by the head script (and the config) on scanner requests only: 'none' or 'accepted'. */
	function scanMode() {
		var cfg = w.RevolterCookiesConfig;
		var m = w.RevolterCookiesScanMode || (cfg && typeof cfg === 'object' ? cfg.scanMode : '');
		return m === 'none' || m === 'accepted' ? m : '';
	}

	/* Scan 'accepted' allows everything without a cookie; scan 'none' ignores any cookie. */
	function consent() {
		var mode = scanMode();
		if (mode === 'accepted') {
			return { id: '', v: version(), t: now(), c: Core.CATEGORIES.slice(0) };
		}
		if (mode === 'none') {
			return null;
		}
		try {
			var c = Core.readCookie(d);
			return Core.isValid(c, version(), now()) ? c : null;
		} catch (e) {
			return null;
		}
	}

	function resolve(value) {
		if (typeof value !== 'string') {
			return '';
		}
		value = value.replace(/^\s+|\s+$/g, '');
		if (value === '') {
			return '';
		}
		try {
			if (typeof w.URL === 'function') {
				return new w.URL(value, d.baseURI || w.location.href).href;
			}
			var a = d.createElement('a');
			a.href = value;
			return /^[a-z][a-z0-9+.\-]*:\/\//i.test(a.href) ? a.href : '';
		} catch (e) {
			return '';
		}
	}

	/* The rule that blocks this URL now, or null. Necessary and allowed rules never block. */
	function blockingRule(value) {
		var url = resolve(value);
		if (url === '') {
			return null;
		}
		var rule = Core.matchRule(w.RevolterCookiesRules, url);
		if (!rule || typeof rule.c !== 'string' || rule.c === 'necessary') {
			return null;
		}
		return Core.allowed(consent(), rule.c) ? null : rule;
	}

	function tagOf(el) {
		return el && el.nodeType === 1 && typeof el.tagName === 'string' ? el.tagName.toUpperCase() : '';
	}

	function rawSet(el, name, value) {
		if (origSetAttribute) {
			origSetAttribute.call(el, name, value);
		} else {
			el.setAttribute(name, value);
		}
	}

	/* Lowercase MIME type without parameters: ' Text/JavaScript; charset=utf-8 ' becomes 'text/javascript'. */
	function normType(type) {
		return typeof type === 'string' ? type.toLowerCase().split(';')[0].replace(/^\s+|\s+$/g, '') : '';
	}

	function isJsType(el) {
		return JS_TYPES.indexOf(normType(el.getAttribute('type'))) !== -1;
	}

	function announce(el) {
		try {
			w.setTimeout(function () {
				var ev;
				try {
					ev = new w.CustomEvent('rc:blocked', { detail: { element: el } });
				} catch (e) {
					ev = d.createEvent('CustomEvent');
					ev.initCustomEvent('rc:blocked', false, false, { element: el });
				}
				d.dispatchEvent(ev);
			}, 0);
		} catch (e) {
			return;
		}
	}

	/* Make a script or iframe inert, keeping the src for activation. value is the src it would get. */
	function neuter(el, value, rule) {
		var tag = tagOf(el);
		if (tag === 'SCRIPT') {
			if (normType(el.getAttribute('type')) === 'module') {
				rawSet(el, 'data-rc-type', 'module');
			}
			rawSet(el, 'type', 'text/plain');
		}
		if (el.hasAttribute('src')) {
			el.removeAttribute('src');
		}
		rawSet(el, 'data-rc-src', String(value).replace(/^\s+|\s+$/g, ''));
		rawSet(el, 'data-rc-category', rule.c);
		rawSet(el, 'data-rc-label', typeof rule.l === 'string' ? rule.l : '');
		if (tag === 'SCRIPT') {
			w.RevolterCookiesQueue.push(el);
		} else {
			if (el.classList) {
				el.classList.add('rc-blocked-embed');
			}
			announce(el);
		}
	}

	/* Check an element that is about to be inserted. */
	function check(el) {
		try {
			var tag = tagOf(el);
			if ((tag !== 'SCRIPT' && tag !== 'IFRAME') || el.hasAttribute('data-rc-ignore')) {
				return;
			}
			if (tag === 'SCRIPT' && !isJsType(el)) {
				return;
			}
			var src = el.getAttribute('src');
			if (typeof src !== 'string' || src === '') {
				return;
			}
			var rule = blockingRule(src);
			if (rule) {
				neuter(el, src, rule);
			}
		} catch (e) {
			return;
		}
	}

	function checkTree(node) {
		try {
			if (!node) {
				return;
			}
			if (node.nodeType === 1) {
				check(node);
			}
			if ((node.nodeType === 1 || node.nodeType === 11) && typeof node.querySelectorAll === 'function') {
				var list = node.querySelectorAll('script[src],iframe[src]');
				for (var i = 0; i < list.length; i++) {
					check(list[i]);
				}
			}
		} catch (e) {
			return;
		}
	}

	/* Check node arguments first .. first + nodeArgs - 1 before the original insertion method runs. */
	function wrapInsert(proto, name, nodeArgs, first) {
		try {
			if (!proto || typeof proto[name] !== 'function') {
				return;
			}
			var orig = proto[name];
			var from = first || 0;
			proto[name] = function () {
				for (var i = from; i < arguments.length && i < from + nodeArgs; i++) {
					if (arguments[i] && typeof arguments[i] === 'object') {
						checkTree(arguments[i]);
					}
				}
				return orig.apply(this, arguments);
			};
		} catch (e) {
			return;
		}
	}

	/* src property setter: returns the original descriptor, or null when it cannot be wrapped. */
	function wrapSrc(Ctor, isScript) {
		try {
			if (!Ctor || !Ctor.prototype || !Object.getOwnPropertyDescriptor || !Object.defineProperty) {
				return null;
			}
			var desc = Object.getOwnPropertyDescriptor(Ctor.prototype, 'src');
			if (!desc || typeof desc.set !== 'function' || typeof desc.get !== 'function' || desc.configurable === false) {
				return null;
			}
			Object.defineProperty(Ctor.prototype, 'src', {
				configurable: true,
				enumerable: desc.enumerable,
				get: function () {
					return desc.get.call(this);
				},
				set: function (value) {
					try {
						if (!this.hasAttribute('data-rc-ignore') && (!isScript || isJsType(this))) {
							var rule = blockingRule(String(value));
							if (rule) {
								neuter(this, String(value), rule);
								return;
							}
						}
					} catch (e) {
						// fall through to the real setter
					}
					desc.set.call(this, value);
				}
			});
			return desc;
		} catch (e) {
			return null;
		}
	}

	function wrapSetAttribute() {
		try {
			var proto = w.Element && w.Element.prototype;
			if (!proto || typeof proto.setAttribute !== 'function') {
				return;
			}
			origSetAttribute = proto.setAttribute;
			proto.setAttribute = function (name, value) {
				try {
					if (typeof name === 'string' && name.toLowerCase() === 'src') {
						var tag = tagOf(this);
						if ((tag === 'SCRIPT' || tag === 'IFRAME') && !this.hasAttribute('data-rc-ignore') && (tag !== 'SCRIPT' || isJsType(this))) {
							var rule = blockingRule(String(value));
							if (rule) {
								neuter(this, String(value), rule);
								return;
							}
						}
					}
				} catch (e) {
					// fall through to the real setter
				}
				return origSetAttribute.apply(this, arguments);
			};
		} catch (e) {
			origSetAttribute = null;
		}
	}

	/* Fallback for iframes that get a src another way (innerHTML, parser quirks). */
	function observe() {
		try {
			if (typeof w.MutationObserver !== 'function' || !d.documentElement) {
				return;
			}
			var mo = new w.MutationObserver(function (records) {
				for (var i = 0; i < records.length; i++) {
					var r = records[i];
					if (r.type === 'attributes') {
						stopIframe(r.target);
					} else if (r.addedNodes) {
						for (var j = 0; j < r.addedNodes.length; j++) {
							var n = r.addedNodes[j];
							if (tagOf(n) === 'IFRAME') {
								stopIframe(n);
							} else if (n && n.nodeType === 1 && typeof n.querySelectorAll === 'function') {
								var frames = n.querySelectorAll('iframe[src]');
								for (var k = 0; k < frames.length; k++) {
									stopIframe(frames[k]);
								}
							}
						}
					}
				}
			});
			mo.observe(d.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['src'] });
		} catch (e) {
			return;
		}
	}

	function stopIframe(el) {
		try {
			if (tagOf(el) !== 'IFRAME' || el.hasAttribute('data-rc-ignore')) {
				return;
			}
			var src = el.getAttribute('src');
			if (typeof src !== 'string' || src === '' || src === 'about:blank') {
				return;
			}
			var rule = blockingRule(src);
			if (!rule) {
				return;
			}
			if (origIframeSrc) {
				origIframeSrc.set.call(el, 'about:blank');
			}
			neuter(el, src, rule);
		} catch (e) {
			return;
		}
	}

	try {
		var c = consent();
		if (c && typeof w.gtag === 'function') {
			w.gtag('consent', 'update', Core.gcmState(c.c));
		}
	} catch (e) {
		// never block the page
	}

	wrapSetAttribute();
	wrapSrc(w.HTMLScriptElement, true);
	origIframeSrc = wrapSrc(w.HTMLIFrameElement, false);
	if (w.Node) {
		wrapInsert(w.Node.prototype, 'appendChild', 1);
		wrapInsert(w.Node.prototype, 'insertBefore', 1);
		wrapInsert(w.Node.prototype, 'replaceChild', 1);
	}
	if (w.Element) {
		wrapInsert(w.Element.prototype, 'append', 1000);
		wrapInsert(w.Element.prototype, 'prepend', 1000);
		wrapInsert(w.Element.prototype, 'after', 1000);
		wrapInsert(w.Element.prototype, 'before', 1000);
		wrapInsert(w.Element.prototype, 'replaceWith', 1000);
		wrapInsert(w.Element.prototype, 'insertAdjacentElement', 1, 1);
	}
	observe();
}(typeof window !== 'undefined' ? window : this, typeof document !== 'undefined' ? document : null));
