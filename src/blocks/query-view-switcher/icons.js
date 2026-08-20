/**
 * Switcher icons for the editor preview.
 *
 * These mirror the shapes in render.php. PHP cannot import a JS module, so the
 * two copies are maintained together — change one, change the other.
 */

export const ICON_SHAPES = {
	list: [
		{ x: 3, y: 4, width: 18, height: 4 },
		{ x: 3, y: 10, width: 18, height: 4 },
		{ x: 3, y: 16, width: 18, height: 4 },
	],
	grid: [
		{ x: 3, y: 3, width: 8, height: 8 },
		{ x: 13, y: 3, width: 8, height: 8 },
		{ x: 3, y: 13, width: 8, height: 8 },
		{ x: 13, y: 13, width: 8, height: 8 },
	],
};

/**
 * Render one mode's icon.
 *
 * @param {string} mode Mode key.
 * @return {Object|null} SVG element, or null for a mode with no icon.
 */
export function ViewIcon( { mode } ) {
	const shapes = ICON_SHAPES[ mode ];

	if ( ! shapes ) {
		return null;
	}

	return (
		<svg
			viewBox="0 0 24 24"
			fill="currentColor"
			aria-hidden="true"
			focusable="false"
		>
			{ shapes.map( ( shape, i ) => (
				<rect key={ i } { ...shape } rx="1.5" />
			) ) }
		</svg>
	);
}
