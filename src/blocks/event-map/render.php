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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$post_id = blockendar_block_event_id( $block );

if ( ! $post_id ) {
	return;
}

$terms = get_the_terms( $post_id, 'event_venue' );

if ( is_wp_error( $terms ) || empty( $terms ) ) {
	return;
}

// Build a list of mappable venues (non-virtual, with valid coords).
$pins = [];
foreach ( $terms as $term ) {
	$virtual = (bool) get_term_meta( $term->term_id, 'blockendar_venue_virtual', true );
	if ( $virtual ) {
		continue;
	}
	$lat = (float) get_term_meta( $term->term_id, 'blockendar_venue_lat', true );
	$lng = (float) get_term_meta( $term->term_id, 'blockendar_venue_lng', true );
	if ( ! $lat || ! $lng ) {
		continue;
	}
	$pins[] = [
		'lat'     => $lat,
		'lng'     => $lng,
		'name'    => $term->name,
		'address' => trim(
			implode(
				', ',
				array_filter(
					[
						(string) get_term_meta( $term->term_id, 'blockendar_venue_address', true ),
						(string) get_term_meta( $term->term_id, 'blockendar_venue_city', true ),
						(string) get_term_meta( $term->term_id, 'blockendar_venue_state', true ),
					]
				)
			)
		),
	];
}

if ( empty( $pins ) ) {
	return;
}

$height     = max( 100, min( 1200, (int) ( $attributes['height'] ?? 400 ) ) );
$zoom       = max( 1, min( 20, (int) ( $attributes['zoom'] ?? 14 ) ) );
$aria_label = 1 === count( $pins )
	/* translators: %s: venue name */
	? sprintf( __( 'Map of %s', 'blockendar' ), $pins[0]['name'] )
	: __( 'Event venue map', 'blockendar' );
?>
<div <?php echo get_block_wrapper_attributes( [ 'class' => 'blockendar-event-map' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	data-pins="<?php echo esc_attr( wp_json_encode( $pins ) ); ?>"
	data-zoom="<?php echo esc_attr( (string) $zoom ); ?>"
	style="height:<?php echo esc_attr( $height . 'px' ); ?>;"
	<?php
	/*
	 * role="img" is what makes the aria-label count: on a bare <div> with no
	 * role the label is ignored, so the carefully built "Map of {venue}" never
	 * reached a screen reader at all.
	 */
	?>
	role="img"
	aria-label="<?php echo esc_attr( $aria_label ); ?>"
>
	<?php
	/*
	 * Fallback content, replaced by Leaflet once it mounts. Without it this
	 * block renders an empty div: with JavaScript off, with the tile host
	 * blocked, or to anyone who cannot use a map, the venue address was simply
	 * absent from the page.
	 */
	?>
	<ul class="blockendar-event-map__fallback">
		<?php foreach ( $pins as $pin ) : ?>
			<li>
				<strong><?php echo esc_html( $pin['name'] ); ?></strong>
				<?php if ( '' !== $pin['address'] ) : ?>
					<span><?php echo esc_html( $pin['address'] ); ?></span>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
</div>
