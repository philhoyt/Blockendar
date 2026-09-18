<?php
/**
 * Integration coverage for the event type exclusion filter.
 *
 * `exclude_type_term_id` is the negation of the existing include filter,
 * built on the same junction table. These tests pin that it binds as
 * parameters, that the row query and the count agree, and how overlap with
 * the include list resolves in the Events Query block.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\DB\EventIndex;
use Blockendar\DB\Schema;
use WP_UnitTestCase;

class ExcludeTypeFilterTest extends WP_UnitTestCase {

	private EventIndex $index;

	private int $exhibit_term;

	private int $talk_term;

	private int $exhibit_id;

	private int $talk_id;

	private int $untyped_id;

	private const WINDOW = [ '2025-09-01 00:00:00', '2025-10-01 00:00:00' ];

	public function set_up(): void {
		parent::set_up();

		Schema::create_tables();
		$this->index = new EventIndex();
		$this->index->flush_cache();

		global $wpdb;
		$events   = Schema::events_table();
		$junction = Schema::type_terms_table();
		$wpdb->query( "DELETE FROM {$events}" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "DELETE FROM {$junction}" ); // phpcs:ignore WordPress.DB

		$this->exhibit_term = self::factory()->term->create( [ 'taxonomy' => 'event_type' ] );
		$this->talk_term    = self::factory()->term->create( [ 'taxonomy' => 'event_type' ] );

		$this->exhibit_id = $this->seed_event( '2025-09-10', [ $this->exhibit_term ] );
		$this->talk_id    = $this->seed_event( '2025-09-11', [ $this->talk_term ] );
		$this->untyped_id = $this->seed_event( '2025-09-12', [] );
	}

	/**
	 * Insert an indexed occurrence with the given event type terms.
	 *
	 * @param string $date     Y-m-d occurrence date.
	 * @param int[]  $type_ids Event type term IDs.
	 * @return int Post ID.
	 */
	private function seed_event( string $date, array $type_ids ): int {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'publish',
				'post_title'  => "Event {$date}",
			]
		);

		$this->index->insert(
			[
				'post_id'        => $post_id,
				'start_datetime' => "{$date} 09:00:00",
				'end_datetime'   => "{$date} 10:00:00",
				'start_date'     => $date,
				'end_date'       => $date,
				'all_day'        => 0,
				'status'         => 'scheduled',
				'type_term_ids'  => $type_ids,
			]
		);

		return $post_id;
	}

	/**
	 * @param object[] $rows Index rows.
	 * @return int[] Post IDs in result order.
	 */
	private function post_ids( array $rows ): array {
		return array_map( fn( $row ) => (int) $row->post_id, $rows );
	}

	public function test_excluding_a_type_drops_only_events_carrying_it(): void {
		$rows = $this->index->get_events_in_range(
			self::WINDOW[0],
			self::WINDOW[1],
			[ 'exclude_type_term_id' => $this->exhibit_term ]
		);

		$this->assertSame( [ $this->talk_id, $this->untyped_id ], $this->post_ids( $rows ) );
	}

	public function test_untyped_events_survive_any_exclusion(): void {
		$rows = $this->index->get_events_in_range(
			self::WINDOW[0],
			self::WINDOW[1],
			[ 'exclude_type_term_id' => [ $this->exhibit_term, $this->talk_term ] ]
		);

		$this->assertSame( [ $this->untyped_id ], $this->post_ids( $rows ) );
	}

	public function test_include_and_exclude_compose(): void {
		$rows = $this->index->get_events_in_range(
			self::WINDOW[0],
			self::WINDOW[1],
			[
				'type_term_id'         => [ $this->exhibit_term, $this->talk_term ],
				'exclude_type_term_id' => $this->exhibit_term,
			]
		);

		$this->assertSame( [ $this->talk_id ], $this->post_ids( $rows ) );
	}

	public function test_count_agrees_with_the_row_query(): void {
		$filters = [ 'exclude_type_term_id' => $this->exhibit_term ];

		$this->assertSame(
			count( $this->index->get_events_in_range( self::WINDOW[0], self::WINDOW[1], $filters ) ),
			$this->index->count_events_in_range( self::WINDOW[0], self::WINDOW[1], $filters )
		);
	}

	public function test_empty_or_invalid_exclusions_are_ignored(): void {
		$all = $this->index->get_events_in_range( ...self::WINDOW );

		foreach ( [ [], [ 0 ], [ 'abc' ], '' ] as $value ) {
			$rows = $this->index->get_events_in_range(
				self::WINDOW[0],
				self::WINDOW[1],
				[ 'exclude_type_term_id' => $value ]
			);
			$this->assertCount( count( $all ), $rows );
		}
	}

	public function test_exclusion_gets_its_own_cache_entry(): void {
		$all      = $this->index->get_events_in_range( ...self::WINDOW );
		$filtered = $this->index->get_events_in_range(
			self::WINDOW[0],
			self::WINDOW[1],
			[ 'exclude_type_term_id' => $this->exhibit_term ]
		);

		$this->assertNotSame( count( $all ), count( $filtered ) );
	}

	public function test_events_query_block_drops_an_id_present_in_both_lists(): void {
		// The explicit allowlist wins: the exhibit stays because it is included.
		$html = do_blocks(
			sprintf(
				'<!-- wp:blockendar/events-query {"typeIds":[%1$d],"excludeTypeIds":[%1$d,%2$d],"showPast":true} -->'
				. '<!-- wp:blockendar/event-template --><!-- wp:post-title /--><!-- /wp:blockendar/event-template -->'
				. '<!-- /wp:blockendar/events-query -->',
				$this->exhibit_term,
				$this->talk_term
			)
		);

		$this->assertStringContainsString( 'Event 2025-09-10', $html );
		$this->assertStringNotContainsString( 'Event 2025-09-11', $html );
	}

	public function test_events_query_block_excludes_a_type(): void {
		$html = do_blocks(
			sprintf(
				'<!-- wp:blockendar/events-query {"excludeTypeIds":[%d],"showPast":true} -->'
				. '<!-- wp:blockendar/event-template --><!-- wp:post-title /--><!-- /wp:blockendar/event-template -->'
				. '<!-- /wp:blockendar/events-query -->',
				$this->exhibit_term
			)
		);

		$this->assertStringNotContainsString( 'Event 2025-09-10', $html );
		$this->assertStringContainsString( 'Event 2025-09-11', $html );
		$this->assertStringContainsString( 'Event 2025-09-12', $html );
	}
}
