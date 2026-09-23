<?php
/**
 * blockendar/event-status render callback.
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

$status = get_post_meta( $post_id, 'blockendar_status', true ) ?: 'scheduled';

if ( 'scheduled' === $status ) {
	return;
}

$labels = [
	'cancelled' => __( 'Cancelled', 'blockendar' ),
	'postponed' => __( 'Postponed', 'blockendar' ),
	'sold_out'  => __( 'Sold Out', 'blockendar' ),
];
$label  = $labels[ $status ] ?? ucfirst( $status );
?>
<?php
/*
 * No role="status" and no aria-label. role="status" is an implicit
 * aria-live="polite" region, but this content is server-rendered and never
 * changes, so nothing is ever announced and the page is left carrying a live
 * region for no reason. The aria-label duplicated the element's own text,
 * which overrides the content and would silently diverge if either changed —
 * the visible word is already the accessible name.
 */
?>
<div <?php echo get_block_wrapper_attributes( [ 'class' => "blockendar-event-status blockendar-status blockendar-status--$status" ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php echo esc_html( $label ); ?>
</div>
