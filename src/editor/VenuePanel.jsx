/**
 * Venue sidebar panel for blockendar_event.
 *
 * Uses the event_venue taxonomy — shows a searchable term selector
 * with inline new-venue creation (name + address fields).
 */
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
	ComboboxControl,
	TextControl,
	Button,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const TAXONOMY = 'event_venue';

export function VenuePanel() {
	const { editPost } = useDispatch( editorStore );
	const { saveEntityRecord } = useDispatch( coreStore );

	const [ showCreate, setShowCreate ] = useState( false );
	const [ newVenue, setNewVenue ] = useState( {
		name: '',
		address: '',
		city: '',
		state: '',
	} );
	const [ saving, setSaving ] = useState( false );

	// Current post's venue term IDs.
	const venueTermIds = useSelect(
		( select ) =>
			select( editorStore ).getEditedPostAttribute( TAXONOMY ) ?? [],
		[]
	);

	// All venue terms.
	const venues = useSelect(
		( select ) =>
			select( coreStore ).getEntityRecords( 'taxonomy', TAXONOMY, {
				per_page: 100,
				hide_empty: false,
			} ) ?? [],
		[]
	);

	const options = venues.map( ( term ) => ( {
		label: term.name,
		value: term.id,
	} ) );

	const currentVenue = venueTermIds.length ? venueTermIds[ 0 ] : null;

	const handleChange = ( termId ) => {
		editPost( { [ TAXONOMY ]: termId ? [ termId ] : [] } );
	};

	const handleCreate = async () => {
		if ( ! newVenue.name.trim() ) {
			return;
		}
		setSaving( true );

		try {
			const term = await saveEntityRecord( 'taxonomy', TAXONOMY, {
				name: newVenue.name,
			} );

			// Save venue meta via REST.
			if ( term?.id ) {
				await apiFetch( {
					path: `/wp/v2/${ TAXONOMY }/${ term.id }`,
					method: 'POST',
					data: {
						meta: {
							blockendar_venue_address: newVenue.address,
							blockendar_venue_city: newVenue.city,
							blockendar_venue_state: newVenue.state,
						},
					},
				} );

				handleChange( term.id );
			}

			setNewVenue( { name: '', address: '', city: '', state: '' } );
			setShowCreate( false );
		} catch ( e ) {
			// eslint-disable-next-line no-console
			console.error( 'Blockendar: failed to create venue', e );
		} finally {
			setSaving( false );
		}
	};

	return (
		<PluginDocumentSettingPanel
			name="blockendar-venue"
			title={ __( 'Venue', 'blockendar' ) }
			className="blockendar-panel-venue"
		>
			<VStack spacing={ 4 }>
				<ComboboxControl
					label={ __( 'Venue', 'blockendar' ) }
					value={ currentVenue }
					options={ options }
					onChange={ handleChange }
					__nextHasNoMarginBottom
				/>

				{ ! showCreate && (
					<Button
						variant="link"
						onClick={ () => setShowCreate( true ) }
					>
						{ __( '+ Add new venue', 'blockendar' ) }
					</Button>
				) }

				{ showCreate && (
					<VStack spacing={ 2 }>
						<TextControl
							label={ __( 'Venue name', 'blockendar' ) }
							value={ newVenue.name }
							onChange={ ( val ) =>
								setNewVenue( ( p ) => ( { ...p, name: val } ) )
							}
							__nextHasNoMarginBottom
						/>
						<TextControl
							label={ __( 'Address', 'blockendar' ) }
							value={ newVenue.address }
							onChange={ ( val ) =>
								setNewVenue( ( p ) => ( {
									...p,
									address: val,
								} ) )
							}
							__nextHasNoMarginBottom
						/>
						<TextControl
							label={ __( 'City', 'blockendar' ) }
							value={ newVenue.city }
							onChange={ ( val ) =>
								setNewVenue( ( p ) => ( { ...p, city: val } ) )
							}
							__nextHasNoMarginBottom
						/>
						<TextControl
							label={ __( 'State / Province', 'blockendar' ) }
							value={ newVenue.state }
							onChange={ ( val ) =>
								setNewVenue( ( p ) => ( { ...p, state: val } ) )
							}
							__nextHasNoMarginBottom
						/>

						<Button
							variant="primary"
							isBusy={ saving }
							disabled={ saving || ! newVenue.name.trim() }
							onClick={ handleCreate }
						>
							{ __( 'Create venue', 'blockendar' ) }
						</Button>

						<Button
							variant="link"
							isDestructive
							onClick={ () => setShowCreate( false ) }
						>
							{ __( 'Cancel', 'blockendar' ) }
						</Button>
					</VStack>
				) }
			</VStack>
		</PluginDocumentSettingPanel>
	);
}
