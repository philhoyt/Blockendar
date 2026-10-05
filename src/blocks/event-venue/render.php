<?php
/**
 * blockendar/event-venue render callback.
 *
 * @package Blockendar
 */
declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$post_id = blockendar_block_event_id( $block );

if ( ! $post_id ) {
	return;
}

$show_addr = (bool) ( $attributes['showAddress'] ?? true );

// Absent on every block saved before these two existed, which must render
// exactly as it did: no links.
$show_directions = ! empty( $attributes['showDirections'] );
$link_name       = ! empty( $attributes['linkName'] );

$terms = get_the_terms( $post_id, \Blockendar\Taxonomy\Venue::TAXONOMY );
if ( is_wp_error( $terms ) || empty( $terms ) ) {
	return;
}
?>
<div <?php echo get_block_wrapper_attributes( [ 'class' => 'blockendar-event-venue' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php foreach ( $terms as $term ) : ?>
		<?php
		$term_id = $term->term_id;
		$virtual = (bool) get_term_meta( $term_id, 'blockendar_venue_virtual', true );
		$stream  = get_term_meta( $term_id, 'blockendar_venue_stream_url', true );

		$address_str = blockendar_venue_address( $term_id );
		$directions  = $show_directions ? blockendar_venue_directions_url( $term_id ) : '';
		$archive     = $link_name ? get_term_link( $term ) : '';
		$archive     = is_string( $archive ) ? $archive : '';

		// Built here so the span's content has no stray whitespace around it:
		// with no link, the markup is what it has always been.
		$name_html = '' !== $archive
			? sprintf( '<a href="%s">%s</a>', esc_url( $archive ), esc_html( $term->name ) )
			: esc_html( $term->name );
		?>
		<?php if ( $term !== reset( $terms ) ) : ?>
			<hr class="blockendar-event-venue__divider" />
		<?php endif; ?>
		<div class="blockendar-event-venue__body">
			<span class="blockendar-event-venue__name"><?php echo $name_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped where it is built, above. ?></span>

			<?php if ( $virtual ) : ?>
				<span class="blockendar-event-venue__virtual-badge"><?php esc_html_e( 'Online', 'blockendar' ); ?></span>
				<?php if ( $stream ) : ?>
					<a class="blockendar-event-venue__stream" href="<?php echo esc_url( $stream ); ?>">
						<?php esc_html_e( 'Join stream', 'blockendar' ); ?>
					</a>
				<?php endif; ?>
			<?php elseif ( $show_addr && $address_str ) : ?>
				<address class="blockendar-event-venue__address"><?php echo esc_html( $address_str ); ?></address>
			<?php endif; ?>

			<?php if ( '' !== $directions ) : ?>
				<a class="blockendar-event-venue__directions" href="<?php echo esc_url( $directions ); ?>">
					<?php esc_html_e( 'Get directions', 'blockendar' ); ?>
				</a>
			<?php endif; ?>

		</div>
	<?php endforeach; ?>
</div>
