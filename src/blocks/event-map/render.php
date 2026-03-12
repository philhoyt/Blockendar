<?php
/**
 * blockendar/event-map render callback.
 *
 * Renders a data-attribute anchor. The view.js initialises the map
 * using the provider configured in plugin settings (OpenStreetMap/Leaflet by default).
 *
 * @package Blockendar
 */
declare( strict_types=1 );

$post_id = $block->context['postId'] ?? get_the_ID();
$terms   = get_the_terms( $post_id, 'event_venue' );

if ( is_wp_error( $terms ) || empty( $terms ) ) {
	return;
}

$term_id = $terms[0]->term_id;
$lat     = (float) get_term_meta( $term_id, 'blockendar_venue_lat', true );
$lng     = (float) get_term_meta( $term_id, 'blockendar_venue_lng', true );
$virtual = (bool) get_term_meta( $term_id, 'blockendar_venue_virtual', true );

if ( $virtual || ! $lat || ! $lng ) {
	return;
}

$height = max( 100, min( 1200, (int) ( $attributes['height'] ?? 400 ) ) );
$zoom   = max( 1, min( 20, (int) ( $attributes['zoom'] ?? 14 ) ) );
$name   = $terms[0]->name;
?>
<div <?php echo get_block_wrapper_attributes( [ 'class' => 'blockendar-event-map' ] ); ?>
	data-lat="<?php echo esc_attr( (string) $lat ); ?>"
	data-lng="<?php echo esc_attr( (string) $lng ); ?>"
	data-zoom="<?php echo esc_attr( (string) $zoom ); ?>"
	data-name="<?php echo esc_attr( $name ); ?>"
	style="height:<?php echo esc_attr( $height . 'px' ); ?>;"
	aria-label="<?php /* translators: %s: venue name */ echo esc_attr( sprintf( __( 'Map of %s', 'blockendar' ), $name ) ); ?>"
></div>
