/**
 * Event Template — editor component.
 *
 * Every query carries one of these. A query whose layouts have been split
 * carries one per layout, and then only the one matching the layout selected in
 * the toolbar is shown: editing two templates side by side would make it
 * ambiguous which one a change lands in, and the toolbar toggle already reads as
 * "show me this layout".
 */
import {
	InnerBlocks,
	useBlockProps,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import { useSelect } from '@wordpress/data';

export function Edit( { attributes, context, clientId } ) {
	const { layout } = attributes;
	const activeLayout = context[ 'blockendar/activeLayout' ]?.type ?? 'list';

	/*
	 * A lone template applies to every layout, whichever one it claims — the
	 * same rule render.php follows. Without this it would hide itself the moment
	 * the toolbar selected a layout it did not match, leaving an empty query.
	 */
	const isOnlyTemplate = useSelect(
		( select ) => {
			const { getBlockRootClientId, getBlocks } =
				select( blockEditorStore );

			const siblings = getBlocks(
				getBlockRootClientId( clientId )
			).filter( ( block ) => block.name === 'blockendar/event-template' );

			return siblings.length <= 1;
		},
		[ clientId ]
	);

	const isActive = isOnlyTemplate || activeLayout === layout;

	const blockProps = useBlockProps( {
		className: `blockendar-event-template${
			isActive ? '' : ' is-inactive-layout'
		}`,
	} );

	return (
		<div { ...blockProps }>
			<InnerBlocks templateInsertUpdatesSelection={ false } />
		</div>
	);
}

export default Edit;
