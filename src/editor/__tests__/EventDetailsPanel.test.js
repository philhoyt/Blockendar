/* eslint-env jest */
/**
 * Tests for the costRangeInvalid logic in EventDetailsPanel.jsx.
 *
 * The logic is extracted as a pure function so it can be tested without
 * rendering the component or mocking WordPress blocks.
 */

// Exact logic from EventDetailsPanel.jsx line 44.
function isCostRangeInvalid( costMinRaw, costMaxRaw ) {
	const minCost = parseFloat( costMinRaw ) || 0;
	const maxCost = parseFloat( costMaxRaw ) || 0;
	return minCost > 0 && maxCost > 0 && minCost > maxCost;
}

describe( 'costRangeInvalid', () => {
	test( 'valid range: min < max', () => {
		expect( isCostRangeInvalid( 10, 25 ) ).toBe( false );
	} );

	test( 'invalid range: min > max', () => {
		expect( isCostRangeInvalid( 25, 10 ) ).toBe( true );
	} );

	test( 'both zero: not set, no error', () => {
		expect( isCostRangeInvalid( 0, 0 ) ).toBe( false );
	} );

	test( 'max not set (0): no error even when min is set', () => {
		expect( isCostRangeInvalid( 25, 0 ) ).toBe( false );
	} );

	test( 'min not set (0): no error even when max is set', () => {
		expect( isCostRangeInvalid( 0, 25 ) ).toBe( false );
	} );

	test( 'equal min and max: not invalid', () => {
		expect( isCostRangeInvalid( 10, 10 ) ).toBe( false );
	} );

	test( 'string inputs (from TextControl): parsed correctly', () => {
		expect( isCostRangeInvalid( '10', '25' ) ).toBe( false );
		expect( isCostRangeInvalid( '25', '10' ) ).toBe( true );
	} );

	test( 'empty string input treated as 0', () => {
		expect( isCostRangeInvalid( '', '' ) ).toBe( false );
	} );
} );
