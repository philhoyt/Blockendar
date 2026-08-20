/**
 * Event Template — editor component.
 *
 * One of these exists per layout once a query's templates are split. Only the
 * one matching the layout currently selected in the toolbar is shown: editing
 * two templates side by side would make it ambiguous which one a change lands
 * in, and the toolbar toggle already reads as "show me this layout".
 */
import { InnerBlocks, useBlockProps } from '@wordpress/block-editor';

export function Edit( { attributes, context } ) {
	const { layout } = attributes;
	const activeLayout = context[ 'blockendar/activeLayout' ]?.type ?? 'list';
	const isActive = activeLayout === layout;

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
