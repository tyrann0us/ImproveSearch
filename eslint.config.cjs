/**
 * ESLint flat config for @wordpress/scripts v32+ (ESLint v10).
 *
 * Mirrors the default config that `wp-scripts lint-js` would apply
 * (`@wordpress/eslint-plugin/recommended` + the test-unit override),
 * and adds project-specific globals and per-file rule tweaks.
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

	...wpPlugin.configs[ 'test-unit' ].map( ( c ) => ( {
		...c,
		// Apply the Jest environment (globals like `jest`, `expect`, …)
		// to every file under tests/jest, not only `*.test.js`, so
		// shared helpers like mw-mock.js are covered too.
		files: [
			'**/@(test|__tests__)/**/*.js',
			'**/?(*.)test.js',
			'tests/jest/**/*.js',
		],
	} ) ),

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
