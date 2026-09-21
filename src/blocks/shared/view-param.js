/**
 * Carry a view mode on a URL the way the server would have.
 *
 * The View Switcher keeps the URL clean for its default mode — no parameter
 * rather than an explicit `view=list` — so this is not a plain "set a query
 * param" helper: the default deletes the parameter instead of naming it. Every
 * control the switcher rewrites after an in-place swap goes through here, so the
 * rule lives in one place and is unit-tested once.
 */

/**
 * Return `href` with the view parameter set to `mode`, or removed when `mode`
 * is the default.
 *
 * @param {string} href        Link or form action, absolute or relative.
 * @param {string} param       The view parameter name for this query.
 * @param {string} mode        Mode to carry.
 * @param {string} defaultView The switcher's default mode.
 * @param {string} base        Base for resolving a relative `href`; callers in
 *                             the browser pass `window.location.href`.
 * @return {string} The rewritten URL.
 */
export function withViewParam( href, param, mode, defaultView, base ) {
	const url = new URL( href, base );

	if ( mode === defaultView ) {
		url.searchParams.delete( param );
	} else {
		url.searchParams.set( param, mode );
	}

	return url.toString();
}
