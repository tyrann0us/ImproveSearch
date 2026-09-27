// @ts-check
const { defineConfig } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: './e2e',
	timeout: 30_000,
	retries: 1,
	workers: 1,
	expect: {
		timeout: 10_000,
	},
	use: {
		baseURL: 'http://localhost:8080',
		screenshot: 'only-on-failure',
		trace: 'on-first-retry',
	},
	projects: [
		{
			name: 'chromium',
			use: { browserName: 'chromium' },
		},
	],
} );
