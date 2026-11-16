/**
 * ESLint flat config for `@wordpress/scripts` v32+.
 *
 * Applies `@wordpress/eslint-plugin/recommended` plus Jest rules for
 * tests/jest, and adds project-specific globals and per-file rule tweaks.
 */

const wpPlugin = require( '@wordpress/eslint-plugin' );
const jestPlugin = require( 'eslint-plugin-jest' );

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
		...jestPlugin.configs[ 'flat/recommended' ],
		// wpPlugin's `test-unit` config targets Vitest since v27, and this
		// suite runs on Jest. Matching all of tests/jest also gives shared
		// helpers like mw-mock.js the Jest globals.
		files: [ 'tests/jest/**/*.js' ],
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
