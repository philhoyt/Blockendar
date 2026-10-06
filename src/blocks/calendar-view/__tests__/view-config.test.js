/**
 * The pure helpers behind the calendar-view frontend.
 */
import {
	pluginForView,
	navLinkOptions,
	siteNow,
	eventsPerDay,
	eventMeta,
	timeOfDay,
	businessHoursFromDataset,
} from '../view-config';

describe( 'pluginForView', () => {
	it.each( [
		[ 'dayGridMonth', 'dayGrid' ],
		[ 'timeGridWeek', 'timeGrid' ],
		[ 'timeGridDay', 'timeGrid' ],
		[ 'listNextMonth', 'list' ],
		[ 'multiMonthYear', 'multiMonth' ],
	] )( 'maps %s to the %s plugin', ( view, plugin ) => {
		expect( pluginForView( view ) ).toBe( plugin );
	} );

	it( 'returns null for a view no installed plugin provides', () => {
		expect( pluginForView( 'resourceTimelineWeek' ) ).toBeNull();
	} );
} );

describe( 'navLinkOptions', () => {
	it( 'turns links off when neither the day nor the week view is offered', () => {
		expect( navLinkOptions( [ 'dayGridMonth', 'listNextMonth' ] ) ).toEqual(
			{ navLinks: false }
		);
	} );

	it( 'sends day and week links to their own views when both are offered', () => {
		expect(
			navLinkOptions( [ 'dayGridMonth', 'timeGridWeek', 'timeGridDay' ] )
		).toEqual( {
			navLinks: true,
			navLinkDayClick: 'timeGridDay',
			navLinkWeekClick: 'timeGridWeek',
		} );
	} );

	it( 'sends week links to the day view when only the day view is offered', () => {
		expect( navLinkOptions( [ 'dayGridMonth', 'timeGridDay' ] ) ).toEqual( {
			navLinks: true,
			navLinkDayClick: 'timeGridDay',
			navLinkWeekClick: 'timeGridDay',
		} );
	} );

	it( 'sends day links to the week view when only the week view is offered', () => {
		expect(
			navLinkOptions( [ 'multiMonthYear', 'timeGridWeek' ] )
		).toEqual( {
			navLinks: true,
			navLinkDayClick: 'timeGridWeek',
			navLinkWeekClick: 'timeGridWeek',
		} );
	} );
} );

describe( 'siteNow', () => {
	// 03:00 UTC on New Year's Day: already the 1st in Tokyo, still the 31st
	// in Los Angeles.
	const instant = new Date( '2026-01-01T03:00:00Z' );

	it( 'formats the wall clock of a zone east of UTC', () => {
		expect( siteNow( 'Asia/Tokyo', instant ) ).toBe(
			'2026-01-01T12:00:00'
		);
	} );

	it( 'formats the wall clock of a zone west of UTC, on the previous date', () => {
		expect( siteNow( 'America/Los_Angeles', instant ) ).toBe(
			'2025-12-31T19:00:00'
		);
	} );

	it( 'writes midnight as 00, not 24', () => {
		expect( siteNow( 'UTC', new Date( '2026-01-01T00:30:00Z' ) ) ).toBe(
			'2026-01-01T00:30:00'
		);
	} );

	it( 'uses the browser clock for the local zone', () => {
		const local = new Date( 2026, 5, 7, 8, 9, 10 );

		expect( siteNow( 'local', local ) ).toBe( '2026-06-07T08:09:10' );
	} );

	it( 'falls back to the browser clock for a zone it cannot name', () => {
		const local = new Date( 2026, 0, 2, 3, 4, 5 );

		expect( siteNow( 'Not/AZone', local ) ).toBe( '2026-01-02T03:04:05' );
	} );
} );

describe( 'eventsPerDay', () => {
	it.each( [
		[ '5', 5 ],
		[ '0', 1 ],
		[ '500', 10 ],
		[ undefined, 3 ],
		[ 'three', 3 ],
	] )( 'turns %p into %i', ( raw, expected ) => {
		expect( eventsPerDay( raw ) ).toBe( expected );
	} );
} );

describe( 'eventMeta', () => {
	it( 'is null with neither a venue nor a cost', () => {
		expect( eventMeta( {} ) ).toBeNull();
		expect( eventMeta( { venue: null, cost: '' } ) ).toBeNull();
		expect( eventMeta( undefined ) ).toBeNull();
	} );

	it( 'joins the venue name and city', () => {
		expect(
			eventMeta( { venue: { name: 'Town Hall', city: 'Springfield' } } )
		).toEqual( { venue: 'Town Hall, Springfield', cost: null } );
	} );

	it( 'uses the venue name alone when there is no city', () => {
		expect(
			eventMeta( { venue: { name: 'Town Hall', city: '' } } )
		).toEqual( { venue: 'Town Hall', cost: null } );
	} );

	it( 'passes the cost through as entered', () => {
		expect( eventMeta( { cost: ' $12 ' } ) ).toEqual( {
			venue: null,
			cost: '$12',
		} );
	} );

	it( 'returns both when both are set', () => {
		expect(
			eventMeta( { venue: { name: 'Town Hall' }, cost: 'Free' } )
		).toEqual( { venue: 'Town Hall', cost: 'Free' } );
	} );
} );

describe( 'timeOfDay', () => {
	it.each( [ '00:00:00', '08:30:00', '23:59:59', '24:00:00' ] )(
		'accepts %s',
		( value ) => {
			expect( timeOfDay( value, 'fallback' ) ).toBe( value );
		}
	);

	it.each( [
		'9am',
		'08:00',
		'25:00:00',
		'24:30:00',
		'08:60:00',
		'',
		undefined,
		8,
	] )( 'rejects %p', ( value ) => {
		expect( timeOfDay( value, 'fallback' ) ).toBe( 'fallback' );
	} );
} );

describe( 'businessHoursFromDataset', () => {
	it( 'is false when the attribute is empty, which means off', () => {
		expect( businessHoursFromDataset( '' ) ).toBe( false );
		expect( businessHoursFromDataset( undefined ) ).toBe( false );
	} );

	it( 'is false for anything that is not business hours JSON', () => {
		expect( businessHoursFromDataset( 'not json' ) ).toBe( false );
		expect( businessHoursFromDataset( '"a string"' ) ).toBe( false );
		expect( businessHoursFromDataset( '[]' ) ).toBe( false );
	} );

	it( 'returns the definition FullCalendar expects', () => {
		expect(
			businessHoursFromDataset(
				JSON.stringify( {
					daysOfWeek: [ 1, 2, 3, 4, 5 ],
					startTime: '09:00:00',
					endTime: '17:00:00',
				} )
			)
		).toEqual( {
			daysOfWeek: [ 1, 2, 3, 4, 5 ],
			startTime: '09:00:00',
			endTime: '17:00:00',
		} );
	} );

	it( 'drops days outside the week and repeats', () => {
		expect(
			businessHoursFromDataset(
				JSON.stringify( {
					daysOfWeek: [ 9, 2, '2', -1, 6 ],
					startTime: '09:00:00',
					endTime: '17:00:00',
				} )
			)
		).toEqual( {
			daysOfWeek: [ 2, 6 ],
			startTime: '09:00:00',
			endTime: '17:00:00',
		} );
	} );

	it( 'is false with no usable days, since that would shade every hour', () => {
		expect(
			businessHoursFromDataset(
				JSON.stringify( {
					daysOfWeek: [ 7 ],
					startTime: '09:00:00',
					endTime: '17:00:00',
				} )
			)
		).toBe( false );
	} );

	it( 'is false when the end is not after the start or a time is malformed', () => {
		expect(
			businessHoursFromDataset(
				JSON.stringify( {
					daysOfWeek: [ 1 ],
					startTime: '17:00:00',
					endTime: '09:00:00',
				} )
			)
		).toBe( false );
		expect(
			businessHoursFromDataset(
				JSON.stringify( {
					daysOfWeek: [ 1 ],
					startTime: '9am',
					endTime: '17:00:00',
				} )
			)
		).toBe( false );
	} );
} );
