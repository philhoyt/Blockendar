<?php
/**
 * blockendar/event-list — server-side render callback.
 *
 * Variables available from the block runtime:
 *   $attributes — block attribute values
 *   $content    — inner block content (unused — dynamic block)
 *   $block      — WP_Block instance
 *
 * @package Blockendar
 */

declare( strict_types=1 );

use Blockendar\DB\EventIndex;

$index    = new EventIndex();
$now      = gmdate( 'Y-m-d H:i:s' );
$per_page = max( 1, min( 100, (int) ( $attributes['perPage'] ?? 10 ) ) );
$page     = max( 1, (int) ( $_GET['blockendar_page'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
$layout   = in_array( $attributes['layout'] ?? 'list', [ 'list', 'grid' ], true )
	? $attributes['layout']
	: 'list';
$group_by   = $attributes['groupBy']   ?? 'none';
$pagination = $attributes['pagination'] ?? 'paged';
$show_past  = ! empty( $attributes['showPast'] );
$order      = 'DESC' === ( $attributes['order'] ?? 'ASC' ) ? 'DESC' : 'ASC';

if ( $show_past ) {
	$start = '2000-01-01 00:00:00';
	$end   = $now;
	if ( 'DESC' !== $order ) {
		$order = 'DESC'; // Past events: most recent first by default.
	}
} else {
	$start = $now;
	$end   = gmdate( 'Y-m-d H:i:s', strtotime( '+3 years' ) );
}

$filters = [
	'venue_term_id' => ! empty( $attributes['venueId'] )      ? (int) $attributes['venueId']      : null,
	'type_term_id'  => ! empty( $attributes['typeId'] )       ? (int) $attributes['typeId']       : null,
	'featured'      => ! empty( $attributes['featuredOnly'] ) ? true                              : null,
	'per_page'      => $per_page,
	'page'          => $page,
	'orderby'       => 'start_datetime',
	'order'         => $order,
];

$events = $index->get_events_in_range( $start, $end, $filters );
$total  = $index->count_events_in_range( $start, $end, $filters );
$pages  = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;

if ( empty( $events ) ) {
	echo '<p class="blockendar-event-list__empty">' . esc_html__( 'No events found.', 'blockendar' ) . '</p>';
	return;
}

// Group events if requested.
$grouped = [];

foreach ( $events as $event ) {
	$key = match ( $group_by ) {
		'date'  => date_i18n( get_option( 'date_format' ), strtotime( $event->start_date ) ),
		'month' => date_i18n( 'F Y', strtotime( $event->start_date ) ),
		'type'  => ! empty( $event->type_term_ids )
			? ( get_term( (int) json_decode( $event->type_term_ids, true )[0], 'event_type' )?->name ?? __( 'Uncategorized', 'blockendar' ) )
			: __( 'Uncategorized', 'blockendar' ),
		default => 'all',
	};

	$grouped[ $key ][] = $event;
}

$block_classes = implode( ' ', array_filter( [
	'blockendar-event-list',
	"blockendar-event-list--$layout",
	'none' !== $group_by ? 'blockendar-event-list--grouped' : '',
] ) );

?>
<div <?php echo get_block_wrapper_attributes( [ 'class' => $block_classes ] ); ?>>

	<?php foreach ( $grouped as $group_label => $group_events ) : ?>

		<?php if ( 'none' !== $group_by ) : ?>
			<h3 class="blockendar-event-list__group-label">
				<?php echo esc_html( $group_label ); ?>
			</h3>
		<?php endif; ?>

		<ul class="blockendar-event-list__items">
			<?php foreach ( $group_events as $event ) :
				$post_id    = (int) $event->post_id;
				$permalink  = get_permalink( $post_id );
				$thumb_id   = get_post_thumbnail_id( $post_id );
				$cost       = get_post_meta( $post_id, 'blockendar_cost', true );
				$status     = $event->status;
				$venue_name = '';

				if ( $event->venue_term_id ) {
					$venue_term = get_term( (int) $event->venue_term_id, 'event_venue' );
					$venue_name = ! is_wp_error( $venue_term ) && $venue_term ? $venue_term->name : '';
				}

				$start_formatted = $event->all_day
					? date_i18n( get_option( 'date_format' ), strtotime( $event->start_date ) )
					: date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $event->start_datetime ) );
			?>
			<li class="blockendar-event-list__item blockendar-event-list__item--<?php echo esc_attr( $status ); ?>">
				<a href="<?php echo esc_url( $permalink ); ?>" class="blockendar-event-list__link">

					<?php if ( 'grid' === $layout && $thumb_id ) : ?>
						<div class="blockendar-event-list__thumb">
							<?php echo wp_get_attachment_image( $thumb_id, 'medium', false, [ 'loading' => 'lazy' ] ); ?>
						</div>
					<?php endif; ?>

					<div class="blockendar-event-list__body">
						<time class="blockendar-event-list__date" datetime="<?php echo esc_attr( $event->start_datetime ); ?>">
							<?php echo esc_html( $start_formatted ); ?>
						</time>

						<h4 class="blockendar-event-list__title">
							<?php echo esc_html( get_the_title( $post_id ) ); ?>
						</h4>

						<?php if ( $venue_name ) : ?>
							<span class="blockendar-event-list__venue"><?php echo esc_html( $venue_name ); ?></span>
						<?php endif; ?>

						<?php if ( $cost ) : ?>
							<span class="blockendar-event-list__cost"><?php echo esc_html( $cost ); ?></span>
						<?php endif; ?>

						<?php if ( 'scheduled' !== $status ) : ?>
							<span class="blockendar-event-list__status blockendar-status--<?php echo esc_attr( $status ); ?>">
								<?php echo esc_html( ucfirst( str_replace( '_', ' ', $status ) ) ); ?>
							</span>
						<?php endif; ?>
					</div>

				</a>
			</li>
			<?php endforeach; ?>
		</ul>

	<?php endforeach; ?>

	<?php if ( $pages > 1 && 'paged' === $pagination ) : ?>
		<nav class="blockendar-event-list__pagination">
			<?php
			$base_url = get_pagenum_link( 1 );

			for ( $p = 1; $p <= $pages; $p++ ) :
				$url = add_query_arg( 'blockendar_page', $p, $base_url );
			?>
			<a
				href="<?php echo esc_url( $url ); ?>"
				class="blockendar-event-list__page-link<?php echo $p === $page ? ' is-current' : ''; ?>"
				aria-current="<?php echo $p === $page ? 'page' : 'false'; ?>"
			><?php echo esc_html( (string) $p ); ?></a>
			<?php endfor; ?>
		</nav>
	<?php endif; ?>

	<?php if ( $pages > 1 && 'load_more' === $pagination && $page < $pages ) : ?>
		<button
			class="blockendar-event-list__load-more"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'blockendar_load_more' ) ); ?>"
			data-page="<?php echo esc_attr( (string) $page ); ?>"
			data-attributes="<?php echo esc_attr( wp_json_encode( $attributes ) ); ?>"
		>
			<?php esc_html_e( 'Load more events', 'blockendar' ); ?>
		</button>
	<?php endif; ?>

</div>
