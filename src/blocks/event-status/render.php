<?php
/**
 * blockendar/event-status render callback.
 *
 * @package Blockendar
 */
declare( strict_types=1 );

$post_id = $block->context['postId'] ?? get_the_ID();
$status  = get_post_meta( $post_id, 'blockendar_status', true ) ?: 'scheduled';

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
<div <?php echo get_block_wrapper_attributes( [ 'class' => "blockendar-event-status blockendar-status blockendar-status--$status" ] ); ?>
	role="status" aria-label="<?php echo esc_attr( $label ); ?>">
	<?php echo esc_html( $label ); ?>
</div>
