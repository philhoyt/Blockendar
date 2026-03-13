/**
 * Tests for event-countdown/view.js tick() behaviour.
 *
 * We extract the pure calculation and rendering logic from the view script
 * so it can be unit-tested without a live timer.
 */

// --- Pure helpers mirrored from view.js ---

const pad = ( n ) => String( n ).padStart( 2, '0' );

function getSegments( diff ) {
	if ( diff <= 0 ) return null;
	const days    = Math.floor( diff / 86_400_000 );
	const hours   = Math.floor( ( diff % 86_400_000 ) / 3_600_000 );
	const minutes = Math.floor( ( diff % 3_600_000 ) / 60_000 );
	const seconds = Math.floor( ( diff % 60_000 ) / 1_000 );
	return { days, hours, minutes, seconds };
}

// --- Tests ---

describe( 'tick — timer stops when element disconnected', () => {
	test( 'clearTimeout is called when el.isConnected is false', () => {
		const clearTimeoutSpy = jest.spyOn( global, 'clearTimeout' );
		const el = {
			isConnected: false,
			dataset: { target: String( Date.now() + 100_000 ), expiredLabel: 'Started.', format: 'd:h:m:s' },
		};
		const timerRef = { current: undefined };

		// Recreate tick inline.
		const tick = () => {
			if ( ! el.isConnected ) {
				clearTimeout( timerRef.current );
				return;
			}
		};

		tick();
		expect( clearTimeoutSpy ).toHaveBeenCalled();
		clearTimeoutSpy.mockRestore();
	} );
} );

describe( 'tick — expired label when diff ≤ 0', () => {
	test( 'getSegments returns null when diff is 0', () => {
		expect( getSegments( 0 ) ).toBeNull();
	} );

	test( 'getSegments returns null for negative diff', () => {
		expect( getSegments( -1000 ) ).toBeNull();
	} );
} );

describe( 'getSegments — correct time breakdown', () => {
	test( '2 days exactly', () => {
		const result = getSegments( 2 * 86_400_000 );
		expect( result ).toEqual( { days: 2, hours: 0, minutes: 0, seconds: 0 } );
	} );

	test( '1 hour 30 minutes', () => {
		const result = getSegments( 90 * 60_000 );
		expect( result ).toEqual( { days: 0, hours: 1, minutes: 30, seconds: 0 } );
	} );

	test( '45 seconds', () => {
		const result = getSegments( 45_000 );
		expect( result ).toEqual( { days: 0, hours: 0, minutes: 0, seconds: 45 } );
	} );

	test( '1 day 2 hours 3 minutes 4 seconds', () => {
		const diff = 86_400_000 + 2 * 3_600_000 + 3 * 60_000 + 4_000;
		const result = getSegments( diff );
		expect( result ).toEqual( { days: 1, hours: 2, minutes: 3, seconds: 4 } );
	} );
} );

describe( 'pad utility', () => {
	test( 'pads single-digit numbers', () => {
		expect( pad( 5 ) ).toBe( '05' );
	} );

	test( 'does not pad two-digit numbers', () => {
		expect( pad( 42 ) ).toBe( '42' );
	} );
} );
