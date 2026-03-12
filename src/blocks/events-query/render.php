<?php
/**
 * blockendar/events-query — server-side render callback.
 *
 * Queries upcoming events from the Blockendar index, deduplicates by post_id,
 * then renders the inner block template once per event — injecting postId and
 * postType context so core/post-title and Blockendar blocks resolve correctly.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

use Blockendar\DB\EventIndex;

$type_ids      = array_filter( array_map( 'intval', (array) ( $attributes['typeIds'] ?? [] ) ) );
$per_page      = max( 1, min( 50, (int) ( $attributes['perPage'] ?? 10 ) ) );
$show_past     = ! empty( $attributes['showPast'] );
$order         = 'DESC' === ( $attributes['order'] ?? 'ASC' ) ? 'DESC' : 'ASC';
$layout        = $attributes['displayLayout'] ?? [ 'type' => 'list' ];
$is_grid       = 'grid' === ( $layout['type'] ?? 'list' );
$column_count  = max( 2, min( 6, (int) ( $layout['columnCount'] ?? 3 ) ) );
$now           = gmdate( 'Y-m-d H:i:s' );

if ( $show_past ) {
	$start = '2000-01-01 00:00:00';
	$end   = $now;
} else {
	$start = $now;
	$end   = gmdate( 'Y-m-d H:i:s', strtotime( '+3 years' ) );
}

$index  = new EventIndex();
$events = $index->get_events_in_range(
	$start,
	$end,
	[
		'type_term_id' => ! empty( $type_ids ) ? $type_ids : null,
		'per_page'     => $per_page * 5, // Fetch extra; dedup reduces the set.
		'page'         => 1,
		'orderby'      => 'start_datetime',
		'order'        => $order,
	]
);

// Deduplicate by post_id — recurring events produce multiple index rows.
$seen     = [];
$post_ids = [];
foreach ( $events as $event ) {
	$pid = (int) $event->post_id;
	if ( ! isset( $seen[ $pid ] ) ) {
		$seen[ $pid ] = true;
		$post_ids[]   = $pid;
		if ( count( $post_ids ) >= $per_page ) {
			break;
		}
	}
}

if ( empty( $post_ids ) ) {
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

?><ul <?php echo get_block_wrapper_attributes( $wrapper_attrs ); ?>>
<?php foreach ( $post_ids as $post_id ) : ?>
	<li class="blockendar-events-query__item">
		<?php
		// Set the global $post to the event post before rendering inner blocks.
		// render_block() seeds context from $GLOBALS['post'], so this ensures
		// core/post-title and other context-aware blocks resolve correctly.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['post'] = get_post( $post_id );
		setup_postdata( $GLOBALS['post'] );

		foreach ( $inner_blocks as $inner_block ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- block render output.
			echo render_block( $inner_block );
		}
		?>
	</li>
<?php endforeach; ?>
</ul>
<?php wp_reset_postdata(); ?>
