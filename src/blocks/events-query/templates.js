/**
 * Working out which inner blocks make up one event card.
 *
 * This mirrors the partitioning in render.php. Both sides have to agree, or the
 * editor previews something other than what visitors get, so the rule lives in
 * one readable place on each side rather than being inlined at the call site.
 */

export const TEMPLATE_BLOCK = 'blockendar/event-template';
export const NO_RESULTS_BLOCK = 'blockendar/events-query-no-results';

/**
 * The per-layout template blocks, if the query has been split.
 *
 * @param {Array} innerBlocks The query's inner blocks.
 * @return {Array} Template container blocks, possibly empty.
 */
export function layoutTemplatesIn( innerBlocks ) {
	return innerBlocks.filter( ( block ) => block.name === TEMPLATE_BLOCK );
}

/**
 * Whether list and grid have genuinely diverged.
 *
 * A single template block is not a split: it produces identical markup for
 * every layout, exactly as ordinary inner blocks do.
 *
 * @param {Array} innerBlocks The query's inner blocks.
 * @return {boolean} True when more than one layout template exists.
 */
export function hasSplitTemplates( innerBlocks ) {
	return layoutTemplatesIn( innerBlocks ).length > 1;
}

/**
 * The blocks making up one event card in the given layout.
 *
 * @param {Array}  innerBlocks  The query's inner blocks.
 * @param {string} activeLayout Layout being rendered or edited.
 * @return {Array} Blocks for a single card.
 */
export function cardBlocksFor( innerBlocks, activeLayout ) {
	const templates = layoutTemplatesIn( innerBlocks );

	if ( templates.length > 1 ) {
		return (
			templates.find(
				( template ) => template.attributes?.layout === activeLayout
			)?.innerBlocks ?? []
		);
	}

	if ( 1 === templates.length ) {
		return templates[ 0 ].innerBlocks;
	}

	// Content saved before per-layout templates existed. The no-results block
	// belongs to the query rather than to a card, so it never repeats.
	return innerBlocks.filter( ( block ) => block.name !== NO_RESULTS_BLOCK );
}
