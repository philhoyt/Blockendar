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

$post_id    = $block->context['postId'] ?? get_the_ID();
$start_date = get_post_meta( $post_id, 'blockendar_start_date', true );
$end_date   = get_post_meta( $post_id, 'blockendar_end_date', true );
$start_time = get_post_meta( $post_id, 'blockendar_start_time', true );
$end_time   = get_post_meta( $post_id, 'blockendar_end_time', true );
$all_day    = (bool) get_post_meta( $post_id, 'blockendar_all_day', true );
$tz_str     = get_post_meta( $post_id, 'blockendar_timezone', true ) ?: wp_timezone_string();
$title      = get_the_title( $post_id );
$detail_url = get_permalink( $post_id );
$ics_url    = rest_url( 'blockendar/v1/events/' . $post_id . '/ical' );
$label      = ! empty( $attributes['label'] ) ? $attributes['label'] : __( 'Add to Calendar', 'blockendar' );

if ( ! $start_date ) {
	return;
}

$fmt_ts = function ( string $date, string $time ) use ( $tz_str, $all_day ): string {
	if ( $all_day ) {
		return str_replace( '-', '', $date );
	}
	try {
		$dt = new DateTimeImmutable( "$date $time:00", new DateTimeZone( $tz_str ) );
		return $dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
	} catch ( Exception ) {
		return str_replace( '-', '', $date );
	}
};

$start_ts  = $fmt_ts( $start_date, $start_time ?: '00:00' );
$end_ts    = $fmt_ts( $end_date ?: $start_date, $end_time ?: $start_time ?: '23:59' );
$enc_title = rawurlencode( $title );
$enc_url   = rawurlencode( $detail_url ?? '' );
$start_dt  = $start_date . ( $start_time ? "T$start_time" : '' );
$end_dt    = ( $end_date ?: $start_date ) . ( $end_time ? "T$end_time" : '' );

$google_url = 'https://calendar.google.com/calendar/render?action=TEMPLATE'
	. '&text=' . $enc_title
	. '&dates=' . $start_ts . '/' . $end_ts
	. '&details=' . $enc_url;

$outlook_params = '?subject=' . $enc_title
	. '&startdt=' . rawurlencode( $start_dt )
	. '&enddt=' . rawurlencode( $end_dt )
	. '&body=' . $enc_url;

$outlook_365_url  = 'https://outlook.office.com/calendar/0/deeplink/compose' . $outlook_params;
$outlook_live_url = 'https://outlook.live.com/calendar/0/deeplink/compose' . $outlook_params;

$show_google       = (bool) ( $attributes['showGoogle'] ?? true );
$show_ical         = (bool) ( $attributes['showIcal'] ?? true );
$show_outlook_365  = (bool) ( $attributes['showOutlook365'] ?? true );
$show_outlook_live = (bool) ( $attributes['showOutlookLive'] ?? true );
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
