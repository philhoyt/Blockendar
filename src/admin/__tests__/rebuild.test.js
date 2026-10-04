import { runRebuild, STALLED_DELAY } from '../rebuild';

/**
 * A stand-in for the rebuild route that answers from a list.
 *
 * @param {Object[]} responses Responses, in order.
 * @return {jest.Mock} The mock.
 */
function routeAnswering( responses ) {
	const post = jest.fn();

	responses.forEach( ( response ) => post.mockResolvedValueOnce( response ) );

	return post;
}

describe( 'runRebuild', () => {
	it( 'stops after one request when the rebuild fits in it', async () => {
		const post = routeAnswering( [
			{ in_progress: false, rebuilt: 12, skipped: 1 },
		] );

		const result = await runRebuild( post );

		expect( post ).toHaveBeenCalledTimes( 1 );
		expect( result ).toEqual( {
			in_progress: false,
			rebuilt: 12,
			skipped: 1,
		} );
	} );

	it( 'keeps posting until the route says it has finished', async () => {
		const post = routeAnswering( [
			{ in_progress: true, rebuilt: 400, skipped: 0 },
			{ in_progress: true, rebuilt: 800, skipped: 2 },
			{ in_progress: false, rebuilt: 950, skipped: 2 },
		] );
		const onProgress = jest.fn();
		const wait = jest.fn().mockResolvedValue();

		const result = await runRebuild( post, { onProgress, wait } );

		expect( post ).toHaveBeenCalledTimes( 3 );
		expect( result.rebuilt ).toBe( 950 );
		expect( onProgress.mock.calls.map( ( [ r ] ) => r.rebuilt ) ).toEqual( [
			400, 800,
		] );
		expect( wait ).not.toHaveBeenCalled();
	} );

	it( 'waits before asking again when a pass got nothing done', async () => {
		const post = routeAnswering( [
			{ in_progress: true, rebuilt: 400, skipped: 0 },
			{ in_progress: true, rebuilt: 400, skipped: 0 },
			{ in_progress: false, rebuilt: 950, skipped: 0 },
		] );
		const wait = jest.fn().mockResolvedValue();

		await runRebuild( post, { wait } );

		expect( wait ).toHaveBeenCalledTimes( 1 );
		expect( wait ).toHaveBeenCalledWith( STALLED_DELAY );
	} );

	it( 'lets a failed request reach the caller', async () => {
		const post = jest.fn().mockRejectedValue( new Error( 'Forbidden' ) );

		await expect( runRebuild( post ) ).rejects.toThrow( 'Forbidden' );
	} );
} );
