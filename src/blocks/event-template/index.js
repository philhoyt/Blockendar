import './index.css';
import { InnerBlocks } from '@wordpress/block-editor';
import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import Edit from './edit.jsx';

registerBlockType( metadata.name, {
	edit: Edit,

	/*
	 * The container itself contributes no markup on the front end — the Events
	 * Query block reads this block's children and renders them once per event,
	 * so serialising the children alone is exactly what it needs.
	 */
	save: () => <InnerBlocks.Content />,
} );
