/**
 * Playwright configuration for Blockendar end-to-end tests.
 *
 * Assumes wp-env is already running. Start it with `npm run env:start`.
 * The port matches .wp-env.json (or a local .wp-env.override.json).
 */

const { defineConfig, devices } = require( '@playwright/test' );

const PORT = process.env.WP_PORT ?? '8890';

module.exports = defineConfig( {
	testDir: './tests/e2e',
	// The suite writes posts through WP-CLI, so parallel workers would race.
	workers: 1,
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 1 : 0,
	reporter: process.env.CI ? 'dot' : 'list',
	use: {
		baseURL: `http://localhost:${ PORT }`,
		trace: 'retain-on-failure',
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
} );
