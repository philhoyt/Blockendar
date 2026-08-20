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

use Blockendar\Blocks\FilterContext;
use Blockendar\DB\EventIndex;

$type_ids        = array_filter( array_map( 'intval', (array) ( $attributes['typeIds'] ?? [] ) ) );
$per_page        = max( 1, min( 50, (int) ( $attributes['perPage'] ?? 10 ) ) );
$show_past       = ! empty( $attributes['showPast'] );
$order           = 'DESC' === ( $attributes['order'] ?? 'ASC' ) ? 'DESC' : 'ASC';
$inherit         = ! empty( $attributes['inherit'] );
$show_pagination = ! empty( $attributes['showPagination'] );
$related_to      = in_array( $attributes['relatedTo'] ?? 'none', [ 'none', 'type', 'venue', 'both' ], true )
	? ( $attributes['relatedTo'] ?? 'none' )
	: 'none';
$query_id        = (string) ( $block->context['blockendar/queryId'] ?? '' );
$page_param      = FilterContext::param_name( 'page', $query_id );
// phpcs:disable WordPress.Security.NonceVerification.Recommended
$current_page = max( 1, absint( wp_unslash( $_GET[ $page_param ] ?? 1 ) ) );
// phpcs:enable
$total_pages = 1;
$layout      = $attributes['displayLayout'] ?? [ 'type' => 'list' ];
$layout_type = (string) ( $layout['type'] ?? 'list' );

/*
 * A view switcher on the page overrides the editor's layout for this request
 * only. The value is already restricted to the allow-list by FilterContext, so
 * anything it returns is a safe key; an unrecognised mode falls through to list,
 * which is the layout every mode degrades to.
 */
$requested_view = FilterContext::get_view( $query_id );

if ( null !== $requested_view ) {
	$layout_type = $requested_view;
}

$is_grid             = 'grid' === $layout_type;
$column_count        = max( 2, min( 6, (int) ( $layout['columnCount'] ?? 3 ) ) );
$column_count_tablet = max( 1, min( 4, (int) ( $layout['columnCountTablet'] ?? 2 ) ) );
$column_count_mobile = max( 1, min( 3, (int) ( $layout['columnCountMobile'] ?? 1 ) ) );
$now                 = gmdate( 'Y-m-d H:i:s' );
$current_post_id     = 'none' !== $related_to ? (int) ( $block->context['postId'] ?? 0 ) : 0;

// Resolve block gap — WordPress only injects --wp--style--block-gap via layout support,
// which we don't use, so we read and resolve the raw attribute value ourselves.
$raw_block_gap   = $attributes['style']['spacing']['blockGap'] ?? null;
$block_gap_style = '';
if ( null !== $raw_block_gap && '' !== (string) $raw_block_gap ) {
	if ( str_starts_with( (string) $raw_block_gap, 'var:' ) ) {
		$parts           = explode( '|', substr( (string) $raw_block_gap, 4 ) );
		$block_gap_value = 'var(--wp--' . implode( '--', $parts ) . ')';
	} else {
		$block_gap_value = (string) $raw_block_gap;
	}
	$block_gap_style = '--wp--style--block-gap:' . $block_gap_value . ';';
}

if ( $show_past ) {
	$start = '2000-01-01 00:00:00';
	$end   = $now;
} else {
	$start = $now;
	$end   = gmdate( 'Y-m-d H:i:s', strtotime( '+3 years' ) );
}

// Read active URL filters (only applied in standard query mode — not inherit/relatedTo).
$url_filters = FilterContext::get_active_filters( $query_id );

$index     = new EventIndex();
$base_args = [
	'per_page' => $per_page + 1,
	'page'     => $current_page,
	'orderby'  => 'start_datetime',
	'order'    => $order,
];

if ( $inherit ) {
	// Inherit mode — derive filters from the current archive/taxonomy context.
	$queried       = get_queried_object();
	$inherit_type  = null;
	$inherit_venue = null;

	if ( $queried instanceof \WP_Term ) {
		if ( 'event_type' === $queried->taxonomy ) {
			$inherit_type = [ $queried->term_id ];
		} elseif ( 'event_venue' === $queried->taxonomy ) {
			$inherit_venue = [ $queried->term_id ];
		}
		// event_tag and other taxonomies: no additional filter — show all events.
	}
	// WP_Post (singular) and post type archives: no additional filter.

	$inherit_filters = [
		'type_term_id'  => $inherit_type,
		'venue_term_id' => $inherit_venue,
		'per_page'      => $per_page,
		'page'          => $current_page,
		'orderby'       => 'start_datetime',
		'order'         => $order,
	];
	$events          = $index->get_events_in_range( $start, $end, $inherit_filters );

	if ( $show_pagination ) {
		$total_pages = max( 1, (int) ceil( $index->count_events_in_range( $start, $end, $inherit_filters ) / $per_page ) );
	}
} elseif ( 'none' !== $related_to && $current_post_id ) {
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
	// Merge URL type filter with the block's static typeIds.
	// Static typeIds act as a curator allowlist — the URL filter cannot widen
	// past what the editor configured. If no static IDs are set, the URL filter
	// applies freely.
	if ( ! empty( $url_filters['type_ids'] ) ) {
		$effective_type_ids = empty( $type_ids )
			? $url_filters['type_ids']
			: array_values( array_intersect( $url_filters['type_ids'], $type_ids ) );
	} else {
		$effective_type_ids = $type_ids;
	}

	// Apply date range filter. When showPast is false the effective start is
	// max(now, filter_date_start) so past dates cannot sneak in via the URL.
	if ( null !== $url_filters['date_start'] ) {
		$filter_start = $url_filters['date_start'] . ' 00:00:00';
		$start        = $show_past ? $filter_start : max( $now, $filter_start );
	}
	if ( null !== $url_filters['date_end'] ) {
		$end = $url_filters['date_end'] . ' 23:59:59';
	}

	$standard_filters = [
		'type_term_id'  => ! empty( $effective_type_ids ) ? $effective_type_ids : null,
		'venue_term_id' => $url_filters['venue_id'],
		'per_page'      => $per_page,
		'page'          => $current_page,
		'orderby'       => 'start_datetime',
		'order'         => $order,
	];
	$events           = $index->get_events_in_range( $start, $end, $standard_filters );

	if ( $show_pagination ) {
		$total_pages = max( 1, (int) ceil( $index->count_events_in_range( $start, $end, $standard_filters ) / $per_page ) );
	}
}

/*
 * Partition inner blocks into three groups:
 *
 * - the no-results block, which belongs to the query rather than to a card;
 * - any per-layout templates (blockendar/event-template), keyed by layout;
 * - anything else, which is a card template saved before per-layout templates
 *   existed and still applies to every layout.
 */
$no_results_block = null;
$inner_blocks     = [];
$layout_templates = [];

foreach ( $block->parsed_block['innerBlocks'] as $inner ) {
	if ( 'blockendar/events-query-no-results' === $inner['blockName'] ) {
		$no_results_block = $inner;
		continue;
	}

	if ( 'blockendar/event-template' === $inner['blockName'] ) {
		$template_layout = sanitize_key( $inner['attrs']['layout'] ?? '' );

		if ( in_array( $template_layout, FilterContext::view_modes(), true ) ) {
			$layout_templates[ $template_layout ] = $inner['innerBlocks'];
		}

		continue;
	}

	$inner_blocks[] = $inner;
}

/*
 * Only render each event more than once when the layouts genuinely differ. A
 * single template — whether it is legacy content or a split that was undone —
 * produces identical markup for every layout, which is what lets the view
 * switcher swap layouts by changing a class instead of reloading.
 */
$has_split_templates = count( $layout_templates ) > 1;

if ( ! $has_split_templates && 1 === count( $layout_templates ) ) {
	$inner_blocks = reset( $layout_templates );
}

if ( empty( $events ) ) {
	if ( $no_results_block ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_block output.
		echo render_block( $no_results_block );
	} else {
		echo '<p class="blockendar-events-query__empty">' . esc_html__( 'No events found.', 'blockendar' ) . '</p>';
	}
	return;
}

$wrapper_attrs = [
	'class'         => 'blockendar-events-query is-' . ( $is_grid ? 'grid' : 'list' ) . '-view',

	// Lets a view switcher on the page find the query it belongs to. Empty is a
	// legitimate value: only the Query Filters block provides an ID, and a
	// switcher outside one matches on the same empty string.
	'data-query-id' => $query_id,
];

/*
 * The column custom properties are emitted for both layouts, not just grid.
 * Switching view on the client swaps this element's class and nothing else, so
 * the grid values have to be present while a list is showing — otherwise the
 * first switch to grid would fall back to the CSS default column count.
 */
$wrapper_attrs['style'] = '--blockendar-columns:' . $column_count . ';'
	. '--blockendar-columns-tablet:' . $column_count_tablet . ';'
	. '--blockendar-columns-mobile:' . $column_count_mobile . ';'
	. $block_gap_style;
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

		if ( $has_split_templates ) {
			/*
			 * Both layouts are rendered and the inactive one is hidden, so the
			 * view switcher can swap between them without a round trip. The
			 * hidden attribute keeps it out of the accessibility tree as well as
			 * out of view — CSS alone would leave it exposed to screen readers.
			 */
			foreach ( $layout_templates as $template_layout => $template_blocks ) {
				printf(
					'<div class="blockendar-events-query__layout" data-layout="%s"%s>',
					esc_attr( $template_layout ),
					$template_layout === $layout_type ? '' : ' hidden'
				);

				foreach ( $template_blocks as $inner_block ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- block render output.
					echo render_block( $inner_block );
				}

				echo '</div>';
			}
		} else {
			foreach ( $inner_blocks as $inner_block ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- block render output.
				echo render_block( $inner_block );
			}
		}
		?>
	</li>
<?php endforeach; ?>
</ul>
<?php
remove_filter( 'post_type_link', 'blockendar_occurrence_permalink_filter', 10 );
unset( $GLOBALS['blockendar_current_occurrence'] );
wp_reset_postdata();

if ( $show_pagination && $total_pages > 1 ) {
	$base_url   = esc_url_raw( remove_query_arg( $page_param ) );
	$sep        = str_contains( $base_url, '?' ) ? '&' : '?';
	$pagination = paginate_links(
		[
			'base'      => $base_url . $sep . $page_param . '=%#%',
			'format'    => '',
			'current'   => $current_page,
			'total'     => $total_pages,
			'type'      => 'list',
			'prev_text' => __( 'Previous', 'blockendar' ),
			'next_text' => __( 'Next', 'blockendar' ),
		]
	);

	if ( $pagination ) {
		echo '<nav class="blockendar-events-query__pagination" aria-label="' . esc_attr__( 'Events navigation', 'blockendar' ) . '">';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- paginate_links() output.
		echo $pagination;
		echo '</nav>';
	}
}
?>
