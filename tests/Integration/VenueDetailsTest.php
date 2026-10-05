<?php
/**
 * Integration coverage for a venue's postal code, directions link and name link.
 *
 * The venue block printed a name and an address. A visitor who wanted to get
 * there copied the address into a maps app, and an address could not hold the
 * one part most maps need to tell two streets of the same name apart.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\Blocks\CalendarLinks;
use Blockendar\DB\EventIndex;
use Blockendar\DB\IndexBuilder;
use Blockendar\DB\Schema;
use Blockendar\ICS\Exporter;
use Blockendar\Meta\VenueMeta;
use WP_REST_Request;
use WP_UnitTestCase;

class VenueDetailsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		Schema::create_tables();

		// WP_UnitTestCase unregisters every meta key in tear_down().
		( new VenueMeta() )->register_meta();

		IndexBuilder::forget_dirty();
		( new EventIndex() )->flush_cache();
	}

	public function tear_down(): void {
		$_POST = [];
		wp_set_current_user( 0 );
		remove_all_filters( 'blockendar_venue_directions_url' );
		IndexBuilder::forget_dirty();
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Fixtures
	// -------------------------------------------------------------------------

	/**
	 * Create a venue.
	 *
	 * @param array $meta Term meta, without the blockendar_venue_ prefix.
	 * @return int Term ID.
	 */
	private function make_venue( array $meta = [] ): int {
		$term_id = self::factory()->term->create(
			[
				'taxonomy' => 'blockendar_event_venue',
				'name'     => 'City Hall',
				'slug'     => 'city-hall',
			]
		);

		$meta += [
			'address' => '1 Main Street',
			'city'    => 'Springfield',
			'state'   => 'IL',
			'country' => 'US',
		];

		foreach ( $meta as $key => $value ) {
			update_term_meta( $term_id, 'blockendar_venue_' . $key, $value );
		}

		return $term_id;
	}

	/**
	 * Create and index a published event at a venue.
	 *
	 * @param int $venue Venue term ID.
	 * @return int Post ID.
	 */
	private function make_event( int $venue ): int {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'publish',
				'post_title'  => 'Council Meeting',
				'meta_input'  => [
					'blockendar_start_date' => '2027-03-09',
					'blockendar_end_date'   => '2027-03-09',
					'blockendar_start_time' => '19:00',
					'blockendar_end_time'   => '21:00',
					'blockendar_timezone'   => 'UTC',
				],
			]
		);

		wp_set_object_terms( $post_id, [ $venue ], 'blockendar_event_venue' );
		( new IndexBuilder() )->build_for_post( $post_id );
		( new EventIndex() )->flush_cache();

		return $post_id;
	}

	/**
	 * Render the venue block for an event.
	 *
	 * @param int   $post_id Event post ID.
	 * @param array $attrs   Block attributes.
	 */
	private function block( int $post_id, array $attrs = [] ): string {
		return ( new \WP_Block(
			[
				'blockName' => 'blockendar/event-venue',
				'attrs'     => $attrs,
			],
			[ 'postId' => $post_id ]
		) )->render();
	}

	/**
	 * The href of the directions link in some markup, decoded, or null.
	 *
	 * @param string $html Block output.
	 */
	private function directions_href( string $html ): ?string {
		if ( ! preg_match( '/<a[^>]*class="[^"]*blockendar-event-venue__directions[^"]*"[^>]*>/', $html, $tag ) ) {
			return null;
		}

		preg_match( '/href="([^"]*)"/', $tag[0], $href );

		return html_entity_decode( $href[1] ?? '' );
	}

	// -------------------------------------------------------------------------
	// Postal code
	// -------------------------------------------------------------------------

	public function test_a_postal_code_typed_on_the_venue_screen_is_saved(): void {
		$venue = $this->make_venue();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$_POST['blockendar_venue_postal_code'] = " 62701<script>\n";
		( new VenueMeta() )->save_venue_fields( $venue );

		$this->assertSame( '62701', get_term_meta( $venue, 'blockendar_venue_postal_code', true ) );
	}

	public function test_the_venue_screens_have_a_postal_code_field(): void {
		$venue = $this->make_venue( [ 'postal_code' => '62701' ] );
		$meta  = new VenueMeta();

		ob_start();
		$meta->render_venue_add_fields();
		$add = (string) ob_get_clean();

		ob_start();
		$meta->render_venue_edit_fields( get_term( $venue ) );
		$edit = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="blockendar_venue_postal_code"', $add );
		$this->assertMatchesRegularExpression( '/name="blockendar_venue_postal_code"\s+value="62701"/', $edit );
	}

	public function test_the_venue_block_prints_the_postal_code_in_the_address(): void {
		$post_id = $this->make_event( $this->make_venue( [ 'postal_code' => '62701' ] ) );

		$this->assertStringContainsString( '1 Main Street, Springfield, IL 62701, US', $this->block( $post_id ) );
	}

	public function test_an_address_without_a_postal_code_reads_as_it_did(): void {
		$post_id = $this->make_event( $this->make_venue() );

		$this->assertStringContainsString( '>1 Main Street, Springfield, IL, US<', $this->block( $post_id ) );
	}

	public function test_a_postal_code_with_no_state_stands_on_its_own(): void {
		$post_id = $this->make_event(
			$this->make_venue(
				[
					'address'     => '10 Downing Street',
					'city'        => 'London',
					'state'       => '',
					'country'     => 'GB',
					'postal_code' => 'SW1A 2AA',
				]
			)
		);

		$this->assertStringContainsString( '10 Downing Street, London, SW1A 2AA, GB', $this->block( $post_id ) );
	}

	public function test_the_feed_gives_the_postal_code_in_the_location(): void {
		$post_id = $this->make_event( $this->make_venue( [ 'postal_code' => '62701' ] ) );

		$ics = (string) ( new Exporter() )->generate_single( $post_id );

		$this->assertStringContainsString( 'LOCATION:City Hall\, 1 Main Street\, Springfield\, IL 62701\, US', str_replace( "\r\n ", '', $ics ) );
	}

	public function test_the_add_to_calendar_links_give_the_postal_code_in_the_location(): void {
		$post_id = $this->make_event( $this->make_venue( [ 'postal_code' => '62701' ] ) );

		$this->assertSame( 'City Hall, 1 Main Street, Springfield, IL 62701, US', CalendarLinks::location( $post_id ) );
	}

	public function test_the_rest_api_gives_the_postal_code_with_the_venue(): void {
		$post_id = $this->make_event( $this->make_venue( [ 'postal_code' => '62701' ] ) );

		do_action( 'rest_api_init' );

		$event = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/blockendar/v1/events/{$post_id}" ) )->get_data();

		$this->assertSame( '62701', $event['venue']['postal_code'] );
	}

	// -------------------------------------------------------------------------
	// Directions
	// -------------------------------------------------------------------------

	/**
	 * The format is Google's documented Maps URL for directions: api=1 and a
	 * destination, as "latitude,longitude" or an address.
	 */
	public function test_a_venue_with_coordinates_links_to_directions_to_them(): void {
		$post_id = $this->make_event(
			$this->make_venue(
				[
					'lat' => 39.7817,
					'lng' => -89.6501,
				]
			)
		);

		$html = $this->block( $post_id, [ 'showDirections' => true ] );

		$this->assertSame( 'https://www.google.com/maps/dir/?api=1&destination=39.7817,-89.6501', $this->directions_href( $html ) );
		$this->assertStringContainsString( 'Get directions', $html );
	}

	public function test_a_venue_without_coordinates_links_to_directions_to_its_address(): void {
		$post_id = $this->make_event( $this->make_venue( [ 'postal_code' => '62701' ] ) );

		$href = $this->directions_href( $this->block( $post_id, [ 'showDirections' => true ] ) );

		$this->assertSame(
			'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode( '1 Main Street, Springfield, IL 62701, US' ),
			$href
		);
	}

	public function test_a_virtual_venue_has_no_directions(): void {
		$post_id = $this->make_event(
			$this->make_venue(
				[
					'virtual' => true,
					'lat'     => 39.7817,
					'lng'     => -89.6501,
				]
			)
		);

		$this->assertNull( $this->directions_href( $this->block( $post_id, [ 'showDirections' => true ] ) ) );
	}

	public function test_a_venue_with_nowhere_to_go_has_no_directions(): void {
		$post_id = $this->make_event(
			$this->make_venue(
				[
					'address' => '',
					'city'    => '',
					'state'   => '',
					'country' => '',
				]
			)
		);

		$this->assertNull( $this->directions_href( $this->block( $post_id, [ 'showDirections' => true ] ) ) );
	}

	public function test_a_site_can_send_directions_to_another_maps_service(): void {
		$venue   = $this->make_venue();
		$post_id = $this->make_event( $venue );
		$seen    = [];

		add_filter(
			'blockendar_venue_directions_url',
			static function ( $url, $term_id ) use ( &$seen ) {
				$seen = [ $url, $term_id ];

				return 'https://maps.example.org/?to=city-hall';
			},
			10,
			2
		);

		$href = $this->directions_href( $this->block( $post_id, [ 'showDirections' => true ] ) );

		$this->assertSame( 'https://maps.example.org/?to=city-hall', $href );
		$this->assertStringStartsWith( 'https://www.google.com/maps/dir/', $seen[0] );
		$this->assertSame( $venue, $seen[1] );
	}

	/**
	 * A filter's answer is printed in an href, so only a web address will do.
	 */
	public function test_a_filtered_address_that_is_not_a_web_address_gives_no_link(): void {
		$post_id = $this->make_event( $this->make_venue() );

		add_filter( 'blockendar_venue_directions_url', static fn() => 'javascript:alert(1)' );

		$this->assertNull( $this->directions_href( $this->block( $post_id, [ 'showDirections' => true ] ) ) );
	}

	// -------------------------------------------------------------------------
	// The name as a link
	// -------------------------------------------------------------------------

	public function test_the_venue_name_can_link_to_the_venues_events(): void {
		$venue   = $this->make_venue();
		$post_id = $this->make_event( $venue );

		$html = $this->block( $post_id, [ 'linkName' => true ] );

		$this->assertMatchesRegularExpression(
			'#<a href="' . preg_quote( esc_url( get_term_link( $venue ) ), '#' ) . '">City Hall</a>#',
			$html
		);
	}

	// -------------------------------------------------------------------------
	// Blocks already on a site
	// -------------------------------------------------------------------------

	/**
	 * A block saved before these options existed has neither attribute.
	 */
	public function test_a_block_with_neither_option_has_no_links(): void {
		$post_id = $this->make_event(
			$this->make_venue(
				[
					'lat' => 39.7817,
					'lng' => -89.6501,
				]
			)
		);

		$html = $this->block( $post_id );

		$this->assertStringNotContainsString( '<a ', $html );
		$this->assertStringContainsString( '<span class="blockendar-event-venue__name">City Hall</span>', $html );
	}
}
