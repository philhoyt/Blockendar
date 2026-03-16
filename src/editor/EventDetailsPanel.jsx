/**
 * Event Details sidebar panel (status, cost, registration, capacity, flags).
 */
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { useSelect, useDispatch } from '@wordpress/data';
import {
	Notice,
	SelectControl,
	TextControl,
	ToggleControl,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const { currencies = [] } = window.blockendarEditor ?? {};

const STATUS_OPTIONS = [
	{ label: __( 'Scheduled', 'blockendar' ), value: 'scheduled' },
	{ label: __( 'Cancelled', 'blockendar' ), value: 'cancelled' },
	{ label: __( 'Postponed', 'blockendar' ), value: 'postponed' },
	{ label: __( 'Sold Out', 'blockendar' ), value: 'sold_out' },
];

const currencyOptions = [
	{ label: __( '— None —', 'blockendar' ), value: '' },
	...currencies.map( ( c ) => ( { label: c, value: c } ) ),
];

export function EventDetailsPanel() {
	const meta = useSelect(
		( select ) =>
			select( editorStore ).getEditedPostAttribute( 'meta' ) ?? {}
	);
	const { editPost } = useDispatch( editorStore );

	const setMeta = ( updates ) =>
		editPost( { meta: { ...meta, ...updates } } );

	const minCost = parseFloat( meta.blockendar_cost_min ) || 0;
	const maxCost = parseFloat( meta.blockendar_cost_max ) || 0;
	const costRangeInvalid = minCost > 0 && maxCost > 0 && minCost > maxCost;

	return (
		<PluginDocumentSettingPanel
			name="blockendar-event-details"
			title={ __( 'Event Details', 'blockendar' ) }
			className="blockendar-panel-event-details"
		>
			<VStack spacing={ 4 }>
				<SelectControl
					label={ __( 'Event status', 'blockendar' ) }
					value={ meta.blockendar_status ?? 'scheduled' }
					options={ STATUS_OPTIONS }
					onChange={ ( val ) =>
						setMeta( { blockendar_status: val } )
					}
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>

				<TextControl
					label={ __( 'Cost (display)', 'blockendar' ) }
					value={ meta.blockendar_cost ?? '' }
					placeholder={ __( 'e.g. $10–$25 or Free', 'blockendar' ) }
					onChange={ ( val ) => setMeta( { blockendar_cost: val } ) }
					__nextHasNoMarginBottom
				/>

				{ costRangeInvalid && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'Min cost must not exceed max cost.',
							'blockendar'
						) }
					</Notice>
				) }

				<TextControl
					label={ __( 'Min cost', 'blockendar' ) }
					type="number"
					min={ 0 }
					step={ 0.01 }
					value={ meta.blockendar_cost_min ?? '' }
					onChange={ ( val ) =>
						setMeta( {
							blockendar_cost_min: parseFloat( val ) || 0,
						} )
					}
					__nextHasNoMarginBottom
				/>

				<TextControl
					label={ __( 'Max cost', 'blockendar' ) }
					type="number"
					min={ 0 }
					step={ 0.01 }
					value={ meta.blockendar_cost_max ?? '' }
					onChange={ ( val ) =>
						setMeta( {
							blockendar_cost_max: parseFloat( val ) || 0,
						} )
					}
					__nextHasNoMarginBottom
				/>

				<SelectControl
					label={ __( 'Currency', 'blockendar' ) }
					value={ meta.blockendar_currency ?? '' }
					options={ currencyOptions }
					onChange={ ( val ) =>
						setMeta( { blockendar_currency: val } )
					}
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>

				<TextControl
					label={ __( 'Registration / Ticket URL', 'blockendar' ) }
					type="url"
					value={ meta.blockendar_registration_url ?? '' }
					placeholder="https://"
					onChange={ ( val ) =>
						setMeta( { blockendar_registration_url: val } )
					}
					__nextHasNoMarginBottom
				/>

				<TextControl
					label={ __( 'Capacity (0 = unlimited)', 'blockendar' ) }
					type="number"
					min={ 0 }
					value={ meta.blockendar_capacity ?? 0 }
					onChange={ ( val ) =>
						setMeta( {
							blockendar_capacity: parseInt( val, 10 ) || 0,
						} )
					}
					__nextHasNoMarginBottom
				/>

				<ToggleControl
					label={ __( 'Featured event', 'blockendar' ) }
					help={ __(
						'Highlights this event in listings and the calendar.',
						'blockendar'
					) }
					checked={ !! meta.blockendar_featured }
					onChange={ ( val ) =>
						setMeta( { blockendar_featured: val } )
					}
				/>

				<ToggleControl
					label={ __( 'Hide from listings', 'blockendar' ) }
					help={ __(
						'Excludes from calendar/list blocks without trashing.',
						'blockendar'
					) }
					checked={ !! meta.blockendar_hide_from_listings }
					onChange={ ( val ) =>
						setMeta( { blockendar_hide_from_listings: val } )
					}
				/>
			</VStack>
		</PluginDocumentSettingPanel>
	);
}
