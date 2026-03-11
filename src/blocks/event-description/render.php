<?php
/**
 * blockendar/event-description render callback.
 *
 * @package Blockendar
 */
declare( strict_types=1 );

$post_id = $block->context['postId'] ?? get_the_ID();
$post    = get_post( $post_id );

if ( ! $post ) return;

$content = apply_filters( 'the_content', $post->post_content );
if ( ! $content ) return;
?>
<div <?php echo get_block_wrapper_attributes( [ 'class' => 'blockendar-event-description' ] ); ?>>
	<?php echo wp_kses_post( $content ); ?>
</div>
