<?php
/**
 * Integration coverage for whether two requests a moment apart share a cached
 * range query.
 *
 * Listings ask the index for "from now" and "until a year from now", and the
 * bounds went into the cache key to the second. A second later the same
 * listing asked a different question, so with a persistent object cache every
 * request missed, and left behind an entry nothing would ask for again.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\Blocks\Cutoff;
use Blockendar\DB\EventIndex;
use Blockendar\DB\Schema;
use WP_REST_Request;
use WP_UnitTestCase;

class IndexCacheExpiryTest extends WP_UnitTestCase {

	private const TEMPLATE = '<!-- wp:blockendar/event-template --><!-- wp:post-title /--><!-- /wp:blockendar/event-template -->';

	private EventIndex $index;

	/**
	 * Ten seconds into a minute, so a second later is the same minute.
	 *
	 * @var \DateTimeImmutable
	 */
	private \DateTimeImmutable $now;

	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		Schema::create_tables();

		$this->index = new EventIndex();

		foreach ( [ Schema::events_table(), Schema::type_terms_table() ] as $table ) {
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
		}

		delete_option( 'blockendar_settings' );

		$this->now = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->setTime( 12, 0, 10 );

		Cutoff::freeze( $this->now );
		$this->index->flush_cache();
	}

	public function tear_down(): void {
		Cutoff::freeze( null );
		$_GET = [];
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Fixtures
	// -------------------------------------------------------------------------

	/**
	 * Index a published event relative to the frozen moment.
	 *
	 * @param string $title Post title, used to find it in the output.
	 * @param string $start Modifier for the start.
	 * @param string $end   Modifier for the end.
	 */
	private function seed( string $title, string $start, string $end ): void {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'publish',
				'post_title'  => $title,
			]
		);

		$starts = $this->now->modify( $start );
		$ends   = $this->now->modify( $end );

		$this->index->insert(
			[
				'post_id'        => $post_id,
				'start_datetime' => $starts->format( 'Y-m-d H:i:s' ),
				'end_datetime'   => $ends->format( 'Y-m-d H:i:s' ),
				'start_date'     => $starts->format( 'Y-m-d' ),
				'end_date'       => $ends->format( 'Y-m-d' ),
			]
		);
	}

	/**
	 * Count the range queries that reach the database while a callback runs.
	 *
	 * @param callable $callback What to run.
	 */
	private function range_queries( callable $callback ): int {
		$count   = 0;
		$table   = Schema::events_table();
		$counter = static function ( $query ) use ( &$count, $table ) {
			if ( preg_match( '/FROM\s+`?' . preg_quote( $table, '/' ) . '`?\s+e\b/i', (string) $query ) ) {
				++$count;
			}

			return $query;
		};

		add_filter( 'query', $counter );
		$callback();
		remove_filter( 'query', $counter );

		return $count;
	}

	/**
	 * Run something now, and again a second later, and count the range
	 * queries the second run needed.
	 *
	 * @param callable $callback What to run.
	 * @return array{ 0: int, 1: int } Queries on the first run and on the second.
	 */
	private function a_second_apart( callable $callback ): array {
		$first = $this->range_queries( $callback );

		Cutoff::freeze( $this->now->modify( '+1 second' ) );

		return [ $first, $this->range_queries( $callback ) ];
	}

	/**
	 * Render the events list block.
	 *
	 * @param string $attrs Block attributes, as JSON.
	 */
	private function events_query( string $attrs = '' ): string {
		return do_blocks(
			'<!-- wp:blockendar/events-query ' . $attrs . ' -->' . self::TEMPLATE . '<!-- /wp:blockendar/events-query -->'
		);
	}

	// -------------------------------------------------------------------------
	// The same listing, a second later
	// -------------------------------------------------------------------------

	/**
	 * @return array<string, array{string}>
	 */
	public function listings(): array {
		return [
			'until the end of the day' => [ '' ],
			'until the event ends'     => [ '{"hideAfter":"end"}' ],
			'for some hours after'     => [ '{"hideAfter":"hours","hideAfterHours":2}' ],
			'past events'              => [ '{"showPast":true,"hideAfter":"end"}' ],
		];
	}

	/**
	 * @dataProvider listings
	 *
	 * @param string $attrs Block attributes, as JSON.
	 */
	public function test_an_events_list_rendered_a_second_later_reuses_the_query( string $attrs ): void {
		$this->seed( 'Tomorrow Evening', '+1 day', '+1 day 2 hours' );
		$this->seed( 'Last Week', '-7 days', '-7 days +2 hours' );

		[ $first, $second ] = $this->a_second_apart( fn() => $this->events_query( $attrs ) );

		$this->assertGreaterThan( 0, $first, 'Precondition: the first render asks the database.' );
		$this->assertSame( 0, $second );
	}

	public function test_the_calendars_fallback_list_rendered_a_second_later_reuses_the_query(): void {
		$this->seed( 'Tomorrow Evening', '+1 day', '+1 day 2 hours' );

		[ $first, $second ] = $this->a_second_apart( fn() => do_blocks( '<!-- wp:blockendar/calendar-view /-->' ) );

		$this->assertGreaterThan( 0, $first, 'Precondition: the first render asks the database.' );
		$this->assertSame( 0, $second );
	}

	public function test_the_events_route_asked_a_second_later_reuses_the_queries(): void {
		$this->seed( 'Tomorrow Evening', '+1 day', '+1 day 2 hours' );

		do_action( 'rest_api_init' );

		$ask = function () {
			$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/blockendar/v1/events' ) );

			$this->assertSame( 200, $response->get_status() );
			$this->assertCount( 1, $response->get_data() );
		};

		[ $first, $second ] = $this->a_second_apart( $ask );

		$this->assertGreaterThan( 0, $first, 'Precondition: the first request asks the database.' );
		$this->assertSame( 0, $second );
	}

	// -------------------------------------------------------------------------
	// What rounding the bounds must not cost
	// -------------------------------------------------------------------------

	/**
	 * "Now" is rounded down, never up. An event about to start, with no end
	 * time, is indexed as ending when it starts; rounding up would have put
	 * the bound past it and dropped it from the list before it began.
	 */
	public function test_an_event_starting_within_the_current_minute_is_still_upcoming(): void {
		$this->seed( 'Doors In Twenty Seconds', '+20 seconds', '+20 seconds' );

		$html = $this->events_query( '{"hideAfter":"end"}' );

		$this->assertStringContainsString( 'Doors In Twenty Seconds', $html );
	}

	/**
	 * The price of the rounding, stated: under the "until it ends" rule an
	 * event stays in the list for the rest of the minute it ended in.
	 */
	public function test_an_event_that_just_ended_leaves_when_the_minute_turns(): void {
		$this->seed( 'Ended Five Seconds Ago', '-1 hour', '-5 seconds' );

		$this->assertStringContainsString( 'Ended Five Seconds Ago', $this->events_query( '{"hideAfter":"end"}' ) );
		$this->assertStringNotContainsString( 'Ended Five Seconds Ago', $this->events_query( '{"hideAfter":"end","showPast":true}' ), 'It is in one list or the other, never both.' );

		Cutoff::freeze( $this->now->modify( '+1 minute' ) );

		$this->assertStringNotContainsString( 'Ended Five Seconds Ago', $this->events_query( '{"hideAfter":"end"}' ) );
		$this->assertStringContainsString( 'Ended Five Seconds Ago', $this->events_query( '{"hideAfter":"end","showPast":true}' ) );
	}

	public function test_the_bounds_are_whole_minutes_and_whole_days(): void {
		$this->assertSame( $this->now->format( 'Y-m-d' ) . ' 12:00:00', Cutoff::now() );
		$this->assertSame( $this->now->format( 'Y-m-d' ) . ' 12:00:00', Cutoff::for_rule( 'end' ) );
		$this->assertSame( $this->now->format( 'Y-m-d' ) . ' 10:00:00', Cutoff::for_rule( 'hours', 2 ) );
		$this->assertSame( $this->now->modify( '+1 year' )->format( 'Y-m-d' ) . ' 00:00:00', Cutoff::ahead( 'P1Y' ) );
		$this->assertSame( $this->now->modify( '+3 years' )->format( 'Y-m-d' ) . ' 00:00:00', Cutoff::ahead( 'P3Y' ) );
	}

	public function test_a_minute_later_is_a_different_question(): void {
		$this->seed( 'Tomorrow Evening', '+1 day', '+1 day 2 hours' );

		$this->events_query( '{"hideAfter":"end"}' );

		Cutoff::freeze( $this->now->modify( '+1 minute' ) );

		$this->assertGreaterThan( 0, $this->range_queries( fn() => $this->events_query( '{"hideAfter":"end"}' ) ) );
	}
}
