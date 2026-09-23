#!/usr/bin/env node
/**
 * Emit the source→bundle map that `wp i18n make-json` needs.
 *
 * WordPress looks up a JS translation catalogue by the md5 of the script path
 * it actually enqueues — `build/editor/index.js`, say. But `wp i18n make-json`
 * names its output after the source references in the .po file, which point at
 * `src/editor/DateTimePanel.jsx` and friends because that is what `make-pot`
 * scanned. Without a map the two never meet: the catalogues are generated and
 * then never found at runtime, and every string silently stays in English.
 *
 * The map is derived rather than hand-maintained so a new block cannot be
 * added without its translations coming along.
 *
 * Nothing under src/blocks/shared/ carries a translatable string, which is
 * what keeps this a one-to-one mapping: a shared module that did would be
 * bundled into several outputs, and --use-map allows only one destination per
 * source.
 */

const fs = require( 'fs' );
const path = require( 'path' );

const ROOT = path.resolve( __dirname, '..' );
const SRC = path.join( ROOT, 'src' );

/**
 * Every .js/.jsx file under a directory, recursively.
 *
 * @param {string} dir Absolute directory path.
 * @return {string[]} Absolute file paths.
 */
function walk( dir ) {
	if ( ! fs.existsSync( dir ) ) {
		return [];
	}

	return fs
		.readdirSync( dir, { withFileTypes: true } )
		.flatMap( ( entry ) => {
			const full = path.join( dir, entry.name );

			if ( entry.isDirectory() ) {
				// Tests never ship, so their strings are not translated.
				return entry.name === '__tests__' ? [] : walk( full );
			}

			return /\.(js|jsx)$/.test( entry.name ) ? [ full ] : [];
		} );
}

/**
 * The built bundle a source file ends up inside.
 *
 * @param {string} relative Source path relative to the plugin root.
 * @return {string|null} The bundle path, or null when the file has no bundle.
 */
function bundleFor( relative ) {
	if ( relative.startsWith( 'src/admin/' ) ) {
		return 'build/admin/index.js';
	}

	if ( relative.startsWith( 'src/editor/' ) ) {
		return 'build/editor/index.js';
	}

	const block = relative.match( /^src\/blocks\/([^/]+)\// );

	if ( ! block || block[ 1 ] === 'shared' ) {
		return null;
	}

	// view scripts are their own entry point; everything else in a block
	// folder is reachable from its index.js.
	return relative.endsWith( '/view.js' ) || relative.endsWith( '/view.jsx' )
		? `build/blocks/${ block[ 1 ] }/view.js`
		: `build/blocks/${ block[ 1 ] }/index.js`;
}

const map = {};

for ( const file of walk( SRC ) ) {
	const relative = path.relative( ROOT, file ).split( path.sep ).join( '/' );
	const bundle = bundleFor( relative );

	if ( bundle ) {
		map[ relative ] = bundle;
	}
}

const out = path.join( ROOT, 'languages', '.i18n-map.json' );
fs.writeFileSync( out, JSON.stringify( map, null, '\t' ) + '\n' );

// eslint-disable-next-line no-console
console.log(
	`Wrote ${ Object.keys( map ).length } mappings to ${ path.relative(
		ROOT,
		out
	) }`
);
