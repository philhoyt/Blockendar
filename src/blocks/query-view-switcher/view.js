/**
 * blockendar/query-view-switcher — progressive enhancement.
 *
 * Without this script the switcher still works: every control is a link
 * carrying its target URL, and the server renders the requested layout.
 *
 * This upgrades that to an in-place swap. List and grid differ only by a class
 * on the query wrapper — the events themselves render identical markup in both
 * — so there is nothing to re-fetch and no reason to reload the page.
 *
 * The swap has one cost: everything else on the page that navigates — the
 * pagination links, the filter forms and their "clear" links — was rendered by
 * the server against the URL before the swap, and still points there. Each
 * swap therefore also rewrites those controls so the next navigation carries
 * the chosen view, exactly as a full reload would have rendered them.
 */

import { speak } from '@wordpress/a11y';
import { __, sprintf } from '@wordpress/i18n';

import { withViewParam } from '../shared/view-param';

const SWITCHER = '.blockendar-view-switcher';
const BUTTON = '.blockendar-view-switcher__button';
const QUERY = '.blockendar-events-query';
const PAGINATION = '.blockendar-events-query__pagination';
const FILTER = '[data-blockendar-filter]';
const FILTER_GROUP = '[data-blockendar-query-id]';
const CLEAR_LINK = '.blockendar-filter__clear[href]';

/**
 * Find the queries a switcher controls.
 *
 * Matching is on the query ID, which mirrors the server: only the Query Filters
 * block provides one, so a switcher and query that both sit outside it match on
 * the same empty string — exactly the pair the URL parameter would have driven.
 *
 * @param {HTMLElement} switcher The switcher wrapper.
 * @return {HTMLElement[]} Matching query wrappers, possibly empty.
 */
function queriesFor( switcher ) {
	const id = switcher.dataset.queryId || '';

	return Array.from( document.querySelectorAll( QUERY ) ).filter(
		( query ) => ( query.dataset.queryId || '' ) === id
	);
}

/**
 * Find the pagination navs a switcher controls.
 *
 * The nav is a sibling of the results list rather than a child, so it carries
 * its own query ID and is matched the same way as the list.
 *
 * @param {HTMLElement} switcher The switcher wrapper.
 * @return {HTMLElement[]} Matching pagination navs, possibly empty.
 */
function paginationFor( switcher ) {
	const id = switcher.dataset.queryId || '';

	return Array.from( document.querySelectorAll( PAGINATION ) ).filter(
		( nav ) => ( nav.dataset.queryId || '' ) === id
	);
}

/**
 * Find the filter blocks that target the same query as a switcher.
 *
 * Filters learn their query from the Query Filters wrapper around them, which
 * is how their own scripts resolve it; a filter outside any wrapper matches on
 * the empty string, the same as a switcher outside one.
 *
 * @param {HTMLElement} switcher The switcher wrapper.
 * @return {HTMLElement[]} Matching filter wrappers, possibly empty.
 */
function filtersFor( switcher ) {
	const id = switcher.dataset.queryId || '';

	return Array.from( document.querySelectorAll( FILTER ) ).filter(
		( filter ) =>
			( filter.closest( FILTER_GROUP )?.dataset.blockendarQueryId ??
				'' ) === id
	);
}

/**
 * Read the modes this switcher actually offers, rather than assuming
 * list/grid — a theme can register more via blockendar_filter_view_modes.
 *
 * @param {HTMLElement} switcher The switcher wrapper.
 * @return {string[]} Mode keys.
 */
function modesFor( switcher ) {
	return Array.from( switcher.querySelectorAll( BUTTON ) ).map(
		( button ) => button.dataset.view
	);
}

/**
 * Whether the visitor is past page one of this query.
 *
 * Switching view resets pagination, so an in-place swap would leave page 4's
 * events sitting under a URL that says page 1. Those clicks navigate instead.
 *
 * @param {HTMLElement} switcher The switcher wrapper.
 * @return {boolean} True when a real navigation is required.
 */
function isPaged( switcher ) {
	const param = switcher.dataset.pageParam;

	if ( ! param ) {
		return false;
	}

	const value = new URL( window.location.href ).searchParams.get( param );

	return !! value && parseInt( value, 10 ) > 1;
}

/**
 * Point every navigating control for this query at the given view.
 *
 * Links get the parameter rewritten. Forms get a hidden input instead: they
 * submit with GET, which replaces the action's query string with the form's own
 * fields, so a parameter on the action would never arrive. Both follow the
 * server's rule that the default view is spelled as no parameter at all — the
 * hidden input is removed rather than set to the default's name.
 *
 * @param {HTMLElement} switcher The switcher wrapper.
 * @param {string}      mode     Mode key now active.
 */
function syncControls( switcher, mode ) {
	const param = switcher.dataset.viewParam;

	if ( ! param ) {
		return;
	}

	const defaultView = switcher.dataset.defaultView;
	const base = window.location.href;
	const rewrite = ( link ) => {
		link.href = withViewParam( link.href, param, mode, defaultView, base );
	};

	paginationFor( switcher ).forEach( ( nav ) =>
		nav.querySelectorAll( 'a[href]' ).forEach( rewrite )
	);

	filtersFor( switcher ).forEach( ( filter ) => {
		filter.querySelectorAll( CLEAR_LINK ).forEach( rewrite );

		filter.querySelectorAll( 'form' ).forEach( ( form ) => {
			// Looked up by name through the form's own collection rather than
			// a selector built from the parameter, so nothing needs escaping.
			// namedItem() hands back a RadioNodeList when several controls share
			// the name; that has no tagName, so it is treated as "not ours".
			const existing = form.elements.namedItem( param );
			const input = existing?.tagName === 'INPUT' ? existing : null;

			if ( mode === defaultView ) {
				input?.remove();
				return;
			}

			if ( input ) {
				input.value = mode;
				return;
			}

			const created = document.createElement( 'input' );

			created.type = 'hidden';
			created.name = param;
			created.value = mode;
			form.appendChild( created );
		} );
	} );
}

/**
 * Apply a view mode to the DOM.
 *
 * @param {HTMLElement}   switcher The switcher wrapper.
 * @param {HTMLElement[]} queries  Queries to relayout.
 * @param {string}        mode     Mode key to activate.
 */
function applyView( switcher, queries, mode ) {
	queries.forEach( ( query ) => {
		modesFor( switcher ).forEach( ( known ) =>
			query.classList.remove( `is-${ known }-view` )
		);
		query.classList.add( `is-${ mode }-view` );

		/*
		 * A query whose layouts use different templates renders both and hides
		 * the inactive one. Queries sharing one template have no such elements,
		 * where this is a no-op and the class swap above is the whole job.
		 */
		query
			.querySelectorAll( '.blockendar-events-query__layout' )
			.forEach( ( layout ) => {
				layout.hidden = layout.dataset.layout !== mode;
			} );
	} );

	switcher.dataset.activeView = mode;

	switcher.querySelectorAll( BUTTON ).forEach( ( button ) => {
		const isActive = button.dataset.view === mode;

		button.classList.toggle( 'is-active', isActive );
		button.setAttribute( 'aria-current', isActive ? 'true' : 'false' );
	} );

	syncControls( switcher, mode );
}

/**
 * Re-derive every switcher's state from the current URL.
 *
 * Runs on back/forward so the layout matches the address bar.
 */
function syncFromUrl() {
	document.querySelectorAll( SWITCHER ).forEach( ( switcher ) => {
		const queries = queriesFor( switcher );

		if ( ! queries.length ) {
			return;
		}

		const param = switcher.dataset.viewParam;
		const requested = param
			? new URL( window.location.href ).searchParams.get( param )
			: null;

		// No parameter means the editor's default is active, which is also the
		// state the server renders for a clean URL.
		const mode = modesFor( switcher ).includes( requested )
			? requested
			: switcher.dataset.defaultView;

		if ( mode ) {
			applyView( switcher, queries, mode );
		}
	} );
}

/**
 * Intercept plain left clicks on a switcher control.
 *
 * @param {MouseEvent} event The click.
 */
function onClick( event ) {
	const button = event.target.closest ? event.target.closest( BUTTON ) : null;

	if ( ! button ) {
		return;
	}

	// Anything but a plain left click stays a real navigation: modified clicks
	// open new tabs and middle clicks open background ones.
	if (
		event.defaultPrevented ||
		event.button !== 0 ||
		event.metaKey ||
		event.ctrlKey ||
		event.shiftKey ||
		event.altKey
	) {
		return;
	}

	const switcher = button.closest( SWITCHER );

	if ( ! switcher ) {
		return;
	}

	const queries = queriesFor( switcher );

	// Nothing on this page to relayout, or the results are not page one. Either
	// way the server has to answer, so leave the link alone.
	if ( ! queries.length || isPaged( switcher ) ) {
		return;
	}

	event.preventDefault();
	applyView( switcher, queries, button.dataset.view );
	window.history.pushState( {}, '', button.href );

	/*
	 * The results are rewritten in place and the page does not navigate, so
	 * without this a screen-reader user activates "Grid view" and gets no
	 * confirmation that anything happened at all.
	 *
	 * Announced here rather than inside applyView(): that also runs on load
	 * from reconcileDefault(), where there is no user action to report.
	 */
	const label =
		button.getAttribute( 'aria-label' ) || button.textContent.trim();

	if ( label ) {
		speak(
			sprintf(
				/* translators: %s: the name of the layout that was applied, e.g. "Grid view". */
				__( '%s applied', 'blockendar' ),
				label
			),
			'polite'
		);
	}
}

/**
 * Adopt the layout the server actually rendered as this switcher's default.
 *
 * The switcher's default and the query's own layout are separate values, and
 * content saved before they were kept in sync can disagree — the results render
 * as a grid while the control highlights list, and "list" then links to a URL
 * that renders a grid.
 *
 * Only applies when the visitor has not chosen a view: an explicit parameter is
 * always their choice, never something to second-guess.
 *
 * @param {HTMLElement}   switcher The switcher wrapper.
 * @param {HTMLElement[]} queries  Queries it controls.
 */
function reconcileDefault( switcher, queries ) {
	const param = switcher.dataset.viewParam;
	const url = new URL( window.location.href );

	if ( ! param || url.searchParams.has( param ) || ! queries.length ) {
		return;
	}

	const modes = modesFor( switcher );
	const rendered = modes.find( ( mode ) =>
		queries[ 0 ].classList.contains( `is-${ mode }-view` )
	);

	if ( ! rendered || rendered === switcher.dataset.defaultView ) {
		return;
	}

	// Set before applyView() runs: syncControls() reads the default to decide
	// whether the rendered mode means "no parameter", and on a clean URL the
	// server-rendered controls already carry none — so this must be a no-op.
	switcher.dataset.defaultView = rendered;

	// Recompute the links so selecting the default still leaves a clean URL.
	const base = new URL( window.location.href );

	base.searchParams.delete( param );

	if ( switcher.dataset.pageParam ) {
		base.searchParams.delete( switcher.dataset.pageParam );
	}

	switcher.querySelectorAll( BUTTON ).forEach( ( button ) => {
		const target = new URL( base );

		if ( button.dataset.view !== rendered ) {
			target.searchParams.set( param, button.dataset.view );
		}

		button.href = target.toString();
	} );

	applyView( switcher, queries, rendered );
}

document.querySelectorAll( SWITCHER ).forEach( ( switcher ) => {
	reconcileDefault( switcher, queriesFor( switcher ) );
} );

document.addEventListener( 'click', onClick );
window.addEventListener( 'popstate', syncFromUrl );
