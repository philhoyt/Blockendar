<?php
/**
 * blockendar/event-datetime render callback.
 *
 * @package Blockendar
 */
declare( strict_types=1 );

$post_id         = $block->context['postId'] ?? get_the_ID();
$show_start_date = (bool) ( $attributes['showStartDate'] ?? true );
$show_start_time = (bool) ( $attributes['showStartTime'] ?? true );
$show_end_date   = (bool) ( $attributes['showEndDate']   ?? true );
$show_end_time   = (bool) ( $attributes['showEndTime']   ?? true );
$show_tz         = (bool) ( $attributes['showTimezone']  ?? false );

$start_date = get_post_meta( $post_id, 'blockendar_start_date', true );
$end_date   = get_post_meta( $post_id, 'blockendar_end_date',   true );
$start_time = get_post_meta( $post_id, 'blockendar_start_time', true );
$end_time   = get_post_meta( $post_id, 'blockendar_end_time',   true );
$all_day    = (bool) get_post_meta( $post_id, 'blockendar_all_day', true );
$tz_str     = get_post_meta( $post_id, 'blockendar_timezone', true ) ?: wp_timezone_string();

if ( ! $start_date ) return;

$date_format = get_option( 'date_format' );
$time_format = get_option( 'time_format' );

$fmt_date = fn( string $date ) => date_i18n( $date_format, strtotime( $date ) );
$fmt_time = fn( string $time, string $date ) => date_i18n( $time_format, strtotime( "$date $time" ) );

$same_day = $start_date === $end_date;
?>
<div <?php echo get_block_wrapper_attributes( [ 'class' => 'blockendar-event-datetime' ] ); ?>>
	<?php if ( $show_start_date || ( $show_start_time && ! $all_day ) ) : ?>
		<time class="blockendar-event-datetime__start" datetime="<?php echo esc_attr( $start_date . ( $start_time ? "T$start_time" : '' ) ); ?>">
			<?php if ( $show_start_date ) echo esc_html( $fmt_date( $start_date ) ); ?>
			<?php if ( $show_start_time && ! $all_day && $start_time ) echo ' @ ' . esc_html( $fmt_time( $start_time, $start_date ) ); ?>
		</time>
	<?php endif; ?>

	<?php if ( $show_end_date && $end_date && ! $same_day ) : ?>
		<span class="blockendar-event-datetime__sep" aria-hidden="true"> – </span>
		<time class="blockendar-event-datetime__end" datetime="<?php echo esc_attr( $end_date . ( $end_time ? "T$end_time" : '' ) ); ?>">
			<?php echo esc_html( $fmt_date( $end_date ) ); ?>
			<?php if ( $show_end_time && ! $all_day && $end_time ) echo ' @ ' . esc_html( $fmt_time( $end_time, $end_date ) ); ?>
		</time>
	<?php elseif ( $show_end_time && ! $all_day && $same_day && $end_time && $end_time !== $start_time ) : ?>
		<span class="blockendar-event-datetime__sep" aria-hidden="true"> – </span>
		<time class="blockendar-event-datetime__end" datetime="<?php echo esc_attr( "$end_date T$end_time" ); ?>">
			<?php echo esc_html( $fmt_time( $end_time, $end_date ) ); ?>
		</time>
	<?php endif; ?>

	<?php if ( $show_tz && ! $all_day ) : ?>
		<span class="blockendar-event-datetime__tz">(<?php echo esc_html( $tz_str ); ?>)</span>
	<?php endif; ?>

	<?php if ( $all_day ) : ?>
		<span class="blockendar-event-datetime__allday"><?php esc_html_e( 'All day', 'blockendar' ); ?></span>
	<?php endif; ?>
</div>
