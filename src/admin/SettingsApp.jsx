/**
 * Blockendar settings SPA — main shell.
 *
 * Fetches blockendar_settings from /wp/v2/settings on mount,
 * distributes to section components, and saves on submit.
 */
import { useState, useEffect } from '@wordpress/element';
import apiFetch                from '@wordpress/api-fetch';
import {
	TabPanel,
	Button,
	Notice,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { GeneralSection }     from './sections/GeneralSection';
import { CalendarSection }    from './sections/CalendarSection';
import { PermalinksSection }  from './sections/PermalinksSection';
import { MapSection }         from './sections/MapSection';
import { CurrencySection }    from './sections/CurrencySection';
import { RecurringSection }   from './sections/RecurringSection';
import { PerformanceSection } from './sections/PerformanceSection';
import { RestApiSection }     from './sections/RestApiSection';

const { restUrl, nonce, optionName, defaults } = window.blockendarSettings ?? {};

// Wire nonce into apiFetch.
apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );

const TABS = [
	{ name: 'general',     title: __( 'General',         'blockendar' ) },
	{ name: 'calendar',    title: __( 'Calendar',         'blockendar' ) },
	{ name: 'permalinks',  title: __( 'Permalinks',       'blockendar' ) },
	{ name: 'map',         title: __( 'Map',              'blockendar' ) },
	{ name: 'currency',    title: __( 'Currency',         'blockendar' ) },
	{ name: 'recurring',   title: __( 'Recurring Events', 'blockendar' ) },
	{ name: 'performance', title: __( 'Performance',      'blockendar' ) },
	{ name: 'rest',        title: __( 'REST API',         'blockendar' ) },
];

export function SettingsApp() {
	const [ settings, setSettings ] = useState( defaults ?? {} );
	const [ saving,   setSaving ]   = useState( false );
	const [ notice,   setNotice ]   = useState( null ); // { type, message }

	// Load current settings on mount.
	useEffect( () => {
		apiFetch( { path: '/wp/v2/settings' } )
			.then( ( data ) => {
				if ( data[ optionName ] ) {
					setSettings( ( prev ) => ( { ...prev, ...data[ optionName ] } ) );
				}
			} )
			.catch( () => {
				setNotice( { type: 'error', message: __( 'Could not load settings.', 'blockendar' ) } );
			} );
	}, [] );

	const update = ( partial ) => setSettings( ( prev ) => ( { ...prev, ...partial } ) );

	const handleSave = async () => {
		setSaving( true );
		setNotice( null );

		try {
			await apiFetch( {
				path:   '/wp/v2/settings',
				method: 'POST',
				data:   { [ optionName ]: settings },
			} );
			setNotice( { type: 'success', message: __( 'Settings saved.', 'blockendar' ) } );
		} catch ( e ) {
			setNotice( {
				type:    'error',
				message: e?.message ?? __( 'Save failed. Please try again.', 'blockendar' ),
			} );
		} finally {
			setSaving( false );
		}
	};

	const sectionProps = { settings, update };

	return (
		<div className="blockendar-settings wrap">
			<h1 className="blockendar-settings__heading">
				{ __( 'Blockendar Settings', 'blockendar' ) }
			</h1>

			{ notice && (
				<Notice
					status={ notice.type }
					isDismissible
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }

			<TabPanel tabs={ TABS } className="blockendar-settings__tabs">
				{ ( tab ) => (
					<div className="blockendar-settings__panel">
						{ tab.name === 'general'     && <GeneralSection     { ...sectionProps } /> }
						{ tab.name === 'calendar'    && <CalendarSection    { ...sectionProps } /> }
						{ tab.name === 'permalinks'  && <PermalinksSection  { ...sectionProps } /> }
						{ tab.name === 'map'         && <MapSection         { ...sectionProps } /> }
						{ tab.name === 'currency'    && <CurrencySection    { ...sectionProps } /> }
						{ tab.name === 'recurring'   && <RecurringSection   { ...sectionProps } /> }
						{ tab.name === 'performance' && <PerformanceSection { ...sectionProps } /> }
						{ tab.name === 'rest'        && <RestApiSection     { ...sectionProps } /> }
					</div>
				) }
			</TabPanel>

			<div className="blockendar-settings__footer">
				<Button
					variant="primary"
					isBusy={ saving }
					disabled={ saving }
					onClick={ handleSave }
				>
					{ saving ? __( 'Saving…', 'blockendar' ) : __( 'Save Settings', 'blockendar' ) }
				</Button>
			</div>
		</div>
	);
}
