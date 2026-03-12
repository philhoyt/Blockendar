/**
 * event-datetime block — editor component.
 */
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, ToggleControl, __experimentalVStack as VStack } from '@wordpress/components';
import { useEntityProp } from '@wordpress/core-data';
import { dateI18n, __experimentalGetSettings } from '@wordpress/date';
import { __ } from '@wordpress/i18n';

export function Edit( { attributes, setAttributes, context } ) {
	const {
		showStartDate, showStartTime,
		showEndDate, showEndTime,
		showTimezone,
	} = attributes;

	const postId   = context?.postId;
	const postType = context?.postType ?? 'blockendar_event';

	const [ meta ] = useEntityProp( 'postType', postType, 'meta', postId );

	const startDate = meta?.blockendar_start_date ?? '';
	const startTime = meta?.blockendar_start_time ?? '';
	const endDate   = meta?.blockendar_end_date   ?? '';
	const endTime   = meta?.blockendar_end_time   ?? '';
	const allDay    = !! meta?.blockendar_all_day;
	const timezone  = meta?.blockendar_timezone   ?? '';

	const { formats } = __experimentalGetSettings();

	const fmtDate = ( date ) =>
		date ? dateI18n( formats.date, date ) : '';

	const fmtTime = ( time, date ) =>
		! allDay && time && date
			? dateI18n( formats.time, `${ date }T${ time }` )
			: '';

	// Use placeholder dates when no event data is set.
	const PLACEHOLDER_START_DATE = '2025-06-15';
	const PLACEHOLDER_START_TIME = '09:00:00';
	const PLACEHOLDER_END_DATE   = '2025-06-15';
	const PLACEHOLDER_END_TIME   = '17:00:00';
	const isPlaceholder = ! startDate;

	const activeStartDate = isPlaceholder ? PLACEHOLDER_START_DATE : startDate;
	const activeStartTime = isPlaceholder ? PLACEHOLDER_START_TIME : startTime;
	const activeEndDate   = isPlaceholder ? PLACEHOLDER_END_DATE   : endDate;
	const activeEndTime   = isPlaceholder ? PLACEHOLDER_END_TIME   : endTime;
	const activeAllDay    = isPlaceholder ? false                   : allDay;
	const activeTimezone  = isPlaceholder ? '' : timezone;

	const blockProps = useBlockProps( {
		className: 'blockendar-event-datetime',
		style: isPlaceholder ? { opacity: 0.5 } : undefined,
	} );

	const sameDay = activeStartDate && activeEndDate && activeStartDate === activeEndDate;

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Display', 'blockendar' ) }>
					<VStack spacing={ 0 }>
						<ToggleControl
							label={ __( 'Show start date', 'blockendar' ) }
							checked={ showStartDate }
							onChange={ ( val ) => setAttributes( { showStartDate: val } ) }
							__nextHasNoMarginBottom
						/>
						<ToggleControl
							label={ __( 'Show start time', 'blockendar' ) }
							checked={ showStartTime }
							onChange={ ( val ) => setAttributes( { showStartTime: val } ) }
							__nextHasNoMarginBottom
						/>
						<ToggleControl
							label={ __( 'Show end date', 'blockendar' ) }
							checked={ showEndDate }
							onChange={ ( val ) => setAttributes( { showEndDate: val } ) }
							__nextHasNoMarginBottom
						/>
						<ToggleControl
							label={ __( 'Show end time', 'blockendar' ) }
							checked={ showEndTime }
							onChange={ ( val ) => setAttributes( { showEndTime: val } ) }
							__nextHasNoMarginBottom
						/>
						<ToggleControl
							label={ __( 'Show timezone', 'blockendar' ) }
							checked={ showTimezone }
							onChange={ ( val ) => setAttributes( { showTimezone: val } ) }
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
						{ showStartTime && ! activeAllDay && activeStartTime && (
							<>{ showStartDate ? ' @ ' : '' }{ fmtTime( activeStartTime, activeStartDate ) }</>
						) }
					</time>
				) }

				{ /* End — different day */ }
				{ showEndDate && activeEndDate && ! sameDay && (
					<>
						<span className="blockendar-event-datetime__sep" aria-hidden="true"> – </span>
						<time className="blockendar-event-datetime__end">
							{ fmtDate( activeEndDate ) }
							{ showEndTime && ! activeAllDay && activeEndTime && (
								<>{ showEndDate ? ' @ ' : '' }{ fmtTime( activeEndTime, activeEndDate ) }</>
							) }
						</time>
					</>
				) }

				{ /* End time only — same day */ }
				{ showEndTime && ! activeAllDay && sameDay && activeEndTime && activeEndTime !== activeStartTime && (
					<>
						<span className="blockendar-event-datetime__sep" aria-hidden="true"> – </span>
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
				{ activeAllDay && showStartDate && (
					<span className="blockendar-event-datetime__sep" aria-hidden="true"> – </span>
				) }
				{ activeAllDay && (
					<span className="blockendar-event-datetime__allday">
						{ __( 'All day', 'blockendar' ) }
					</span>
				) }
			</div>
		</>
	);
}
