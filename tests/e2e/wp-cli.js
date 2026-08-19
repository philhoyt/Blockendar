/**
 * Shared WP-CLI helper for end-to-end tests.
 *
 * Commands run inside the wp-env container, so the suite needs `npm run env:start`
 * before it will pass.
 */

const { execFileSync } = require( 'child_process' );

/**
 * Run a WP-CLI command inside wp-env and return its output.
 *
 * @param {string[]} args WP-CLI arguments.
 * @return {string} Command output with wp-env's own status lines removed.
 */
function wpCli( args ) {
	const raw = execFileSync(
		'npx',
		[ 'wp-env', 'run', 'cli', '--', 'wp', ...args ],
		{ encoding: 'utf8', cwd: process.cwd() }
	).replace( /\r/g, '' );

	// wp-env wraps output in its own status lines ("ℹ Starting …", "✔ Ran …");
	// strip them so callers see only what wp itself printed.
	return raw
		.split( '\n' )
		.filter(
			( line ) => ! /^\s*[ℹ✔✖⚠]/.test( line ) && '' !== line.trim()
		)
		.join( '\n' )
		.trim();
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

module.exports = { wpCli, wpCliId };
