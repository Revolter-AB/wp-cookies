// @ts-check
const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
	testDir: __dirname,
	testMatch: /.*\.spec\.js$/,
	timeout: 60000,
	expect: { timeout: 10000 },
	fullyParallel: false,
	workers: 1,
	retries: 0,
	reporter: [['list']],
	use: {
		baseURL: 'https://wptests.test',
		ignoreHTTPSErrors: true,
		headless: true,
		channel: 'chrome',
		actionTimeout: 15000,
		navigationTimeout: 30000,
		trace: 'off',
		screenshot: 'off',
		video: 'off',
	},
	// The scan runs first: it builds the declaration that withdrawal and the cookie tables use.
	projects: [
		{ name: 'scan', testMatch: /scan\.spec\.js$/, use: { browserName: 'chromium', channel: 'chrome' } },
		{ name: 'visitor', testMatch: /consent\.spec\.js$/, dependencies: ['scan'], use: { browserName: 'chromium', channel: 'chrome' } },
	],
});
