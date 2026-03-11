import {
	SelectControl,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const CURRENCIES = [
	'USD','EUR','GBP','CAD','AUD','JPY','CHF','CNY',
	'INR','MXN','BRL','KRW','SEK','NOK','DKK','NZD',
	'SGD','HKD','ZAR','AED','PLN','CZK','HUF','THB',
];

const POSITION_OPTIONS = [
	{ label: __( 'Before amount ($10)', 'blockendar' ), value: 'before' },
	{ label: __( 'After amount (10$)',  'blockendar' ), value: 'after' },
];

export function CurrencySection( { settings, update } ) {
	const currency = settings.default_currency ?? 'USD';
	const position = settings.currency_position ?? 'before';
	const preview  = position === 'before' ? `${ currency } 10.00` : `10.00 ${ currency }`;

	return (
		<VStack spacing={ 5 }>
			<h2>{ __( 'Currency', 'blockendar' ) }</h2>

			<SelectControl
				label={ __( 'Default currency', 'blockendar' ) }
				help={ __( 'Used as the default for new events. Individual events can override this.', 'blockendar' ) }
				value={ currency }
				options={ CURRENCIES.map( ( c ) => ( { label: c, value: c } ) ) }
				onChange={ ( val ) => update( { default_currency: val } ) }
				__nextHasNoMarginBottom
			/>

			<SelectControl
				label={ __( 'Symbol position', 'blockendar' ) }
				help={ `${ __( 'Preview:', 'blockendar' ) } ${ preview }` }
				value={ position }
				options={ POSITION_OPTIONS }
				onChange={ ( val ) => update( { currency_position: val } ) }
				__nextHasNoMarginBottom
			/>
		</VStack>
	);
}
