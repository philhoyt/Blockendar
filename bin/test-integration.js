#!/usr/bin/env node
/**
 * Run the integration suite, and fail on PHP errors PHPUnit did not.
 *
 * PHPUnit converts a notice, warning or deprecation raised *inside a test* into
 * a failure. It cannot see one raised outside a test — while the plugin loads
 * on muplugins_loaded, while Schema::create_tables() runs on init, in
 * set_up_before_class() — and a `WordPress database error` is never thrown at
 * all: wpdb::print_error() hands it to error_log() and carries on. On the tests
 * site error_log is the path /dev/stderr, so all of that reaches this process's
 * stderr and, until now, scrolled past.
 *
 * This wraps the same wp-env command `npm run test:integration` always ran,
 * forwards both streams as they arrive, and after the child exits scans what
 * went to stderr for the shape WordPress logs errors in. Any hit fails the run
 * even when PHPUnit printed OK. The child's own exit code is preserved when
 * there are none.
 *
 * Because stdout is a pipe here rather than a TTY, wp-env runs docker with -T,
 * which is what keeps stdout and stderr apart. It also means PHPUnit's
 * colors="true" (AUTO) would fall back to monochrome, hence --colors=always.
 * Any extra arguments are passed through to PHPUnit, e.g. --filter.
 */

const { spawn } = require( 'child_process' );
const { filterErrorLines } = require( '../tests/debug-log' );

const PHPUNIT_ARGS = [
	'wp-env',
	'run',
	'tests-cli',
	'--env-cwd=wp-content/plugins/blockendar',
	'--',
	'vendor/bin/phpunit',
	'-c',
	'phpunit-integration.xml',
	'--colors=always',
	...process.argv.slice( 2 ),
];

const child = spawn( 'npx', PHPUNIT_ARGS, {
	cwd: process.cwd(),
	stdio: [ 'inherit', 'pipe', 'pipe' ],
} );

// Chunks are collected as Buffers and decoded once at the end: a multibyte
// character split across two chunks would otherwise decode as garbage.
const stderrChunks = [];

child.stdout.on( 'data', ( chunk ) => process.stdout.write( chunk ) );
child.stderr.on( 'data', ( chunk ) => {
	stderrChunks.push( chunk );
	process.stderr.write( chunk );
} );

// A Ctrl-C here should stop PHPUnit in the container too, not orphan it.
for ( const signal of [ 'SIGINT', 'SIGTERM' ] ) {
	process.on( signal, () => child.kill( signal ) );
}

child.on( 'error', ( error ) => {
	process.stderr.write( `Could not start wp-env: ${ error.message }\n` );
	process.exit( 1 );
} );

child.on( 'close', ( code, signal ) => {
	const stderr = Buffer.concat( stderrChunks ).toString( 'utf8' );
	// wp-env re-prints the child's stderr on failure, and PHP's
	// ignore_repeated_errors already collapses identical consecutive lines,
	// so this is detection, not a count.
	const hits = [ ...new Set( filterErrorLines( stderr ) ) ];

	if ( hits.length > 0 ) {
		process.stderr.write(
			'\n' +
				`${ hits.length } PHP error line(s) reached stderr during the integration run.\n` +
				'PHPUnit does not see errors raised outside a test (plugin load, schema\n' +
				'creation, set_up_before_class) or WordPress database errors, so this run\n' +
				'fails regardless of the result above.\n\n' +
				hits.join( '\n' ) +
				'\n\nLines that are not ours to fix belong in ALLOWLIST in tests/debug-log.js, with a reason.\n'
		);
		process.exit( 1 );
	}

	if ( signal ) {
		process.stderr.write( `PHPUnit was stopped by ${ signal }.\n` );
		process.exit( 1 );
	}

	process.exit( code === null ? 1 : code );
} );
