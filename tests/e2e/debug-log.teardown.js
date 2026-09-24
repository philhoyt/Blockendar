/**
 * Teardown project: fail the run if PHP logged an error while it ran.
 *
 * Reads the mark debug-log.setup.js recorded and asks the dev site's debug.log
 * for every PHP notice, warning, deprecation, fatal or database error appended
 * since. Anything already in the file before the mark is ignored, so a stale
 * entry cannot fail a run it had nothing to do with.
 *
 * This is a real test, not a globalTeardown hook, so a hit is a named failure
 * in the reporter with the offending lines in its message.
 *
 * What it covers is wider than the browser: every wpCli() fixture command boots
 * WordPress with the same WP_DEBUG settings, and WP-Cron fires from E2E page
 * loads on the dev site. A line attributed to a cron hook is a real plugin bug
 * that surfaced on the run where the event happened to be due.
 *
 * Wired in playwright.config.js as the `debug-log-check` project. Ctrl-C ends a
 * run before teardown projects execute, so an interrupted run is not checked;
 * it exits non-zero regardless.
 */

const { test, expect } = require( '@playwright/test' );
const { newErrorLines } = require( '../debug-log' );

test( 'no PHP errors were logged during the run', () => {
	const raw = process.env.BLOCKENDAR_DEBUG_LOG_MARK;

	expect(
		raw,
		'BLOCKENDAR_DEBUG_LOG_MARK is unset: the debug-log-mark setup project ' +
			'did not run or failed, so there is no mark to diff against.'
	).toBeDefined();

	const byteMark = Number( raw );
	const { lines, truncated, size } = newErrorLines( 'cli', byteMark );

	const grew = truncated
		? `debug.log is ${ size } bytes but the mark was ${ byteMark }, so it ` +
		  'was truncated or recreated during the run and the whole file was checked'
		: `debug.log grew from ${ byteMark } to ${ size } bytes during the run`;

	expect(
		lines,
		`${ grew }. PHP errors logged:\n\n${ lines.join( '\n' ) }\n\n` +
			'Each line names the file and line that raised it. If it came from a ' +
			'WP-Cron hook, reproduce with `wp cron event run <hook>`; if from a ' +
			'fixture, with the wp command the spec ran. Lines that are not ours to ' +
			'fix belong in ALLOWLIST in tests/debug-log.js, with a reason.'
	).toEqual( [] );
} );
