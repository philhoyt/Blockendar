/**
 * ESLint flat config for Blockendar.
 *
 * Replaces the legacy .eslintrc.js — ESLint 9 (shipped with @wordpress/scripts 34)
 * no longer reads eslintrc files, and @wordpress/eslint-plugin 25 is flat-config only.
 *
 * Extends the @wordpress/scripts default config and layers project overrides on top.
 */

const wpScriptsConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );
const jestPlugin = require( 'eslint-plugin-jest' );

module.exports = [
	// Vendored third-party code is not ours to lint.
	{
		// plans/ is git-ignored scratch space and can hold vendored third-party
		// sources; linting it added ~20 minutes to every run.
		ignores: [
			'lib/**',
			'build/**',
			'vendor/**',
			'node_modules/**',
			'plans/**',
			'test-results/**',
			'playwright-report/**',
		],
	},
	{
		// ESLint flat config discovers only *.js, *.cjs, *.mjs on its own.
		// Name every other extension here or the rules below never see it.
		files: [ '**/*.jsx', '**/*.ts', '**/*.tsx' ],
	},

	...wpScriptsConfig,

	{
		// Playwright specs, not Vitest tests: the wp-scripts default config applies
		// the Vitest unit-test rules to every *.spec.js, and this one misreads prose
		// such as "it (see the header)" in a comment as a commented-out test.
		files: [ 'tests/e2e/**/*.js' ],
		rules: {
			'vitest/no-commented-out-tests': 'off',
		},
	},
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
				'@wordpress/editor',
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
	// The unit suite stays on Jest (wp-scripts test-unit-jest); the default
	// test override in @wordpress/eslint-plugin 27+ targets Vitest instead.
	{
		...jestPlugin.configs[ 'flat/recommended' ],
		files: [ 'tests/**/*.test.js', 'src/**/__tests__/**/*.js' ],
	},
];
