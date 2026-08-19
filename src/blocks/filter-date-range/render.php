<?php
/**
 * blockendar/filter-date-range — server-side render callback.
 *
 * Renders two <input type="date"> fields wrapped in a <form>.
 * Works without JavaScript — JS upgrades the inputs to a Flatpickr range
 * picker. On submission the page reloads with blockendar_date_start and
 * blockendar_date_end query params set.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Blockendar\Blocks\FilterContext;

$query_id    = (string) ( $block->context['blockendar/queryId'] ?? '' );
$label       = sanitize_text_field( $attributes['label'] ?? '' );
$label_start = sanitize_text_field( $attributes['labelStart'] ?? __( 'From', 'blockendar' ) );
$label_end   = sanitize_text_field( $attributes['labelEnd'] ?? __( 'To', 'blockendar' ) );
// Shown in place of the start label once JavaScript collapses the two fields
// into a single range picker, where "From" would no longer describe the input.
$label_range = sanitize_text_field( $attributes['labelRange'] ?? __( 'Dates', 'blockendar' ) );

// Field ids must be unique per instance: the param names are shared by every
// date-range block that targets the same query, so using them directly would
// emit duplicate ids and break label association when a page has two.
$id_start = wp_unique_id( 'blockendar-date-start-' );
$id_end   = wp_unique_id( 'blockendar-date-end-' );
$id_group = wp_unique_id( 'blockendar-date-group-' );
$min_date = sanitize_text_field( $attributes['minDate'] ?? '' );
$max_date = sanitize_text_field( $attributes['maxDate'] ?? '' );

$param_start = FilterContext::param_name( 'date_start', $query_id );
$param_end   = FilterContext::param_name( 'date_end', $query_id );
$page_param  = FilterContext::param_name( 'page', $query_id );

$active_filters = FilterContext::get_active_filters( $query_id );
$active_start   = $active_filters['date_start'] ?? '';
$active_end     = $active_filters['date_end'] ?? '';
$has_dates      = null !== $active_filters['date_start'] || null !== $active_filters['date_end'];

$form_action = esc_url( remove_query_arg( [ $param_start, $param_end, $page_param ] ) );

// Preserve other active filters.
$hidden_inputs = '';
if ( ! empty( $active_filters['type_ids'] ) ) {
	$hidden_inputs .= '<input type="hidden" name="' . esc_attr( FilterContext::param_name( 'type', $query_id ) ) . '" value="' . esc_attr( implode( ',', $active_filters['type_ids'] ) ) . '">';
}
if ( null !== $active_filters['venue_id'] ) {
	$hidden_inputs .= '<input type="hidden" name="' . esc_attr( FilterContext::param_name( 'venue', $query_id ) ) . '" value="' . esc_attr( (string) $active_filters['venue_id'] ) . '">';
}

$clear_url = esc_url( remove_query_arg( [ $param_start, $param_end, $page_param ] ) );

$wrapper_attrs = get_block_wrapper_attributes(
	[
		'class'                  => 'blockendar-filter-date-range' . ( $has_dates ? ' has-active-dates' : '' ),
		'data-blockendar-filter' => 'date-range',
		'data-label-range'       => $label_range,
		'data-param-start'       => $param_start,
		'data-param-end'         => $param_end,
		'data-min-date'          => $min_date,
		'data-max-date'          => $max_date,
	]
);
?>
<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<form method="get" action="<?php echo $form_action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $hidden_inputs;
		?>

		<fieldset class="blockendar-filter-date-range__group">
		<?php if ( '' !== $label ) : ?>
			<legend class="blockendar-filter__label" id="<?php echo esc_attr( $id_group ); ?>">
				<?php echo esc_html( $label ); ?>
			</legend>
		<?php endif; ?>

		<div class="blockendar-filter-date-range__fields">
			<div class="blockendar-filter-date-range__field">
				<label for="<?php echo esc_attr( $id_start ); ?>" class="blockendar-filter-date-range__label">
					<?php echo esc_html( $label_start ); ?>
				</label>
				<input
					type="date"
					id="<?php echo esc_attr( $id_start ); ?>"
					name="<?php echo esc_attr( $param_start ); ?>"
					class="blockendar-filter-date-range__input"
					value="<?php echo esc_attr( $active_start ); ?>"
					<?php echo '' !== $min_date ? 'min="' . esc_attr( $min_date ) . '"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo '' !== $max_date ? 'max="' . esc_attr( $max_date ) . '"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				>
			</div>

			<div class="blockendar-filter-date-range__field">
				<label for="<?php echo esc_attr( $id_end ); ?>" class="blockendar-filter-date-range__label">
					<?php echo esc_html( $label_end ); ?>
				</label>
				<input
					type="date"
					id="<?php echo esc_attr( $id_end ); ?>"
					name="<?php echo esc_attr( $param_end ); ?>"
					class="blockendar-filter-date-range__input"
					value="<?php echo esc_attr( $active_end ); ?>"
					<?php echo '' !== $min_date ? 'min="' . esc_attr( $min_date ) . '"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo '' !== $max_date ? 'max="' . esc_attr( $max_date ) . '"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				>
			</div>
		</div>
		</fieldset>

		<button type="submit" class="blockendar-filter__submit">
			<?php esc_html_e( 'Apply dates', 'blockendar' ); ?>
		</button>

		<?php if ( $has_dates ) : ?>
			<a href="<?php echo $clear_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" class="blockendar-filter__clear">
				<?php esc_html_e( 'Clear dates', 'blockendar' ); ?>
			</a>
		<?php endif; ?>
	</form>
</div>
