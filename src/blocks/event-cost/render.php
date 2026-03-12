<?php
/**
 * blockendar/event-cost render callback.
 *
 * @package Blockendar
 */
declare( strict_types=1 );

$post_id      = $block->context['postId'] ?? get_the_ID();
$cost         = get_post_meta( $post_id, 'blockendar_cost', true );
$reg_url      = get_post_meta( $post_id, 'blockendar_registration_url', true );
$button_label = ! empty( $attributes['buttonLabel'] )
	? $attributes['buttonLabel']
	: __( 'Register / Get Tickets', 'blockendar' );

if ( ! $cost && ! $reg_url ) {
	return;
}
?>
<div <?php echo get_block_wrapper_attributes( [ 'class' => 'blockendar-event-cost' ] ); ?>>
	<?php if ( $cost ) : ?>
		<span class="blockendar-event-cost__amount">
			<?php echo esc_html( $cost ); ?>
		</span>
	<?php endif; ?>

	<?php if ( $reg_url ) : ?>
		<a class="blockendar-event-cost__cta wp-element-button" href="<?php echo esc_url( $reg_url ); ?>" target="_blank" rel="noopener noreferrer">
			<?php echo esc_html( $button_label ); ?>
		</a>
	<?php endif; ?>
</div>
