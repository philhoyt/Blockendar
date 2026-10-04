/**
 * Tests for the date and time rules of the Date & Time panel.
 */
import {
	endDateUpdates,
	endTimeUpdates,
	minuteOptions,
	nextDay,
	oneHourAfter,
	startTimeUpdates,
} from '../datetime';

describe( 'minuteOptions', () => {
	test( 'offers five-minute steps', () => {
		const values = minuteOptions( 30 ).map( ( o ) => o.value );

		expect( values ).toHaveLength( 12 );
		expect( values[ 0 ] ).toBe( '00' );
		expect( values[ 11 ] ).toBe( '55' );
	} );

	test( 'adds a stored minute that is off the grid, in order', () => {
		const values = minuteOptions( 58 ).map( ( o ) => o.value );

		expect( values ).toHaveLength( 13 );
		expect( values.slice( -2 ) ).toEqual( [ '55', '58' ] );
		expect( minuteOptions( 7 ).map( ( o ) => o.value )[ 2 ] ).toBe( '07' );
	} );
} );

describe( 'nextDay', () => {
	test( 'crosses a month, a year and a leap day', () => {
		expect( nextDay( '2027-03-09' ) ).toBe( '2027-03-10' );
		expect( nextDay( '2027-01-31' ) ).toBe( '2027-02-01' );
		expect( nextDay( '2027-12-31' ) ).toBe( '2028-01-01' );
		expect( nextDay( '2028-02-28' ) ).toBe( '2028-02-29' );
	} );
} );

describe( 'oneHourAfter', () => {
	test( 'adds an hour and keeps the minute', () => {
		expect( oneHourAfter( '19:58' ) ).toBe( '20:58' );
	} );

	test( 'stops at the end of the day', () => {
		expect( oneHourAfter( '23:30' ) ).toBe( '23:59' );
	} );
} );

describe( 'endTimeUpdates', () => {
	const event = {
		startDate: '2027-03-09',
		endDate: '2027-03-09',
		startTime: '22:00',
	};

	test( 'an end before the start is the next day, not a clamp', () => {
		expect( endTimeUpdates( event, '01:00' ) ).toEqual( {
			blockendar_end_time: '01:00',
			blockendar_end_date: '2027-03-10',
		} );
	} );

	test( 'an end equal to the start is the next day too', () => {
		expect( endTimeUpdates( event, '22:00' ).blockendar_end_date ).toBe(
			'2027-03-10'
		);
	} );

	test( 'an end after the start leaves the date alone', () => {
		expect( endTimeUpdates( event, '23:30' ) ).toEqual( {
			blockendar_end_time: '23:30',
		} );
	} );

	test( 'an event already ending on a later day keeps its end date', () => {
		expect(
			endTimeUpdates( { ...event, endDate: '2027-03-11' }, '01:00' )
		).toEqual( { blockendar_end_time: '01:00' } );
	} );
} );

describe( 'endDateUpdates', () => {
	const event = {
		startDate: '2027-03-09',
		startTime: '22:00',
		endTime: '01:00',
	};

	test( 'an end date before the start becomes the start date', () => {
		expect(
			endDateUpdates( { ...event, endTime: '23:00' }, '2027-03-01' )
		).toEqual( { blockendar_end_date: '2027-03-09' } );
	} );

	test( 'pulled back onto the start date, an early end time moves after the start', () => {
		expect( endDateUpdates( event, '2027-03-09' ) ).toEqual( {
			blockendar_end_date: '2027-03-09',
			blockendar_end_time: '23:00',
		} );
	} );

	test( 'a later end date leaves the time alone', () => {
		expect( endDateUpdates( event, '2027-03-10' ) ).toEqual( {
			blockendar_end_date: '2027-03-10',
		} );
	} );
} );

describe( 'startTimeUpdates', () => {
	const event = {
		startDate: '2027-03-09',
		endDate: '2027-03-09',
		endTime: '21:00',
	};

	test( 'a start past the end pushes the end an hour on', () => {
		expect( startTimeUpdates( event, '21:30' ) ).toEqual( {
			blockendar_start_time: '21:30',
			blockendar_end_time: '22:30',
		} );
	} );

	test( 'a late start does not wrap the end past midnight', () => {
		expect( startTimeUpdates( event, '23:30' ).blockendar_end_time ).toBe(
			'23:59'
		);
	} );

	test( 'a start before the end changes nothing else', () => {
		expect( startTimeUpdates( event, '19:58' ) ).toEqual( {
			blockendar_start_time: '19:58',
		} );
	} );
} );
