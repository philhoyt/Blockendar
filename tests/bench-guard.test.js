/**
 * Every benchmark script in bin/bench/ must begin with the WP_CLI guard.
 *
 * The directory sits inside the plugin, which wp-env serves over HTTP. The
 * guard makes a direct request exit before the script does anything; .htaccess
 * is the other half, where Apache honours it. This test is the part that cannot
 * be forgotten when someone copies example.php and deletes the top.
 */

const fs = require( 'fs' );
const path = require( 'path' );

const BENCH_DIR = path.join( __dirname, '..', 'bin', 'bench' );

// After the opening tag, an optional docblock and any line comments or blank
// lines, the first statement has to be the guard — exactly this shape.
const STARTS_WITH_GUARD =
	/^<\?php\s*(?:\/\*\*[\s\S]*?\*\/\s*)?(?:\/\/[^\n]*\n\s*)*if \( ! defined\( 'WP_CLI' \) \|\| ! WP_CLI \) \{\s*exit;\s*\}/;

const scripts = fs
	.readdirSync( BENCH_DIR )
	.filter( ( name ) => name.endsWith( '.php' ) )
	.sort();

describe( 'bin/bench', () => {
	it( 'ships at least the example script', () => {
		expect( scripts ).toContain( 'example.php' );
	} );

	it.each( scripts )( '%s opens with the WP_CLI guard', ( name ) => {
		const source = fs.readFileSync( path.join( BENCH_DIR, name ), 'utf8' );

		expect( source ).toMatch( STARTS_WITH_GUARD );
	} );

	it( 'denies direct HTTP access with .htaccess', () => {
		const htaccess = fs.readFileSync(
			path.join( BENCH_DIR, '.htaccess' ),
			'utf8'
		);

		expect( htaccess ).toMatch( /^Require all denied\s*$/m );
	} );
} );
