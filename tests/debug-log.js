/**
 * Read new PHP error lines out of a wp-env site's debug.log.
 *
 * WordPress writes PHP notices, warnings, deprecations, fatals and database
 * errors to wp-content/debug.log when WP_DEBUG and WP_DEBUG_LOG are on, which
 * they are for the dev site. The E2E suite records the log's byte length before
 * any test runs (the "mark") and, after the last one, fails on any matching line
 * appended since. Everything already in the file before the mark is ignored, so
 * a stale entry from last week cannot fail today's run.
 *
 * All reads go through `wp-env run <container>`, so this works the same on a
 * laptop and in CI without resolving where wp-env keeps the site on the host.
 * Nothing here spawns at import time; Jest loads this module too.
 */

const { wpEnvRun } = require( './e2e/wp-cli' );

/** Path of the log inside every wp-env container. */
const LOG = '/var/www/html/wp-content/debug.log';

/**
 * A PHP error line as WordPress logs it.
 *
 * Anchored on the "PHP <level>:" prefix core writes, or wpdb's own prefix, and
 * never on a bare "Warning": npm prints "npm warn …" to stderr, and wp-env's
 * ora status lines ("ℹ Starting …", "✔ Ran …") land there too. The same shape
 * appears on the tests site's stderr, because its `error_log` ini is the path
 * /dev/stderr, so one pattern serves both suites.
 */
const PATTERN =
	/\bPHP (?:Notice|Warning|Deprecated|Fatal error|Parse error|Recoverable fatal error):|WordPress database error\b/;

/**
 * Lines that match PATTERN but are not ours to fix.
 *
 * Each entry is a RegExp tested against the whole line. Add one only with a
 * comment saying where the line comes from and what would let it be removed.
 *
 * @type {RegExp[]}
 */
const ALLOWLIST = [];

/**
 * Keep only the lines that look like PHP errors and are not allowlisted.
 *
 * Pure: safe to unit test with fixture text.
 *
 * @param {string} text Raw log text.
 * @return {string[]} Matching lines, in order.
 */
function filterErrorLines( text ) {
	return text
		.split( '\n' )
		.filter(
			( line ) =>
				PATTERN.test( line ) &&
				! ALLOWLIST.some( ( allowed ) => allowed.test( line ) )
		);
}

/**
 * Current byte length of the log, or 0 when it does not exist yet.
 *
 * A fresh wp-env has no debug.log until something is logged, so a missing file
 * has to read as "nothing written yet", not as an error — otherwise the first
 * run in a new environment fails before a single test starts.
 *
 * @param {string} container 'cli' for the dev site, 'tests-cli' for the tests site.
 * @return {number} Size in bytes.
 */
function mark( container ) {
	const out = wpEnvRun( container, [
		'sh',
		'-c',
		`stat -c %s ${ LOG } 2>/dev/null || echo 0`,
	] );

	return parseInt( out, 10 ) || 0;
}

/**
 * Error lines appended to the log since a mark.
 *
 * The slice happens inside the container, on bytes, with `tail -c`. Slicing
 * the decoded string in JavaScript would count UTF-16 code units against a
 * byte offset and drift after the first "’" or "—" — both common in WordPress
 * messages. `tail -c +N` is 1-indexed: +N starts *at* byte N, so the first
 * byte after a mark of M is at +(M+1).
 *
 * If the file is now shorter than the mark it was truncated or recreated in
 * between (a manual `> debug.log`, a `wp-env clean`), and the whole file is
 * treated as new so nothing hides behind a stale offset.
 *
 * @param {string} container Container name, as for mark().
 * @param {number} byteMark  Value returned by mark() before the run.
 * @return {{ lines: string[], truncated: boolean, size: number }} Matching
 *         lines, whether the whole file had to be read, and its current size.
 */
function newErrorLines( container, byteMark ) {
	const size = mark( container );
	const truncated = size < byteMark;
	const from = truncated ? 1 : byteMark + 1;

	const text =
		0 === size
			? ''
			: wpEnvRun( container, [
					'sh',
					'-c',
					`tail -c +${ from } ${ LOG } 2>/dev/null || true`,
			  ] );

	return { lines: filterErrorLines( text ), truncated, size };
}

module.exports = {
	LOG,
	PATTERN,
	ALLOWLIST,
	filterErrorLines,
	mark,
	newErrorLines,
};
