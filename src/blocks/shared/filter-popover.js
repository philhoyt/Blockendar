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

	const isOpen = () => root.classList.contains( OPEN_CLASS );

	const open = () => {
		if ( isOpen() ) {
			return;
		}

		root.classList.add( OPEN_CLASS );
		trigger.setAttribute( 'aria-expanded', 'true' );

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
