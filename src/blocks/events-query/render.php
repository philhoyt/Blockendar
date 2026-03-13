<?php
/**
 * blockendar/events-query — server-side render callback.
 *
 * Queries events from the Blockendar index and renders the inner block template
 * once per occurrence. For recurring events each occurrence in the queried range
 * is a separate list item.
 *
 * While inner blocks render for each row, $GLOBALS['blockendar_current_occurrence']
 * is set to the current index row so that blockendar_resolve_occurrence() returns
 * the correct occurrence without needing a URL query param. A post_type_link filter
 * stamps ?occurrence_date= on the permalink so core/post-title links are correct too.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

use Blockendar\DB\EventIndex;

$type_ids        = array_filter( array_map( 'intval', (array) ( $attributes['typeIds'] ?? [] ) ) );
$per_page        = max( 1, min( 50, (int) ( $attributes['perPage'] ?? 10 ) ) );
$show_past       = ! empty( $attributes['showPast'] );
$order           = 'DESC' === ( $attributes['order'] ?? 'ASC' ) ? 'DESC' : 'ASC';
$related_to      = in_array( $attributes['relatedTo'] ?? 'none', [ 'none', 'type', 'venue', 'both' ], true )
	? ( $attributes['relatedTo'] ?? 'none' )
	: 'none';
$layout          = $attributes['displayLayout'] ?? [ 'type' => 'list' ];
$is_grid         = 'grid' === ( $layout['type'] ?? 'list' );
$column_count    = max( 2, min( 6, (int) ( $layout['columnCount'] ?? 3 ) ) );
$now             = gmdate( 'Y-m-d H:i:s' );
$current_post_id = 'none' !== $related_to ? (int) ( $block->context['postId'] ?? 0 ) : 0;

if ( $show_past ) {
	$start = '2000-01-01 00:00:00';
	$end   = $now;
} else {
	$start = $now;
	$end   = gmdate( 'Y-m-d H:i:s', strtotime( '+3 years' ) );
}

$index     = new EventIndex();
$base_args = [
	'per_page' => $per_page + 1,
	'page'     => 1,
	'orderby'  => 'start_datetime',
	'order'    => $order,
];

if ( 'none' !== $related_to && $current_post_id ) {
	// Resolve the current event's taxonomy terms for related-events mode.
	$type_terms  = get_the_terms( $current_post_id, 'event_type' );
	$venue_terms = get_the_terms( $current_post_id, 'event_venue' );

	$rel_type_ids  = ( ! is_wp_error( $type_terms ) && ! empty( $type_terms ) )
		? array_column( (array) $type_terms, 'term_id' )
		: [];
	$rel_venue_ids = ( ! is_wp_error( $venue_terms ) && ! empty( $venue_terms ) )
		? array_column( (array) $venue_terms, 'term_id' )
		: [];

	$raw_events = [];

	if ( in_array( $related_to, [ 'type', 'both' ], true ) && ! empty( $rel_type_ids ) ) {
		$raw_events = array_merge(
			$raw_events,
			$index->get_events_in_range( $start, $end, array_merge( $base_args, [ 'type_term_id' => $rel_type_ids ] ) )
		);
	}

	if ( in_array( $related_to, [ 'venue', 'both' ], true ) && ! empty( $rel_venue_ids ) ) {
		$raw_events = array_merge(
			$raw_events,
			$index->get_events_in_range( $start, $end, array_merge( $base_args, [ 'venue_term_id' => $rel_venue_ids ] ) )
		);
	}

	// Sort merged results, deduplicate by index row ID, and exclude the current post.
	usort( $raw_events, static fn( $a, $b ) => strcmp( $a->start_datetime, $b->start_datetime ) * ( 'DESC' === $order ? -1 : 1 ) );

	$seen   = [];
	$events = [];

	foreach ( $raw_events as $event ) {
		if ( (int) $event->post_id === $current_post_id || isset( $seen[ $event->id ] ) ) {
			continue;
		}

		$seen[ $event->id ] = true;
		$events[]           = $event;

		if ( count( $events ) >= $per_page ) {
			break;
		}
	}
} else {
	$events = $index->get_events_in_range(
		$start,
		$end,
		array_merge(
			$base_args,
			[
				'type_term_id' => ! empty( $type_ids ) ? $type_ids : null,
				'per_page'     => $per_page,
			]
		)
	);
}

if ( empty( $events ) ) {
	echo '<p class="blockendar-events-query__empty">' . esc_html__( 'No events found.', 'blockendar' ) . '</p>';
	return;
}

$inner_blocks = $block->parsed_block['innerBlocks'];

$wrapper_attrs = [
	'class' => 'blockendar-events-query is-' . ( $is_grid ? 'grid' : 'list' ) . '-view',
];
if ( $is_grid ) {
	$wrapper_attrs['style'] = '--blockendar-columns:' . $column_count . ';';
}

// Stamp ?occurrence_date= onto CPT permalinks while inner blocks render so that
// core/post-title (and any other link) navigates to the correct occurrence.
add_filter( 'post_type_link', 'blockendar_occurrence_permalink_filter', 10, 2 );

?><ul <?php echo get_block_wrapper_attributes( $wrapper_attrs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
<?php foreach ( $events as $row ) : ?>
	<li class="blockendar-events-query__item">
		<?php
		// Expose the current occurrence row so blockendar_resolve_occurrence() can
		// return it from inner block render callbacks without a URL query param.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['blockendar_current_occurrence'] = $row;

		// Set the global $post to the event post before rendering inner blocks.
		// render_block() seeds context from $GLOBALS['post'], so this ensures
		// core/post-title and other context-aware blocks resolve correctly.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['post'] = get_post( (int) $row->post_id );
		setup_postdata( $GLOBALS['post'] );

		foreach ( $inner_blocks as $inner_block ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- block render output.
			echo render_block( $inner_block );
		}
		?>
	</li>
<?php endforeach; ?>
</ul>
<?php
remove_filter( 'post_type_link', 'blockendar_occurrence_permalink_filter', 10 );
unset( $GLOBALS['blockendar_current_occurrence'] );
wp_reset_postdata();
?>
