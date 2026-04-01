/**
 * event-countdown — editor component.
 */
import './style.css';
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	TextControl,
	ComboboxControl,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import metadata from './block.json';

const LABELS = { d: 'days', h: 'hours', m: 'minutes', s: 'seconds' };
const DEMO = { d: '05', h: '12', m: '30', s: '45' };

function Edit( { attributes, setAttributes } ) {
	const { expiredLabel, passedLabel, format, pinnedPostId } = attributes;
	const [ search, setSearch ] = useState( '' );

	const events = useSelect(
		( select ) =>
			select( coreStore ).getEntityRecords(
				'postType',
				'blockendar_event',
				{
					per_page: 20,
					search,
					_fields: [ 'id', 'title' ],
					status: 'publish',
				}
			),
		[ search ]
	);

	const options = ( events ?? [] ).map( ( e ) => ( {
		value: String( e.id ),
		label: e.title.rendered,
	} ) );

	const segments = ( format ?? 'd:h:m:s' ).split( ':' );
	const blockProps = useBlockProps( {
		className: 'blockendar-event-countdown',
	} );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Countdown', 'blockendar' ) }>
					<VStack spacing={ 4 }>
						<SelectControl
							label={ __( 'Format', 'blockendar' ) }
							value={ format }
							options={ [
								{
									label: __(
										'Days, hours, minutes \u0026 seconds',
										'blockendar'
									),
									value: 'd:h:m:s',
								},
								{
									label: __(
										'Days, hours \u0026 minutes',
										'blockendar'
									),
									value: 'd:h:m',
								},
								{
									label: __(
										'Days \u0026 hours',
										'blockendar'
									),
									value: 'd:h',
								},
								{
									label: __( 'Days only', 'blockendar' ),
									value: 'd',
								},
							] }
							onChange={ ( val ) =>
								setAttributes( { format: val } )
							}
							__nextHasNoMarginBottom
						/>
						<TextControl
							label={ __( 'Started message', 'blockendar' ) }
							value={ expiredLabel }
							placeholder={ __(
								'This event has started.',
								'blockendar'
							) }
							onChange={ ( val ) =>
								setAttributes( { expiredLabel: val } )
							}
							__nextHasNoMarginBottom
						/>
						<TextControl
							label={ __( 'Passed message', 'blockendar' ) }
							value={ passedLabel }
							placeholder={ __(
								'This event has passed.',
								'blockendar'
							) }
							onChange={ ( val ) =>
								setAttributes( { passedLabel: val } )
							}
							__nextHasNoMarginBottom
						/>
					</VStack>
				</PanelBody>

				<PanelBody
					title={ __( 'Event', 'blockendar' ) }
					initialOpen={ false }
				>
					<ComboboxControl
						label={ __( 'Pin to event', 'blockendar' ) }
						value={ pinnedPostId ? String( pinnedPostId ) : '' }
						options={ options }
						onChange={ ( val ) =>
							setAttributes( {
								pinnedPostId: val ? parseInt( val, 10 ) : 0,
							} )
						}
						onFilterValueChange={ setSearch }
						help={ __(
							'Override which event this block counts down to. Leave empty to use the current page\u2019s event.',
							'blockendar'
						) }
						__nextHasNoMarginBottom
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				{ segments.map( ( seg ) => (
					<span key={ seg } className="blockendar-countdown__segment">
						<strong>{ DEMO[ seg ] }</strong>{ ' ' }
						<span className="blockendar-countdown__unit">
							{ LABELS[ seg ] }
						</span>
					</span>
				) ) }
			</div>
		</>
	);
}

registerBlockType( metadata.name, { edit: Edit } );
