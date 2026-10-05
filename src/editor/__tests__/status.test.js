import { statusHasReason, statusUpdate } from '../status';

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
