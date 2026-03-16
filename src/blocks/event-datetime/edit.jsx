/**
 * event-datetime block — editor component.
 */
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	Button,
	PanelBody,
	RadioControl,
	TextControl,
	ToggleControl,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useEntityProp, store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { dateI18n, getSettings } from '@wordpress/date';
import { __ } from '@wordpress/i18n';

const TIME_FORMAT_OPTIONS = [
	{
		label: __( 'Default (from settings)', 'blockendar' ),
		value: '',
	},
	{
		label: __( '12-hour (9:30 am / 2:15 pm)', 'blockendar' ),
		value: 'g:i a',
	},
	{
		label: __( '24-hour (09:30 / 14:15)', 'blockendar' ),
		value: 'H:i',
	},
];

export function Edit( { attributes, setAttributes, context } ) {
	const blockendarSettings = useSelect( ( select ) => {
		const site = select( coreStore ).getEntityRecord( 'root', 'site' );
		return site?.blockendar_settings ?? null;
	} );

	const wpFormats = getSettings().formats;
	const siteDateFormat = blockendarSettings?.date_format || wpFormats.date;
	const siteTimeFormat = blockendarSettings?.time_format || wpFormats.time;

	const {
		showStartDate,
		showStartTime,
		showEndDate,
		showEndTime,
		showTimezone,
		dateFormat,
		timeFormat,
		timeSeparator,
		rangeSeparator,
	} = attributes;

	const postId = context?.postId;
	const postType = context?.postType ?? 'blockendar_event';

	const [ meta ] = useEntityProp( 'postType', postType, 'meta', postId );

	const startDate = meta?.blockendar_start_date ?? '';
	const startTime = meta?.blockendar_start_time ?? '';
	const endDate = meta?.blockendar_end_date ?? '';
	const endTime = meta?.blockendar_end_time ?? '';
	const allDay = !! meta?.blockendar_all_day;
	const timezone = meta?.blockendar_timezone ?? '';

	const effectiveDateFormat = dateFormat || siteDateFormat;
	const effectiveTimeFormat = timeFormat || siteTimeFormat;

	const fmtDate = ( date ) =>
		date ? dateI18n( effectiveDateFormat, date ) : '';

	const fmtTime = ( time, date ) =>
		! allDay && time && date
			? dateI18n( effectiveTimeFormat, `${ date }T${ time }` )
			: '';

	// Use placeholder dates when no event data is set.
	const PLACEHOLDER_START_DATE = '2025-06-15';
	const PLACEHOLDER_START_TIME = '09:00:00';
	const PLACEHOLDER_END_DATE = '2025-06-15';
	const PLACEHOLDER_END_TIME = '17:00:00';
	const isPlaceholder = ! startDate;

	const activeStartDate = isPlaceholder ? PLACEHOLDER_START_DATE : startDate;
	const activeStartTime = isPlaceholder ? PLACEHOLDER_START_TIME : startTime;
	const activeEndDate = isPlaceholder ? PLACEHOLDER_END_DATE : endDate;
	const activeEndTime = isPlaceholder ? PLACEHOLDER_END_TIME : endTime;
	const activeAllDay = isPlaceholder ? false : allDay;
	const activeTimezone = isPlaceholder ? '' : timezone;

	const blockProps = useBlockProps( {
		className: 'blockendar-event-datetime',
		style: isPlaceholder ? { opacity: 0.5 } : undefined,
	} );

	const sameDay =
		activeStartDate && activeEndDate && activeStartDate === activeEndDate;

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Display', 'blockendar' ) }>
					<VStack spacing={ 0 }>
						<ToggleControl
							label={ __( 'Show start date', 'blockendar' ) }
							checked={ showStartDate }
							onChange={ ( val ) =>
								setAttributes( { showStartDate: val } )
							}
							__nextHasNoMarginBottom
						/>
						<ToggleControl
							label={ __( 'Show start time', 'blockendar' ) }
							checked={ showStartTime }
							onChange={ ( val ) =>
								setAttributes( { showStartTime: val } )
							}
							__nextHasNoMarginBottom
						/>
						<ToggleControl
							label={ __( 'Show end date', 'blockendar' ) }
							checked={ showEndDate }
							onChange={ ( val ) =>
								setAttributes( { showEndDate: val } )
							}
							__nextHasNoMarginBottom
						/>
						<ToggleControl
							label={ __( 'Show end time', 'blockendar' ) }
							checked={ showEndTime }
							onChange={ ( val ) =>
								setAttributes( { showEndTime: val } )
							}
							__nextHasNoMarginBottom
						/>
						<ToggleControl
							label={ __( 'Show timezone', 'blockendar' ) }
							checked={ showTimezone }
							onChange={ ( val ) =>
								setAttributes( { showTimezone: val } )
							}
							__nextHasNoMarginBottom
						/>
					</VStack>
				</PanelBody>

				<PanelBody
					title={ __( 'Format', 'blockendar' ) }
					initialOpen={ false }
				>
					<VStack spacing={ 4 }>
						<HStack alignment="flex-end" spacing={ 2 }>
							<TextControl
								label={ __( 'Date format', 'blockendar' ) }
								help={
									<>
										{ __( 'Preview:', 'blockendar' ) }{ ' ' }
										<code>
											{ dateI18n(
												effectiveDateFormat,
												new Date()
											) }
										</code>
									</>
								}
								placeholder={ siteDateFormat }
								value={ dateFormat }
								onChange={ ( val ) =>
									setAttributes( { dateFormat: val } )
								}
								__nextHasNoMarginBottom
							/>
							{ dateFormat && (
								<Button
									variant="tertiary"
									onClick={ () =>
										setAttributes( { dateFormat: '' } )
									}
								>
									{ __( 'Reset', 'blockendar' ) }
								</Button>
							) }
						</HStack>

						<RadioControl
							label={ __( 'Time format', 'blockendar' ) }
							selected={ timeFormat }
							options={ TIME_FORMAT_OPTIONS }
							onChange={ ( val ) =>
								setAttributes( { timeFormat: val } )
							}
						/>

						<TextControl
							label={ __( 'Date/time separator', 'blockendar' ) }
							help={ __(
								'Symbol placed between the date and time.',
								'blockendar'
							) }
							value={ timeSeparator }
							onChange={ ( val ) =>
								setAttributes( { timeSeparator: val } )
							}
							__nextHasNoMarginBottom
						/>

						<TextControl
							label={ __( 'Range separator', 'blockendar' ) }
							help={ __(
								'Symbol placed between start and end.',
								'blockendar'
							) }
							value={ rangeSeparator }
							onChange={ ( val ) =>
								setAttributes( { rangeSeparator: val } )
							}
							__nextHasNoMarginBottom
						/>
					</VStack>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				{ /* Start */ }
				{ ( showStartDate || ( showStartTime && ! activeAllDay ) ) && (
					<time className="blockendar-event-datetime__start">
						{ showStartDate && fmtDate( activeStartDate ) }
						{ showStartTime &&
							! activeAllDay &&
							activeStartTime && (
								<>
									{ showStartDate
										? ` ${ timeSeparator } `
										: '' }
									{ fmtTime(
										activeStartTime,
										activeStartDate
									) }
								</>
							) }
					</time>
				) }

				{ /* End — different day */ }
				{ showEndDate && activeEndDate && ! sameDay && (
					<>
						<span
							className="blockendar-event-datetime__sep"
							aria-hidden="true"
						>
							{ ` ${ rangeSeparator } ` }
						</span>
						<time className="blockendar-event-datetime__end">
							{ fmtDate( activeEndDate ) }
							{ showEndTime &&
								! activeAllDay &&
								activeEndTime && (
									<>
										{ showEndDate
											? ` ${ timeSeparator } `
											: '' }
										{ fmtTime(
											activeEndTime,
											activeEndDate
										) }
									</>
								) }
						</time>
					</>
				) }

				{ /* End time only — same day */ }
				{ showEndTime &&
					! activeAllDay &&
					sameDay &&
					activeEndTime &&
					activeEndTime !== activeStartTime && (
						<>
							<span
								className="blockendar-event-datetime__sep"
								aria-hidden="true"
							>
								{ ` ${ rangeSeparator } ` }
							</span>
							<time className="blockendar-event-datetime__end">
								{ fmtTime( activeEndTime, activeEndDate ) }
							</time>
						</>
					) }

				{ /* Timezone */ }
				{ showTimezone && ! activeAllDay && activeTimezone && (
					<span className="blockendar-event-datetime__tz">
						({ activeTimezone })
					</span>
				) }

				{ /* All day */ }
				{ activeAllDay && showStartTime && showStartDate && (
					<span
						className="blockendar-event-datetime__sep"
						aria-hidden="true"
					>
						{ ` ${ rangeSeparator } ` }
					</span>
				) }
				{ activeAllDay && showStartTime && (
					<span className="blockendar-event-datetime__allday">
						{ __( 'All day', 'blockendar' ) }
					</span>
				) }
			</div>
		</>
	);
}
