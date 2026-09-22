import { useState } from '@wordpress/element';
import {
	ToggleControl,
	TextControl,
	Button,
	__experimentalVStack as VStack,
	__experimentalHStack as HStack,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const {
	feedUrl: FEED_URL = '',
	feedUrlWebcal: FEED_URL_WEBCAL = '',
} = window.blockendarSettings ?? {};

function generateToken( length = 32 ) {
	const chars =
		'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
	return Array.from(
		{ length },
		() => chars[ Math.floor( Math.random() * chars.length ) ]
	).join( '' );
}

/**
 * A feed URL with a copy button.
 *
 * @param {Object} props
 * @param {string} props.label Field label.
 * @param {string} props.url   The URL to show.
 * @param {string} props.help  Description below the field.
 */
function FeedUrlField( { label, url, help } ) {
	const [ copied, setCopied ] = useState( false );

	const copy = async () => {
		try {
			await window.navigator.clipboard.writeText( url );
			setCopied( true );
			window.setTimeout( () => setCopied( false ), 2000 );
		} catch {
			// Clipboard access can be refused (insecure origin, permissions).
			// The field is selectable, so the user can still copy by hand.
			setCopied( false );
		}
	};

	return (
		<VStack spacing={ 1 }>
			<HStack alignment="bottom" spacing={ 2 } justify="flex-start">
				{ /* The field has to take the free space, or the URL people are
				     being asked to copy is cut off mid-host. */ }
				<div style={ { flexGrow: 1, minWidth: 0 } }>
					<TextControl
						label={ label }
						value={ url }
						readOnly
						onChange={ () => {} }
						__nextHasNoMarginBottom
					/>
				</div>
				<Button variant="secondary" onClick={ copy }>
					{ copied
						? __( 'Copied', 'blockendar' )
						: __( 'Copy', 'blockendar' ) }
				</Button>
			</HStack>
			{ help && <p className="description">{ help }</p> }
		</VStack>
	);
}

export function RestApiSection( { settings, update } ) {
	const [ tokenVisible, setTokenVisible ] = useState( false );
	const token = settings.rest_feed_token ?? '';
	const isPublic = settings.rest_public ?? true;

	// The token only does anything while the API is closed. Appending it to a
	// public feed URL would put a credential in circulation for no benefit.
	const withToken = ( url ) =>
		! isPublic && token
			? `${ url }${ url.includes( '?' ) ? '&' : '?' }token=${ encodeURIComponent(
					token
			  ) }`
			: url;

	return (
		<VStack spacing={ 5 }>
			<h2>{ __( 'REST API', 'blockendar' ) }</h2>

			<ToggleControl
				label={ __( 'Public REST endpoints', 'blockendar' ) }
				help={ __(
					'Allow unauthenticated access to /blockendar/v1/events and /blockendar/v1/calendar. ' +
						'Disable to require authentication for all event data.',
					'blockendar'
				) }
				checked={ isPublic }
				onChange={ ( val ) => update( { rest_public: val } ) }
			/>

			<VStack spacing={ 4 }>
				<h3 style={ { margin: 0 } }>
					{ __( 'Calendar subscription', 'blockendar' ) }
				</h3>

				<FeedUrlField
					label={ __( 'Subscription link (webcal)', 'blockendar' ) }
					url={ withToken( FEED_URL_WEBCAL ) }
					help={ __(
						'Share this to let people subscribe. Most desktop and mobile calendar apps open a webcal link directly.',
						'blockendar'
					) }
				/>

				<FeedUrlField
					label={ __( 'Feed URL (https)', 'blockendar' ) }
					url={ withToken( FEED_URL ) }
					help={ __(
						'The same feed over https. Use this for Google Calendar, which asks for an https URL rather than a webcal link.',
						'blockendar'
					) }
				/>

				<TextControl
					label={ __( 'Include past events for', 'blockendar' ) }
					type="number"
					min={ 0 }
					max={ 3650 }
					value={ settings.subscribe_past_days ?? 30 }
					onChange={ ( val ) =>
						update( { subscribe_past_days: parseInt( val, 10 ) || 0 } )
					}
					help={ __(
						'Days of history the subscription includes. Keeps recently finished events visible in a subscriber calendar.',
						'blockendar'
					) }
					__nextHasNoMarginBottom
				/>

				<TextControl
					label={ __( 'Include upcoming events for', 'blockendar' ) }
					type="number"
					min={ 1 }
					max={ 3650 }
					value={ settings.subscribe_future_days ?? 365 }
					onChange={ ( val ) =>
						update( {
							subscribe_future_days: parseInt( val, 10 ) || 1,
						} )
					}
					help={ __(
						'Days ahead the subscription covers. The feed cannot show events further out than the recurring event horizon has generated.',
						'blockendar'
					) }
					__nextHasNoMarginBottom
				/>
			</VStack>

			<VStack spacing={ 2 }>
				<HStack alignment="left" spacing={ 2 }>
					<TextControl
						label={ __(
							'Calendar feed token (optional)',
							'blockendar'
						) }
						help={ __(
							'When the REST API is not public, this token lets a calendar app read the feed without logging in. ' +
								'Anyone who has the link has the calendar, so share it the way you would share a password.',
							'blockendar'
						) }
						type={ tokenVisible ? 'text' : 'password' }
						value={ token }
						onChange={ ( val ) =>
							update( { rest_feed_token: val } )
						}
						__nextHasNoMarginBottom
					/>
					<Button
						variant="tertiary"
						style={ { marginTop: 24 } }
						onClick={ () => setTokenVisible( ( v ) => ! v ) }
					>
						{ tokenVisible
							? __( 'Hide', 'blockendar' )
							: __( 'Show', 'blockendar' ) }
					</Button>
					<Button
						variant="tertiary"
						style={ { marginTop: 24 } }
						onClick={ () =>
							update( { rest_feed_token: generateToken() } )
						}
					>
						{ __( 'Generate', 'blockendar' ) }
					</Button>
					{ token && (
						<Button
							variant="tertiary"
							isDestructive
							style={ { marginTop: 24 } }
							onClick={ () => update( { rest_feed_token: '' } ) }
						>
							{ __( 'Clear', 'blockendar' ) }
						</Button>
					) }
				</HStack>

				{ isPublic && token && (
					<p className="description">
						{ __(
							'The REST API is public, so this token is not currently required to read the feed.',
							'blockendar'
						) }
					</p>
				) }
			</VStack>
		</VStack>
	);
}
