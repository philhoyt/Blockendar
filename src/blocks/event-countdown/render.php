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

$post_id       = $block->context['postId'] ?? get_the_ID();
$occurrence    = blockendar_resolve_occurrence( $post_id );
$start_date    = $occurrence ? $occurrence->start_date : get_post_meta( $post_id, 'blockendar_start_date', true );
$start_time    = get_post_meta( $post_id, 'blockendar_start_time', true );
$tz_str        = get_post_meta( $post_id, 'blockendar_timezone', true ) ?: wp_timezone_string();
$expired_label = $attributes['expiredLabel'] ?: __( 'This event has started.', 'blockendar' );

if ( ! $start_date ) {
	return;
}

try {
	$tz         = new DateTimeZone( $tz_str );
	$dt         = new DateTimeImmutable( "$start_date " . ( $start_time ?: '00:00' ) . ':00', $tz );
	$target_utc = $dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'c' );
} catch ( Exception ) {
	return;
}
?>
<div <?php echo get_block_wrapper_attributes( [ 'class' => 'blockendar-event-countdown' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	data-target="<?php echo esc_attr( $target_utc ); ?>"
	data-expired-label="<?php echo esc_attr( $expired_label ); ?>"
>
	<noscript><?php esc_html_e( 'Enable JavaScript to see the countdown.', 'blockendar' ); ?></noscript>
</div>
