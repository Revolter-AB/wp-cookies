// Admin scan on wptests.test. Runs before the visitor specs (see playwright.config.js):
// withdrawal deletes the cookies the declaration lists, and the declaration comes from this scan.
const { test, expect, login } = require('./helpers');

// The scan agent waits a fixed 4 s after load in every frame (11 pages x 2 passes here), which
// would not fit the 60 s test budget. With every third party stubbed nothing arrives late, so
// scan frames get a 400 ms settle instead. Nothing else about the scan changes.
const FAST_SETTLE = `if (/[?&]rc_scan=/.test(location.search)) {
	var rcSt = window.setTimeout;
	window.setTimeout = function (fn, ms) {
		var args = Array.prototype.slice.call(arguments);
		if (ms === 4000) { args[1] = 400; }
		return rcSt.apply(window, args);
	};
}`;

test('admin scan finds _ga and youtube cookies, zero set-before-consent', async ({ page, context }) => {
	test.setTimeout(60000);
	await context.addInitScript(FAST_SETTLE);
	await login(page);
	await page.goto('/wp-admin/admin.php?page=revolter-cookies');
	// A fresh admin gets onboarding pointers (from other plugins) that cover the button.
	await page.addStyleTag({ content: '.wp-pointer{display:none!important}' });
	const rescan = page.locator('[data-rc-scan]').first();
	await expect(rescan).toBeVisible();

	// Every wait is registered before the click: the Overview reloads 2 s after the scan ends.
	const finished = page.waitForResponse((r) => /revolter-cookies\/v1\/scan\/finish/.test(r.url()) && r.request().method() === 'POST', { timeout: 50000 });
	const reloaded = page.waitForEvent('load', { timeout: 55000 });
	await rescan.click();
	// Read the summary in the page the moment it appears, before the reload can remove it.
	const summary = page.waitForFunction(
		() => {
			const el = document.querySelector('.rc-scan-result.rc-scan-success');
			return el ? el.textContent : false;
		},
		null,
		{ polling: 'raf', timeout: 50000 }
	);
	const res = await finished;
	expect(res.status()).toBe(200);
	const text = await (await summary).jsonValue();
	expect(text).toContain('Scan complete. Pages scanned:');
	expect(text).toContain('(failed: 0)');

	// The Overview reloads with the new results.
	await reloaded;
	await expect(page.locator('.rc-last-scan')).toContainText('Last scan:');

	const names = page.locator('table.rc-items tbody tr td:first-child strong');
	await expect(names.filter({ hasText: /^_ga$/ })).toHaveCount(1);
	await expect(names.filter({ hasText: /^YSC$/ })).toHaveCount(1);

	const leaks = page.locator('.rc-tile', { hasText: 'Set before consent' }).locator('.rc-tile-n');
	await expect(leaks).toHaveText('0');
	await expect(page.locator('table.rc-items .rc-leak')).toHaveCount(0);
});
