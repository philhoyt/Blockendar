import {
	TextControl,
	RadioControl,
	SelectControl,
	Button,
	ExternalLink,
	__experimentalVStack as VStack,
	__experimentalHStack as HStack,
} from '@wordpress/components';
import { dateI18n } from '@wordpress/date';
import { __, sprintf } from '@wordpress/i18n';

const { siteTimezone = 'UTC', generalSettingsUrl = '' } =
	window.blockendarSettings ?? {};

const TIMEZONE_OPTIONS = [
	{
		label: __( "Use each event's own timezone", 'blockendar' ),
		value: 'event',
	},
	{
		label: __( 'Always use the site timezone', 'blockendar' ),
		value: 'site',
	},
];

const TIME_FORMAT_OPTIONS = [
	{
		label: __( '12-hour (9:30 am / 2:15 pm)', 'blockendar' ),
		value: 'g:i a',
	},
	{
		label: __( '24-hour (09:30 / 14:15)', 'blockendar' ),
		value: 'H:i',
	},
];

export function GeneralSection( { settings, update, defaults } ) {
	// Detect current mode from whatever format string is stored.
	const stored = settings.time_format ?? 'g:i a';
	const timeFormatValue =
		stored.includes( 'g' ) || stored.includes( 'h' ) ? 'g:i a' : 'H:i';

	return (
		<VStack spacing={ 5 }>
			<h2>{ __( 'General', 'blockendar' ) }</h2>

			<div>
				<HStack alignment="flex-end" spacing={ 2 }>
					<div style={ { flex: 1 } }>
						<TextControl
							label={ __( 'Date format', 'blockendar' ) }
							help={
								<>
									{ __( 'Preview:', 'blockendar' ) }
									<code>
										{ dateI18n(
											settings.date_format || 'F j, Y',
											new Date()
										) }
									</code>
									{ ' · ' }
									<ExternalLink href="https://www.php.net/manual/en/datetime.format.php">
										{ __(
											'PHP date format reference',
											'blockendar'
										) }
									</ExternalLink>
								</>
							}
							value={ settings.date_format ?? '' }
							onChange={ ( val ) =>
								update( { date_format: val } )
							}
							__nextHasNoMarginBottom
						/>
					</div>
					<Button
						variant="tertiary"
						onClick={ () =>
							update( {
								date_format: defaults.date_format ?? 'F j, Y',
							} )
						}
					>
						{ __( 'Reset', 'blockendar' ) }
					</Button>
				</HStack>
			</div>

			<RadioControl
				label={ __( 'Time format', 'blockendar' ) }
				help={ __(
					'Used in the event editor time picker and on the frontend.',
					'blockendar'
				) }
				selected={ timeFormatValue }
				options={ TIME_FORMAT_OPTIONS }
				onChange={ ( val ) => update( { time_format: val } ) }
			/>

			<SelectControl
				label={ __( 'Timezone display', 'blockendar' ) }
				help={ __(
					'Controls how event times are displayed to visitors.',
					'blockendar'
				) }
				value={ settings.timezone_mode ?? 'site' }
				options={ TIMEZONE_OPTIONS }
				onChange={ ( val ) => update( { timezone_mode: val } ) }
				__nextHasNoMarginBottom
			/>

			<p style={ { margin: 0 } }>
				{ sprintf(
					/* translators: %s: IANA timezone identifier e.g. America/New_York */
					__(
						'Event times are stored and displayed in the site timezone: %s.',
						'blockendar'
					),
					siteTimezone
				) }{ ' ' }
				<a href={ generalSettingsUrl }>
					{ __(
						'Change in WordPress General Settings →',
						'blockendar'
					) }
				</a>
			</p>
		</VStack>
	);
}
