/**
 * Blockendar settings SPA — main shell.
 *
 * Fetches blockendar_settings from /wp/v2/settings on mount,
 * distributes to section components, and saves on submit.
 */
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Button, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { GeneralSection } from './sections/GeneralSection';
import { CalendarSection } from './sections/CalendarSection';
import { PermalinksSection } from './sections/PermalinksSection';
import { MapSection } from './sections/MapSection';
import { CurrencySection } from './sections/CurrencySection';
import { RecurringSection } from './sections/RecurringSection';
import { PerformanceSection } from './sections/PerformanceSection';
import { RestApiSection } from './sections/RestApiSection';
import { ImportSection } from './sections/ImportSection';

const { nonce, optionName, defaults } = window.blockendarSettings ?? {};

// Wire nonce into apiFetch.
apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );

const SECTIONS = [
	{ name: 'general', title: __( 'General', 'blockendar' ) },
	{ name: 'calendar', title: __( 'Calendar', 'blockendar' ) },
	{ name: 'permalinks', title: __( 'Permalinks', 'blockendar' ) },
	{ name: 'map', title: __( 'Map', 'blockendar' ) },
	{ name: 'currency', title: __( 'Currency', 'blockendar' ) },
	{ name: 'recurring', title: __( 'Recurring Events', 'blockendar' ) },
	{ name: 'performance', title: __( 'Performance', 'blockendar' ) },
	{ name: 'rest', title: __( 'REST API', 'blockendar' ) },
	{ name: 'import', title: __( 'Import', 'blockendar' ), noSave: true },
];

export function SettingsApp() {
	const [ settings, setSettings ] = useState( defaults ?? {} );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null ); // { type, message }
	const [ activeSection, setActiveSection ] = useState( 'general' );

	// Load current settings on mount.
	useEffect( () => {
		apiFetch( { path: '/wp/v2/settings' } )
			.then( ( data ) => {
				if ( data[ optionName ] ) {
					setSettings( ( prev ) => ( {
						...prev,
						...data[ optionName ],
					} ) );
				}
			} )
			.catch( () => {
				setNotice( {
					type: 'error',
					message: __( 'Could not load settings.', 'blockendar' ),
				} );
			} );
	}, [] );

	const update = ( partial ) =>
		setSettings( ( prev ) => ( { ...prev, ...partial } ) );

	const handleSave = async () => {
		setSaving( true );
		setNotice( null );

		try {
			await apiFetch( {
				path: '/wp/v2/settings',
				method: 'POST',
				data: { [ optionName ]: settings },
			} );
			setNotice( {
				type: 'success',
				message: __( 'Settings saved.', 'blockendar' ),
			} );
		} catch ( e ) {
			setNotice( {
				type: 'error',
				message:
					e?.message ??
					__( 'Save failed. Please try again.', 'blockendar' ),
			} );
		} finally {
			setSaving( false );
		}
	};

	const sectionProps = { settings, update, defaults: defaults ?? {} };
	const currentSection = SECTIONS.find( ( s ) => s.name === activeSection );

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

			<div className="blockendar-settings__layout">
				{ /* Sidebar nav */ }
				<nav className="blockendar-settings__sidebar">
					<ul>
						{ SECTIONS.map( ( section ) => (
							<li key={ section.name }>
								<button
									type="button"
									className={
										'blockendar-settings__nav-item' +
										( activeSection === section.name
											? ' is-active'
											: '' )
									}
									onClick={ () => {
										setActiveSection( section.name );
										setNotice( null );
									} }
								>
									{ section.title }
								</button>
							</li>
						) ) }
					</ul>
				</nav>

				{ /* Content panel */ }
				<div className="blockendar-settings__content">
					<div className="blockendar-settings__panel">
						{ activeSection === 'general' && (
							<GeneralSection { ...sectionProps } />
						) }
						{ activeSection === 'calendar' && (
							<CalendarSection { ...sectionProps } />
						) }
						{ activeSection === 'permalinks' && (
							<PermalinksSection { ...sectionProps } />
						) }
						{ activeSection === 'map' && (
							<MapSection { ...sectionProps } />
						) }
						{ activeSection === 'currency' && (
							<CurrencySection { ...sectionProps } />
						) }
						{ activeSection === 'recurring' && (
							<RecurringSection { ...sectionProps } />
						) }
						{ activeSection === 'performance' && (
							<PerformanceSection { ...sectionProps } />
						) }
						{ activeSection === 'rest' && (
							<RestApiSection { ...sectionProps } />
						) }
						{ activeSection === 'import' && <ImportSection /> }
					</div>

					{ ! currentSection?.noSave && (
						<div className="blockendar-settings__footer">
							<Button
								variant="primary"
								isBusy={ saving }
								disabled={ saving }
								onClick={ handleSave }
							>
								{ saving
									? __( 'Saving…', 'blockendar' )
									: __( 'Save Settings', 'blockendar' ) }
							</Button>
						</div>
					) }
				</div>
			</div>
		</div>
	);
}
