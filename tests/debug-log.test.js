/**
 * Unit tests for the debug.log reader.
 *
 * The container spawn is mocked; these cover the parts that are pure string
 * and arithmetic logic — the pattern, the allowlist, the byte mark and the
 * tail offset — which is where an off-by-one or a too-loose regex would hide.
 */

jest.mock( './e2e/wp-cli', () => ( { wpEnvRun: jest.fn() } ) );

const { wpEnvRun } = require( './e2e/wp-cli' );
const {
	LOG,
	ALLOWLIST,
	filterErrorLines,
	mark,
	newErrorLines,
} = require( './debug-log' );

const STAMP = '[24-Sep-2026 12:25:28 UTC]';

const ERROR_LINES = [
	`${ STAMP } PHP Notice:  Function wpdb::prepare was called incorrectly.`,
	`${ STAMP } PHP Warning:  Undefined array key "venue" in /var/www/html/x.php on line 9`,
	`${ STAMP } PHP Deprecated:  strlen(): Passing null to parameter #1 ($string) of type string is deprecated`,
	`${ STAMP } PHP Fatal error:  Uncaught TypeError: generate_feed(): Argument #1 must be of type array`,
	`${ STAMP } PHP Parse error:  syntax error, unexpected token "}"`,
	`${ STAMP } PHP Recoverable fatal error:  Object of class WP_Post could not be converted to string`,
	`${ STAMP } WordPress database error Unknown column 'nope' for query SELECT nope FROM wp_posts made by require`,
];

const NOISE_LINES = [
	`${ STAMP } Automatic updates starting...`,
	`${ STAMP } Automatic updates complete.`,
	"ℹ Starting 'wp post create --porcelain' on the cli container.",
	"✔ Ran `wp post create --porcelain` in 'cli'. (in 0s 512ms)",
	'npm warn exec The following package was not found and will be installed: wp-env',
	'#0 /var/www/html/wp-includes/class-wpdb.php(2187): wpdb->prepare()',
	'  thrown in /var/www/html/wp-content/plugins/blockendar/includes/ICS/Exporter.php on line 40',
	'Warning: this is a word in a sentence, not a PHP warning',
];

afterEach( () => {
	wpEnvRun.mockReset();
	ALLOWLIST.length = 0;
} );

describe( 'filterErrorLines', () => {
	it.each( ERROR_LINES )( 'matches: %s', ( line ) => {
		expect( filterErrorLines( line ) ).toEqual( [ line ] );
	} );

	it.each( NOISE_LINES )( 'ignores: %s', ( line ) => {
		expect( filterErrorLines( line ) ).toEqual( [] );
	} );

	it( 'keeps only the error lines from a mixed log, in order', () => {
		const text = [
			NOISE_LINES[ 0 ],
			ERROR_LINES[ 0 ],
			NOISE_LINES[ 5 ],
			ERROR_LINES[ 6 ],
			'',
		].join( '\n' );

		expect( filterErrorLines( text ) ).toEqual( [
			ERROR_LINES[ 0 ],
			ERROR_LINES[ 6 ],
		] );
	} );

	it( 'honours an allowlist entry and nothing else', () => {
		ALLOWLIST.push( /Function wpdb::prepare was called incorrectly/ );

		expect(
			filterErrorLines(
				[ ERROR_LINES[ 0 ], ERROR_LINES[ 1 ] ].join( '\n' )
			)
		).toEqual( [ ERROR_LINES[ 1 ] ] );
	} );

	it( 'still matches a line with multibyte characters in the message', () => {
		const line = `${ STAMP } PHP Notice:  Blockendar — ‘venue’ isn’t set in /x.php on line 1`;

		expect( filterErrorLines( line ) ).toEqual( [ line ] );
	} );
} );

describe( 'mark', () => {
	it( 'returns the byte size stat reports', () => {
		wpEnvRun.mockReturnValue( '7723' );

		expect( mark( 'cli' ) ).toBe( 7723 );
		expect( wpEnvRun ).toHaveBeenCalledWith( 'cli', [
			'sh',
			'-c',
			expect.stringContaining( `stat -c %s ${ LOG }` ),
		] );
	} );

	it( 'reads a missing file as 0 bytes', () => {
		// The shell fallback prints 0 when stat fails.
		wpEnvRun.mockReturnValue( '0' );
		expect( mark( 'cli' ) ).toBe( 0 );

		// And garbage from a broken container as 0, never NaN.
		wpEnvRun.mockReturnValue( '' );
		expect( mark( 'cli' ) ).toBe( 0 );
	} );

	it( 'targets the container it was asked about', () => {
		wpEnvRun.mockReturnValue( '12' );
		mark( 'tests-cli' );

		expect( wpEnvRun.mock.calls[ 0 ][ 0 ] ).toBe( 'tests-cli' );
	} );
} );

describe( 'newErrorLines', () => {
	it( 'tails from the byte after the mark — tail -c +N is 1-indexed', () => {
		wpEnvRun
			.mockReturnValueOnce( '8000' ) // stat
			.mockReturnValueOnce( ERROR_LINES[ 2 ] ); // tail

		const result = newErrorLines( 'cli', 7000 );

		expect( result ).toEqual( {
			lines: [ ERROR_LINES[ 2 ] ],
			truncated: false,
			size: 8000,
		} );
		expect( wpEnvRun.mock.calls[ 1 ][ 1 ][ 2 ] ).toContain(
			`tail -c +7001 ${ LOG }`
		);
	} );

	it( 'reads the whole file when it is shorter than the mark', () => {
		wpEnvRun
			.mockReturnValueOnce( '500' )
			.mockReturnValueOnce( ERROR_LINES[ 0 ] );

		const result = newErrorLines( 'cli', 7000 );

		expect( result.truncated ).toBe( true );
		expect( result.lines ).toEqual( [ ERROR_LINES[ 0 ] ] );
		expect( wpEnvRun.mock.calls[ 1 ][ 1 ][ 2 ] ).toContain(
			`tail -c +1 ${ LOG }`
		);
	} );

	it( 'does not tail an empty or missing file', () => {
		wpEnvRun.mockReturnValueOnce( '0' );

		expect( newErrorLines( 'cli', 0 ) ).toEqual( {
			lines: [],
			truncated: false,
			size: 0,
		} );
		expect( wpEnvRun ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'returns nothing when only noise was appended', () => {
		wpEnvRun
			.mockReturnValueOnce( '9000' )
			.mockReturnValueOnce( NOISE_LINES.join( '\n' ) );

		expect( newErrorLines( 'cli', 8000 ).lines ).toEqual( [] );
	} );
} );
