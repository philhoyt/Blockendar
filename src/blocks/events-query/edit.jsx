/**
 * Events Query block — editor component.
 */
import { InnerBlocks, InspectorControls, useBlockProps, BlockControls } from '@wordpress/block-editor';
import {
	PanelBody,
	RangeControl,
	ToggleControl,
	CheckboxControl,
	ToolbarButton,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useSelect }       from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { __, _x }          from '@wordpress/i18n';

const IconList = (
	<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false">
		<path d="M4 6h2v2H4V6zm3.5 1.5h12v-1h-12v1zM4 11h2v2H4v-2zm3.5 1.5h12v-1h-12v1zM4 16h2v2H4v-2zm3.5 1.5h12v-1h-12v1z" />
	</svg>
);

const IconGrid = (
	<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false">
		<path d="M5 5h6v6H5V5zm0 8h6v6H5v-6zm8-8h6v6h-6V5zm0 8h6v6h-6v-6z" />
	</svg>
);

const TEMPLATE = [
	[ 'core/post-title',           { isLink: true, level: 3 } ],
	[ 'blockendar/event-datetime' ],
	[ 'blockendar/event-venue'    ],
];

export function Edit( { attributes, setAttributes } ) {
	const { typeIds, perPage, showPast, order, displayLayout } = attributes;
	const isGrid = displayLayout?.type === 'grid';
	const columnCount = displayLayout?.columnCount ?? 3;

	const terms = useSelect(
		( select ) => select( coreStore ).getEntityRecords(
			'taxonomy',
			'event_type',
			{ per_page: -1, _fields: [ 'id', 'name' ] }
		),
		[]
	);

	const blockProps = useBlockProps( {
		className: `blockendar-events-query is-${ isGrid ? 'grid' : 'list' }-view`,
		style: isGrid ? { '--blockendar-columns': columnCount } : undefined,
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
					label={ _x( 'List view', 'events query display layout', 'blockendar' ) }
					isActive={ ! isGrid }
					onClick={ () => setAttributes( { displayLayout: { type: 'list' } } ) }
				/>
				<ToolbarButton
					icon={ IconGrid }
					label={ _x( 'Grid view', 'events query display layout', 'blockendar' ) }
					isActive={ isGrid }
					onClick={ () => setAttributes( { displayLayout: { type: 'grid', columnCount } } ) }
				/>
			</BlockControls>
			<InspectorControls>
				<PanelBody title={ __( 'Layout', 'blockendar' ) }>
					<VStack spacing={ 3 }>
						{ isGrid && (
							<RangeControl
								label={ __( 'Columns', 'blockendar' ) }
								value={ columnCount }
								onChange={ ( val ) => setAttributes( { displayLayout: { type: 'grid', columnCount: val } } ) }
								min={ 2 }
								max={ 6 }
								__nextHasNoMarginBottom
							/>
						) }
					</VStack>
				</PanelBody>

				<PanelBody title={ __( 'Query', 'blockendar' ) }>
					<VStack spacing={ 3 }>
						<RangeControl
							label={ __( 'Events per page', 'blockendar' ) }
							value={ perPage }
							onChange={ ( val ) => setAttributes( { perPage: val } ) }
							min={ 1 }
							max={ 50 }
							__nextHasNoMarginBottom
						/>
						<ToggleControl
							label={ __( 'Show past events', 'blockendar' ) }
							checked={ showPast }
							onChange={ ( val ) => setAttributes( { showPast: val } ) }
							__nextHasNoMarginBottom
						/>
						<ToggleControl
							label={ __( 'Reverse order', 'blockendar' ) }
							checked={ order === 'DESC' }
							onChange={ ( val ) => setAttributes( { order: val ? 'DESC' : 'ASC' } ) }
							__nextHasNoMarginBottom
						/>
					</VStack>
				</PanelBody>

				{ terms?.length > 0 && (
					<PanelBody title={ __( 'Filter by Event Type', 'blockendar' ) } initialOpen={ false }>
						<VStack spacing={ 2 }>
							{ terms.map( ( term ) => (
								<CheckboxControl
									key={ term.id }
									label={ term.name }
									checked={ typeIds.includes( term.id ) }
									onChange={ ( checked ) => toggleType( term.id, checked ) }
									__nextHasNoMarginBottom
								/>
							) ) }
						</VStack>
					</PanelBody>
				) }
			</InspectorControls>

			<div { ...blockProps }>
				<div className="blockendar-events-query__template-label">
					{ __( 'Event template — repeats for each result', 'blockendar' ) }
				</div>
				<InnerBlocks
					template={ TEMPLATE }
					templateInsertUpdatesSelection={ false }
				/>
			</div>
		</>
	);
}
