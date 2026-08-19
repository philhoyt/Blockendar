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

$terms = get_terms(
	[
		'taxonomy'   => 'event_type',
		'hide_empty' => ! $show_empty,
		'orderby'    => 'name',
		'order'      => 'ASC',
	]
);

if ( is_wp_error( $terms ) || empty( $terms ) ) {
	return;
}

// Build the form action URL using the current paginated link base so that the
// form works with both pretty and plain WordPress permalink structures.
$form_action = esc_url( remove_query_arg( [ $param_name, FilterContext::param_name( 'page', $query_id ) ] ) );

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

			<select name="<?php echo esc_attr( $param_name ); ?>[]" multiple
				class="blockendar-filter__select"
				aria-label="<?php esc_attr_e( 'Filter by event type', 'blockendar' ); ?>">
				<?php foreach ( $terms as $term ) : ?>
					<?php
					$selected = in_array( $term->term_id, $active_ids, true ) ? ' selected' : '';
					$count    = $show_count ? ' (' . (int) $term->count . ')' : '';
					?>
					<option value="<?php echo esc_attr( (string) $term->term_id ); ?>"<?php echo $selected; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
						<?php echo esc_html( $term->name . $count ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="blockendar-filter__submit screen-reader-text">
				<?php esc_html_e( 'Apply', 'blockendar' ); ?>
			</button>

		<?php else : ?>

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
			<button type="submit" class="blockendar-filter__submit">
				<?php esc_html_e( 'Apply', 'blockendar' ); ?>
			</button>

		<?php endif; ?>

		<?php if ( ! empty( $active_ids ) ) : ?>
			<a href="<?php echo esc_url( remove_query_arg( $param_name ) ); ?>" class="blockendar-filter__clear">
				<?php esc_html_e( 'Clear', 'blockendar' ); ?>
			</a>
		<?php endif; ?>
	</form>
</div>
