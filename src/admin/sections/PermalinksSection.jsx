import {
	TextControl,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export function PermalinksSection( { settings, update } ) {
	const slug = settings.events_slug ?? 'events';
	const preview = `/${ slug }/your-event-name/`;

	return (
		<VStack spacing={ 5 }>
			<h2>{ __( 'Permalinks', 'blockendar' ) }</h2>

			<TextControl
				label={ __( 'Events base slug', 'blockendar' ) }
				help={
					<>
						{ __(
							'The URL prefix for events. Preview:',
							'blockendar'
						) }
						<code>{ preview }</code>
						<br />
						{ __(
							'Save and visit Settings > Permalinks to flush rewrite rules after changing this.',
							'blockendar'
						) }
					</>
				}
				value={ slug }
				onChange={ ( val ) => update( { events_slug: val } ) }
				__nextHasNoMarginBottom
			/>
		</VStack>
	);
}
