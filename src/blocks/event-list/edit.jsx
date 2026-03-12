/**
 * event-list block — editor component.
 *
 * The list itself is server-side rendered via render.php.
 * The editor shows inspector controls and a disabled preview.
 */
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	ToggleControl,
	SelectControl,
	RangeControl,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';

export function Edit( { attributes, setAttributes } ) {
	const {
		venueId,
		typeId,
		featuredOnly,
		showPast,
		perPage,
		layout,
		groupBy,
		pagination,
		order,
	} = attributes;

	const blockProps = useBlockProps();

	const venues = useSelect(
		( select ) =>
			select( coreStore ).getEntityRecords( 'taxonomy', 'event_venue', {
				per_page: 100,
				hide_empty: false,
			} ) ?? [],
		[]
	);

	const types = useSelect(
		( select ) =>
			select( coreStore ).getEntityRecords( 'taxonomy', 'event_type', {
				per_page: 100,
				hide_empty: false,
			} ) ?? [],
		[]
	);

	const venueOptions = [
		{ label: __( 'All venues', 'blockendar' ), value: 0 },
		...venues.map( ( v ) => ( { label: v.name, value: v.id } ) ),
	];

	const typeOptions = [
		{ label: __( 'All types', 'blockendar' ), value: 0 },
		...types.map( ( t ) => ( { label: t.name, value: t.id } ) ),
	];

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Filter', 'blockendar' ) }>
					<VStack spacing={ 3 }>
						<SelectControl
							label={ __( 'Venue', 'blockendar' ) }
							value={ venueId ?? 0 }
							options={ venueOptions }
							onChange={ ( val ) =>
								setAttributes( {
									venueId: parseInt( val, 10 ) || undefined,
								} )
							}
							__nextHasNoMarginBottom
						/>
						<SelectControl
							label={ __( 'Event type', 'blockendar' ) }
							value={ typeId ?? 0 }
							options={ typeOptions }
							onChange={ ( val ) =>
								setAttributes( {
									typeId: parseInt( val, 10 ) || undefined,
								} )
							}
							__nextHasNoMarginBottom
						/>
						<ToggleControl
							label={ __( 'Featured events only', 'blockendar' ) }
							checked={ featuredOnly }
							onChange={ ( val ) =>
								setAttributes( { featuredOnly: val } )
							}
						/>
						<ToggleControl
							label={ __( 'Show past events', 'blockendar' ) }
							checked={ showPast }
							onChange={ ( val ) =>
								setAttributes( { showPast: val } )
							}
						/>
					</VStack>
				</PanelBody>

				<PanelBody title={ __( 'Display', 'blockendar' ) }>
					<VStack spacing={ 3 }>
						<RangeControl
							label={ __( 'Events per page', 'blockendar' ) }
							value={ perPage }
							min={ 1 }
							max={ 100 }
							onChange={ ( val ) =>
								setAttributes( { perPage: val } )
							}
							__nextHasNoMarginBottom
						/>
						<SelectControl
							label={ __( 'Layout', 'blockendar' ) }
							value={ layout }
							options={ [
								{
									label: __( 'List', 'blockendar' ),
									value: 'list',
								},
								{
									label: __( 'Grid', 'blockendar' ),
									value: 'grid',
								},
							] }
							onChange={ ( val ) =>
								setAttributes( { layout: val } )
							}
							__nextHasNoMarginBottom
						/>
						<SelectControl
							label={ __( 'Group by', 'blockendar' ) }
							value={ groupBy }
							options={ [
								{
									label: __( 'None', 'blockendar' ),
									value: 'none',
								},
								{
									label: __( 'Date', 'blockendar' ),
									value: 'date',
								},
								{
									label: __( 'Month', 'blockendar' ),
									value: 'month',
								},
								{
									label: __( 'Event type', 'blockendar' ),
									value: 'type',
								},
							] }
							onChange={ ( val ) =>
								setAttributes( { groupBy: val } )
							}
							__nextHasNoMarginBottom
						/>
						<SelectControl
							label={ __( 'Pagination', 'blockendar' ) }
							value={ pagination }
							options={ [
								{
									label: __( 'Paged', 'blockendar' ),
									value: 'paged',
								},
								{
									label: __( 'Load more', 'blockendar' ),
									value: 'load_more',
								},
							] }
							onChange={ ( val ) =>
								setAttributes( { pagination: val } )
							}
							__nextHasNoMarginBottom
						/>
						<SelectControl
							label={ __( 'Order', 'blockendar' ) }
							value={ order }
							options={ [
								{
									label: __( 'Soonest first', 'blockendar' ),
									value: 'ASC',
								},
								{
									label: __( 'Latest first', 'blockendar' ),
									value: 'DESC',
								},
							] }
							onChange={ ( val ) =>
								setAttributes( { order: val } )
							}
							__nextHasNoMarginBottom
						/>
					</VStack>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<ServerSideRender
					block="blockendar/event-list"
					attributes={ attributes }
				/>
			</div>
		</>
	);
}
