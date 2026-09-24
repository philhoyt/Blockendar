/**
 * Playwright configuration for Blockendar end-to-end tests.
 *
 * Assumes wp-env is already running. Start it with `npm run env:start`.
 * The port matches .wp-env.json (or a local .wp-env.override.json).
 *
 * Three projects run in sequence:
 *
 *   debug-log-mark   records where the dev site's debug.log ends
 *   chromium         the suite proper, which depends on the mark
 *   debug-log-check  teardown of the mark: fails if PHP logged an error since
 *
 * So a notice, warning, deprecation, fatal or database error raised by any
 * request the suite makes — or by a wpCli() fixture, or by WP-Cron firing from
 * a page load — fails the run and names the line. See tests/debug-log.js.
 *
 * Passing --project, a file path or -g never skips the setup and teardown
 * projects; only --no-deps does. An interrupted run (Ctrl-C) exits before the
 * teardown and is therefore unchecked, but exits non-zero anyway.
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
			name: 'debug-log-mark',
			testMatch: /debug-log\.setup\.js$/,
			teardown: 'debug-log-check',
			// Re-running the mark or the check would just spawn wp-env again for
			// the same answer and print the same failure twice.
			retries: 0,
		},
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
			dependencies: [ 'debug-log-mark' ],
			// The default testMatch already skips these — they are not *.spec.js —
			// but the design should not hang on a filename convention.
			testIgnore: /debug-log\.(setup|teardown)\.js$/,
		},
		{
			name: 'debug-log-check',
			testMatch: /debug-log\.teardown\.js$/,
			retries: 0,
		},
	],
} );
