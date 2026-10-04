<?php
/**
 * blockendar/add-to-calendar render callback.
 *
 * @package Blockendar
 */
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$post_id = blockendar_block_event_id( $block );

if ( ! $post_id ) {
	return;
}

$occurrence = blockendar_resolve_occurrence( $post_id );
$start_date = $occurrence ? $occurrence->start_date : get_post_meta( $post_id, 'blockendar_start_date', true );
$end_date   = $occurrence ? $occurrence->end_date : get_post_meta( $post_id, 'blockendar_end_date', true );
$all_day    = $occurrence ? (bool) $occurrence->all_day : (bool) get_post_meta( $post_id, 'blockendar_all_day', true );
$ongoing    = $occurrence ? ! empty( $occurrence->ongoing ) : (bool) get_post_meta( $post_id, 'blockendar_ongoing', true );
$start_time = get_post_meta( $post_id, 'blockendar_start_time', true );
$end_time   = get_post_meta( $post_id, 'blockendar_end_time', true );

// Google/Outlook deep links require an end, and the index holds a far-future
// sentinel for ongoing events. Use the start day instead: timed events run to
// 23:59 that day, all-day events cover the start day only. The iCal link goes
// through the REST endpoint, which omits DTEND for ongoing events.
if ( $ongoing ) {
	$end_date = $start_date;
	$end_time = $all_day ? '' : '23:59';
}
$tz_str  = get_post_meta( $post_id, 'blockendar_timezone', true ) ?: wp_timezone_string();
$ics_url = rest_url( 'blockendar/v1/events/' . $post_id . '/ical' );

// The Google and Outlook links describe the occurrence on screen; the download
// has to be the same one. A single event's link stays as it was.
if ( $occurrence && ! empty( $occurrence->recurrence_id ) ) {
	$ics_url = add_query_arg( 'occurrence_date', $occurrence->start_date, $ics_url );
}

$label = ! empty( $attributes['label'] ) ? $attributes['label'] : __( 'Add to Calendar', 'blockendar' );

if ( ! $start_date ) {
	return;
}

try {
	$tz = new DateTimeZone( $tz_str );
} catch ( Exception ) {
	$tz = wp_timezone();
}

/*
 * The occurrence as two moments in the event's own timezone. For an all-day
 * event both are midnight, on the first day and on the last: end_date is the
 * last day, and CalendarLinks adds the day each service wants on top.
 */
$last_date = $end_date ?: $start_date;

if ( $all_day ) {
	$start = DateTimeImmutable::createFromFormat( '!Y-m-d', $start_date, $tz );
	$end   = DateTimeImmutable::createFromFormat( '!Y-m-d', $last_date, $tz );
} else {
	$start = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $start_date . ' ' . ( $start_time ?: '00:00' ), $tz );
	$end   = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $last_date . ' ' . ( $end_time ?: $start_time ?: '23:59' ), $tz );
}

$google_url       = '';
$outlook_365_url  = '';
$outlook_live_url = '';

// A date that will not parse leaves only the iCalendar link, which the REST
// route builds for itself.
if ( $start && $end ) {
	$calendar_event = [
		'title'    => \Blockendar\Blocks\CalendarLinks::title( $post_id ),
		'start'    => $start,
		'end'      => $end,
		'all_day'  => $all_day,
		'details'  => \Blockendar\Blocks\CalendarLinks::details( $post_id ),
		'location' => \Blockendar\Blocks\CalendarLinks::location( $post_id ),
	];

	$google_url       = \Blockendar\Blocks\CalendarLinks::google( $calendar_event );
	$outlook_365_url  = \Blockendar\Blocks\CalendarLinks::outlook( $calendar_event, 'outlook.office.com' );
	$outlook_live_url = \Blockendar\Blocks\CalendarLinks::outlook( $calendar_event, 'outlook.live.com' );
}

$show_google       = '' !== $google_url && ( $attributes['showGoogle'] ?? true );
$show_ical         = (bool) ( $attributes['showIcal'] ?? true );
$show_outlook_365  = '' !== $outlook_365_url && ( $attributes['showOutlook365'] ?? true );
$show_outlook_live = '' !== $outlook_live_url && ( $attributes['showOutlookLive'] ?? true );
?>
<div <?php echo get_block_wrapper_attributes( [ 'class' => 'blockendar-add-to-calendar' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<details class="blockendar-add-to-calendar__dropdown">
		<summary class="blockendar-add-to-calendar__toggle wp-element-button">
			<?php echo esc_html( $label ); ?>
		</summary>
		<ul class="blockendar-add-to-calendar__menu">
			<?php if ( $show_google ) : ?>
				<li>
					<a class="blockendar-add-to-calendar__item"
						href="<?php echo esc_url( $google_url ); ?>"
						target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Google Calendar', 'blockendar' ); ?>
					</a>
				</li>
			<?php endif; ?>

			<?php if ( $show_ical ) : ?>
				<li>
					<a class="blockendar-add-to-calendar__item"
						href="<?php echo esc_url( $ics_url ); ?>">
						<?php esc_html_e( 'iCalendar', 'blockendar' ); ?>
					</a>
				</li>
			<?php endif; ?>

			<?php if ( $show_outlook_365 ) : ?>
				<li>
					<a class="blockendar-add-to-calendar__item"
						href="<?php echo esc_url( $outlook_365_url ); ?>"
						target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Outlook 365', 'blockendar' ); ?>
					</a>
				</li>
			<?php endif; ?>

			<?php if ( $show_outlook_live ) : ?>
				<li>
					<a class="blockendar-add-to-calendar__item"
						href="<?php echo esc_url( $outlook_live_url ); ?>"
						target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Outlook Live', 'blockendar' ); ?>
					</a>
				</li>
			<?php endif; ?>
		</ul>
	</details>
</div>
