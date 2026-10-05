import { readSettings } from '../settings-load';

describe( 'readSettings', () => {
	it( 'returns the settings the endpoint sent', () => {
		const data = {
			title: 'Site',
			blockendar_settings: { date_format: 'F j' },
		};

		expect( readSettings( data, 'blockendar_settings' ) ).toEqual( {
			ok: true,
			settings: { date_format: 'F j' },
		} );
	} );

	it.each( [
		[
			'null, as WordPress answers for a value that does not fit',
			{ blockendar_settings: null },
		],
		[ 'missing', { title: 'Site' } ],
		[ 'a list', { blockendar_settings: [] } ],
		[ 'text', { blockendar_settings: 'F j' } ],
		[ 'no response at all', null ],
	] )( 'reports a failure to load when the setting is %s', ( _, data ) => {
		expect( readSettings( data, 'blockendar_settings' ).ok ).toBe( false );
	} );
} );
