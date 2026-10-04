<?php
/**
 * blockendar/event-cost render callback.
 *
 * @package Blockendar
 */
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

/**
 * If $raw is a plain number, wrap it with the correct currency symbol.
 * Non-numeric strings ("Free", "$10–$25") are returned unchanged.
 */
if ( ! function_exists( 'blockendar_format_cost' ) ) :
	function blockendar_format_cost( string $raw ): string {
		if ( '' === $raw || ! is_numeric( $raw ) ) {
			return $raw;
		}

		$settings = (array) get_option( 'blockendar_settings', [] );
		$currency = $settings['default_currency'] ?? 'USD';
		$position = $settings['currency_position'] ?? 'before';
		$symbol   = blockendar_currency_symbol( $currency );

		return 'before' === $position ? $symbol . $raw : $raw . $symbol;
	}
endif;

$post_id = blockendar_block_event_id( $block );

if ( ! $post_id ) {
	return;
}

$cost         = (string) get_post_meta( $post_id, 'blockendar_cost', true );
$reg_url      = get_post_meta( $post_id, 'blockendar_registration_url', true );
$button_label = ! empty( $attributes['buttonLabel'] )
	? $attributes['buttonLabel']
	: __( 'Register / Get Tickets', 'blockendar' );
$show_cost    = $attributes['showCost'] ?? true;
$show_button  = $attributes['showButton'] ?? true;

// Not a truthiness test: a cost of "0" is a free event, and is shown.
if ( '' === $cost && ! $reg_url ) {
	return;
}

$cost_display = blockendar_format_cost( $cost );

if ( ( ! $show_cost || ! $cost_display ) && ( ! $show_button || ! $reg_url ) ) {
	return;
}
?>
<div <?php echo get_block_wrapper_attributes( [ 'class' => 'blockendar-event-cost' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( $show_cost && $cost_display ) : ?>
		<span class="blockendar-event-cost__amount">
			<?php echo esc_html( $cost_display ); ?>
		</span>
	<?php endif; ?>

	<?php if ( $show_button && $reg_url ) : ?>
		<a class="blockendar-event-cost__cta wp-element-button" href="<?php echo esc_url( $reg_url ); ?>" target="_blank" rel="noopener noreferrer">
			<?php echo esc_html( $button_label ); ?>
		</a>
	<?php endif; ?>
</div>
