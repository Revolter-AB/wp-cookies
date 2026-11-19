/**
 * Revolter Cookies admin scan runner.
 *
 * Loads every listed page twice in a hidden same-origin iframe (scan mode 'none', then 'accepted'),
 * collects the scan agent's reports and sends them to the scan REST endpoints, strictly one at a time.
 * ES5 only.
 */
(function (w, d) {
	'use strict';

	var CONSENT = 'rc_consent';
	var KEEP = /^(wordpress|wp-|rc_consent)/;
	var MAX_AGE = 365 * 86400;
	var TIMEOUT_MS = 15000;
	var RELOAD_MS = 2000;
	var running = false;

	function noop() {}

	function each(list, fn) {
		for (var i = 0; i < list.length; i++) {
			fn(list[i], i);
		}
	}

	function contains(list, x) {
		for (var i = 0; i < list.length; i++) {
			if (list[i] === x) {
				return true;
			}
		}
		return false;
	}

	/* sprintf-style %1$d / %2$s / %s / %d placeholders, replaced in one pass. */
	function format(text, args) {
		var next = 0;
		return String(text || '').replace(/%(?:(\d+)\$)?[ds]/g, function (match, pos) {
			var i = pos ? parseInt(pos, 10) - 1 : next++;
			return i >= 0 && i < args.length ? String(args[i]) : match;
		});
	}

	/* ---------- Pure helpers ---------- */

	function withScanParams(url, token, mode) {
		var s = String(url);
		var hash = '';
		var at = s.indexOf('#');
		if (at !== -1) {
			hash = s.substring(at);
			s = s.substring(0, at);
		}
		var sep = s.indexOf('?') === -1 ? '?' : (/[?&]$/.test(s) ? '' : '&');
		return s + sep + 'rc_scan=' + encodeURIComponent(token) + '&rc_scan_mode=' + encodeURIComponent(mode) + hash;
	}

	function hostOf(url) {
		var m = /^(?:https?:)?\/\/([^\/?#]+)/i.exec(typeof url === 'string' ? url : '');
		if (!m) {
			return '';
		}
		var h = m[1];
		var at = h.lastIndexOf('@');
		if (at !== -1) {
			h = h.substring(at + 1);
		}
		return h.replace(/:\d*$/, '').toLowerCase();
	}

	function bare(host) {
		return String(host || '').toLowerCase().replace(/^www\./, '');
	}

	/* Hosts of the URLs other than the site's own (www twin included), in first-seen order. */
	function thirdPartyHosts(urls, siteHost) {
		var out = [];
		var own = bare(siteHost);
		each(urls || [], function (url) {
			var host = hostOf(url);
			if (host !== '' && bare(host) !== own && !contains(out, host)) {
				out.push(host);
			}
		});
		return out;
	}

	/* ---------- Cookies ---------- */

	/* The host and every parent domain (a.b.example.co.uk → b.example.co.uk, example.co.uk, co.uk). Browsers ignore public suffixes. */
	function domainsOf(host) {
		var out = [];
		if (!host || /^[0-9.]+$/.test(host) || host.indexOf(':') !== -1) {
			return out;
		}
		var labels = host.split('.');
		for (var i = 0; i < labels.length - 1; i++) {
			out.push(labels.slice(i).join('.'));
		}
		return out;
	}

	function expire(name) {
		var base = name + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; max-age=0; path=/';
		try {
			d.cookie = base;
			each(domainsOf(w.location.hostname || ''), function (domain) {
				d.cookie = base + '; domain=.' + domain;
			});
		} catch (e) {
			noop();
		}
	}

	function cookieNames() {
		var out = [];
		each(String(d.cookie || '').split(';'), function (part) {
			var name = part.replace(/^\s+/, '').split('=')[0];
			if (name && !contains(out, name)) {
				out.push(name);
			}
		});
		return out;
	}

	/* Delete every script-visible cookie except WordPress's own and the consent cookie. */
	function clearCookies() {
		each(cookieNames(), function (name) {
			if (!KEEP.test(name)) {
				expire(name);
			}
		});
	}

	function readConsentRaw() {
		var parts = String(d.cookie || '').split(';');
		for (var i = 0; i < parts.length; i++) {
			var part = parts[i].replace(/^\s+/, '');
			if (part.indexOf(CONSENT + '=') === 0) {
				return part.substring(CONSENT.length + 1);
			}
		}
		return null;
	}

	/* Put rc_consent back exactly as it was, written like consent-core's writeCookie; delete it if there was none. */
	function restoreConsent(raw) {
		try {
			if (raw === null) {
				if (readConsentRaw() !== null) {
					expire(CONSENT);
				}
				return;
			}
			if (readConsentRaw() === raw) {
				return;
			}
			var secure = w.location.protocol === 'https:';
			d.cookie = CONSENT + '=' + raw + '; path=/; max-age=' + MAX_AGE + '; SameSite=Lax' + (secure ? '; Secure' : '');
		} catch (e) {
			noop();
		}
	}

	/* ---------- REST ---------- */

	function post(cfg, route, body) {
		return new w.Promise(function (resolve, reject) {
			w.fetch(cfg.rest + 'scan/' + route, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
				body: JSON.stringify(body || {})
			}).then(function (res) {
				return res.json()['catch'](function () {
					return null;
				}).then(function (data) {
					if (!res.ok) {
						var err = new Error(data && typeof data.message === 'string' ? data.message : 'HTTP ' + res.status);
						reject(err);
						return;
					}
					resolve(data);
				});
			})['catch'](reject);
		});
	}

	/* ---------- Pages ---------- */

	/* Load one page; resolves with the agent's report or null (timeout, error, foreign or wrong-mode load). */
	function loadPage(url, token, mode, timeout) {
		return new w.Promise(function (resolve) {
			var frame = d.createElement('iframe');
			var finished = false;
			var timer = null;

			function finish(result) {
				if (finished) {
					return;
				}
				finished = true;
				w.clearTimeout(timer);
				w.removeEventListener('message', onMessage);
				if (frame.parentNode) {
					frame.parentNode.removeChild(frame);
				}
				resolve(result);
			}

			function onMessage(e) {
				if (e.source !== frame.contentWindow || e.origin !== w.location.origin) {
					return;
				}
				var data = e.data;
				if (!data || typeof data !== 'object' || data.type !== 'rc-scan-result') {
					return;
				}
				finish(data.mode === mode ? data : null);
			}

			function onLoad() {
				try {
					// Throws when the frame ended up cross-origin (off-site redirect, refused by headers).
					var href = frame.contentWindow.location.href;
					if (typeof href !== 'string' || href.indexOf(w.location.origin + '/') !== 0) {
						if (href !== 'about:blank') {
							finish(null);
						}
					}
				} catch (err) {
					finish(null);
				}
			}

			w.addEventListener('message', onMessage);
			frame.setAttribute('referrerpolicy', 'no-referrer');
			frame.setAttribute('aria-hidden', 'true');
			frame.setAttribute('tabindex', '-1');
			frame.setAttribute('title', 'scan');
			frame.style.position = 'absolute';
			frame.style.left = '-10000px';
			frame.style.top = '0';
			frame.style.width = '1280px';
			frame.style.height = '800px';
			frame.style.border = '0';
			frame.addEventListener('load', onLoad);
			frame.addEventListener('error', function () {
				finish(null);
			});
			frame.setAttribute('src', withScanParams(url, token, mode));
			timer = w.setTimeout(function () {
				finish(null);
			}, timeout);
			(d.body || d.documentElement).appendChild(frame);
		});
	}

	function strings(list) {
		var out = [];
		each(list && typeof list.length === 'number' ? list : [], function (x) {
			if (typeof x === 'string' && x !== '') {
				out.push(x);
			}
		});
		return out;
	}

	/* Resources as {url, type} objects; plain URL strings are kept as they are. */
	function resourceList(list) {
		var out = [];
		each(list && typeof list.length === 'number' ? list : [], function (x) {
			if (typeof x === 'string' && x !== '') {
				out.push(x);
			} else if (x && typeof x === 'object' && typeof x.url === 'string' && x.url !== '') {
				out.push({ url: x.url, type: typeof x.type === 'string' ? x.type : '' });
			}
		});
		return out;
	}

	/* ---------- UI ---------- */

	function buildUi(container) {
		var root = d.createElement('div');
		root.className = 'rc-scan';
		var bar = d.createElement('progress');
		bar.className = 'rc-scan-progress';
		bar.max = 1;
		bar.value = 0;
		var status = d.createElement('p');
		status.className = 'rc-scan-status';
		status.setAttribute('role', 'status');
		status.setAttribute('aria-live', 'polite');
		var log = d.createElement('ul');
		log.className = 'rc-scan-log';
		var result = d.createElement('p');
		result.className = 'rc-scan-result';
		result.hidden = true;
		root.appendChild(bar);
		root.appendChild(status);
		root.appendChild(log);
		root.appendChild(result);
		if (container) {
			while (container.firstChild) {
				container.removeChild(container.firstChild);
			}
			container.appendChild(root);
		}
		return {
			progress: function (value, max, text) {
				bar.max = Math.max(1, max);
				bar.value = value;
				status.textContent = text;
			},
			status: function (text) {
				status.textContent = text;
			},
			log: function (text) {
				var li = d.createElement('li');
				li.textContent = text;
				log.appendChild(li);
			},
			result: function (text, isError) {
				bar.hidden = true;
				status.textContent = '';
				result.textContent = text;
				result.className = 'rc-scan-result ' + (isError ? 'rc-scan-error' : 'rc-scan-success');
				result.setAttribute('role', isError ? 'alert' : 'status');
				result.hidden = false;
			}
		};
	}

	/* ---------- Run ---------- */

	function config(opts) {
		var loc = w.RevolterCookiesScanL10n && typeof w.RevolterCookiesScanL10n === 'object' ? w.RevolterCookiesScanL10n : {};
		return {
			rest: String(opts.rest || loc.rest || ''),
			nonce: String(opts.nonce || loc.nonce || ''),
			l10n: opts.l10n || loc.strings || {},
			container: opts.container || null,
			onDone: typeof opts.onDone === 'function' ? opts.onDone : null,
			timeout: typeof opts.timeout === 'number' && opts.timeout > 0 ? opts.timeout : TIMEOUT_MS,
			reloadDelay: typeof opts.reloadDelay === 'number' && opts.reloadDelay >= 0 ? opts.reloadDelay : RELOAD_MS,
			reload: typeof opts.reload === 'function' ? opts.reload : function () {
				w.location.reload();
			}
		};
	}

	/* Run fn over list strictly in sequence; fn returns a promise. */
	function sequence(list, fn) {
		var p = w.Promise.resolve();
		each(list, function (item, i) {
			p = p.then(function () {
				return fn(item, i);
			});
		});
		return p;
	}

	function run(opts) {
		if (running) {
			return w.Promise.resolve(null);
		}
		running = true;
		var cfg = config(opts || {});
		var t = cfg.l10n;
		var ui = buildUi(cfg.container);
		var saved;
		var touched = false;
		var todo = [];
		var all = [];
		var token = '';
		var steps = 0;

		function pass(mode, phase) {
			return sequence(todo, function (url, i) {
				ui.progress(steps, todo.length * 2, format(t.progress, [i + 1, todo.length, phase]));
				// Each page starts from a clean jar, so a cookie is attributed only to the page that set it.
				clearCookies();
				return loadPage(url, token, mode, cfg.timeout).then(function (data) {
					steps++;
					var body = data ? {
						url: url,
						mode: mode,
						status: 'ok',
						cookies: strings(data.cookies),
						storage: strings(data.storage),
						resources: resourceList(data.resources)
					} : { url: url, mode: mode, status: 'failed' };
					if (!data) {
						ui.log(format(t.pageFailed, [url]));
					}
					return post(cfg, 'page', body)['catch'](function () {
						ui.log(format(t.saveFailed, [url]));
					});
				});
			});
		}

		function done(summary) {
			running = false;
			if (cfg.onDone) {
				try {
					cfg.onDone(summary);
				} catch (e) {
					noop();
				}
			} else if (summary && !summary.all_failed) {
				w.setTimeout(cfg.reload, cfg.reloadDelay);
			}
		}

		function fail(text, err) {
			ui.result(String(text || '') + (err && err.message ? ' ' + err.message : ''), true);
			done(null);
		}

		var restore = function () {
			if (touched) {
				clearCookies();
				restoreConsent(saved);
				touched = false;
			}
		};

		var started = false;
		return post(cfg, 'start', {}).then(function (data) {
			if (!data || typeof data.token !== 'string' || !data.urls || typeof data.urls.length !== 'number') {
				throw new Error('');
			}
			started = true;
			token = data.token;
			var skip = data.resumed && data.done && typeof data.done.length === 'number' ? data.done : [];
			all = strings(data.urls);
			each(all, function (url) {
				if (!contains(skip, url)) {
					todo.push(url);
				}
			});
			saved = readConsentRaw();
			touched = true;
			clearCookies();
			return pass('none', t.phaseNone);
		}).then(function () {
			clearCookies();
			return pass('accepted', t.phaseAccepted);
		}).then(function () {
			clearCookies();
			// Every listed URL, done ones included: 'done' covers the browser passes only, and scan/server merges idempotently.
			return sequence(all, function (url, i) {
				ui.progress(todo.length * 2, todo.length * 2, format(t.progress, [i + 1, all.length, t.phaseServer]));
				return post(cfg, 'server', { url: url })['catch'](function () {
					ui.log(format(t.saveFailed, [url]));
				});
			});
		}).then(function () {
			ui.status(String(t.finishing || ''));
			return post(cfg, 'finish', {});
		}).then(function (summary) {
			restore();
			summary = summary && typeof summary === 'object' ? summary : {};
			if (summary.all_failed) {
				ui.result(t.allFailed, true);
			} else {
				ui.result(format(t.summary, [summary.pages || 0, summary.failed || 0, summary.declared || 0, summary.needs_review || 0]), false);
			}
			done(summary);
			return summary;
		}, function (err) {
			restore();
			fail(started ? t.finishFailed : t.startFailed, err);
			return null;
		});
	}

	w.RevolterCookiesScan = {
		withScanParams: withScanParams,
		thirdPartyHosts: thirdPartyHosts,
		run: run
	};
}(typeof window !== 'undefined' ? window : this, typeof document !== 'undefined' ? document : null));
