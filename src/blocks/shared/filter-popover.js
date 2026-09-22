/**
 * Shared trigger/panel behaviour for the filter blocks.
 *
 * The server renders a button and a panel; without JavaScript the panel is simply
 * visible and the surrounding <form> works as plain HTML. This module upgrades
 * that pair into a popover, which is why it adds the `is-enhanced` class itself:
 * the stylesheet hides the panel only once that class is present, so a visitor
 * whose JavaScript never runs is never left with an unreachable control.
 *
 * Deliberately not a focus trap. These popovers are non-modal — the page behind
 * them stays operable, and trapping focus in a non-modal surface strands keyboard
 * users. Focus moves into the panel on open and returns to the trigger on close.
 */

const OPEN_CLASS = 'is-open';
const ENHANCED_CLASS = 'is-enhanced';
const FLOATING_CLASS = 'is-floating';

/*
 * Whether the panel can be shown in the browser's top layer.
 *
 * An absolutely positioned panel is painted inside whatever stacking context
 * its block happens to sit in. Page builders routinely wrap blocks in groups
 * with isolation: isolate or overflow: hidden, and a group rendered later in
 * the document — the first event card, say — then paints over the open panel,
 * or clips it. The top layer sits above every stacking context, so a panel
 * placed there is never covered. Browsers without the Popover API keep the
 * absolutely positioned panel.
 */
const TOP_LAYER =
	'function' === typeof document.createElement( 'div' ).showPopover;

/**
 * Pin a top-layer panel beneath its trigger.
 *
 * Top-layer elements are positioned against the initial containing block, so
 * the trigger's place in the document — its viewport rectangle plus the
 * scroll offset — is measured and handed to the stylesheet as custom
 * properties. Positioned absolutely rather than fixed, the panel then scrolls
 * with the page like the in-flow panel did, so a calendar that runs past the
 * fold is reached by scrolling down as before. The panel sizes itself from
 * its content, at least as wide as the trigger, and is nudged back inside the
 * viewport when it would run off the right-hand edge.
 *
 * @param {HTMLElement} trigger The trigger button.
 * @param {HTMLElement} panel   The open panel.
 */
function position( trigger, panel ) {
	const rect = trigger.getBoundingClientRect();
	const margin = 8;
	let left = rect.left;

	panel.style.setProperty(
		'--blockendar-anchor-top',
		`${ rect.bottom + window.scrollY }px`
	);
	panel.style.setProperty( '--blockendar-anchor-width', `${ rect.width }px` );
	panel.style.setProperty(
		'--blockendar-anchor-left',
		`${ left + window.scrollX }px`
	);

	// Width is only known once the panel is laid out at the anchor width.
	const overflow = left + panel.offsetWidth + margin - window.innerWidth;

	if ( overflow > 0 ) {
		left = Math.max( margin, left - overflow );
		panel.style.setProperty(
			'--blockendar-anchor-left',
			`${ left + window.scrollX }px`
		);
	}
}

/**
 * Wire one trigger/panel pair inside a filter block.
 *
 * @param {HTMLElement} root              Block wrapper element.
 * @param {Object}      options           Optional hooks.
 * @param {Function}    [options.onOpen]  Called after the panel becomes visible.
 * @param {Function}    [options.onClose] Called after the panel is hidden.
 * @return {{ open: Function, close: Function, isOpen: Function }|null} Controls, or null when the markup is absent.
 */
export function initFilterPopover( root, options = {} ) {
	const trigger = root.querySelector( '.blockendar-filter__trigger' );
	const panel = root.querySelector( '.blockendar-filter__panel' );

	if ( ! trigger || ! panel ) {
		return null;
	}

	root.classList.add( ENHANCED_CLASS );

	if ( TOP_LAYER ) {
		// Manual: the module keeps its own Escape, outside-click and focus-out
		// handling, so the browser's light dismiss would only double up.
		panel.popover = 'manual';
		root.classList.add( FLOATING_CLASS );
	}

	const isOpen = () => root.classList.contains( OPEN_CLASS );

	// A top-layer panel is anchored to the document rather than to the block,
	// so it is re-measured when the window resizes or a scroll container
	// between the block and the document moves the trigger. Document scrolling
	// alone needs nothing: the panel scrolls with the page.
	const follow = () => position( trigger, panel );

	const float = () => {
		if ( ! TOP_LAYER ) {
			return;
		}

		if ( ! panel.matches( ':popover-open' ) ) {
			panel.showPopover();
		}

		follow();
		window.addEventListener( 'scroll', follow, {
			capture: true,
			passive: true,
		} );
		window.addEventListener( 'resize', follow );
	};

	const sink = () => {
		if ( ! TOP_LAYER ) {
			return;
		}

		window.removeEventListener( 'scroll', follow, { capture: true } );
		window.removeEventListener( 'resize', follow );

		if ( panel.matches( ':popover-open' ) ) {
			panel.hidePopover();
		}
	};

	const open = () => {
		if ( isOpen() ) {
			return;
		}

		root.classList.add( OPEN_CLASS );
		trigger.setAttribute( 'aria-expanded', 'true' );
		float();

		// Hand focus to the first control so keyboard users land inside the panel
		// rather than tabbing through the rest of the page to reach it.
		const firstControl = panel.querySelector(
			'input, button, select, textarea, a[href]'
		);
		firstControl?.focus();

		options.onOpen?.( { root, trigger, panel } );
	};

	const close = ( { returnFocus = true } = {} ) => {
		if ( ! isOpen() ) {
			return;
		}

		sink();
		root.classList.remove( OPEN_CLASS );
		trigger.setAttribute( 'aria-expanded', 'false' );

		if ( returnFocus ) {
			trigger.focus();
		}

		options.onClose?.( { root, trigger, panel } );
	};

	trigger.addEventListener( 'click', () => {
		if ( isOpen() ) {
			close();
		} else {
			open();
		}
	} );

	// Escape closes from anywhere inside the block, including the panel.
	root.addEventListener( 'keydown', ( event ) => {
		if ( 'Escape' === event.key && isOpen() ) {
			event.stopPropagation();
			close();
		}
	} );

	// Clicking away closes, but focus is left where the visitor put it rather
	// than being yanked back to the trigger.
	document.addEventListener( 'click', ( event ) => {
		if ( isOpen() && ! root.contains( event.target ) ) {
			close( { returnFocus: false } );
		}
	} );

	// Tabbing out of the block closes it too, so the panel cannot be left open
	// behind a keyboard user who has moved on.
	document.addEventListener( 'focusin', ( event ) => {
		if ( isOpen() && ! root.contains( event.target ) ) {
			close( { returnFocus: false } );
		}
	} );

	return { open, close, isOpen };
}
