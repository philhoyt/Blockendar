/**
 * Event Details sidebar panel (status, cost, registration, capacity, flags).
 */
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { useSelect, useDispatch } from '@wordpress/data';
import {
	SelectControl,
	TextControl,
	ToggleControl,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const STATUS_OPTIONS = [
	{ label: __( 'Scheduled', 'blockendar' ), value: 'scheduled' },
	{ label: __( 'Cancelled', 'blockendar' ), value: 'cancelled' },
	{ label: __( 'Postponed', 'blockendar' ), value: 'postponed' },
	{ label: __( 'Sold Out', 'blockendar' ), value: 'sold_out' },
];

export function EventDetailsPanel() {
	const meta = useSelect(
		( select ) =>
			select( editorStore ).getEditedPostAttribute( 'meta' ) ?? {}
	);
	const { editPost } = useDispatch( editorStore );

	const setMeta = ( updates ) =>
		editPost( { meta: { ...meta, ...updates } } );

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
