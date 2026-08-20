<?php
/**
 * blockendar/filter-event-type — server-side render callback.
 *
 * Renders a list of event_type terms as checkboxes or a <select> dropdown.
 * Active terms are read from $_GET and marked with aria-current / CSS class.
 * The form preserves all other active filter params as hidden inputs so that
 * applying this filter doesn't wipe out venue or date selections.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Blockendar\Blocks\FilterContext;

$query_id      = (string) ( $block->context['blockendar/queryId'] ?? '' );
$display_style = in_array( $attributes['displayStyle'] ?? 'list', [ 'list', 'dropdown' ], true )
	? $attributes['displayStyle']
	: 'list';
$show_count    = ! empty( $attributes['showCount'] );
$show_empty    = ! empty( $attributes['showEmptyTerms'] );
$label         = sanitize_text_field( $attributes['label'] ?? '' );

$param_name = FilterContext::param_name( 'type', $query_id );
$active_ids = FilterContext::get_active_filters( $query_id )['type_ids'];

$term_args = [
	'taxonomy' => 'event_type',
	'orderby'  => 'name',
	'order'    => 'ASC',
];

/*
 * "Empty" means no upcoming events, not no posts at all. The taxonomy's own
 * count includes every event ever assigned to a term, so a type whose events
 * have all finished would otherwise offer itself as a filter that can only
 * return an empty list. Restrict to the terms the index says still have
 * something ahead of them.
 */
if ( ! $show_empty ) {
	$index       = new \Blockendar\DB\EventIndex();
	$with_events = $index->get_term_ids_with_events( 'type' );

	if ( empty( $with_events ) ) {
		return;
	}

	$term_args['include'] = $with_events;
}

$terms = get_terms( $term_args );

if ( is_wp_error( $terms ) || empty( $terms ) ) {
	return;
}

// Build the form action URL using the current paginated link base so that the
// form works with both pretty and plain WordPress permalink structures.
$page_param  = FilterContext::param_name( 'page', $query_id );
$form_action = esc_url( remove_query_arg( [ $param_name, $page_param ] ) );

// Collect all other active filter params to preserve through this form submission.
$other_filters = FilterContext::get_active_filters( $query_id );
$hidden_inputs = '';

if ( null !== $other_filters['venue_id'] ) {
	$hidden_inputs .= '<input type="hidden" name="' . esc_attr( FilterContext::param_name( 'venue', $query_id ) ) . '" value="' . esc_attr( (string) $other_filters['venue_id'] ) . '">';
}
if ( null !== $other_filters['date_start'] ) {
	$hidden_inputs .= '<input type="hidden" name="' . esc_attr( FilterContext::param_name( 'date_start', $query_id ) ) . '" value="' . esc_attr( $other_filters['date_start'] ) . '">';
}
if ( null !== $other_filters['date_end'] ) {
	$hidden_inputs .= '<input type="hidden" name="' . esc_attr( FilterContext::param_name( 'date_end', $query_id ) ) . '" value="' . esc_attr( $other_filters['date_end'] ) . '">';
}

// Unique per instance: two of these blocks can target the same query, so the
// param name is not a safe id.
$panel_id   = wp_unique_id( 'blockendar-type-panel-' );
$trigger_id = wp_unique_id( 'blockendar-type-trigger-' );

/*
 * Summary shown on the trigger. One selection names it, several are counted, and
 * none falls back to the placeholder — the same shape the reference UI uses.
 */
$selected_terms = array_values(
	array_filter(
		$terms,
		static fn( $term ) => in_array( $term->term_id, $active_ids, true )
	)
);

if ( 1 === count( $selected_terms ) ) {
	$trigger_text = $selected_terms[0]->name;
} elseif ( count( $selected_terms ) > 1 ) {
	$trigger_text = sprintf(
		/* translators: %d: number of selected event types. */
		_n( '%d type selected', '%d types selected', count( $selected_terms ), 'blockendar' ),
		count( $selected_terms )
	);
} else {
	/*
	 * Falls back in PHP as well as in block.json: instances saved before this
	 * attribute existed carry no triggerLabel at all, and an empty trigger would
	 * be worse than a generic one.
	 */
	$placeholder  = sanitize_text_field( $attributes['triggerLabel'] ?? '' );
	$trigger_text = '' !== $placeholder ? $placeholder : __( 'All types', 'blockendar' );
}

$wrapper_attrs = get_block_wrapper_attributes(
	[
		'class'                  => 'blockendar-filter-event-type is-style-' . $display_style,
		'data-blockendar-filter' => 'event-type',
		'data-param-name'        => $param_name,
	]
);
?>
<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( '' !== $label ) : ?>
		<p class="blockendar-filter__label"><?php echo esc_html( $label ); ?></p>
	<?php endif; ?>

	<form method="get" action="<?php echo $form_action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped above.
		echo $hidden_inputs;
		?>

		<?php if ( 'dropdown' === $display_style ) : ?>
			<?php
			/*
			 * type="button" matters: this sits inside the filter <form>, and a bare
			 * <button> defaults to type="submit", which would reload the page on
			 * every open.
			 */
			?>
			<button
				type="button"
				class="blockendar-filter__trigger"
				id="<?php echo esc_attr( $trigger_id ); ?>"
				aria-expanded="false"
				aria-controls="<?php echo esc_attr( $panel_id ); ?>"
			>
				<span class="blockendar-filter__trigger-text"><?php echo esc_html( $trigger_text ); ?></span>
				<span class="blockendar-filter__trigger-icon" aria-hidden="true"></span>
			</button>

			<div class="blockendar-filter__panel" id="<?php echo esc_attr( $panel_id ); ?>">
		<?php endif; ?>

			<ul class="blockendar-filter__list" role="group" aria-label="<?php esc_attr_e( 'Filter by event type', 'blockendar' ); ?>">
				<?php foreach ( $terms as $term ) : ?>
					<?php
					$is_active = in_array( $term->term_id, $active_ids, true );
					$count     = $show_count ? ' <span class="blockendar-filter__count">(' . (int) $term->count . ')</span>' : '';
					$li_class  = $is_active ? ' is-active' : '';
					?>
					<li class="blockendar-filter__item<?php echo esc_attr( $li_class ); ?>"
						<?php echo $is_active ? 'aria-current="true"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
						<label class="blockendar-filter__checkbox-label">
							<input
								type="checkbox"
								name="<?php echo esc_attr( $param_name ); ?>[]"
								value="<?php echo esc_attr( (string) $term->term_id ); ?>"
								<?php checked( $is_active ); ?>
							>
							<?php echo esc_html( $term->name ); ?>
							<?php
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							echo $count;
							?>
						</label>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php
			/*
			 * Both actions sit together inside the panel, so a visitor who has opened
			 * the dropdown can commit or discard without hunting for a control
			 * outside it. Clear is a plain link: it is a navigation to the unfiltered
			 * URL, works without JavaScript, and needs no handler.
			 */
			?>
			<div class="blockendar-filter__actions">
				<?php if ( ! empty( $active_ids ) ) : ?>
					<a href="<?php echo esc_url( remove_query_arg( [ $param_name, $page_param ] ) ); ?>" class="blockendar-filter__clear">
						<?php esc_html_e( 'Clear', 'blockendar' ); ?>
					</a>
				<?php endif; ?>

				<button type="submit" class="blockendar-filter__submit">
					<?php esc_html_e( 'Apply', 'blockendar' ); ?>
				</button>
			</div>

		<?php if ( 'dropdown' === $display_style ) : ?>
			</div>
		<?php endif; ?>
	</form>
</div>
