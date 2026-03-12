import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import { useBlockProps } from '@wordpress/block-editor';

function Edit() {
	return (
		<div { ...useBlockProps() }>
			<p style={ { textAlign: 'center', padding: '1em', opacity: 0.6 } }>
				{ metadata.title }
			</p>
		</div>
	);
}

registerBlockType( metadata.name, { edit: Edit } );
