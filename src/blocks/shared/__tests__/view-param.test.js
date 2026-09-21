/**
 * The one rule behind every URL the view switcher rewrites: carry the chosen
 * mode, except that the default mode is spelled as "no parameter".
 */

import { withViewParam } from '../view-param';

const BASE = 'https://example.test/events/?utm=x';

describe( 'withViewParam', () => {
	it( 'sets the parameter for a non-default mode', () => {
		expect(
			withViewParam(
				'/events/?blockendar_page=2',
				'blockendar_view',
				'grid',
				'list',
				BASE
			)
		).toBe(
			'https://example.test/events/?blockendar_page=2&blockendar_view=grid'
		);
	} );

	it( 'deletes the parameter for the default mode rather than naming it', () => {
		expect(
			withViewParam(
				'/events/?blockendar_view=grid&blockendar_page=2',
				'blockendar_view',
				'list',
				'list',
				BASE
			)
		).toBe( 'https://example.test/events/?blockendar_page=2' );
	} );

	it( 'replaces an existing value instead of appending a second one', () => {
		const result = withViewParam(
			'/events/?blockendar_view=list',
			'blockendar_view',
			'grid',
			'list',
			BASE
		);

		expect( result ).toBe(
			'https://example.test/events/?blockendar_view=grid'
		);
	} );

	it( 'keeps repeated array-style parameters intact', () => {
		expect(
			withViewParam(
				'/events/?blockendar_type%5B%5D=1&blockendar_type%5B%5D=2',
				'blockendar_view',
				'grid',
				'list',
				BASE
			)
		).toBe(
			'https://example.test/events/?blockendar_type%5B%5D=1&blockendar_type%5B%5D=2&blockendar_view=grid'
		);
	} );

	it( 'keeps a fragment', () => {
		expect(
			withViewParam(
				'/events/#results',
				'blockendar_view',
				'grid',
				'list',
				BASE
			)
		).toBe( 'https://example.test/events/?blockendar_view=grid#results' );
	} );

	it( 'handles a bare path with no query string', () => {
		expect(
			withViewParam( '/events/', 'blockendar_view', 'grid', 'list', BASE )
		).toBe( 'https://example.test/events/?blockendar_view=grid' );
	} );

	it( 'resolves a relative href against the base', () => {
		expect(
			withViewParam( 'page/2/', 'blockendar_view', 'grid', 'list', BASE )
		).toBe( 'https://example.test/events/page/2/?blockendar_view=grid' );
	} );

	it( 'leaves an absolute href on its own origin', () => {
		expect(
			withViewParam(
				'https://other.test/a/?x=1',
				'blockendar_view',
				'grid',
				'list',
				BASE
			)
		).toBe( 'https://other.test/a/?x=1&blockendar_view=grid' );
	} );

	it( 'is a no-op for the default mode on a URL that never had the parameter', () => {
		expect(
			withViewParam(
				'/events/?blockendar_page=3',
				'blockendar_view',
				'list',
				'list',
				BASE
			)
		).toBe( 'https://example.test/events/?blockendar_page=3' );
	} );

	it( "scopes to the given parameter name, leaving another query's view alone", () => {
		expect(
			withViewParam(
				'/events/?blockendar_view_sidebar=grid',
				'blockendar_view',
				'list',
				'list',
				BASE
			)
		).toBe( 'https://example.test/events/?blockendar_view_sidebar=grid' );
	} );
} );
