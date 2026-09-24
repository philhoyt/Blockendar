/**
 * Setup project: note where the dev site's debug.log ends before any test runs.
 *
 * The teardown project (debug-log.teardown.js) fails the run if PHP logged an
 * error after this point. Playwright forwards environment variables a setup
 * worker sets to the projects that depend on it and to its teardown, so the
 * mark travels in process.env rather than a file — nothing to clean up and no
 * path that `--output` could move.
 *
 * Wired in playwright.config.js as the `debug-log-mark` project.
 */

const { test } = require( '@playwright/test' );
const { mark } = require( '../debug-log' );

test( 'record where debug.log ends', () => {
	process.env.BLOCKENDAR_DEBUG_LOG_MARK = String( mark( 'cli' ) );
} );
