/**
 * event-countdown — frontend view script.
 * Hydrates .blockendar-event-countdown elements with a live ticker.
 */
document.querySelectorAll( '.blockendar-event-countdown' ).forEach( ( el ) => {
	const target = new Date( el.dataset.target );

	// render.php always writes a parseable target, but a hand-edited block or a
	// truncated attribute would otherwise tick out "NaN days" forever.
	if ( Number.isNaN( target.getTime() ) ) {
		return;
	}

	const ticker = el.querySelector( '.blockendar-event-countdown__ticker' );

	if ( ! ticker ) {
		return;
	}

	/*
	 * Marks that the ticker is live, which lets the stylesheet fold the static
	 * sentence away visually while leaving it in the accessibility tree. With
	 * no JavaScript the class never lands and that sentence stays visible as
	 * the only content.
	 */
	el.classList.add( 'blockendar-js-ready' );
	const endTarget = el.dataset.endTarget
		? new Date( el.dataset.endTarget )
		: null;
	const expiredLabel = el.dataset.expiredLabel ?? 'This event has started.';
	const passedLabel = el.dataset.passedLabel ?? 'This event has passed.';
	const format = el.dataset.format ?? 'd:h:m:s';
	const segments = new Set( format.split( ':' ) );

	// Translated server-side; the fallbacks only apply to markup saved before
	// these attributes existed.
	const labels = {
		d: el.dataset.unitDays ?? 'days',
		h: el.dataset.unitHours ?? 'hours',
		m: el.dataset.unitMinutes ?? 'minutes',
		s: el.dataset.unitSeconds ?? 'seconds',
	};
	const pad = ( n ) => String( n ).padStart( 2, '0' );

	let timer;
	const tick = () => {
		if ( ! el.isConnected ) {
			clearTimeout( timer );
			return;
		}

		const now = Date.now();
		const diff = target - now;

		if ( endTarget && endTarget - now <= 0 ) {
			ticker.textContent = passedLabel;
			return;
		}

		if ( diff <= 0 ) {
			ticker.textContent = expiredLabel;
			timer = setTimeout( tick, 10_000 );
			return;
		}

		const days = Math.floor( diff / 86_400_000 );
		const hours = Math.floor( ( diff % 86_400_000 ) / 3_600_000 );
		const minutes = Math.floor( ( diff % 3_600_000 ) / 60_000 );
		const seconds = Math.floor( ( diff % 60_000 ) / 1_000 );

		const allSegments = [
			{ key: 'd', value: String( days ) },
			{ key: 'h', value: pad( hours ) },
			{ key: 'm', value: pad( minutes ) },
			{ key: 's', value: pad( seconds ) },
		];

		ticker.innerHTML = allSegments
			.filter( ( { key } ) => segments.has( key ) )
			.map(
				( { key, value } ) =>
					`<span class="blockendar-countdown__segment">` +
					`<strong>${ value }</strong>` +
					` <span class="blockendar-countdown__unit">${ labels[ key ] }</span>` +
					`</span>`
			)
			.join( ' ' );

		/*
		 * Only re-render as often as the smallest displayed unit changes. A
		 * format without seconds was still repainting every second for as long
		 * as the page stayed open, for no visible difference.
		 */
		timer = setTimeout( tick, segments.has( 's' ) ? 1_000 : 30_000 );
	};

	tick();
} );
