/**
 * Drives a full index rebuild from the Performance panel.
 *
 * The rebuild route does one pass and says whether there is more to do, so a
 * rebuild is as many requests as it takes. Kept apart from the panel so the
 * loop can be tested without rendering it.
 */

/** How long to hold off when a pass got nothing done, in milliseconds. */
export const STALLED_DELAY = 2000;

const defaultWait = ( ms ) =>
	new Promise( ( resolve ) => setTimeout( resolve, ms ) );

/**
 * Post to the rebuild route until the rebuild has finished.
 *
 * A pass that made no progress means another one holds the lock — the
 * background run, usually. Asking again at once would only ask the same thing
 * in a tight loop, so that case waits first.
 *
 * @param {Function} post               Posts to the rebuild route; resolves to its response.
 * @param {Object}   options
 * @param {Function} options.onProgress Called with each response that is not the last.
 * @param {Function} options.wait       Resolves after a number of milliseconds.
 * @return {Promise<Object>} The final response.
 */
export async function runRebuild(
	post,
	{ onProgress = () => {}, wait = defaultWait } = {}
) {
	let handled = -1;

	for (;;) {
		const result = await post();

		if ( ! result.in_progress ) {
			return result;
		}

		onProgress( result );

		const total = result.rebuilt + result.skipped;

		if ( total === handled ) {
			await wait( STALLED_DELAY );
		}

		handled = total;
	}
}
