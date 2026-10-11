import { statusHasReason, statusOptions, statusUpdate } from '../status';

describe( 'statusHasReason', () => {
	it.each( [
		[ 'cancelled', true ],
		[ 'postponed', true ],
		[ 'sold_out', true ],
		[ 'scheduled', false ],
		[ '', false ],
		[ undefined, false ],
	] )( 'for "%s" is %s', ( status, expected ) => {
		expect( statusHasReason( status ) ).toBe( expected );
	} );
} );

describe( 'statusUpdate', () => {
	it( 'leaves the reason alone when the event is still off', () => {
		expect( statusUpdate( 'postponed' ) ).toEqual( {
			blockendar_status: 'postponed',
		} );
	} );

	it( 'clears the reason when the event is put back on', () => {
		expect( statusUpdate( 'scheduled' ) ).toEqual( {
			blockendar_status: 'scheduled',
			blockendar_status_reason: '',
		} );
	} );
} );

describe( 'statusOptions', () => {
	const defaults = [ 'scheduled', 'cancelled', 'postponed', 'sold_out' ];

	it( 'offers the four shipped statuses when the server sent none', () => {
		expect( statusOptions( undefined ).map( ( o ) => o.value ) ).toEqual(
			defaults
		);
		expect( statusOptions( [] ).map( ( o ) => o.value ) ).toEqual(
			defaults
		);
		expect( statusOptions( 'nonsense' ).map( ( o ) => o.value ) ).toEqual(
			defaults
		);
	} );

	it( 'offers what the server sent, so a filtered status can be chosen', () => {
		const sent = [
			{ value: 'scheduled', label: 'Scheduled' },
			{ value: 'waitlist', label: 'Waiting list' },
		];

		expect( statusOptions( sent ) ).toEqual( sent );
	} );

	it( 'drops malformed entries and labels a status by its value when it has none', () => {
		const sent = [
			null,
			{ label: 'No value' },
			{ value: '', label: 'Empty value' },
			{ value: 'waitlist' },
		];

		expect( statusOptions( sent ) ).toEqual( [
			{ value: 'waitlist', label: 'waitlist' },
		] );
	} );
} );
