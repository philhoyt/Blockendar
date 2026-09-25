/**
 * The build and start scripts must pass --blocks-manifest.
 *
 * BlockRegistrar registers every block from build/blocks-manifest.php through
 * wp_register_block_types_from_metadata_collection(), and returns without
 * registering anything when that file is missing. wp-scripts only writes the
 * file when the flag is present, so dropping it from either script would ship
 * a plugin with no blocks and no error to say why. The integration suite would
 * catch it after a build; this catches it in the unit run, before one.
 */

const path = require( 'path' );

const { scripts } = require( path.join( __dirname, '..', 'package.json' ) );

describe( 'package.json scripts', () => {
	it.each( [ 'build', 'start' ] )(
		'%s passes --blocks-manifest to wp-scripts',
		( name ) => {
			expect( scripts[ name ] ).toMatch(
				/^wp-scripts (build|start)\b.*\s--blocks-manifest(\s|$)/
			);
		}
	);
} );
