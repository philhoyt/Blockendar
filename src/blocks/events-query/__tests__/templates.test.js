/**
 * The card-template partitioning shared by the editor and mirrored in render.php.
 */

import {
	cardBlocksFor,
	hasSplitTemplates,
	TEMPLATE_BLOCK,
	NO_RESULTS_BLOCK,
} from '../templates';

const title = { name: 'core/post-title', innerBlocks: [] };
const datetime = { name: 'blockendar/event-datetime', innerBlocks: [] };
const noResults = { name: NO_RESULTS_BLOCK, innerBlocks: [] };

const template = ( layout, innerBlocks ) => ( {
	name: TEMPLATE_BLOCK,
	attributes: { layout },
	innerBlocks,
} );

describe( 'hasSplitTemplates', () => {
	it( 'is false for content saved before per-layout templates existed', () => {
		expect( hasSplitTemplates( [ title, noResults ] ) ).toBe( false );
	} );

	it( 'is false for a single template block', () => {
		expect( hasSplitTemplates( [ template( 'list', [ title ] ) ] ) ).toBe(
			false
		);
	} );

	it( 'is true once both layouts have their own template', () => {
		expect(
			hasSplitTemplates( [
				template( 'list', [ title ] ),
				template( 'grid', [ datetime ] ),
			] )
		).toBe( true );
	} );
} );

describe( 'cardBlocksFor', () => {
	it( 'drops the no-results block, which is not part of a card', () => {
		expect( cardBlocksFor( [ title, noResults ], 'list' ) ).toEqual( [
			title,
		] );
	} );

	it( 'returns the template matching the active layout', () => {
		const blocks = [
			template( 'list', [ title ] ),
			template( 'grid', [ datetime ] ),
		];

		expect( cardBlocksFor( blocks, 'list' ) ).toEqual( [ title ] );
		expect( cardBlocksFor( blocks, 'grid' ) ).toEqual( [ datetime ] );
	} );

	it( 'uses a lone template whatever layout it claims', () => {
		expect(
			cardBlocksFor( [ template( 'list', [ title ] ) ], 'grid' )
		).toEqual( [ title ] );
	} );

	it( 'returns nothing for a layout with no template rather than guessing', () => {
		const blocks = [
			template( 'list', [ title ] ),
			template( 'grid', [ datetime ] ),
		];

		expect( cardBlocksFor( blocks, 'masonry' ) ).toEqual( [] );
	} );
} );
