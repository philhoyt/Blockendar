<?php
/**
 * blockendar/add-to-calendar render callback.
 *
 * Generates add-to-calendar links client-side from data attributes.
 * No server round-trip for link generation.
 *
 * @package Blockendar
 */
declare( strict_types=1 );

$post_id    = $block->context['postId'] ?? get_the_ID();
$start_date = get_post_meta( $post_id, 'blockendar_start_date', true );
$end_date   = get_post_meta( $post_id, 'blockendar_end_date',   true );
$start_time = get_post_meta( $post_id, 'blockendar_start_time', true );
$end_time   = get_post_meta( $post_id, 'blockendar_end_time',   true );
$all_day    = (bool) get_post_meta( $post_id, 'blockendar_all_day', true );
$tz_str     = get_post_meta( $post_id, 'blockendar_timezone', true ) ?: wp_timezone_string();
$title      = get_the_title( $post_id );
$detail_url = get_permalink( $post_id );
$ics_url    = home_url( "/events/" . get_post_field( 'post_name', $post_id ) . "/ical/" );

if ( ! $start_date ) return;

// Build UTC timestamps for Google Calendar format (Ymd\THis\Z).
$fmt_ical_ts = function( string $date, string $time ) use ( $tz_str, $all_day ): string {
	if ( $all_day ) {
		return str_replace( '-', '', $date );
	}
	try {
		$tz = new DateTimeZone( $tz_str );
		$dt = new DateTimeImmutable( "$date $time:00", $tz );
		return $dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
	} catch ( Exception ) {
		return str_replace( '-', '', $date );
	}
};

$start_ts  = $fmt_ical_ts( $start_date, $start_time ?: '00:00' );
$end_ts    = $fmt_ical_ts( $end_date ?: $start_date, $end_time ?: $start_time ?: '23:59' );
$enc_title = rawurlencode( $title );
$enc_url   = rawurlencode( $detail_url ?? '' );

$google_url = "https://calendar.google.com/calendar/render?action=TEMPLATE"
	. "&text=$enc_title"
	. "&dates=$start_ts/$end_ts"
	. "&details=$enc_url";

$show_google  = (bool) ( $attributes['showGoogle']  ?? true );
$show_apple   = (bool) ( $attributes['showApple']   ?? true );
$show_outlook = (bool) ( $attributes['showOutlook'] ?? true );
$show_ics     = (bool) ( $attributes['showIcs']     ?? true );
?>
<div <?php echo get_block_wrapper_attributes( [ 'class' => 'blockendar-add-to-calendar' ] ); ?>>
	<span class="blockendar-add-to-calendar__label"><?php esc_html_e( 'Add to calendar:', 'blockendar' ); ?></span>
	<ul class="blockendar-add-to-calendar__links">
		<?php if ( $show_google ) : ?>
			<li>
				<a class="blockendar-add-to-calendar__link" href="<?php echo esc_url( $google_url ); ?>"
					target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Google', 'blockendar' ); ?>
				</a>
			</li>
		<?php endif; ?>

		<?php if ( $show_apple || $show_ics ) : ?>
			<li>
				<a class="blockendar-add-to-calendar__link" href="<?php echo esc_url( $ics_url ); ?>">
					<?php if ( $show_apple && $show_ics ) : ?>
						<?php esc_html_e( 'Apple / .ics', 'blockendar' ); ?>
					<?php elseif ( $show_apple ) : ?>
						<?php esc_html_e( 'Apple Calendar', 'blockendar' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'Download .ics', 'blockendar' ); ?>
					<?php endif; ?>
				</a>
			</li>
		<?php endif; ?>

		<?php if ( $show_outlook ) : ?>
			<li>
				<a class="blockendar-add-to-calendar__link"
					href="https://outlook.live.com/calendar/0/deeplink/compose?subject=<?php echo esc_attr( $enc_title ); ?>&startdt=<?php echo esc_attr( $start_date . ( $start_time ? "T$start_time" : '' ) ); ?>&enddt=<?php echo esc_attr( ( $end_date ?: $start_date ) . ( $end_time ? "T$end_time" : '' ) ); ?>&body=<?php echo esc_attr( $enc_url ); ?>"
					target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Outlook', 'blockendar' ); ?>
				</a>
			</li>
		<?php endif; ?>
	</ul>
</div>
