<?php
/**
 * blockendar/event-datetime render callback.
 *
 * @package Blockendar
 */
declare( strict_types=1 );

$post_id      = $block->context['postId'] ?? get_the_ID();
$show_tz      = (bool) ( $attributes['showTimezone'] ?? true );
$show_end     = (bool) ( $attributes['showEndDate'] ?? true );
$start_date   = get_post_meta( $post_id, 'blockendar_start_date', true );
$end_date     = get_post_meta( $post_id, 'blockendar_end_date',   true );
$start_time   = get_post_meta( $post_id, 'blockendar_start_time', true );
$end_time     = get_post_meta( $post_id, 'blockendar_end_time',   true );
$all_day      = (bool) get_post_meta( $post_id, 'blockendar_all_day',  true );
$tz_str       = get_post_meta( $post_id, 'blockendar_timezone', true ) ?: wp_timezone_string();

if ( ! $start_date ) return;

$date_format = get_option( 'date_format' );
$time_format = get_option( 'time_format' );

$format_dt = function( string $date, string $time ) use ( $all_day, $date_format, $time_format ): string {
	if ( $all_day ) {
		return date_i18n( $date_format, strtotime( $date ) );
	}
	$ts = $time ? strtotime( "$date $time" ) : strtotime( $date );
	return date_i18n( "$date_format $time_format", $ts );
};

$start_label = $format_dt( $start_date, $start_time );
$end_label   = $end_date && $show_end ? $format_dt( $end_date, $end_time ) : '';
$same_day    = $start_date === $end_date;
?>
<div <?php echo get_block_wrapper_attributes( [ 'class' => 'blockendar-event-datetime' ] ); ?>>
	<span class="dashicons dashicons-clock" aria-hidden="true"></span>

	<time class="blockendar-event-datetime__start" datetime="<?php echo esc_attr( $start_date . ( $start_time ? "T$start_time" : '' ) ); ?>">
		<?php echo esc_html( $start_label ); ?>
	</time>

	<?php if ( $end_label && ! $same_day ) : ?>
		<span class="blockendar-event-datetime__sep" aria-hidden="true">–</span>
		<time class="blockendar-event-datetime__end" datetime="<?php echo esc_attr( $end_date . ( $end_time ? "T$end_time" : '' ) ); ?>">
			<?php echo esc_html( $end_label ); ?>
		</time>
	<?php elseif ( $end_label && ! $all_day && $end_time && $end_time !== $start_time ) : ?>
		<span class="blockendar-event-datetime__sep" aria-hidden="true">–</span>
		<time class="blockendar-event-datetime__end" datetime="<?php echo esc_attr( "$end_date T$end_time" ); ?>">
			<?php echo esc_html( date_i18n( $time_format, strtotime( "$end_date $end_time" ) ) ); ?>
		</time>
	<?php endif; ?>

	<?php if ( $show_tz && ! $all_day ) : ?>
		<span class="blockendar-event-datetime__tz">(<?php echo esc_html( $tz_str ); ?>)</span>
	<?php endif; ?>

	<?php if ( $all_day ) : ?>
		<span class="blockendar-event-datetime__allday"><?php esc_html_e( 'All day', 'blockendar' ); ?></span>
	<?php endif; ?>
</div>
