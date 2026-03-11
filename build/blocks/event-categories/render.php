<?php
/**
 * blockendar/event-categories render callback.
 *
 * @package Blockendar
 */
declare( strict_types=1 );

$post_id = $block->context['postId'] ?? get_the_ID();
$terms   = get_the_terms( $post_id, 'event_type' );

if ( is_wp_error( $terms ) || empty( $terms ) ) return;
?>
<div <?php echo get_block_wrapper_attributes( [ 'class' => 'blockendar-event-categories' ] ); ?>>
	<span class="blockendar-event-categories__label"><?php esc_html_e( 'Type:', 'blockendar' ); ?></span>
	<ul class="blockendar-event-categories__list">
		<?php foreach ( $terms as $term ) : ?>
			<li class="blockendar-event-categories__item">
				<a href="<?php echo esc_url( get_term_link( $term ) ); ?>" class="blockendar-event-categories__link">
					<?php echo esc_html( $term->name ); ?>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
</div>
