/**
 * Shared wp-env helpers for end-to-end tests.
 *
 * Commands run inside a wp-env container, so the suite needs `npm run env:start`
 * before it will pass.
 */

const { execFileSync } = require( 'child_process' );

/**
 * Run a command inside a wp-env container and return its stdout.
 *
 * wp-env's own status lines ("ℹ Starting …", "✔ Ran …") are written by ora to
 * stderr, which execFileSync passes through to the terminal rather than
 * capturing, so they never appear in the returned text — only the command's
 * own stdout does. Blank lines are dropped so callers can split on newlines
 * without guarding against them.
 *
 * @param {string}   container Container name: 'cli' or 'tests-cli'.
 * @param {string[]} args      Command and arguments to run inside it.
 * @return {string} Trimmed stdout with carriage returns and blank lines removed.
 */
function wpEnvRun( container, args ) {
	const raw = execFileSync(
		'npx',
		[ 'wp-env', 'run', container, '--', ...args ],
		{ encoding: 'utf8', cwd: process.cwd() }
	).replace( /\r/g, '' );

	return raw
		.split( '\n' )
		.filter( ( line ) => '' !== line.trim() )
		.join( '\n' )
		.trim();
}

/**
 * Run a WP-CLI command inside wp-env's cli container and return its output.
 *
 * @param {string[]} args WP-CLI arguments.
 * @return {string} Command output.
 */
function wpCli( args ) {
	return wpEnvRun( 'cli', [ 'wp', ...args ] );
}

/**
 * Run a WP-CLI command and return the first integer in its output.
 *
 * @param {string[]} args WP-CLI arguments.
 * @return {string} The captured ID.
 */
function wpCliId( args ) {
	const out = wpCli( args );
	const match = out.match( /\d+/ );

	if ( ! match ) {
		throw new Error(
			`Expected an ID from wp ${ args.join( ' ' ) }, got: ${ out }`
		);
	}

	return match[ 0 ];
}

module.exports = { wpEnvRun, wpCli, wpCliId };
