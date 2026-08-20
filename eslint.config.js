/**
 * ESLint flat config for Blockendar.
 *
 * Replaces the legacy .eslintrc.js — ESLint 9 (shipped with @wordpress/scripts 34)
 * no longer reads eslintrc files, and @wordpress/eslint-plugin 25 is flat-config only.
 *
 * Extends the @wordpress/scripts default config and layers project overrides on top.
 */

const wpScriptsConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	// Vendored third-party code is not ours to lint.
	{
		ignores: [ 'lib/**', 'build/**', 'vendor/**', 'node_modules/**' ],
	},

	...wpScriptsConfig,

	{
		// E2E specs run in Node, but the callbacks passed to page.evaluate() run in
		// the browser under test. Without these the linter flags browser globals
		// that are perfectly valid inside those callbacks.
		files: [ 'tests/e2e/**/*.js' ],
		languageOptions: {
			globals: {
				document: 'readonly',
				getComputedStyle: 'readonly',
				window: 'readonly',
			},
		},
	},

	{
		settings: {
			// @wordpress/* packages are provided by WordPress at runtime and
			// externalised by the build, so they are not resolvable on disk.
			'import/core-modules': [
				'@wordpress/blocks',
				'@wordpress/block-editor',
				'@wordpress/components',
				'@wordpress/element',
				'@wordpress/i18n',
				'@wordpress/data',
				'@wordpress/plugins',
				'@wordpress/edit-post',
				'@wordpress/primitives',
				'@wordpress/api-fetch',
				'@wordpress/url',
				'@wordpress/date',
				'@wordpress/compose',
				'@wordpress/hooks',
				'@wordpress/notices',
				'@wordpress/core-data',
				'@wordpress/server-side-render',
			],
		},
		rules: {
			// Experimental APIs are intentionally used here because the WordPress
			// version in use does not yet export the stable equivalents at runtime.
			'@wordpress/no-unsafe-wp-apis': 'off',
		},
	},
];
