<?php
/**
 * The plugin still works on top of the taxonomies, whatever they are named.
 *
 * The migration tests prove the data moves; these prove the code that reads
 * it keeps reading it. Every surface here hardcoded a taxonomy name before the
 * 2.0.0 rename — REST payloads, the calendar feed, the ICS export, the venue
 * and map blocks, related events, the calendar's archive auto-filter and the
 * filter blocks. Each test creates its terms through the class constants and
 * asserts the term's data comes out the other end, so a call site the rename
 * missed — one still asking for 'event_venue' once nothing registers it —
 * returns nothing and fails here by name.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\CPT\EventPostType;
use Blockendar\DB\EventIndex;
use Blockendar\DB\IndexBuilder;
use Blockendar\ICS\Exporter;
use Blockendar\REST\EventsController;
use Blockendar\Taxonomy\EventType;
use Blockendar\Taxonomy\Venue;
use WP_REST_Request;
use WP_UnitTestCase;

class TaxonomyRuntimeSurfacesTest extends WP_UnitTestCase {

	private int $venue_id;
	private int $type_id;
	private int $other_type_id;
	private int $event;
	private int $sibling;
	private int $stranger;
	private string $start;
	private string $end;

	public function set_up(): void {
		parent::set_up();
		_set_cron_array( [] );

		$this->venue_id = (int) wp_insert_term( 'Town Hall', Venue::TAXONOMY )['term_id'];
		update_term_meta( $this->venue_id, 'blockendar_venue_address', '1 Civic Square' );
		update_term_meta( $this->venue_id, 'blockendar_venue_city', 'Portland' );
		update_term_meta( $this->venue_id, 'blockendar_venue_lat', '45.5152' );
		update_term_meta( $this->venue_id, 'blockendar_venue_lng', '-122.6784' );

		$this->type_id       = (int) wp_insert_term( 'Concerts', EventType::TAXONOMY )['term_id'];
		$this->other_type_id = (int) wp_insert_term( 'Lectures', EventType::TAXONOMY )['term_id'];

		// Three upcoming events: the subject, a sibling of the same type, and a stranger.
		$this->event    = $this->make_event( 'Runtime Subject', 7, $this->type_id, $this->venue_id );
		$this->sibling  = $this->make_event( 'Runtime Sibling', 14, $this->type_id, null );
		$this->stranger = $this->make_event( 'Runtime Stranger', 21, $this->other_type_id, null );

		$this->start = gmdate( 'Y-m-d 00:00:00' );
		$this->end   = gmdate( 'Y-m-d 00:00:00', strtotime( '+60 days' ) );
	}

	private function make_event( string $title, int $days_ahead, int $type_id, ?int $venue_id ): int {
		$date = gmdate( 'Y-m-d', strtotime( "+{$days_ahead} days" ) );
		$id   = self::factory()->post->create(
			[
				'post_type'   => EventPostType::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
			]
		);

		foreach ( [
			'blockendar_start_date' => $date,
			'blockendar_end_date'   => $date,
			'blockendar_start_time' => '19:00',
			'blockendar_end_time'   => '21:00',
			'blockendar_timezone'   => 'UTC',
			'blockendar_status'     => 'scheduled',
		] as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}

		wp_set_object_terms( $id, [ $type_id ], EventType::TAXONOMY );
		if ( null !== $venue_id ) {
			wp_set_object_terms( $id, [ $venue_id ], Venue::TAXONOMY );
		}

		( new IndexBuilder() )->build_for_post( $id );

		return $id;
	}

	private function as_global_post( int $post_id ): void {
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		setup_postdata( $GLOBALS['post'] );
	}

	public function test_rest_event_payload_carries_the_venue_and_types(): void {
		$request = new WP_REST_Request( 'GET', '/blockendar/v1/events/' . $this->event );
		$request->set_param( 'id', $this->event );

		$data = ( new EventsController() )->get_event( $request )->get_data();

		$this->assertSame( 'Town Hall', $data['venue']['name'] );
		$this->assertSame( 'Portland', $data['venue']['city'] );
		$this->assertSame( [ 'Concerts' ], array_column( $data['event_types'], 'name' ) );
	}

	public function test_calendar_feed_carries_the_venue_summary(): void {
		$request = new WP_REST_Request( 'GET', '/blockendar/v1/calendar' );
		$request->set_param( 'start', $this->start );
		$request->set_param( 'end', $this->end );

		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );

		// Shape-agnostic on purpose: the venue summary must be present for the
		// subject event wherever the calendar payload places it.
		$mine = array_values( array_filter( $response->get_data(), fn( $i ) => str_contains( wp_json_encode( $i ), 'Runtime Subject' ) ) );
		$this->assertCount( 1, $mine );
		$this->assertStringContainsString( '"name":"Town Hall"', wp_json_encode( $mine[0] ) );
	}

	public function test_ics_export_resolves_the_venue_into_location(): void {
		$rows = ( new EventIndex() )->get_events_in_range( $this->start, $this->end );
		$ics  = ( new Exporter() )->generate_feed( $rows );

		$this->assertStringContainsString( 'LOCATION:Town Hall', $ics );
		$this->assertStringContainsString( 'Portland', $ics );
	}

	public function test_event_venue_block_renders_the_venue(): void {
		$this->as_global_post( $this->event );

		$html = do_blocks( '<!-- wp:blockendar/event-venue /-->' );

		$this->assertStringContainsString( 'blockendar-event-venue__name', $html );
		$this->assertStringContainsString( 'Town Hall', $html );
		$this->assertStringContainsString( 'Portland', $html );
	}

	public function test_event_map_block_places_the_venue_pin(): void {
		$this->as_global_post( $this->event );

		$html = do_blocks( '<!-- wp:blockendar/event-map /-->' );

		$this->assertNotSame( '', trim( $html ), 'a venue with coordinates must produce a map' );
		$this->assertStringContainsString( '45.5152', $html );
	}

	public function test_related_events_resolve_the_current_events_type(): void {
		$this->as_global_post( $this->event );

		$html = do_blocks( '<!-- wp:blockendar/events-query {"relatedTo":"type","perPage":5} --><!-- wp:post-title /--><!-- /wp:blockendar/events-query -->' );

		$this->assertStringContainsString( 'Runtime Sibling', $html, 'same type must be listed' );
		$this->assertStringNotContainsString( 'Runtime Stranger', $html, 'a different type must not' );
		$this->assertStringNotContainsString( 'Runtime Subject', $html, 'the current event is excluded from its own related list' );
	}

	public function test_calendar_auto_filters_on_the_type_archive(): void {
		$slug = get_term( $this->type_id )->slug;
		$this->go_to( '/?' . EventType::TAXONOMY . '=' . $slug );
		$this->assertTrue( is_tax( EventType::TAXONOMY ), 'precondition: the main query is the type archive' );

		$html = do_blocks( '<!-- wp:blockendar/calendar-view /-->' );

		$this->assertStringContainsString( 'data-type-ids="[' . $this->type_id . ']"', $html );
	}

	public function test_calendar_auto_filters_on_the_venue_archive(): void {
		$slug = get_term( $this->venue_id )->slug;
		$this->go_to( '/?' . Venue::TAXONOMY . '=' . $slug );
		$this->assertTrue( is_tax( Venue::TAXONOMY ), 'precondition: the main query is the venue archive' );

		$html = do_blocks( '<!-- wp:blockendar/calendar-view /-->' );

		$this->assertStringContainsString( 'data-venue-ids="[' . $this->venue_id . ']"', $html );
	}

	public function test_filter_blocks_list_the_terms(): void {
		$types = do_blocks( '<!-- wp:blockendar/filter-event-type {"showEmptyTerms":true} /-->' );
		$this->assertStringContainsString( 'Concerts', $types );
		$this->assertStringContainsString( 'Lectures', $types );

		$venues = do_blocks( '<!-- wp:blockendar/filter-venue {"showEmpty":true} /-->' );
		$this->assertStringContainsString( 'Town Hall', $venues );
	}
}
