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

if ( $pinned_id > 0 ) {
	// Pinned event — always use its next occurrence (not URL-based).
	$post_id    = $pinned_id;
	$occurrence = \Blockendar\DB\EventIndex::next_occurrence( $post_id );
	$start_date = $occurrence ? $occurrence->start_date : get_post_meta( $post_id, 'blockendar_start_date', true );
} else {
	// Context event — honour ?occurrence_date= if present.
	$post_id    = $block->context['postId'] ?? get_the_ID();
	$occurrence = blockendar_resolve_occurrence( (int) $post_id );
	$start_date = $occurrence ? $occurrence->start_date : get_post_meta( $post_id, 'blockendar_start_date', true );
}

$start_time = get_post_meta( $post_id, 'blockendar_start_time', true );
$end_date   = get_post_meta( $post_id, 'blockendar_end_date', true );
$end_time   = get_post_meta( $post_id, 'blockendar_end_time', true );
$tz_str     = get_post_meta( $post_id, 'blockendar_timezone', true ) ?: wp_timezone_string();

if ( ! $start_date ) {
	return;
}

try {
	$tz         = new DateTimeZone( $tz_str );
	$utc        = new DateTimeZone( 'UTC' );
	$dt         = new DateTimeImmutable( "$start_date " . ( $start_time ?: '00:00' ) . ':00', $tz );
	$target_utc = $dt->setTimezone( $utc )->format( 'c' );

	$end_utc = '';
	if ( $end_date ) {
		$end_dt  = new DateTimeImmutable( "$end_date " . ( $end_time ?: '23:59' ) . ':00', $tz );
		$end_utc = $end_dt->setTimezone( $utc )->format( 'c' );
	}
} catch ( Exception ) {
	return;
}
?>
<div <?php echo get_block_wrapper_attributes( [ 'class' => 'blockendar-event-countdown' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	data-target="<?php echo esc_attr( $target_utc ); ?>"
	data-end-target="<?php echo esc_attr( $end_utc ); ?>"
	data-format="<?php echo esc_attr( $format ); ?>"
	data-expired-label="<?php echo esc_attr( $expired_label ); ?>"
	data-passed-label="<?php echo esc_attr( $passed_label ); ?>"
>
	<noscript><?php esc_html_e( 'Enable JavaScript to see the countdown.', 'blockendar' ); ?></noscript>
</div>
