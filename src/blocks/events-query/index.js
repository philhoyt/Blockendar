/**
 * Events Query block — queries events from the Blockendar index and renders
 * each result using the editable inner block template.
 */
import { registerBlockType } from '@wordpress/blocks';
import { InnerBlocks }      from '@wordpress/block-editor';
import { Edit }             from './edit';
import './style.css';
import metadata             from './block.json';

registerBlockType( metadata.name, {
	edit: Edit,
	// Inner blocks must be serialised to post content so render.php
	// can access $block->parsed_block['innerBlocks'] for context injection.
	save: () => <InnerBlocks.Content />,
} );
