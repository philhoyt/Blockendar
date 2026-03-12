import { useState } from '@wordpress/element';
import {
	ToggleControl,
	TextControl,
	Button,
	__experimentalVStack as VStack,
	__experimentalHStack as HStack,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const { restUrl } = window.blockendarSettings ?? {};

function generateToken( length = 32 ) {
	const chars =
		'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
	return Array.from(
		{ length },
		() => chars[ Math.floor( Math.random() * chars.length ) ]
	).join( '' );
}

export function RestApiSection( { settings, update } ) {
	const [ tokenVisible, setTokenVisible ] = useState( false );
	const token = settings.rest_feed_token ?? '';
	const feedUrl = `${ restUrl }blockendar/v1/calendar${
		token ? `?token=${ token }` : ''
	}`;

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
				checked={ settings.rest_public ?? true }
				onChange={ ( val ) => update( { rest_public: val } ) }
			/>

			<VStack spacing={ 2 }>
				<HStack alignment="left" spacing={ 2 }>
					<TextControl
						label={ __(
							'Calendar feed token (optional)',
							'blockendar'
						) }
						help={ __(
							'When set, the calendar feed URL will require this token. ' +
								'Useful for sharing private calendars without full authentication.',
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

				{ token && (
					<p className="description">
						{ __( 'Calendar feed URL:', 'blockendar' ) }{ ' ' }
						<code style={ { userSelect: 'all' } }>{ feedUrl }</code>
					</p>
				) }
			</VStack>
		</VStack>
	);
}
