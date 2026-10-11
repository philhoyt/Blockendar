<?php
/**
 * Integration coverage for the REST payload and pagination additions.
 *
 * A consumer of /blockendar/v1/events used to get UTC datetimes with nothing
 * to say they were UTC, no timezone, no Link header and no argument
 * descriptions. The additions are additive: everything that was there is
 * still there, with the same value, which the first test pins.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\DB\EventIndex;
use Blockendar\DB\IndexBuilder;
use Blockendar\DB\Schema;
use Blockendar\Meta\EventMeta;
use Blockendar\Recurrence\RuleRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

class RestPayloadTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		Schema::create_tables();

		foreach ( [ Schema::events_table(), Schema::type_terms_table(), Schema::recurrence_table() ] as $table ) {
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
		}

		( new EventMeta() )->register_meta();

		delete_option( 'blockendar_settings' );
		IndexBuilder::forget_dirty();
		( new EventIndex() )->flush_cache();

		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		delete_option( 'timezone_string' );
		delete_option( 'gmt_offset' );
		IndexBuilder::forget_dirty();
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Fixtures
	// -------------------------------------------------------------------------

	/**
	 * Create and index a published event in March 2027.
	 *
	 * @param string     $title Post title.
	 * @param array      $meta  Meta to override, without the prefix.
	 * @param array|null $rule  Repeat rule.
	 * @return int Post ID.
	 */
	private function make_event( string $title, array $meta = [], ?array $rule = null ): int {
		$meta += [
			'start_date' => '2027-03-09',
			'end_date'   => '2027-03-09',
			'start_time' => '19:00',
			'end_time'   => '21:00',
			'timezone'   => 'UTC',
		];

		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'publish',
				'post_title'  => $title,
			]
		);

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, 'blockendar_' . $key, $value );
		}

		if ( null !== $rule ) {
			( new RuleRepository() )->upsert( $post_id, $rule );
		}

		( new IndexBuilder() )->build_for_post( $post_id );
		( new EventIndex() )->flush_cache();

		return $post_id;
	}

	/**
	 * GET /blockendar/v1/events for March 2027.
	 *
	 * @param array $params Extra query parameters.
	 */
	private function collection( array $params = [] ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/blockendar/v1/events' );
		$request->set_query_params(
			$params + [
				'start' => '2027-03-01',
				'end'   => '2027-03-31',
			]
		);

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * GET /blockendar/v1/events/{id}.
	 */
	private function single( int $post_id ): array {
		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/blockendar/v1/events/' . $post_id ) )->get_data();
	}

	/**
	 * The Link header of a response, or '' when there is none.
	 */
	private function link_header( WP_REST_Response $response ): string {
		return (string) ( $response->get_headers()['Link'] ?? '' );
	}

	// -------------------------------------------------------------------------
	// Nothing was taken away
	// -------------------------------------------------------------------------

	public function test_every_field_of_the_existing_responses_is_still_there_with_its_value(): void {
		$post_id = $this->make_event( 'Gala Night' );

		$row = $this->collection()->get_data()[0];

		$this->assertIsInt( $row['id'] );
		$this->assertGreaterThan( 0, $row['id'] );

		$expected = [
			'post_id'        => $post_id,
			'title'          => 'Gala Night',
			'url'            => get_permalink( $post_id ),
			'start_datetime' => '2027-03-09 19:00:00',
			'end_datetime'   => '2027-03-09 21:00:00',
			'start_date'     => '2027-03-09',
			'end_date'       => '2027-03-09',
			'all_day'        => false,
			'ongoing'        => false,
			'status'         => 'scheduled',
			'venue_term_id'  => null,
			'type_term_ids'  => [],
		];

		$this->assertSame( $expected, array_intersect_key( $row, $expected ) );

		$event = $this->single( $post_id );

		$this->assertSame(
			[ 'id', 'title', 'slug', 'url', 'status', 'meta', 'recurrence', 'instances', 'venue', 'event_types', 'event_tags' ],
			array_keys( $event )
		);
		$this->assertSame( 'publish', $event['status'] );
		$this->assertSame( '2027-03-09', $event['meta']['start_date'] );
		$this->assertSame( '19:00', $event['meta']['start_time'] );
		$this->assertSame( 'UTC', $event['meta']['timezone'] );
		$this->assertNull( $event['recurrence'] );

		$instance = $event['instances'][0];
		$expected = [
			'post_id'        => $post_id,
			'start_datetime' => '2027-03-09 19:00:00',
			'end_datetime'   => '2027-03-09 21:00:00',
			'start_date'     => '2027-03-09',
			'end_date'       => '2027-03-09',
			'all_day'        => false,
			'ongoing'        => false,
			'status'         => 'scheduled',
			'recurrence_id'  => null,
		];

		$this->assertSame( $expected, array_intersect_key( $instance, $expected ) );
	}

	// -------------------------------------------------------------------------
	// timezone, start and end
	// -------------------------------------------------------------------------

	/**
	 * Chicago is six hours behind UTC on 9 March 2027 (summer time begins on
	 * the 14th), so 19:00 there is 01:00 UTC the next day.
	 */
	public function test_a_row_names_its_timezone_and_gives_the_same_instant_with_an_offset(): void {
		$this->make_event( 'Chicago Show', [ 'timezone' => 'America/Chicago' ] );

		$row = $this->collection()->get_data()[0];

		$this->assertSame( '2027-03-10 01:00:00', $row['start_datetime'], 'Precondition: the index is in UTC.' );
		$this->assertSame( 'America/Chicago', $row['timezone'] );
		$this->assertSame( '2027-03-09T19:00:00-06:00', $row['start'] );
		$this->assertSame( '2027-03-09T21:00:00-06:00', $row['end'] );
		$this->assertSame( strtotime( $row['start_datetime'] . ' UTC' ), strtotime( $row['start'] ) );
		$this->assertSame( strtotime( $row['end_datetime'] . ' UTC' ), strtotime( $row['end'] ) );
	}

	public function test_an_event_with_no_timezone_of_its_own_reports_the_sites(): void {
		update_option( 'timezone_string', 'Europe/London' );

		$this->make_event( 'London Talk', [ 'timezone' => '' ] );

		$row = $this->collection()->get_data()[0];

		$this->assertSame( 'Europe/London', $row['timezone'] );
		$this->assertSame( '2027-03-09T19:00:00+00:00', $row['start'] );
		$this->assertSame( strtotime( $row['start_datetime'] . ' UTC' ), strtotime( $row['start'] ) );
	}

	public function test_an_ongoing_event_has_no_end(): void {
		$this->make_event(
			'Open Exhibition',
			[
				'ongoing'  => true,
				'end_date' => '',
			]
		);

		$row = $this->collection()->get_data()[0];

		$this->assertTrue( $row['ongoing'] );
		$this->assertNull( $row['end_datetime'] );
		$this->assertNull( $row['end'] );
		$this->assertSame( '2027-03-09T19:00:00+00:00', $row['start'] );
	}

	public function test_each_instance_of_a_recurring_event_carries_the_same_three_fields(): void {
		$post_id = $this->make_event(
			'Weekly Class',
			[ 'timezone' => 'America/Chicago' ],
			[
				'frequency' => 'weekly',
				'count'     => 3,
			]
		);

		$event = $this->single( $post_id );

		$this->assertArrayNotHasKey( 'timezone', $event, 'A recurring event has no single start, so nothing is added at the top level.' );
		$this->assertCount( 3, $event['instances'] );

		foreach ( $event['instances'] as $instance ) {
			$this->assertSame( 'America/Chicago', $instance['timezone'] );
			$this->assertSame( strtotime( $instance['start_datetime'] . ' UTC' ), strtotime( $instance['start'] ) );
			$this->assertSame( strtotime( $instance['end_datetime'] . ' UTC' ), strtotime( $instance['end'] ) );
		}

		// The third class falls after the clocks go forward.
		$this->assertSame( '2027-03-09T19:00:00-06:00', $event['instances'][0]['start'] );
		$this->assertSame( '2027-03-23T19:00:00-05:00', $event['instances'][2]['start'] );

		$instances = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/blockendar/v1/events/{$post_id}/instances" ) )->get_data();

		$this->assertSame( 'America/Chicago', $instances[0]['timezone'] );
		$this->assertSame( '2027-03-09T19:00:00-06:00', $instances[0]['start'] );
	}

	// -------------------------------------------------------------------------
	// Link headers
	// -------------------------------------------------------------------------

	public function test_the_pages_of_a_collection_are_linked(): void {
		foreach ( [ 'One', 'Two', 'Three' ] as $title ) {
			$this->make_event( $title );
		}

		$first = $this->collection( [ 'per_page' => 1 ] );
		$link  = $this->link_header( $first );

		$this->assertSame( '3', $first->get_headers()['X-WP-TotalPages'] );
		$this->assertStringContainsString( 'rel="next"', $link );
		$this->assertStringNotContainsString( 'rel="prev"', $link );
		// The test site has plain permalinks, so the route is a rest_route query
		// argument; the assertion is on the page and the relation only.
		$this->assertMatchesRegularExpression( '#<[^>]*page=2[^>]*>; rel="next"#', $link );
		$this->assertStringContainsString( 'blockendar%2Fv1%2Fevents', $link );
		$this->assertStringContainsString( 'per_page=1', $link, 'The links keep the request\'s other parameters.' );
		$this->assertStringContainsString( 'start=2027-03-01', $link );

		$middle = $this->link_header(
			$this->collection(
				[
					'per_page' => 1,
					'page'     => 2,
				]
			)
		);

		$this->assertMatchesRegularExpression( '#page=1[^>]*>; rel="prev"#', $middle );
		$this->assertMatchesRegularExpression( '#page=3[^>]*>; rel="next"#', $middle );

		$last = $this->link_header(
			$this->collection(
				[
					'per_page' => 1,
					'page'     => 3,
				]
			)
		);

		$this->assertStringContainsString( 'rel="prev"', $last );
		$this->assertStringNotContainsString( 'rel="next"', $last );
		$this->assertMatchesRegularExpression( '#page=2[^>]*>; rel="prev"#', $last );
	}

	public function test_a_collection_that_fits_on_one_page_has_no_link_header(): void {
		$this->make_event( 'Only One' );

		$this->assertSame( '', $this->link_header( $this->collection() ) );
	}

	// -------------------------------------------------------------------------
	// OPTIONS
	// -------------------------------------------------------------------------

	public function test_the_collection_route_describes_every_argument_and_its_schema(): void {
		$data = rest_get_server()->dispatch( new WP_REST_Request( 'OPTIONS', '/blockendar/v1/events' ) )->get_data();

		$args = $data['endpoints'][0]['args'];

		$this->assertSame(
			[ 'start', 'end', 'venue', 'type', 'status', 'featured', 'per_page', 'page', 'orderby', 'order' ],
			array_keys( $args )
		);

		foreach ( $args as $name => $arg ) {
			$this->assertNotEmpty( $arg['description'] ?? '', "{$name} has a description." );
			$this->assertNotEmpty( $arg['type'] ?? '', "{$name} has a type." );
		}

		$this->assertSame( 'blockendar_occurrence', $data['schema']['title'] );

		foreach ( [ 'start_datetime', 'timezone', 'start', 'end', 'title', 'url', 'type_term_ids' ] as $field ) {
			$this->assertArrayHasKey( $field, $data['schema']['properties'] );
		}
	}

	public function test_the_single_and_instances_routes_have_a_schema(): void {
		$event = rest_get_server()->dispatch( new WP_REST_Request( 'OPTIONS', '/blockendar/v1/events/1' ) )->get_data();
		$this->assertSame( 'blockendar_event', $event['schema']['title'] );
		$this->assertSame( 'blockendar_instance', $event['schema']['properties']['instances']['items']['title'] );
		$this->assertNotEmpty( $event['endpoints'][0]['args']['id']['description'] );

		$instances = rest_get_server()->dispatch( new WP_REST_Request( 'OPTIONS', '/blockendar/v1/events/1/instances' ) )->get_data();
		$this->assertSame( 'blockendar_instance', $instances['schema']['title'] );
		$this->assertArrayHasKey( 'recurrence_id', $instances['schema']['properties'] );
	}
}
