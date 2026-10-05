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

$event_status = get_post_meta( $post_id, 'blockendar_status', true ) ?: 'scheduled';
$status       = $event_status;

/*
 * One occurrence of a series can be cancelled while the event itself is not.
 * The occurrence in question is the one the rest of the page is about: the
 * list item being rendered, the date in the address, or the next one coming.
 * It is read only for a cancellation. Everything else is the event's own
 * status, and an event with no rows in the index (a draft, a preview) has
 * nothing else to go by.
 */
$occurrence = blockendar_resolve_occurrence( $post_id );

if ( null !== $occurrence && 'cancelled' === ( $occurrence->status ?? '' ) ) {
	$status = 'cancelled';
}

if ( 'scheduled' === $status ) {
	return;
}

$labels = [
	'cancelled' => __( 'Cancelled', 'blockendar' ),
	'postponed' => __( 'Postponed', 'blockendar' ),
	'sold_out'  => __( 'Sold Out', 'blockendar' ),
];
$label  = $labels[ $status ] ?? ucfirst( $status );

/*
 * The reason explains the event's own status. It is not shown when what is
 * on show is one date cancelled by itself: that cancellation has no reason of
 * its own, and the event's would be the wrong one.
 */
$show_reason = (bool) ( $attributes['showReason'] ?? true );
$reason      = $show_reason && $status === $event_status
	? trim( (string) get_post_meta( $post_id, 'blockendar_status_reason', true ) )
	: '';
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
	<?php if ( '' !== $reason ) : ?>
		<span class="blockendar-status__reason"><?php echo esc_html( $reason ); ?></span>
	<?php endif; ?>
</div>
