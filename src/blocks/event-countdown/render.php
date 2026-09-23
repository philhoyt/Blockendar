<?php
/**
 * blockendar/event-countdown render callback.
 *
 * Renders a data-attribute anchor for the client-side view.js to hydrate.
 *
 * @package Blockendar
 */
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$allowed_formats = [ 'd:h:m:s', 'd:h:m', 'd:h', 'd' ];
$format          = in_array( $attributes['format'] ?? 'd:h:m:s', $allowed_formats, true )
	? $attributes['format']
	: 'd:h:m:s';

$expired_label = $attributes['expiredLabel'] ?: __( 'This event has started.', 'blockendar' );
$passed_label  = $attributes['passedLabel'] ?: __( 'This event has passed.', 'blockendar' );

$pinned_id = (int) ( $attributes['pinnedPostId'] ?? 0 );

$post_id = blockendar_block_event_id( $block, $pinned_id );

if ( ! $post_id ) {
	return;
}

if ( $pinned_id > 0 ) {
	// Pinned event — always use its next occurrence (not URL-based).
	$occurrence = \Blockendar\DB\EventIndex::next_occurrence( $post_id );
} else {
	// Context event — honour ?occurrence_date= if present.
	$occurrence = blockendar_resolve_occurrence( $post_id );
}

$start_date = $occurrence ? $occurrence->start_date : get_post_meta( $post_id, 'blockendar_start_date', true );

$start_time = get_post_meta( $post_id, 'blockendar_start_time', true );
$end_date   = get_post_meta( $post_id, 'blockendar_end_date', true );
$end_time   = get_post_meta( $post_id, 'blockendar_end_time', true );
$tz_str     = get_post_meta( $post_id, 'blockendar_timezone', true ) ?: wp_timezone_string();
$ongoing    = $occurrence ? ! empty( $occurrence->ongoing ) : (bool) get_post_meta( $post_id, 'blockendar_ongoing', true );

if ( ! $start_date ) {
	return;
}

try {
	$tz         = new DateTimeZone( $tz_str );
	$utc        = new DateTimeZone( 'UTC' );
	$dt         = new DateTimeImmutable( "$start_date " . ( $start_time ?: '00:00' ) . ':00', $tz );
	$target_utc = $dt->setTimezone( $utc )->format( 'c' );

	// An ongoing event is "in progress" indefinitely once it starts: give the
	// ticker no end target so it never reaches the "passed" state.
	$end_utc = '';
	if ( $end_date && ! $ongoing ) {
		$end_dt  = new DateTimeImmutable( "$end_date " . ( $end_time ?: '23:59' ) . ':00', $tz );
		$end_utc = $end_dt->setTimezone( $utc )->format( 'c' );
	}
} catch ( Exception ) {
	return;
}
?>
<?php
$blockendar_settings = (array) get_option( 'blockendar_settings', [] );
$countdown_date_fmt  = $blockendar_settings['date_format'] ?? get_option( 'date_format', 'F j, Y' );
$countdown_time_fmt  = $blockendar_settings['time_format'] ?? get_option( 'time_format', 'g:i a' );
$countdown_stamp     = strtotime( $target_utc );

$countdown_static = false !== $countdown_stamp
	? sprintf(
		/* translators: 1: event start date, 2: event start time. */
		__( 'Starts on %1$s at %2$s.', 'blockendar' ),
		date_i18n( $countdown_date_fmt, $countdown_stamp ),
		date_i18n( $countdown_time_fmt, $countdown_stamp )
	)
	: '';
?>
<div <?php echo get_block_wrapper_attributes( [ 'class' => 'blockendar-event-countdown' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	data-target="<?php echo esc_attr( $target_utc ); ?>"
	data-end-target="<?php echo esc_attr( $end_utc ); ?>"
	data-format="<?php echo esc_attr( $format ); ?>"
	data-expired-label="<?php echo esc_attr( $expired_label ); ?>"
	data-passed-label="<?php echo esc_attr( $passed_label ); ?>"
	<?php
	/*
	 * Unit labels come from the server so they are translatable. The ticker
	 * runs outside any build that could carry JS translations, and hardcoding
	 * them here meant the editor preview was translated while the front end
	 * stayed English.
	 */
	?>
	data-unit-days="<?php echo esc_attr__( 'days', 'blockendar' ); ?>"
	data-unit-hours="<?php echo esc_attr__( 'hours', 'blockendar' ); ?>"
	data-unit-minutes="<?php echo esc_attr__( 'minutes', 'blockendar' ); ?>"
	data-unit-seconds="<?php echo esc_attr__( 'seconds', 'blockendar' ); ?>"
>
	<?php
	/*
	 * The ticker rewrites itself every second, which is unusable to read with a
	 * virtual cursor — the value has already changed by the time it is spoken.
	 * It is hidden from assistive tech, and this static sentence carries the
	 * same information in a form that holds still. It is also the only content
	 * anyone gets with JavaScript off.
	 */
	?>
	<?php if ( '' !== $countdown_static ) : ?>
		<span class="blockendar-event-countdown__static">
			<?php echo esc_html( $countdown_static ); ?>
		</span>
	<?php endif; ?>
	<span class="blockendar-event-countdown__ticker" aria-hidden="true"></span>
</div>
