/**
 * blockendar/query-view-switcher — block editor component.
 */
import {
	useBlockProps,
	InspectorControls,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { ViewIcon } from './icons';

const QUERY_BLOCK = 'blockendar/events-query';

/**
 * Find an events query anywhere beneath a block.
 *
 * @param {Object} block Block to search.
 * @return {Object|null} The first events query found, or null.
 */
function findQuery( block ) {
	if ( ! block ) {
		return null;
	}

	if ( block.name === QUERY_BLOCK ) {
		return block;
	}

	for ( const child of block.innerBlocks ?? [] ) {
		const found = findQuery( child );

		if ( found ) {
			return found;
		}
	}

	return null;
}

export default function Edit( { attributes, setAttributes, clientId } ) {
	const { showLabels, defaultView } = attributes;

	/*
	 * The layout the query itself is set to. This is the switcher's default: the
	 * two used to be authored separately and could drift, which rendered the
	 * results in one layout while the control highlighted the other.
	 *
	 * Walks up from the switcher and searches each ancestor, so it finds the
	 * query whether the two are siblings inside Query Filters or nested deeper.
	 */
	const queryLayout = useSelect(
		( select ) => {
			const { getBlockParents, getBlock } = select( blockEditorStore );
			const parents = getBlockParents( clientId );

			for ( const parentId of [ ...parents ].reverse() ) {
				const query = findQuery( getBlock( parentId ) );

				if ( query ) {
					return query.attributes?.displayLayout?.type ?? 'list';
				}
			}

			return null;
		},
		[ clientId ]
	);

	useEffect( () => {
		if ( queryLayout && queryLayout !== defaultView ) {
			setAttributes( { defaultView: queryLayout } );
		}
	}, [ queryLayout, defaultView, setAttributes ] );

	const blockProps = useBlockProps( {
		className: 'blockendar-view-switcher',
	} );

	const activeView = queryLayout ?? defaultView;

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'View Switcher', 'blockendar' ) }>
					<p className="components-base-control__help">
						{ queryLayout
							? __(
									'The starting layout follows the Events Query block, so the control and the results always agree. Change it on the query itself.',
									'blockendar'
							  )
							: __(
									'Place this block with an Events Query block. It follows that block for its starting layout.',
									'blockendar'
							  ) }
					</p>
					<ToggleControl
						label={ __( 'Show labels', 'blockendar' ) }
						help={ __(
							'Shows text beside each icon. When off the icons carry an accessible label instead.',
							'blockendar'
						) }
						checked={ showLabels }
						onChange={ ( val ) =>
							setAttributes( { showLabels: val } )
						}
						__nextHasNoMarginBottom
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				{ [ 'list', 'grid' ].map( ( mode ) => (
					<span
						key={ mode }
						className={ `blockendar-view-switcher__button${
							mode === activeView ? ' is-active' : ''
						}` }
					>
						<span
							className="blockendar-view-switcher__icon"
							aria-hidden="true"
						>
							<ViewIcon mode={ mode } />
						</span>
						{ showLabels && (
							<span className="blockendar-view-switcher__label">
								{ 'list' === mode
									? __( 'List view', 'blockendar' )
									: __( 'Grid view', 'blockendar' ) }
							</span>
						) }
					</span>
				) ) }
			</div>
		</>
	);
}
