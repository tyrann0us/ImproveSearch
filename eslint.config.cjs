/**
 * ESLint flat config for `wp-scripts` v32+ (ESLint v10).
 *
 * Mirrors the default config that `wp-scripts lint-js` would apply
 * (the recommended config of `@wordpress/eslint-plugin`), and adds
 * project-specific globals and per-file rule tweaks.
 */

const wpPlugin = require( '@wordpress/eslint-plugin' );

module.exports = [
	{
		ignores: [
			'**/build/**',
			'**/node_modules/**',
			'**/vendor/**',
			'**/coverage/**',
			'**/playwright-report/**',
			'**/test-results/**',
		],
	},

	...wpPlugin.configs.recommended,

	{
		// The Jest globals, declared here rather than taken from the
		// plugin's test-unit config: that one switched to Vitest in
		// @wordpress/eslint-plugin 27 and no longer defines them.
		// Covers every file under tests/jest, not only `*.test.js`,
		// so shared helpers like mw-mock.js are included.
		files: [ 'tests/jest/**/*.js' ],
		languageOptions: {
			globals: {
				afterEach: 'readonly',
				beforeEach: 'readonly',
				describe: 'readonly',
				expect: 'readonly',
				global: 'readonly',
				it: 'readonly',
				jest: 'readonly',
				test: 'readonly',
			},
		},
	},

	{
		languageOptions: {
			globals: {
				mw: 'readonly',
				$: 'readonly',
				location: 'readonly',
				MutationObserver: 'readonly',
			},
		},
	},

	{
		files: [ 'tests/playwright/**/*.js' ],
		rules: {
			'react-hooks/rules-of-hooks': 'off',
		},
	},
];
