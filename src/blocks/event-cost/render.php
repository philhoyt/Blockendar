<?php
/**
 * blockendar/event-cost render callback.
 *
 * @package Blockendar
 */
declare( strict_types=1 );

/** Map ISO 4217 code → display symbol (mirrors edit.jsx CURRENCY_SYMBOLS). */
function blockendar_currency_symbol( string $code ): string {
	static $map = [
		'USD' => '$',
		'EUR' => '€',
		'GBP' => '£',
		'CAD' => 'CA$',
		'AUD' => 'A$',
		'JPY' => '¥',
		'CHF' => 'CHF',
		'CNY' => '¥',
		'INR' => '₹',
		'MXN' => 'MX$',
		'BRL' => 'R$',
		'KRW' => '₩',
		'SEK' => 'kr',
		'NOK' => 'kr',
		'DKK' => 'kr',
		'NZD' => 'NZ$',
		'SGD' => 'S$',
		'HKD' => 'HK$',
		'ZAR' => 'R',
	];
	return $map[ $code ] ?? $code;
}

/**
 * If $raw is a plain number, wrap it with the correct currency symbol.
 * Non-numeric strings ("Free", "$10–$25") are returned unchanged.
 */
function blockendar_format_cost( string $raw, int $post_id ): string {
	if ( '' === $raw || ! is_numeric( $raw ) ) {
		return $raw;
	}

	$settings = (array) get_option( 'blockendar_settings', [] );
	$currency = (string) get_post_meta( $post_id, 'blockendar_currency', true );
	if ( ! $currency ) {
		$currency = $settings['default_currency'] ?? 'USD';
	}
	$position = $settings['currency_position'] ?? 'before';
	$symbol   = blockendar_currency_symbol( $currency );

	return 'before' === $position ? $symbol . $raw : $raw . $symbol;
}

$post_id      = $block->context['postId'] ?? get_the_ID();
$cost         = (string) get_post_meta( $post_id, 'blockendar_cost', true );
$reg_url      = get_post_meta( $post_id, 'blockendar_registration_url', true );
$button_label = ! empty( $attributes['buttonLabel'] )
	? $attributes['buttonLabel']
	: __( 'Register / Get Tickets', 'blockendar' );

if ( ! $cost && ! $reg_url ) {
	return;
}

$cost_display = blockendar_format_cost( $cost, (int) $post_id );
?>
<div <?php echo get_block_wrapper_attributes( [ 'class' => 'blockendar-event-cost' ] ); ?>>
	<?php if ( $cost_display ) : ?>
		<span class="blockendar-event-cost__amount">
			<?php echo esc_html( $cost_display ); ?>
		</span>
	<?php endif; ?>

	<?php if ( $reg_url ) : ?>
		<a class="blockendar-event-cost__cta wp-element-button" href="<?php echo esc_url( $reg_url ); ?>" target="_blank" rel="noopener noreferrer">
			<?php echo esc_html( $button_label ); ?>
		</a>
	<?php endif; ?>
</div>
