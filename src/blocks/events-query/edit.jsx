/**
 * Events Query block — editor component.
 */
import {
	InnerBlocks,
	InspectorControls,
	useBlockProps,
	BlockControls,
	BlockContextProvider,
	BlockPreview,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import {
	PanelBody,
	RangeControl,
	SelectControl,
	ToggleControl,
	CheckboxControl,
	ToolbarButton,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useState } from '@wordpress/element';
import { useResizeObserver } from '@wordpress/compose';
import { store as coreStore } from '@wordpress/core-data';
import { useEntityRecords } from '@wordpress/core-data';
import { __, _x } from '@wordpress/i18n';

/*
 * Upper bound on how many events the editor previews, regardless of how many the
 * block is set to show. Each preview renders the full inner-block template, so an
 * unbounded perPage would turn a large number into a slow editor for no extra
 * information — half a dozen is enough to judge a layout.
 */
const MAX_PREVIEWS = 6;

const IconList = (
	<svg
		xmlns="http://www.w3.org/2000/svg"
		viewBox="0 0 24 24"
		width="24"
		height="24"
		aria-hidden="true"
		focusable="false"
	>
		<path d="M4 6h2v2H4V6zm3.5 1.5h12v-1h-12v1zM4 11h2v2H4v-2zm3.5 1.5h12v-1h-12v1zM4 16h2v2H4v-2zm3.5 1.5h12v-1h-12v1z" />
	</svg>
);

const IconGrid = (
	<svg
		xmlns="http://www.w3.org/2000/svg"
		viewBox="0 0 24 24"
		width="24"
		height="24"
		aria-hidden="true"
		focusable="false"
	>
		<path d="M5 5h6v6H5V5zm0 8h6v6H5v-6zm8-8h6v6h-6V5zm0 8h6v6h-6v-6z" />
	</svg>
);

const TEMPLATE = [
	[ 'core/post-title', { isLink: true, level: 3 } ],
	[ 'blockendar/event-datetime' ],
	[ 'blockendar/event-venue' ],
	[ 'blockendar/events-query-no-results' ],
];

/**
 * A read-only copy of the block template rendered with one event's context.
 *
 * BlockPreview scales its contents by container width / viewportWidth, so a fixed
 * viewportWidth renders every preview at a fraction of the size of the editable
 * first item — 0.92 against the default 700. Measuring the container and passing
 * its own width back makes the ratio 1, so the previews match the item above them.
 *
 * @param {Object} props        Component props.
 * @param {Array}  props.blocks Inner blocks to preview.
 */
function EventPreview( { blocks } ) {
	const [ width, setWidth ] = useState( 0 );

	const setMeasuredRef = useResizeObserver(
		( entries ) => {
			const measured = Math.round(
				entries[ 0 ]?.contentRect?.width ?? 0
			);

			if ( measured ) {
				setWidth( measured );
			}
		},
		{ box: 'border-box' }
	);

	return (
		<div
			className="blockendar-events-query__preview"
			ref={ setMeasuredRef }
		>
			{ /* Held back until measured, so nothing renders at the wrong scale first. */ }
			{ width > 0 && (
				<BlockPreview blocks={ blocks } viewportWidth={ width } />
			) }
		</div>
	);
}

export function Edit( { attributes, setAttributes, clientId } ) {
	const {
		typeIds,
		perPage,
		showPast,
		order,
		inherit,
		showPagination,
		relatedTo,
		displayLayout,
	} = attributes;
	const isGrid = displayLayout?.type === 'grid';
	const columnCount = displayLayout?.columnCount ?? 3;
	const columnCountTablet = displayLayout?.columnCountTablet ?? 2;
	const columnCountMobile = displayLayout?.columnCountMobile ?? 1;

	const terms = useSelect(
		( select ) =>
			select( coreStore ).getEntityRecords( 'taxonomy', 'event_type', {
				per_page: -1,
				_fields: [ 'id', 'name' ],
			} ),
		[]
	);

	/*
	 * _fields is limited to the id because that is all the preview needs: each
	 * inner block resolves its own data from the postId supplied through block
	 * context, exactly as it does on the front end.
	 */
	const previewCount = Math.min( Math.max( perPage, 1 ), MAX_PREVIEWS );
	const { records: previewEvents, hasResolved } = useEntityRecords(
		'postType',
		'blockendar_event',
		{
			per_page: previewCount,
			status: 'publish',
			_fields: [ 'id' ],
		}
	);

	const events = previewEvents ?? [];
	const firstPostId = events[ 0 ]?.id ?? 0;

	// The template the previews render. Read from the block itself so a preview
	// always reflects whatever the editor has just changed.
	const innerBlocks = useSelect(
		( select ) => select( blockEditorStore ).getBlocks( clientId ),
		[ clientId ]
	);

	/*
	 * The no-results block belongs to the query, not to an event card: render.php
	 * partitions it out before looping over events, and the previews have to do
	 * the same. Left in, it repeats once per previewed event — a message about
	 * having no events, shown against events that plainly exist.
	 */
	const previewBlocks = innerBlocks.filter(
		( block ) => block.name !== 'blockendar/events-query-no-results'
	);

	const blockProps = useBlockProps( {
		className: `blockendar-events-query is-${
			isGrid ? 'grid' : 'list'
		}-view`,
		style: isGrid
			? {
					'--blockendar-columns': columnCount,
					'--blockendar-columns-tablet': columnCountTablet,
					'--blockendar-columns-mobile': columnCountMobile,
			  }
			: undefined,
	} );

	const toggleType = ( termId, checked ) => {
		setAttributes( {
			typeIds: checked
				? [ ...typeIds, termId ]
				: typeIds.filter( ( id ) => id !== termId ),
		} );
	};

	return (
		<>
			<BlockControls>
				<ToolbarButton
					icon={ IconList }
					label={ _x(
						'List view',
						'events query display layout',
						'blockendar'
					) }
					isActive={ ! isGrid }
					onClick={ () =>
						setAttributes( { displayLayout: { type: 'list' } } )
					}
				/>
				<ToolbarButton
					icon={ IconGrid }
					label={ _x(
						'Grid view',
						'events query display layout',
						'blockendar'
					) }
					isActive={ isGrid }
					onClick={ () =>
						setAttributes( {
							displayLayout: {
								type: 'grid',
								columnCount,
								columnCountTablet,
								columnCountMobile,
							},
						} )
					}
				/>
			</BlockControls>
			<InspectorControls>
				<PanelBody title={ __( 'Layout', 'blockendar' ) }>
					<VStack spacing={ 3 }>
						{ isGrid && (
							<>
								<RangeControl
									label={ __(
										'Columns — Mobile',
										'blockendar'
									) }
									value={ columnCountMobile }
									onChange={ ( val ) =>
										setAttributes( {
											displayLayout: {
												type: 'grid',
												columnCount,
												columnCountTablet,
												columnCountMobile: val,
											},
										} )
									}
									min={ 1 }
									max={ 3 }
									__nextHasNoMarginBottom
								/>
								<RangeControl
									label={ __(
										'Columns — Tablet',
										'blockendar'
									) }
									value={ columnCountTablet }
									onChange={ ( val ) =>
										setAttributes( {
											displayLayout: {
												type: 'grid',
												columnCount,
												columnCountTablet: val,
												columnCountMobile,
											},
										} )
									}
									min={ 1 }
									max={ 4 }
									__nextHasNoMarginBottom
								/>
								<RangeControl
									label={ __(
										'Columns — Desktop',
										'blockendar'
									) }
									value={ columnCount }
									onChange={ ( val ) =>
										setAttributes( {
											displayLayout: {
												type: 'grid',
												columnCount: val,
												columnCountTablet,
												columnCountMobile,
											},
										} )
									}
									min={ 2 }
									max={ 6 }
									__nextHasNoMarginBottom
								/>
							</>
						) }
					</VStack>
				</PanelBody>

				<PanelBody title={ __( 'Query', 'blockendar' ) }>
					<VStack spacing={ 3 }>
						<ToggleControl
							label={ __(
								'Inherit query from template',
								'blockendar'
							) }
							checked={ inherit }
							onChange={ ( val ) =>
								setAttributes( { inherit: val } )
							}
							help={ __(
								'Automatically filters by the current archive term (event type or venue). Use this when placing the block inside a taxonomy template.',
								'blockendar'
							) }
							__nextHasNoMarginBottom
						/>
						<RangeControl
							label={ __( 'Events per page', 'blockendar' ) }
							value={ perPage }
							onChange={ ( val ) =>
								setAttributes( { perPage: val } )
							}
							min={ 1 }
							max={ 50 }
							__nextHasNoMarginBottom
						/>
						<ToggleControl
							label={ __( 'Show past events', 'blockendar' ) }
							checked={ showPast }
							onChange={ ( val ) =>
								setAttributes( { showPast: val } )
							}
							__nextHasNoMarginBottom
						/>
						<ToggleControl
							label={ __( 'Reverse order', 'blockendar' ) }
							checked={ order === 'DESC' }
							onChange={ ( val ) =>
								setAttributes( { order: val ? 'DESC' : 'ASC' } )
							}
							__nextHasNoMarginBottom
						/>
						<ToggleControl
							label={ __( 'Show pagination', 'blockendar' ) }
							checked={ showPagination }
							onChange={ ( val ) =>
								setAttributes( { showPagination: val } )
							}
							__nextHasNoMarginBottom
						/>
						{ ! inherit && (
							<SelectControl
								label={ __( 'Related events', 'blockendar' ) }
								value={ relatedTo ?? 'none' }
								options={ [
									{
										label: __( 'Off', 'blockendar' ),
										value: 'none',
									},
									{
										label: __(
											'Same event type',
											'blockendar'
										),
										value: 'type',
									},
									{
										label: __( 'Same venue', 'blockendar' ),
										value: 'venue',
									},
									{
										label: __(
											'Same type or venue',
											'blockendar'
										),
										value: 'both',
									},
								] }
								onChange={ ( val ) =>
									setAttributes( { relatedTo: val } )
								}
								help={
									relatedTo !== 'none'
										? __(
												'Shows events sharing the current post\u2019s type or venue. Place this block inside a single-event template.',
												'blockendar'
										  )
										: undefined
								}
								__nextHasNoMarginBottom
							/>
						) }
					</VStack>
				</PanelBody>

				{ ! inherit && relatedTo === 'none' && terms?.length > 0 && (
					<PanelBody
						title={ __( 'Filter by Event Type', 'blockendar' ) }
						initialOpen={ false }
					>
						<VStack spacing={ 2 }>
							{ terms.map( ( term ) => (
								<CheckboxControl
									key={ term.id }
									label={ term.name }
									checked={ typeIds.includes( term.id ) }
									onChange={ ( checked ) =>
										toggleType( term.id, checked )
									}
									__nextHasNoMarginBottom
								/>
							) ) }
						</VStack>
					</PanelBody>
				) }
			</InspectorControls>

			<div { ...blockProps }>
				<BlockContextProvider
					value={ {
						postId: firstPostId,
						postType: 'blockendar_event',
					} }
				>
					<InnerBlocks
						template={ TEMPLATE }
						templateInsertUpdatesSelection={ false }
					/>
				</BlockContextProvider>
				{ /*
				   Remaining items are read-only previews of the same template with
				   each event's own context, so the block shows real content rather
				   than grey bars. Only the first item is editable; editing every
				   copy would be ambiguous about which one owns the template.
				*/ }
				{ ! hasResolved &&
					Array.from( { length: previewCount - 1 } ).map( ( _, i ) => (
						<div
							key={ `ghost-${ i }` }
							className="blockendar-events-query__ghost"
							aria-hidden="true"
						>
							<div className="blockendar-events-query__ghost-line blockendar-events-query__ghost-line--title" />
							<div className="blockendar-events-query__ghost-line blockendar-events-query__ghost-line--meta" />
							<div className="blockendar-events-query__ghost-line blockendar-events-query__ghost-line--meta" />
						</div>
					) ) }

				{ hasResolved &&
					events.slice( 1 ).map( ( event ) => (
						<BlockContextProvider
							key={ event.id }
							value={ {
								postId: event.id,
								postType: 'blockendar_event',
							} }
						>
							<EventPreview blocks={ previewBlocks } />
						</BlockContextProvider>
					) ) }

				{ hasResolved && 0 === events.length && (
					<p className="blockendar-events-query__notice">
						{ __(
							'No published events yet. The layout above is a template — it will repeat for each event once you publish some.',
							'blockendar'
						) }
					</p>
				) }
			</div>
		</>
	);
}
