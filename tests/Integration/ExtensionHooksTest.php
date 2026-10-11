<?php
/**
 * Integration coverage for the extension points added in 2.4.0.
 *
 * Each hook is a compatibility promise, so each is pinned here from the
 * outside: a filter is attached the way a site would attach it, and the
 * listing, the response or the feed is read back.
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
use WP_UnitTestCase;

class ExtensionHooksTest extends WP_UnitTestCase {

	private const TEMPLATE = '<!-- wp:blockendar/event-template --><!-- wp:post-title /--><!-- /wp:blockendar/event-template -->';

	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		Schema::create_tables();

		foreach ( [ Schema::events_table(), Schema::type_terms_table(), Schema::recurrence_table() ] as $table ) {
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
		}

		// WP_UnitTestCase unregisters every meta key in tear_down().
		( new EventMeta() )->register_meta();

		delete_option( 'blockendar_settings' );
		IndexBuilder::forget_dirty();
		( new EventIndex() )->flush_cache();

		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		unset( $GLOBALS['blockendar_current_occurrence'] );
		$_GET = [];
		wp_set_current_user( 0 );
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
	 * Render the events list block.
	 *
	 * @param string $attrs Block attributes, as JSON.
	 */
	private function listing( string $attrs = '{"perPage":20}' ): string {
		return do_blocks( '<!-- wp:blockendar/events-query ' . $attrs . ' -->' . self::TEMPLATE . '<!-- /wp:blockendar/events-query -->' );
	}

	/**
	 * A request to the calendar route for March 2027.
	 *
	 * @param array $params Extra query parameters.
	 */
	private function calendar_request( array $params = [] ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/blockendar/v1/calendar' );
		$request->set_query_params(
			$params + [
				'start' => '2027-03-01T00:00:00',
				'end'   => '2027-04-01T00:00:00',
			]
		);

		return $request;
	}

	/**
	 * The titles in the calendar's JSON for March 2027.
	 *
	 * @return string[]
	 */
	private function calendar_titles(): array {
		$titles = array_column( rest_get_server()->dispatch( $this->calendar_request() )->get_data(), 'title' );
		sort( $titles );

		return $titles;
	}

	/**
	 * The iCalendar feed for March 2027.
	 */
	private function feed(): string {
		return (string) rest_get_server()->dispatch( $this->calendar_request( [ 'format' => 'ics' ] ) )->get_data();
	}

	/**
	 * The REST collection for March 2027.
	 */
	private function collection(): \WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/blockendar/v1/events' );
		$request->set_query_params(
			[
				'start' => '2027-03-01',
				'end'   => '2027-03-31',
			]
		);

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Render the status block for an event.
	 *
	 * @param int $post_id Event post ID.
	 */
	private function badge( int $post_id ): string {
		return ( new \WP_Block(
			[
				'blockName' => 'blockendar/event-status',
				'attrs'     => [],
			],
			[ 'postId' => $post_id ]
		) )->render();
	}

	// -------------------------------------------------------------------------
	// blockendar_event_query_filters
	// -------------------------------------------------------------------------

	/**
	 * One filter, every listing. The count beside a page of results goes
	 * through the same filter, so the pages offered match the pages there are.
	 */
	public function test_a_query_filter_reaches_every_listing_and_its_count(): void {
		$this->make_event( 'Plain Evening' );
		$this->make_event( 'Gala Night', [ 'featured' => true ] );

		$this->assertStringContainsString( 'Plain Evening', $this->listing(), 'Precondition: both events list.' );

		$contexts = [];

		add_filter(
			'blockendar_event_query_filters',
			static function ( array $filters, string $start, string $end, string $context ) use ( &$contexts ): array {
				$contexts[ $context ] = true;
				$filters['featured']  = true;

				return $filters;
			},
			10,
			4
		);

		// The block, one to a page: a count that ignored the filter would offer a second page.
		$html = $this->listing( '{"perPage":1,"showPagination":true}' );

		$this->assertStringContainsString( 'Gala Night', $html );
		$this->assertStringNotContainsString( 'Plain Evening', $html );
		$this->assertStringNotContainsString( 'page-numbers', $html, 'One matching event, one to a page: nothing to paginate.' );

		// The REST collection and its total.
		$response = $this->collection();

		$this->assertSame( [ 'Gala Night' ], array_column( $response->get_data(), 'title' ) );
		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );

		// The calendar's JSON.
		$this->assertSame( [ 'Gala Night' ], $this->calendar_titles() );

		// The feed.
		$feed = $this->feed();

		$this->assertStringContainsString( 'SUMMARY:Gala Night', $feed );
		$this->assertStringNotContainsString( 'Plain Evening', $feed );

		ksort( $contexts );
		$this->assertSame( [ 'block', 'calendar', 'ics', 'rest' ], array_keys( $contexts ), 'Each caller names itself.' );
	}

	/**
	 * A filtered query must not be served from the cache of the unfiltered
	 * one, or the other way round.
	 */
	public function test_a_filtered_query_does_not_share_a_cache_entry_with_the_unfiltered_one(): void {
		$this->make_event( 'Plain Evening' );
		$this->make_event( 'Gala Night', [ 'featured' => true ] );

		$index = new EventIndex();
		$range = [ '2027-03-01 00:00:00', '2027-04-01 00:00:00' ];

		$this->assertCount( 2, $index->get_events_in_range( ...$range ), 'Precondition: the unfiltered query is cached with two rows.' );

		$force = static function ( array $filters ): array {
			$filters['featured'] = true;

			return $filters;
		};

		add_filter( 'blockendar_event_query_filters', $force );
		$this->assertCount( 1, $index->get_events_in_range( ...$range ) );
		$this->assertSame( 1, $index->count_events_in_range( ...$range ) );

		remove_filter( 'blockendar_event_query_filters', $force );
		$this->assertCount( 2, $index->get_events_in_range( ...$range ) );
		$this->assertSame( 2, $index->count_events_in_range( ...$range ) );
	}

	// -------------------------------------------------------------------------
	// blockendar_event_statuses
	// -------------------------------------------------------------------------

	/**
	 * A status the filter adds is one the editor can save (the meta schema and
	 * the sanitizer accept it), one the index stores, and one the block shows.
	 */
	public function test_a_status_added_by_filter_can_be_saved_and_is_shown(): void {
		add_filter( 'blockendar_event_statuses', static fn( array $statuses ): array => $statuses + [ 'waitlist' => 'Waiting list' ] );

		// The schema is read at registration.
		unregister_post_meta( 'blockendar_event', 'blockendar_status' );
		( new EventMeta() )->register_meta();

		$post_id = $this->make_event( 'Full House' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$request = new WP_REST_Request( 'POST', '/wp/v2/blockendar-events/' . $post_id );
		$request->set_body_params( [ 'meta' => [ 'blockendar_status' => 'waitlist' ] ] );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status(), 'The editor saves through this route; the schema must accept the status.' );
		$this->assertSame( 'waitlist', get_post_meta( $post_id, 'blockendar_status', true ) );

		( new IndexBuilder() )->build_for_post( $post_id );
		( new EventIndex() )->flush_cache();

		$rows = ( new EventIndex() )->get_by_post_id( $post_id );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'waitlist', $rows[0]->status );

		$badge = $this->badge( $post_id );

		$this->assertStringContainsString( 'Waiting list', $badge );
		$this->assertStringContainsString( 'blockendar-status--waitlist', $badge );
	}

	public function test_the_editor_is_told_the_filtered_statuses(): void {
		add_filter( 'blockendar_event_statuses', static fn( array $statuses ): array => $statuses + [ 'waitlist' => 'Waiting list' ] );

		$this->assertContains(
			[
				'value' => 'waitlist',
				'label' => 'Waiting list',
			],
			\Blockendar\Blocks\BlockRegistrar::editor_statuses()
		);
	}

	/**
	 * The index stores the status in varchar(20). A longer key is dropped
	 * from the list, so nothing can save it, rather than being cut short on
	 * the way into the table and never matching the meta again.
	 */
	public function test_a_filtered_status_longer_than_twenty_characters_is_rejected_not_truncated(): void {
		$too_long = 'a_status_name_that_is_far_too_long';

		add_filter(
			'blockendar_event_statuses',
			static fn( array $statuses ): array => $statuses + [
				$too_long        => 'Too long',
				'Waitlist Open!' => 'Needs sanitising',
			]
		);

		$statuses = EventMeta::statuses();

		$this->assertArrayNotHasKey( $too_long, $statuses );
		$this->assertArrayHasKey( 'waitlistopen', $statuses, 'Keys go through sanitize_key().' );
		$this->assertSame( 'scheduled', ( new EventMeta() )->sanitize_status( $too_long ) );

		$post_id = $this->make_event( 'Oversold', [ 'status' => $too_long ] );
		$rows    = ( new EventIndex() )->get_by_post_id( $post_id );

		$this->assertSame( 'scheduled', $rows[0]->status, 'Nothing of the long name reaches the index.' );
	}

	public function test_scheduled_cannot_be_filtered_away(): void {
		add_filter( 'blockendar_event_statuses', static fn(): array => [ 'cancelled' => 'Cancelled' ] );

		$this->assertSame( [ 'scheduled', 'cancelled' ], array_keys( EventMeta::statuses() ) );
	}

	// -------------------------------------------------------------------------
	// blockendar_ics_event_lines
	// -------------------------------------------------------------------------

	public function test_a_line_added_to_an_event_appears_in_the_feed_folded(): void {
		$this->make_event( 'Gala Night' );

		// 118 octets: has to be folded once.
		$added = 'X-BLOCKENDAR-NOTE:' . str_repeat( 'abcdefghij', 10 );

		add_filter(
			'blockendar_ics_event_lines',
			static function ( array $lines, object $row ) use ( $added ): array {
				$lines[] = $added . '-' . $row->post_id;

				return $lines;
			},
			10,
			2
		);

		$feed = $this->feed();

		foreach ( explode( "\r\n", $feed ) as $line ) {
			$this->assertLessThanOrEqual( 75, strlen( $line ), 'Every physical line of the feed is within RFC 5545\'s limit.' );
		}

		$this->assertStringNotContainsString( $added, $feed, 'Precondition: the line really was folded.' );

		$unfolded = str_replace( "\r\n ", '', $feed );
		$position = strpos( $unfolded, "\r\n" . $added );

		$this->assertNotFalse( $position, 'Unfolded, the line is whole.' );
		$this->assertGreaterThan( strpos( $unfolded, 'BEGIN:VEVENT' ), $position );
		$this->assertLessThan( strpos( $unfolded, 'END:VEVENT' ), $position, 'A line appended to the array lands inside the event.' );
	}

	// -------------------------------------------------------------------------
	// blockendar_calendar_event and blockendar_rest_event
	// -------------------------------------------------------------------------

	public function test_the_calendar_and_rest_payloads_can_be_changed_per_event(): void {
		$post_id = $this->make_event( 'Gala Night' );

		add_filter(
			'blockendar_calendar_event',
			static function ( array $event, object $row ): array {
				$event['extendedProps']['note'] = 'calendar ' . $row->post_id;

				return $event;
			},
			10,
			2
		);

		add_filter(
			'blockendar_rest_event',
			static function ( array $data, object $row ): array {
				$data['note'] = 'rest ' . $row->post_id;

				return $data;
			},
			10,
			2
		);

		$calendar = rest_get_server()->dispatch( $this->calendar_request() )->get_data();
		$this->assertSame( 'calendar ' . $post_id, $calendar[0]['extendedProps']['note'] );

		$collection = $this->collection()->get_data();
		$this->assertSame( 'rest ' . $post_id, $collection[0]['note'] );
	}

	// -------------------------------------------------------------------------
	// blockendar_index_built
	// -------------------------------------------------------------------------

	public function test_index_built_fires_once_per_build_for_single_and_recurring_events(): void {
		$fired = [];

		add_action(
			'blockendar_index_built',
			static function ( int $post_id ) use ( &$fired ): void {
				$fired[] = $post_id;
			}
		);

		$single = $this->make_event( 'Single' );
		$series = $this->make_event(
			'Series',
			[],
			[
				'frequency' => 'weekly',
				'count'     => 3,
			]
		);
		$draft  = self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'draft',
			]
		);

		$builder = new IndexBuilder();

		$fired = [];
		$builder->build_for_post( $single );
		$this->assertSame( [ $single ], $fired, 'Once for a single event.' );

		$fired = [];
		$builder->build_for_post( $series );
		$this->assertSame( [ $series ], $fired, 'Once for a recurring event, whose rows the Generator writes.' );
		$this->assertCount( 3, ( new EventIndex() )->get_by_post_id( $series ), 'Precondition: the recurring path ran.' );

		$fired = [];
		$builder->build_for_post( $draft );
		$this->assertSame( [], $fired, 'Removing an unpublished event\'s rows is not a build.' );
	}
}
