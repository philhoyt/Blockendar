import { timezoneLabel } from '../timezone-label';

describe( 'timezoneLabel', () => {
	it( 'gives the abbreviation in force on the day', () => {
		expect(
			timezoneLabel( 'America/Chicago', '2026-07-10', '19:00' )
		).toBe( 'CDT' );
		expect(
			timezoneLabel( 'America/Chicago', '2026-01-10', '19:00' )
		).toBe( 'CST' );
	} );

	it( 'labels an offset the way WordPress writes one', () => {
		expect( timezoneLabel( '+05:30', '2026-07-10', '19:00' ) ).toBe(
			'UTC+05:30'
		);
		expect( timezoneLabel( '-05:00', '2026-07-10', '19:00' ) ).toBe(
			'UTC-05:00'
		);
	} );

	it( 'works without a time, and with seconds on it', () => {
		expect( timezoneLabel( 'America/Chicago', '2026-07-10', '' ) ).toBe(
			'CDT'
		);
		expect(
			timezoneLabel( 'America/Chicago', '2026-07-10', '09:00:00' )
		).toBe( 'CDT' );
	} );

	it( 'never prints the identifier for a zone Intl knows', () => {
		expect(
			timezoneLabel( 'Europe/Berlin', '2026-07-10', '19:00' )
		).not.toBe( 'Europe/Berlin' );
	} );

	it( 'falls back to the value it was given when it is not a timezone', () => {
		expect( timezoneLabel( 'Mars/Olympus', '2026-07-10', '19:00' ) ).toBe(
			'Mars/Olympus'
		);
		expect( timezoneLabel( '', '2026-07-10', '19:00' ) ).toBe( '' );
	} );
} );
