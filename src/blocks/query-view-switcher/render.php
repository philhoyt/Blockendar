<?php
/**
 * blockendar/query-view-switcher — server-side render callback.
 *
 * Renders one link per available view mode. They are links rather than buttons
 * because switching view is a navigation: each carries the target URL, so the
 * control works with JavaScript disabled and needs no view script at all.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Blockendar\Blocks\FilterContext;

$query_id   = (string) ( $block->context['blockendar/queryId'] ?? '' );
$view_param = FilterContext::param_name( 'view', $query_id );
$page_param = FilterContext::param_name( 'page', $query_id );

/*
 * Both attributes fall back here as well as in block.json: instances saved
 * before this block gained them carry no value at all.
 */
$show_labels  = ! empty( $attributes['showLabels'] );
$default_view = sanitize_key( $attributes['defaultView'] ?? '' );

$modes = FilterContext::view_modes();

if ( empty( $modes ) ) {
	return;
}

if ( ! in_array( $default_view, $modes, true ) ) {
	$default_view = $modes[0];
}

// No param in the URL means the editor's chosen default is the active one.
$active_view = FilterContext::get_view( $query_id ) ?? $default_view;

$mode_labels = [
	'list' => __( 'List view', 'blockendar' ),
	'grid' => __( 'Grid view', 'blockendar' ),
];

$wrapper_attrs = get_block_wrapper_attributes(
	[
		'class'            => 'blockendar-view-switcher',
		'data-active-view' => $active_view,
	]
);
?>
<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	role="group"
	aria-label="<?php esc_attr_e( 'Change the event layout', 'blockendar' ); ?>">
	<?php
	foreach ( $modes as $mode ) :
		$is_active = ( $mode === $active_view );

		/*
		 * Selecting the default drops the param rather than spelling it out, so
		 * the canonical URL of an unfiltered list stays clean. Changing view also
		 * resets pagination: page 4 of a list is not page 4 of a grid.
		 */
		$url = $mode === $default_view
			? remove_query_arg( [ $view_param, $page_param ] )
			: add_query_arg( $view_param, $mode, remove_query_arg( $page_param ) );

		/* translators: %s: view mode key, used when a theme registers a mode the plugin has no label for. */
		$label = $mode_labels[ $mode ] ?? sprintf( __( '%s view', 'blockendar' ), $mode );
		?>
		<a
			href="<?php echo esc_url( $url ); ?>"
			class="blockendar-view-switcher__button<?php echo $is_active ? ' is-active' : ''; ?>"
			data-view="<?php echo esc_attr( $mode ); ?>"
			aria-current="<?php echo $is_active ? 'true' : 'false'; ?>"
			<?php echo $show_labels ? '' : 'aria-label="' . esc_attr( $label ) . '"'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		>
			<span class="blockendar-view-switcher__icon" aria-hidden="true"></span>
			<?php if ( $show_labels ) : ?>
				<span class="blockendar-view-switcher__label"><?php echo esc_html( $label ); ?></span>
			<?php endif; ?>
		</a>
	<?php endforeach; ?>
</div>
