<?php
/**
 * blockendar/event-datetime render callback.
 *
 * @package Blockendar
 */
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$post_id         = $block->context['postId'] ?? get_the_ID();
$show_start_date = (bool) ( $attributes['showStartDate'] ?? true );
$show_start_time = (bool) ( $attributes['showStartTime'] ?? true );
$show_end_date   = (bool) ( $attributes['showEndDate'] ?? true );
$show_end_time   = (bool) ( $attributes['showEndTime'] ?? true );
$show_tz         = (bool) ( $attributes['showTimezone'] ?? false );

// For recurring events honour ?occurrence_date= (set by calendar links); fall back
// to next upcoming occurrence, or post meta when all occurrences are past.
$occurrence = blockendar_resolve_occurrence( $post_id );
$start_date = $occurrence ? $occurrence->start_date : get_post_meta( $post_id, 'blockendar_start_date', true );
$end_date   = $occurrence ? $occurrence->end_date : get_post_meta( $post_id, 'blockendar_end_date', true );
$all_day    = $occurrence ? (bool) $occurrence->all_day : (bool) get_post_meta( $post_id, 'blockendar_all_day', true );
// Time and timezone are the same across all occurrences — always read from meta.
$start_time = get_post_meta( $post_id, 'blockendar_start_time', true );
$end_time   = get_post_meta( $post_id, 'blockendar_end_time', true );
$tz_str     = get_post_meta( $post_id, 'blockendar_timezone', true ) ?: wp_timezone_string();

if ( ! $start_date ) {
	return;
}

$blockendar_settings = (array) get_option( 'blockendar_settings', [] );
$site_date_format    = $blockendar_settings['date_format'] ?? get_option( 'date_format', 'F j, Y' );
$site_time_format    = $blockendar_settings['time_format'] ?? get_option( 'time_format', 'g:i a' );
$date_format         = ( ! empty( $attributes['dateFormat'] ) ) ? $attributes['dateFormat'] : $site_date_format;
$time_format         = ( ! empty( $attributes['timeFormat'] ) ) ? $attributes['timeFormat'] : $site_time_format;
$time_sep            = isset( $attributes['timeSeparator'] ) ? $attributes['timeSeparator'] : '@';
$range_sep           = isset( $attributes['rangeSeparator'] ) ? $attributes['rangeSeparator'] : '–';

$fmt_date = fn( string $date ) => date_i18n( $date_format, strtotime( $date ) );
$fmt_time = fn( string $time, string $date ) => date_i18n( $time_format, strtotime( "$date $time" ) );

$same_day = $start_date === $end_date;
?>
<div <?php echo get_block_wrapper_attributes( [ 'class' => 'blockendar-event-datetime' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( $show_start_date || ( $show_start_time && ! $all_day ) ) : ?>
		<time class="blockendar-event-datetime__start" datetime="<?php echo esc_attr( $start_date . ( $start_time ? "T$start_time" : '' ) ); ?>">
			<?php
			if ( $show_start_date ) {
				echo esc_html( $fmt_date( $start_date ) );}
			?>
			<?php
			if ( $show_start_time && ! $all_day && $start_time ) {
				echo ( $show_start_date ? ' ' . esc_html( $time_sep ) . ' ' : '' ) . esc_html( $fmt_time( $start_time, $start_date ) );}
			?>
		</time>
	<?php endif; ?>

	<?php if ( $show_end_date && $end_date && ! $same_day ) : ?>
		<span class="blockendar-event-datetime__sep" aria-hidden="true"> <?php echo esc_html( $range_sep ); ?> </span>
		<time class="blockendar-event-datetime__end" datetime="<?php echo esc_attr( $end_date . ( $end_time ? "T$end_time" : '' ) ); ?>">
			<?php echo esc_html( $fmt_date( $end_date ) ); ?>
			<?php
			if ( $show_end_time && ! $all_day && $end_time ) {
				echo ( $show_end_date ? ' ' . esc_html( $time_sep ) . ' ' : '' ) . esc_html( $fmt_time( $end_time, $end_date ) );}
			?>
		</time>
	<?php elseif ( $show_end_time && ! $all_day && $same_day && $end_time && $end_time !== $start_time ) : ?>
		<span class="blockendar-event-datetime__sep" aria-hidden="true"> <?php echo esc_html( $range_sep ); ?> </span>
		<time class="blockendar-event-datetime__end" datetime="<?php echo esc_attr( "$end_date T$end_time" ); ?>">
			<?php echo esc_html( $fmt_time( $end_time, $end_date ) ); ?>
		</time>
	<?php endif; ?>

	<?php if ( $show_tz && ! $all_day ) : ?>
		<span class="blockendar-event-datetime__tz">(<?php echo esc_html( $tz_str ); ?>)</span>
	<?php endif; ?>

	<?php if ( $all_day && $show_start_time && $show_start_date ) : ?>
		<span class="blockendar-event-datetime__sep" aria-hidden="true"> <?php echo esc_html( $range_sep ); ?> </span>
	<?php endif; ?>

	<?php if ( $all_day && $show_start_time ) : ?>
		<span class="blockendar-event-datetime__allday"><?php esc_html_e( 'All day', 'blockendar' ); ?></span>
	<?php endif; ?>
</div>
