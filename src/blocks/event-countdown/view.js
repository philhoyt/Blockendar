/**
 * event-countdown — frontend view script.
 * Hydrates .blockendar-event-countdown elements with a live ticker.
 */
document.querySelectorAll( '.blockendar-event-countdown' ).forEach( ( el ) => {
	const target = new Date( el.dataset.target );
	const expiredLabel = el.dataset.expiredLabel ?? 'This event has started.';

	const pad = ( n ) => String( n ).padStart( 2, '0' );

	const tick = () => {
		const diff = target - Date.now();

		if ( diff <= 0 ) {
			el.textContent = expiredLabel;
			return;
		}

		const days = Math.floor( diff / 86_400_000 );
		const hours = Math.floor( ( diff % 86_400_000 ) / 3_600_000 );
		const minutes = Math.floor( ( diff % 3_600_000 ) / 60_000 );
		const seconds = Math.floor( ( diff % 60_000 ) / 1_000 );

		el.innerHTML =
			`<span class="blockendar-countdown__segment"><strong>${ days }</strong> d</span>` +
			`<span class="blockendar-countdown__segment"><strong>${ pad(
				hours
			) }</strong> h</span>` +
			`<span class="blockendar-countdown__segment"><strong>${ pad(
				minutes
			) }</strong> m</span>` +
			`<span class="blockendar-countdown__segment"><strong>${ pad(
				seconds
			) }</strong> s</span>`;

		setTimeout( tick, 1_000 );
	};

	tick();
} );
