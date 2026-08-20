<?php
/**
 * blockendar/query-view-switcher — server-side render callback.
 *
 * Renders one link per available view mode. They are links rather than buttons
 * because switching view is a navigation: each carries the target URL, so the
 * control still works with JavaScript disabled.
 *
 * view.js upgrades that to an in-place swap where it can. The data-* attributes
 * below are what it needs to do so without re-deriving the parameter naming
 * convention in JavaScript.
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

/*
 * Icon bodies, drawn on a 24x24 grid and filled with currentColor so they take
 * the theme's colour without a fill attribute to keep in sync. A mode with no
 * entry here — one a theme registered via blockendar_filter_view_modes —
 * renders its label instead of an empty box.
 */
$mode_icons = [
	'list' => '<rect x="3" y="4" width="18" height="4" rx="1.5"/>'
		. '<rect x="3" y="10" width="18" height="4" rx="1.5"/>'
		. '<rect x="3" y="16" width="18" height="4" rx="1.5"/>',
	'grid' => '<rect x="3" y="3" width="8" height="8" rx="1.5"/>'
		. '<rect x="13" y="3" width="8" height="8" rx="1.5"/>'
		. '<rect x="3" y="13" width="8" height="8" rx="1.5"/>'
		. '<rect x="13" y="13" width="8" height="8" rx="1.5"/>',
];

$wrapper_attrs = get_block_wrapper_attributes(
	[
		'class'             => 'blockendar-view-switcher',
		'data-active-view'  => $active_view,
		'data-default-view' => $default_view,
		'data-query-id'     => $query_id,
		'data-view-param'   => $view_param,
		'data-page-param'   => $page_param,
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
			<?php if ( isset( $mode_icons[ $mode ] ) ) : ?>
				<span class="blockendar-view-switcher__icon" aria-hidden="true">
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded SVG literal from $mode_icons above; no dynamic input reaches it.
					echo '<svg viewBox="0 0 24 24" fill="currentColor" focusable="false" aria-hidden="true">' . $mode_icons[ $mode ] . '</svg>';
					?>
				</span>
			<?php endif; ?>
			<?php if ( $show_labels || ! isset( $mode_icons[ $mode ] ) ) : ?>
				<span class="blockendar-view-switcher__label"><?php echo esc_html( $label ); ?></span>
			<?php endif; ?>
		</a>
	<?php endforeach; ?>
</div>
