/**
 * add-to-calendar block — editor component.
 */
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	TextControl,
	ToggleControl,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export function Edit( { attributes, setAttributes } ) {
	const { label, showGoogle, showIcal, showOutlook365, showOutlookLive } =
		attributes;

	const displayLabel = label || __( 'Add to Calendar', 'blockendar' );
	const blockProps = useBlockProps( {
		className: 'blockendar-add-to-calendar',
	} );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Settings', 'blockendar' ) }>
					<VStack spacing={ 3 }>
						<TextControl
							label={ __( 'Button label', 'blockendar' ) }
							value={ label }
							onChange={ ( val ) =>
								setAttributes( { label: val } )
							}
							placeholder={ __(
								'Add to Calendar',
								'blockendar'
							) }
							__nextHasNoMarginBottom
						/>
						<ToggleControl
							label={ __( 'Google Calendar', 'blockendar' ) }
							checked={ showGoogle }
							onChange={ ( val ) =>
								setAttributes( { showGoogle: val } )
							}
							__nextHasNoMarginBottom
						/>
						<ToggleControl
							label={ __( 'iCalendar', 'blockendar' ) }
							checked={ showIcal }
							onChange={ ( val ) =>
								setAttributes( { showIcal: val } )
							}
							__nextHasNoMarginBottom
						/>
						<ToggleControl
							label={ __( 'Outlook 365', 'blockendar' ) }
							checked={ showOutlook365 }
							onChange={ ( val ) =>
								setAttributes( { showOutlook365: val } )
							}
							__nextHasNoMarginBottom
						/>
						<ToggleControl
							label={ __( 'Outlook Live', 'blockendar' ) }
							checked={ showOutlookLive }
							onChange={ ( val ) =>
								setAttributes( { showOutlookLive: val } )
							}
							__nextHasNoMarginBottom
						/>
					</VStack>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<div className="blockendar-add-to-calendar__dropdown">
					<div className="blockendar-add-to-calendar__toggle wp-element-button">
						{ displayLabel }
					</div>
					<ul
						className="blockendar-add-to-calendar__menu"
						style={ { display: 'none' } }
					>
						{ showGoogle && (
							<li>
								<span className="blockendar-add-to-calendar__item">
									{ __( 'Google Calendar', 'blockendar' ) }
								</span>
							</li>
						) }
						{ showIcal && (
							<li>
								<span className="blockendar-add-to-calendar__item">
									{ __( 'iCalendar', 'blockendar' ) }
								</span>
							</li>
						) }
						{ showOutlook365 && (
							<li>
								<span className="blockendar-add-to-calendar__item">
									{ __( 'Outlook 365', 'blockendar' ) }
								</span>
							</li>
						) }
						{ showOutlookLive && (
							<li>
								<span className="blockendar-add-to-calendar__item">
									{ __( 'Outlook Live', 'blockendar' ) }
								</span>
							</li>
						) }
					</ul>
				</div>
			</div>
		</>
	);
}
