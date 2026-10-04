/**
 * Tests for turning the "Repeats" controls into a rule and back.
 *
 * The editor compares the rule it holds with the one the server returned to
 * decide whether the event has been edited, so the shape matters as much as
 * the values: includes/REST/RecurrenceField.php returns these keys in this
 * order.
 */
import { NO_RULE, dateParts, formFromRule, ruleFromForm } from '../recurrence';

// Tuesday 9 March 2027, the second Tuesday of the month.
const START = '2027-03-09';

const form = ( overrides = {} ) => ( {
	preset: 'weekly_day',
	endType: 'never',
	untilDate: '',
	count: '',
	...overrides,
} );

describe( 'dateParts', () => {
	test( 'finds the weekday and which one of the month it is', () => {
		expect( dateParts( START ) ).toMatchObject( { byday: 'TU', nth: 2 } );
		expect( dateParts( '2027-03-31' ) ).toMatchObject( {
			byday: 'WE',
			nth: 5,
		} );
	} );

	test( 'has nothing to say without a date', () => {
		expect( dateParts( '' ) ).toBeNull();
	} );
} );

describe( 'ruleFromForm', () => {
	test( 'writes a rule exactly as the server reads it back', () => {
		const rule = ruleFromForm(
			form( { endType: 'count', count: '4' } ),
			START
		);

		expect( rule ).toEqual( {
			frequency: 'weekly',
			interval_val: 1,
			byday: 'TU',
			bymonthday: null,
			bysetpos: null,
			until_date: null,
			count: 4,
		} );
		expect( Object.keys( rule ) ).toEqual( [
			'frequency',
			'interval_val',
			'byday',
			'bymonthday',
			'bysetpos',
			'until_date',
			'count',
		] );
	} );

	test( 'monthly follows the nth weekday of the start date', () => {
		expect(
			ruleFromForm( form( { preset: 'monthly_weekday' } ), START )
		).toMatchObject( { frequency: 'monthly', byday: 'TU', bysetpos: '2' } );
	} );

	test( 'daily and yearly carry no weekday', () => {
		expect(
			ruleFromForm( form( { preset: 'daily' } ), START )
		).toMatchObject( { frequency: 'daily', byday: null, bysetpos: null } );
		expect(
			ruleFromForm( form( { preset: 'yearly_date' } ), START )
		).toMatchObject( { frequency: 'yearly', byday: null } );
	} );

	test( 'only the chosen kind of end is written', () => {
		const both = { untilDate: '2027-06-01', count: '4' };

		expect(
			ruleFromForm( form( { ...both, endType: 'date' } ), START )
		).toMatchObject( { until_date: '2027-06-01', count: null } );
		expect(
			ruleFromForm( form( { ...both, endType: 'count' } ), START )
		).toMatchObject( { until_date: null, count: 4 } );
		expect(
			ruleFromForm( form( { ...both, endType: 'never' } ), START )
		).toMatchObject( { until_date: null, count: null } );
	} );

	test( 'a count that is not a positive number is no count', () => {
		expect(
			ruleFromForm( form( { endType: 'count', count: '' } ), START ).count
		).toBeNull();
		expect(
			ruleFromForm( form( { endType: 'count', count: '0' } ), START )
				.count
		).toBeNull();
	} );

	test( 'no repeat is the one value the server uses for it', () => {
		expect( ruleFromForm( form( { preset: 'none' } ), START ) ).toBe(
			NO_RULE
		);
		expect( NO_RULE ).toEqual( { frequency: 'none' } );
	} );
} );

describe( 'formFromRule', () => {
	test( 'reads a stored rule into the controls', () => {
		expect(
			formFromRule( { frequency: 'weekly', until_date: null, count: 4 } )
		).toEqual( {
			preset: 'weekly_day',
			endType: 'count',
			untilDate: '',
			count: '4',
		} );
		expect(
			formFromRule( { frequency: 'daily', until_date: '2027-06-01' } )
		).toMatchObject( {
			preset: 'daily',
			endType: 'date',
			untilDate: '2027-06-01',
		} );
	} );

	test( 'an event with no rule, or none loaded yet, does not repeat', () => {
		const none = {
			preset: 'none',
			endType: 'never',
			untilDate: '',
			count: '',
		};

		expect( formFromRule( NO_RULE ) ).toEqual( none );
		expect( formFromRule( undefined ) ).toEqual( none );
		expect( formFromRule( null ) ).toEqual( none );
	} );

	test( 'a rule survives the round trip through the controls', () => {
		const rule = ruleFromForm(
			form( {
				preset: 'monthly_weekday',
				endType: 'date',
				untilDate: '2027-09-14',
			} ),
			START
		);

		expect( ruleFromForm( formFromRule( rule ), START ) ).toEqual( rule );
	} );
} );
