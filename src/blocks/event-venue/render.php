<?php
/**
 * blockendar/event-venue render callback.
 *
 * @package Blockendar
 */
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$post_id   = $block->context['postId'] ?? get_the_ID();
$show_addr = (bool) ( $attributes['showAddress'] ?? true );

$terms = get_the_terms( $post_id, 'event_venue' );
if ( is_wp_error( $terms ) || empty( $terms ) ) {
	return;
}
?>
<div <?php echo get_block_wrapper_attributes( [ 'class' => 'blockendar-event-venue' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php foreach ( $terms as $term ) : ?>
		<?php
		$term_id = $term->term_id;
		$virtual = (bool) get_term_meta( $term_id, 'blockendar_venue_virtual', true );
		$address = get_term_meta( $term_id, 'blockendar_venue_address', true );
		$city    = get_term_meta( $term_id, 'blockendar_venue_city', true );
		$state   = get_term_meta( $term_id, 'blockendar_venue_state', true );
		$country = get_term_meta( $term_id, 'blockendar_venue_country', true );
		$stream  = get_term_meta( $term_id, 'blockendar_venue_stream_url', true );

		$address_parts = array_filter( [ $address, $city, $state, $country ] );
		$address_str   = implode( ', ', $address_parts );
		?>
		<?php if ( $term !== reset( $terms ) ) : ?>
			<hr class="blockendar-event-venue__divider" />
		<?php endif; ?>
		<div class="blockendar-event-venue__body">
			<span class="blockendar-event-venue__name"><?php echo esc_html( $term->name ); ?></span>

			<?php if ( $virtual ) : ?>
				<span class="blockendar-event-venue__virtual-badge"><?php esc_html_e( 'Online', 'blockendar' ); ?></span>
				<?php if ( $stream ) : ?>
					<a class="blockendar-event-venue__stream" href="<?php echo esc_url( $stream ); ?>">
						<?php esc_html_e( 'Join stream', 'blockendar' ); ?>
					</a>
				<?php endif; ?>
			<?php elseif ( $show_addr && $address_str ) : ?>
				<address class="blockendar-event-venue__address"><?php echo esc_html( $address_str ); ?></address>
			<?php endif; ?>

		</div>
	<?php endforeach; ?>
</div>
