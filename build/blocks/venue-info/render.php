<?php
/**
 * blockendar/venue-info render callback.
 *
 * @package Blockendar
 */
declare( strict_types=1 );

$post_id     = $block->context['postId'] ?? get_the_ID();
$show_map    = (bool) ( $attributes['showMap']     ?? false );
$show_phone  = (bool) ( $attributes['showPhone']   ?? true );
$show_website = (bool) ( $attributes['showWebsite'] ?? true );

$terms = get_the_terms( $post_id, 'event_venue' );
if ( is_wp_error( $terms ) || empty( $terms ) ) return;

$term    = $terms[0];
$term_id = $term->term_id;
$virtual = (bool) get_term_meta( $term_id, 'blockendar_venue_virtual', true );
$address = get_term_meta( $term_id, 'blockendar_venue_address',  true );
$addr2   = get_term_meta( $term_id, 'blockendar_venue_address2', true );
$city    = get_term_meta( $term_id, 'blockendar_venue_city',     true );
$state   = get_term_meta( $term_id, 'blockendar_venue_state',    true );
$postcode = get_term_meta( $term_id, 'blockendar_venue_postcode', true );
$country = get_term_meta( $term_id, 'blockendar_venue_country',  true );
$phone   = get_term_meta( $term_id, 'blockendar_venue_phone',    true );
$website = get_term_meta( $term_id, 'blockendar_venue_url',      true );
$lat     = (float) get_term_meta( $term_id, 'blockendar_venue_lat', true );
$lng     = (float) get_term_meta( $term_id, 'blockendar_venue_lng', true );
$stream  = get_term_meta( $term_id, 'blockendar_venue_stream_url', true );
?>
<div <?php echo get_block_wrapper_attributes( [ 'class' => 'blockendar-venue-info' ] ); ?>>
	<h3 class="blockendar-venue-info__name">
		<a href="<?php echo esc_url( get_term_link( $term ) ); ?>"><?php echo esc_html( $term->name ); ?></a>
	</h3>

	<?php if ( $virtual ) : ?>
		<span class="blockendar-venue-info__virtual"><?php esc_html_e( 'Online / Virtual', 'blockendar' ); ?></span>
		<?php if ( $stream ) : ?>
			<a class="blockendar-venue-info__stream wp-element-button" href="<?php echo esc_url( $stream ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Join stream', 'blockendar' ); ?>
			</a>
		<?php endif; ?>
	<?php else : ?>
		<address class="blockendar-venue-info__address">
			<?php if ( $address )  : ?><span><?php echo esc_html( $address ); ?></span><br><?php endif; ?>
			<?php if ( $addr2 )    : ?><span><?php echo esc_html( $addr2 ); ?></span><br><?php endif; ?>
			<?php
			$cityline = implode( ', ', array_filter( [ $city, $state, $postcode ] ) );
			if ( $cityline ) echo esc_html( $cityline ) . '<br>';
			if ( $country ) echo esc_html( $country );
			?>
		</address>

		<?php if ( $show_map && $lat && $lng ) : ?>
			<div class="blockendar-venue-info__map"
				data-lat="<?php echo esc_attr( (string) $lat ); ?>"
				data-lng="<?php echo esc_attr( (string) $lng ); ?>"
				data-name="<?php echo esc_attr( $term->name ); ?>">
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( $show_phone && $phone ) : ?>
		<a class="blockendar-venue-info__phone" href="tel:<?php echo esc_attr( preg_replace( '/\s+/', '', $phone ) ); ?>">
			<?php echo esc_html( $phone ); ?>
		</a>
	<?php endif; ?>

	<?php if ( $show_website && $website ) : ?>
		<a class="blockendar-venue-info__website" href="<?php echo esc_url( $website ); ?>" target="_blank" rel="noopener noreferrer">
			<?php echo esc_html( preg_replace( '#^https?://#', '', $website ) ); ?>
		</a>
	<?php endif; ?>
</div>
