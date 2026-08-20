<?php
/**
 * blockendar/filter-venue — server-side render callback.
 *
 * Renders venue terms as radio buttons (single-select) or a <select> dropdown.
 * Includes an "All venues" option to clear the filter.
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
$show_empty    = ! empty( $attributes['showEmpty'] );
$show_virtual  = isset( $attributes['showVirtual'] ) ? (bool) $attributes['showVirtual'] : true;
$label         = sanitize_text_field( $attributes['label'] ?? '' );

$param_name = FilterContext::param_name( 'venue', $query_id );
$active_id  = FilterContext::get_active_filters( $query_id )['venue_id'];

$term_args = [
	'taxonomy' => 'event_venue',
	'orderby'  => 'name',
	'order'    => 'ASC',
];

/*
 * "Empty" means no upcoming events, not no posts at all — a venue whose last
 * event was years ago should not offer itself as a filter that can only return
 * an empty list. See the same note in filter-event-type.
 */
if ( ! $show_empty ) {
	$index       = new \Blockendar\DB\EventIndex();
	$with_events = $index->get_term_ids_with_events( 'venue' );

	if ( empty( $with_events ) ) {
		return;
	}

	$term_args['include'] = $with_events;
}

$all_terms = get_terms( $term_args );

if ( is_wp_error( $all_terms ) || empty( $all_terms ) ) {
	return;
}

// Optionally filter out virtual venues.
if ( ! $show_virtual ) {
	$all_terms = array_filter(
		$all_terms,
		static function ( $term ) {
			return ! get_term_meta( $term->term_id, 'blockendar_venue_virtual', true );
		}
	);
}

$terms = array_values( $all_terms );

if ( empty( $terms ) ) {
	return;
}

$page_param  = FilterContext::param_name( 'page', $query_id );
$form_action = esc_url( remove_query_arg( [ $param_name, $page_param ] ) );

// Preserve other active filters.
$other_filters = FilterContext::get_active_filters( $query_id );
$hidden_inputs = '';

if ( ! empty( $other_filters['type_ids'] ) ) {
	$hidden_inputs .= '<input type="hidden" name="' . esc_attr( FilterContext::param_name( 'type', $query_id ) ) . '" value="' . esc_attr( implode( ',', $other_filters['type_ids'] ) ) . '">';
}
if ( null !== $other_filters['date_start'] ) {
	$hidden_inputs .= '<input type="hidden" name="' . esc_attr( FilterContext::param_name( 'date_start', $query_id ) ) . '" value="' . esc_attr( $other_filters['date_start'] ) . '">';
}
if ( null !== $other_filters['date_end'] ) {
	$hidden_inputs .= '<input type="hidden" name="' . esc_attr( FilterContext::param_name( 'date_end', $query_id ) ) . '" value="' . esc_attr( $other_filters['date_end'] ) . '">';
}

// Unique per instance: the param name is shared by every venue filter targeting
// the same query, so it cannot serve as an id.
$panel_id   = wp_unique_id( 'blockendar-venue-panel-' );
$trigger_id = wp_unique_id( 'blockendar-venue-trigger-' );

/*
 * Venue is single-select, so the trigger either names the chosen venue or falls
 * back to the placeholder. render.php falls back as well as block.json, because
 * instances saved before triggerLabel existed carry no value.
 */
$selected_venue = null;
foreach ( $terms as $term ) {
	if ( $active_id === $term->term_id ) {
		$selected_venue = $term;
		break;
	}
}

$placeholder  = sanitize_text_field( $attributes['triggerLabel'] ?? '' );
$placeholder  = '' !== $placeholder ? $placeholder : __( 'All venues', 'blockendar' );
$trigger_text = $selected_venue ? $selected_venue->name : $placeholder;

$wrapper_attrs = get_block_wrapper_attributes(
	[
		'class'                  => 'blockendar-filter-venue is-style-' . $display_style,
		'data-blockendar-filter' => 'venue',
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
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $hidden_inputs;
		?>

		<?php if ( 'dropdown' === $display_style ) : ?>
			<?php
			/*
			 * type="button": this sits inside the filter <form>, where a bare
			 * <button> submits and would reload the page on every open.
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

			<ul class="blockendar-filter__list" role="radiogroup" aria-label="<?php esc_attr_e( 'Filter by venue', 'blockendar' ); ?>">
				<li class="blockendar-filter__item<?php echo null === $active_id ? ' is-active' : ''; ?>">
					<label class="blockendar-filter__radio-label">
						<input type="radio" name="<?php echo esc_attr( $param_name ); ?>" value=""
							<?php checked( null === $active_id ); ?>>
						<?php esc_html_e( 'All venues', 'blockendar' ); ?>
					</label>
				</li>
				<?php foreach ( $terms as $term ) : ?>
					<?php $is_active = ( $active_id === $term->term_id ); ?>
					<li class="blockendar-filter__item<?php echo $is_active ? ' is-active' : ''; ?>"
						<?php echo $is_active ? 'aria-current="true"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
						<label class="blockendar-filter__radio-label">
							<input type="radio" name="<?php echo esc_attr( $param_name ); ?>"
								value="<?php echo esc_attr( (string) $term->term_id ); ?>"
								<?php checked( $is_active ); ?>>
							<?php echo esc_html( $term->name ); ?>
						</label>
					</li>
				<?php endforeach; ?>
			</ul>
			<div class="blockendar-filter__actions">
				<?php if ( null !== $active_id ) : ?>
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
