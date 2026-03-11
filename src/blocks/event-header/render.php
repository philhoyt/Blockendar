<?php
/**
 * blockendar/event-header render callback.
 *
 * @package Blockendar
 */
declare( strict_types=1 );

$post_id     = $block->context['postId'] ?? get_the_ID();
$show_status = (bool) ( $attributes['showStatus'] ?? true );
$start_date  = get_post_meta( $post_id, 'blockendar_start_date', true );
$all_day     = (bool) get_post_meta( $post_id, 'blockendar_all_day', true );
$start_time  = get_post_meta( $post_id, 'blockendar_start_time', true );
$status      = get_post_meta( $post_id, 'blockendar_status', true ) ?: 'scheduled';

$date_formatted = $start_date
	? date_i18n( get_option( 'date_format' ), strtotime( $start_date ) )
	: '';

$time_formatted = ( ! $all_day && $start_time )
	? date_i18n( get_option( 'time_format' ), strtotime( "2000-01-01 $start_time" ) )
	: '';
?>
<header <?php echo get_block_wrapper_attributes( [ 'class' => 'blockendar-event-header' ] ); ?>>
	<h1 class="blockendar-event-header__title"><?php echo esc_html( get_the_title( $post_id ) ); ?></h1>

	<?php if ( $date_formatted ) : ?>
		<div class="blockendar-event-header__meta">
			<time datetime="<?php echo esc_attr( $start_date ); ?>" class="blockendar-event-header__date">
				<?php echo esc_html( $date_formatted ); ?>
				<?php if ( $time_formatted ) : ?>
					<span class="blockendar-event-header__time"><?php echo esc_html( $time_formatted ); ?></span>
				<?php endif; ?>
			</time>
		</div>
	<?php endif; ?>

	<?php if ( $show_status && 'scheduled' !== $status ) : ?>
		<span class="blockendar-event-header__status blockendar-status blockendar-status--<?php echo esc_attr( $status ); ?>">
			<?php echo esc_html( ucfirst( str_replace( '_', ' ', $status ) ) ); ?>
		</span>
	<?php endif; ?>
</header>
