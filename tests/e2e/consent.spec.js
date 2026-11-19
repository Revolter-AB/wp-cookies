// Visitor-side consent flows on wptests.test. Run through tests/e2e/run.sh (setup + teardown).
const { test, expect, fixture, trackerRequests, cookie, consentCats, login } = require('./helpers');

const NO_CONSENT_TRACKERS = [/googletagmanager\.com/, /connect\.facebook\.net/, /youtube\.com/, /google\.com\/maps/];

test.describe('visitor consent', () => {
	test('first visit shows banner and makes no tracker requests', async ({ page, context, requestLog }) => {
		await page.goto('/rc-e2e/');
		await expect(page.locator('#rc-banner')).toBeVisible();
		await expect(page.locator('#rc-banner .rc-title')).toHaveText('We use cookies');
		await expect(page.locator('#rc-settings-button')).toBeHidden();
		// Placeholders replace the blocked iframes.
		await expect(page.locator('.rc-placeholder[data-rc-placeholder="marketing"]')).toHaveCount(2);
		await page.waitForLoadState('load');
		expect(trackerRequests(requestLog, NO_CONSENT_TRACKERS)).toEqual([]);
		expect(await cookie(context, '_ga')).toBeUndefined();
		expect(await cookie(context, 'rc_consent')).toBeUndefined();
		// The policy link points at the configured cookie policy post.
		await expect(page.locator('#rc-banner a[href*="/cookie-policy/"]')).toHaveCount(1);
	});

	test('reject all keeps trackers blocked after reload and shows settings button', async ({ page, context, requestLog }) => {
		await page.goto('/rc-e2e/');
		const logged = page.waitForResponse((r) => r.url().includes('revolter-cookies/v1/consent'));
		await page.locator('#rc-banner [data-rc-action="reject-all"]').click();
		await logged;
		await expect(page.locator('#rc-banner')).toBeHidden();
		await expect(page.locator('#rc-settings-button')).toBeVisible();
		expect(await consentCats(context)).toEqual(['necessary']);

		await page.reload();
		await page.waitForLoadState('load');
		await expect(page.locator('#rc-settings-button')).toBeVisible();
		await expect(page.locator('#rc-banner')).toBeHidden();
		await expect(page.locator('script[data-rc-src*="googletagmanager"]')).toHaveAttribute('type', 'text/plain');
		expect(trackerRequests(requestLog, NO_CONSENT_TRACKERS)).toEqual([]);
		expect(await cookie(context, '_ga')).toBeUndefined();

		// The settings button reopens the preferences.
		await page.locator('#rc-settings-button').click();
		await expect(page.locator('#rc-modal')).toBeVisible();
	});

	test('accept all loads gtag and sets _ga', async ({ page, context, requestLog }) => {
		await page.goto('/rc-e2e/');
		const gtag = page.waitForRequest((r) => r.url().includes('googletagmanager.com/gtag/js?id=G-E2ETEST000'));
		await page.locator('#rc-banner [data-rc-action="accept-all"]').click();
		await gtag;
		await page.waitForFunction(() => document.cookie.indexOf('_ga=') !== -1);
		expect((await cookie(context, '_ga')).value).toBe('GA1.1.123.456');
		expect(await consentCats(context)).toEqual(expect.arrayContaining(['necessary', 'statistics', 'marketing']));
		// The inline config ran after gtag.js, and consent mode was updated.
		const dl = await page.evaluate(() => JSON.stringify(Array.from(window.dataLayer || [], (a) => Array.from(a))));
		expect(dl).toContain('G-E2ETEST000');
		expect(dl).toContain('"consent","update"');
		// Marketing activated too: pixel and both iframes.
		await expect(page.locator('#rc-e2e-youtube')).toHaveAttribute('src', /youtube\.com\/embed/);
		await expect(page.locator('#rc-e2e-maps')).toHaveAttribute('src', /google\.com\/maps/);
		expect(trackerRequests(requestLog, [/connect\.facebook\.net\/en_US\/fbevents\.js/]).length).toBeGreaterThan(0);
		await expect(page.locator('.rc-placeholder')).toHaveCount(0);
	});

	test('youtube placeholder accept-and-show loads iframe only', async ({ page, context, requestLog }) => {
		await page.goto('/rc-e2e/');
		const ph = page.locator('#rc-e2e-youtube + .rc-placeholder');
		await expect(ph).toBeVisible();
		await expect(ph.locator('.rc-ph-text')).toContainText('YouTube');
		const yt = page.waitForRequest((r) => r.url().includes('youtube.com/embed/dQw4w9WgXcQ'));
		await ph.locator('.rc-ph-accept').click();
		await yt;
		await expect(page.locator('#rc-e2e-youtube')).toBeVisible();
		await expect(page.locator('#rc-e2e-youtube')).toHaveAttribute('src', 'https://www.youtube.com/embed/dQw4w9WgXcQ');
		expect((await consentCats(context)).sort()).toEqual(['marketing', 'necessary']);
		// Statistics stays blocked: no gtag request and no _ga.
		expect(trackerRequests(requestLog, [/googletagmanager\.com/, /google-analytics\.com/])).toEqual([]);
		expect(await cookie(context, '_ga')).toBeUndefined();
		await expect(page.locator('script[data-rc-src*="googletagmanager"]')).toHaveAttribute('type', 'text/plain');
	});

	test('withdraw from cookie policy page removes _ga and reloads', async ({ page, context }) => {
		await page.goto('/rc-e2e/');
		await page.locator('#rc-banner [data-rc-action="accept-all"]').click();
		await page.waitForFunction(() => document.cookie.indexOf('_ga=') !== -1);

		await page.goto('/cookie-policy/');
		await expect(page.locator('link#rc-declaration-css')).toHaveCount(1);
		const box = page.locator('[data-rc-consent-status]');
		await expect(box).toHaveAttribute('data-rc-state', 'given');
		await expect(box.locator('.rc-consent-text')).toContainText('You have accepted');

		const reloaded = page.waitForEvent('load');
		await box.locator('[data-rc-action="withdraw"]').click();
		await reloaded;
		await expect(page.locator('[data-rc-consent-status]')).toHaveAttribute('data-rc-state', 'given');
		expect(await cookie(context, '_ga')).toBeUndefined();
		expect(await consentCats(context)).toEqual(['necessary']);
		await expect(page.locator('#rc-settings-button')).toBeVisible();
	});

	test('cookie table renders on shortcode page and elementor page', async ({ page }) => {
		await page.goto('/privacy/');
		await expect(page.locator('link#rc-declaration-css')).toHaveCount(1);
		await expect(page.locator('.rc-declaration .rc-table').first()).toBeVisible();
		await expect(page.locator('.rc-declaration .rc-table code', { hasText: 'rc_consent' })).toHaveCount(1);
		const link = page.locator('.rc-settings-link a.rc-open-settings');
		await expect(link).toHaveText('Change cookie settings');
		await link.click();
		await expect(page.locator('#rc-modal')).toBeVisible();

		await page.goto('/cookie-policy/');
		await expect(page.locator('.rc-decl-full .rc-table').first()).toBeVisible();

		await page.goto('/elementor-table/');
		await expect(page.locator('.elementor-widget-rc-cookies-table .rc-table').first()).toBeVisible();
		await expect(page.locator('link#rc-declaration-css')).toHaveCount(1);
	});

	test('swedish locale shows Swedish banner', async ({ page, context }) => {
		await context.addCookies([{ name: 'rc_e2e_locale', value: 'sv_SE', domain: 'wptests.test', path: '/' }]);
		await page.goto('/rc-e2e/');
		await expect(page.locator('#rc-banner .rc-title')).toHaveText('Vi använder cookies');
		await expect(page.locator('#rc-banner [data-rc-action="accept-all"]')).toHaveText('Godkänn alla');
		await expect(page.locator('#rc-banner [data-rc-action="reject-all"]')).toHaveText('Neka alla');
		await expect(page.locator('#rc-banner [data-rc-action="customize"]')).toHaveText('Anpassa');
		await expect(page.locator('#rc-banner')).toContainText('Skapad med omsorg av');
		await expect(page.locator('#rc-e2e-youtube + .rc-placeholder .rc-ph-accept')).toHaveText('Godkänn och visa');

		await page.goto('/cookie-policy/');
		await expect(page.locator('.rc-decl-title')).toHaveText('Cookiedeklaration');
		await expect(page.locator('[data-rc-action="withdraw"]')).toHaveText('Återkalla');
	});

	test('returning visitor with marketing consent sees the Elementor video without a reload loop', async ({ page, context, requestLog }) => {
		await page.goto('/elementor-video/');
		await page.locator('#rc-banner [data-rc-action="accept-all"]').click();
		await expect(page.locator('#rc-settings-button')).toBeVisible();

		// Returning visit: count main-frame navigations for 5 s.
		const navs = [];
		page.on('framenavigated', (f) => {
			if (f === page.mainFrame()) {
				navs.push(f.url());
			}
		});
		const before = requestLog.length;
		await page.goto('/elementor-video/');
		const widget = page.locator('.elementor-widget-video');
		await expect(widget).not.toHaveClass(/rc-blocked-widget/);
		await expect(widget.locator('iframe')).toHaveCount(1);
		await expect(widget.locator('iframe')).toHaveAttribute('src', /youtube\.com\/embed\/dQw4w9WgXcQ/);
		await expect(page.locator('.rc-placeholder')).toHaveCount(0);
		await page.waitForTimeout(5000);
		expect(navs.length).toBeLessThanOrEqual(1);
		expect(requestLog.slice(before).some((u) => /youtube\.com\/(iframe_api|player_api)/.test(u))).toBe(true);
	});
});

test.describe('builder previews', () => {
	test('banner hidden inside elementor editor preview', async ({ page }) => {
		const f = fixture();
		await login(page);
		await page.goto('/elementor-table/?elementor-preview=' + f.posts.table);
		await page.waitForLoadState('load');
		await expect(page.locator('#rc-root')).toHaveCount(0);
	});

	test('anonymous elementor-preview request still shows the banner', async ({ page }) => {
		await page.goto('/rc-e2e/?elementor-preview=1');
		await expect(page.locator('#rc-root')).toHaveCount(1);
		await expect(page.locator('#rc-banner')).toBeVisible();
	});

	test('elementor editor lists the widgets and its preview has no banner', async ({ page }) => {
		const f = fixture();
		await login(page);
		await page.goto('/wp-admin/post.php?post=' + f.posts.table + '&action=elementor');
		const preview = page.frameLocator('#elementor-preview-iframe');
		await expect(preview.locator('.elementor-widget-rc-cookies-table .rc-table').first()).toBeVisible({ timeout: 45000 });
		await expect(preview.locator('#rc-root')).toHaveCount(0);
		await expect(page.locator('#elementor-panel')).toBeVisible();
		await expect(page.locator('#elementor-panel .elementor-element .title', { hasText: 'Cookie table' })).toHaveCount(1);
	});

	test('block editor previews the cookie table block', async ({ page }) => {
		await login(page);
		await page.goto('/wp-admin/post-new.php');
		await page.waitForFunction(() => window.wp && wp.data && wp.blocks && wp.blocks.getBlockType('revolter-cookies/cookies-table'));
		await page.evaluate(() => {
			wp.data.dispatch('core/block-editor').insertBlocks(wp.blocks.createBlock('revolter-cookies/cookies-table'));
		});
		const canvas = page.frameLocator('iframe[name="editor-canvas"]');
		await expect(canvas.locator('.rc-table').first()).toBeVisible({ timeout: 30000 });
	});
});
