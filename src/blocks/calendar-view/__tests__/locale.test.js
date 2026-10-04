import { readdirSync } from 'fs';
import { dirname, join } from 'path';
import { eventTimeFormat, localeCandidates } from '../locale';
import { LOCALE_LOADERS } from '../locale-loaders';

describe( 'localeCandidates', () => {
	test( 'tries the region, then the language', () => {
		expect( localeCandidates( 'de_DE' ) ).toEqual( [ 'de-de', 'de' ] );
		expect( localeCandidates( 'pt_BR' ) ).toEqual( [ 'pt-br', 'pt' ] );
		expect( localeCandidates( 'zh_CN' ) ).toEqual( [ 'zh-cn', 'zh' ] );
	} );

	test( 'ignores a variant after the region', () => {
		expect( localeCandidates( 'de_DE_formal' ) ).toEqual( [
			'de-de',
			'de',
		] );
	} );

	test( 'a bare language is itself', () => {
		expect( localeCandidates( 'fr' ) ).toEqual( [ 'fr' ] );
	} );

	test( 'English loads nothing unless there is a regional file to try', () => {
		expect( localeCandidates( 'en_US' ) ).toEqual( [ 'en-us' ] );
		expect( localeCandidates( 'en_GB' ) ).toEqual( [ 'en-gb' ] );
		expect( localeCandidates( 'en' ) ).toEqual( [] );
	} );

	test( 'nothing usable gives nothing to try', () => {
		expect( localeCandidates( '' ) ).toEqual( [] );
		expect( localeCandidates( undefined ) ).toEqual( [] );
		expect( localeCandidates( '../../etc' ) ).toEqual( [] );
	} );
} );

describe( 'eventTimeFormat', () => {
	test( '12-hour with a meridiem', () => {
		expect( eventTimeFormat( 'g:i a' ) ).toEqual( {
			hour: 'numeric',
			minute: '2-digit',
			hour12: true,
			meridiem: 'short',
		} );
	} );

	test( '24-hour with a leading zero', () => {
		expect( eventTimeFormat( 'H:i' ) ).toEqual( {
			hour: '2-digit',
			minute: '2-digit',
			hour12: false,
		} );
	} );

	test( '24-hour without one', () => {
		expect( eventTimeFormat( 'G:i' ) ).toMatchObject( {
			hour: 'numeric',
			hour12: false,
		} );
	} );

	test( '12-hour with a leading zero and no meridiem', () => {
		expect( eventTimeFormat( 'h:i' ) ).toEqual( {
			hour: '2-digit',
			minute: '2-digit',
			hour12: true,
			meridiem: false,
		} );
	} );

	test( 'an escaped letter is not a format character', () => {
		expect( eventTimeFormat( 'G\\h i' ).hour12 ).toBe( false );
	} );

	test( 'no format is the WordPress default', () => {
		expect( eventTimeFormat( '' ) ).toMatchObject( { hour12: true } );
	} );
} );

describe( 'LOCALE_LOADERS', () => {
	test( 'lists every locale the installed FullCalendar ships', () => {
		const directory = join(
			dirname( require.resolve( '@fullcalendar/core/package.json' ) ),
			'locales'
		);
		const shipped = readdirSync( directory )
			.filter( ( file ) => /^[a-z-]+\.js$/.test( file ) )
			.map( ( file ) => file.replace( /\.js$/, '' ) )
			.sort();

		expect( shipped.length ).toBeGreaterThan( 50 );
		expect( Object.keys( LOCALE_LOADERS ).sort() ).toEqual( shipped );
	} );
} );
