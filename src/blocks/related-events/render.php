<?php
/**
 * blockendar/related-events render callback.
 *
 * @package Blockendar
 */
declare( strict_types=1 );

use Blockendar\DB\EventIndex;

$post_id  = $block->context['postId'] ?? get_the_ID();
$max      = min( 10, max( 1, (int) ( $attributes['maxEvents'] ?? 3 ) ) );
$match_by = in_array( $attributes['matchBy'] ?? 'type', [ 'type', 'venue', 'both' ], true )
	? $attributes['matchBy']
	: 'type';

$index = new EventIndex();
$now   = gmdate( 'Y-m-d H:i:s' );
$end   = gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) );

// Get current event's type/venue terms.
$type_terms  = get_the_terms( $post_id, 'event_type' );
$venue_terms = get_the_terms( $post_id, 'event_venue' );
$type_id     = ( ! is_wp_error( $type_terms ) && ! empty( $type_terms ) ) ? $type_terms[0]->term_id : null;
$venue_id    = ( ! is_wp_error( $venue_terms ) && ! empty( $venue_terms ) ) ? $venue_terms[0]->term_id : null;

$events = [];

if ( in_array( $match_by, [ 'type', 'both' ], true ) && $type_id ) {
	$by_type = $index->get_events_in_range(
		$now,
		$end,
		[
			'type_term_id' => $type_id,
			'per_page'     => $max + 1,
		]
	);
	$events  = array_merge( $events, $by_type );
}

if ( in_array( $match_by, [ 'venue', 'both' ], true ) && $venue_id ) {
	$by_venue = $index->get_events_in_range(
		$now,
		$end,
		[
			'venue_term_id' => $venue_id,
			'per_page'      => $max + 1,
		]
	);
	$events   = array_merge( $events, $by_venue );
}

// Deduplicate and exclude current post.
$seen   = [];
$output = [];

foreach ( $events as $event ) {
	$pid = (int) $event->post_id;

	if ( $pid === $post_id || isset( $seen[ $pid ] ) ) {
		continue;
	}

	$seen[ $pid ] = true;
	$output[]     = $event;

	if ( count( $output ) >= $max ) {
		break;
	}
}

if ( empty( $output ) ) {
	return;
}
$blockendar_settings = (array) get_option( 'blockendar_settings', [] );
$date_format         = $blockendar_settings['date_format'] ?? get_option( 'date_format', 'F j, Y' );
?>
<div <?php echo get_block_wrapper_attributes( [ 'class' => 'blockendar-related-events' ] ); ?>>
	<h3 class="blockendar-related-events__heading"><?php esc_html_e( 'Related Events', 'blockendar' ); ?></h3>
	<ul class="blockendar-related-events__list">
		<?php
		foreach ( $output as $event ) :
			$pid  = (int) $event->post_id;
			$date = date_i18n( $date_format, strtotime( $event->start_date ) );
			?>
			<li class="blockendar-related-events__item">
				<a href="<?php echo esc_url( get_permalink( $pid ) ); ?>">
					<?php echo esc_html( get_the_title( $pid ) ); ?>
				</a>
				<time class="blockendar-related-events__date" datetime="<?php echo esc_attr( $event->start_date ); ?>">
					<?php echo esc_html( $date ); ?>
				</time>
			</li>
		<?php endforeach; ?>
	</ul>
</div>
