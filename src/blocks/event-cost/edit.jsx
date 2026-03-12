/**
 * event-cost block — editor component.
 */
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl }           from '@wordpress/components';
import { useEntityProp, store as coreStore } from '@wordpress/core-data';
import { useSelect }                         from '@wordpress/data';
import { __ }                                from '@wordpress/i18n';

/** ISO 4217 → display symbol map (matches get_currency_list() in BlockRegistrar). */
const CURRENCY_SYMBOLS = {
	USD: '$',    EUR: '€',    GBP: '£',    CAD: 'CA$',  AUD: 'A$',
	JPY: '¥',    CHF: 'CHF',  CNY: '¥',    INR: '₹',    MXN: 'MX$',
	BRL: 'R$',   KRW: '₩',   SEK: 'kr',   NOK: 'kr',   DKK: 'kr',
	NZD: 'NZ$',  SGD: 'S$',   HKD: 'HK$',  ZAR: 'R',
};

/**
 * If `value` is a plain number string, prefix/suffix the currency symbol.
 * Otherwise return it unchanged (handles "Free", "$10–$25", etc.).
 */
function formatCost( value, currency, position ) {
	if ( ! value || isNaN( Number( value ) ) ) {
		return value;
	}
	const symbol = CURRENCY_SYMBOLS[ currency ] ?? currency;
	return 'before' === position ? symbol + value : value + symbol;
}

export function Edit( { attributes, setAttributes, context } ) {
	const { buttonLabel } = attributes;
	const postId   = context?.postId;
	const postType = context?.postType ?? 'blockendar_event';

	const [ meta ] = useEntityProp( 'postType', postType, 'meta', postId );

	const cost          = meta?.blockendar_cost             ?? '';
	const regUrl        = meta?.blockendar_registration_url ?? '';
	const eventCurrency = meta?.blockendar_currency         ?? '';

	// Read default currency + position from plugin settings.
	const site             = useSelect( ( select ) => select( coreStore ).getEntityRecord( 'root', 'site' ), [] );
	const pluginSettings   = site?.blockendar_settings ?? {};
	const defaultCurrency  = pluginSettings.default_currency  ?? 'USD';
	const currencyPosition = pluginSettings.currency_position ?? 'before';
	const activeCurrency   = eventCurrency || defaultCurrency;

	const isPlaceholder = ! cost && ! regUrl;
	const rawCost       = isPlaceholder ? '25.00' : cost;
	const displayCost   = formatCost( rawCost, activeCurrency, currencyPosition );
	const showButton    = isPlaceholder || !! regUrl;
	const displayLabel  = buttonLabel || __( 'Register / Get Tickets', 'blockendar' );

	// Build inline layout styles from the `layout` attribute so the editor
	// preview matches the frontend (supports.layout doesn't auto-apply these
	// for leaf blocks in the editor).
	const { layout = {} } = attributes;
	const layoutStyle = {
		flexDirection:  layout.orientation === 'vertical' ? 'column' : undefined,
		justifyContent: layout.justifyContent                        ?? undefined,
		alignItems:     layout.verticalAlignment                     ?? undefined,
		flexWrap:       layout.flexWrap                              ?? undefined,
	};

	// blockGap is stored in attributes.style.spacing.blockGap. WordPress handles
	// it via a generated <style> tag in the editor (targeting a container class
	// the element never receives), not blockProps.style, so we apply it as an
	// inline style. Preset values ("var:preset|spacing|30") → CSS vars.
	const rawGap = attributes.style?.spacing?.blockGap;
	const gap    = rawGap?.startsWith?.( 'var:' )
		? rawGap.replace( /var:(\w+)\|(\w+)\|(\w+)/, 'var(--wp--$1--$2--$3)' )
		: rawGap;

	const blockProps = useBlockProps( { className: 'blockendar-event-cost' } );

	const wrapperStyle = {
		...blockProps.style,
		...layoutStyle,
		...( gap ? { gap } : {} ),
		...( isPlaceholder ? { opacity: 0.5 } : {} ),
	};

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Settings', 'blockendar' ) }>
					<TextControl
						label={ __( 'Button label', 'blockendar' ) }
						value={ buttonLabel }
						onChange={ ( val ) => setAttributes( { buttonLabel: val } ) }
						placeholder={ __( 'Register / Get Tickets', 'blockendar' ) }
						__nextHasNoMarginBottom
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps } style={ wrapperStyle }>
				{ displayCost && (
					<span className="blockendar-event-cost__amount">
						{ displayCost }
					</span>
				) }
				{ showButton && (
					<a className="blockendar-event-cost__cta wp-element-button">
						{ displayLabel }
					</a>
				) }
			</div>
		</>
	);
}
