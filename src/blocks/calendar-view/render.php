<?php
/**
 * blockendar/calendar-view — server-side render callback.
 *
 * Outputs the block wrapper div with data attributes.
 * view.jsx reads these to configure and mount FullCalendar.
 *
 * @package Blockendar
 */
declare( strict_types=1 );

$enabled_views = $attributes['enabledViews'] ?? [ 'dayGridMonth', 'timeGridWeek', 'timeGridDay', 'listNextMonth' ];
$default_view  = $attributes['defaultView'] ?? 'dayGridMonth';
$first_day     = (int) ( $attributes['firstDay'] ?? 0 );
$venue_ids     = array_map( 'intval', (array) ( $attributes['venueIds'] ?? [] ) );
$type_ids      = array_map( 'intval', (array) ( $attributes['typeIds'] ?? [] ) );
$featured_only = ! empty( $attributes['featuredOnly'] ) ? 'true' : 'false';

// On an event_type taxonomy archive, auto-filter to the queried term.
if ( is_tax( 'event_type' ) ) {
	$queried = get_queried_object();
	if ( $queried instanceof \WP_Term ) {
		$type_ids = array_values( array_unique( array_merge( $type_ids, [ $queried->term_id ] ) ) );
	}
}

// On an event_venue taxonomy archive, auto-filter to the queried venue.
if ( is_tax( 'event_venue' ) ) {
	$queried = get_queried_object();
	if ( $queried instanceof \WP_Term ) {
		$venue_ids = array_values( array_unique( array_merge( $venue_ids, [ $queried->term_id ] ) ) );
	}
}

$rest_url = esc_url_raw( rest_url( 'blockendar/v1' ) );

$data_attrs = [
	'data-rest-url'      => $rest_url,
	'data-default-view'  => $default_view,
	'data-first-day'     => (string) $first_day,
	'data-enabled-views' => wp_json_encode( $enabled_views ),
	'data-featured-only' => $featured_only,
	'data-venue-ids'     => wp_json_encode( array_values( $venue_ids ) ),
	'data-type-ids'      => wp_json_encode( array_values( $type_ids ) ),
];

$data_attr_str = '';
foreach ( $data_attrs as $key => $value ) {
	$data_attr_str .= ' ' . $key . '="' . esc_attr( $value ) . '"';
}
?>
<div <?php echo get_block_wrapper_attributes(); ?><?php echo $data_attr_str; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above ?>>
</div>
