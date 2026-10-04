<?php
/**
 * Integration coverage for keeping the index in step with writes that are not
 * a save of the event.
 *
 * The index was rebuilt on save_post and nowhere else. Code that changed an
 * event's date with update_post_meta(), or its venue with wp_set_object_terms(),
 * left the index as it was until someone opened the event and saved it. And a
 * save from the block editor built the index twice: once on save_post, before
 * the request's meta had been written, and again after.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\Admin\SettingsPage;
use Blockendar\DB\EventIndex;
use Blockendar\DB\IndexBuilder;
use Blockendar\DB\Schema;
use Blockendar\Meta\EventMeta;
use WP_REST_Request;
use WP_UnitTestCase;

class IndexDirtySyncTest extends WP_UnitTestCase {

	private EventIndex $index;

	private IndexBuilder $builder;

	public function set_up(): void {
		parent::set_up();

		Schema::create_tables();

		// WP_UnitTestCase unregisters every meta key in tear_down(), and the
		// REST save below writes registered meta.
		( new EventMeta() )->register_meta();

		$this->index   = new EventIndex();
		$this->builder = new IndexBuilder();

		// Under the 'cron' strategy a flush queues a deferred build; one left
		// by an earlier test would satisfy the assertion on its own.
		_set_cron_array( [] );

		delete_option( SettingsPage::OPTION_NAME );

		// Whatever earlier tests left waiting is not this test's business.
		IndexBuilder::forget_dirty();
		$this->index->flush_cache();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		delete_option( SettingsPage::OPTION_NAME );
		IndexBuilder::forget_dirty();
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Fixtures
	// -------------------------------------------------------------------------

	/**
	 * Create and index an event on 9 March 2027.
	 *
	 * @param string $status Post status.
	 * @return int Post ID.
	 */
	private function make_event( string $status = 'publish' ): int {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => $status,
				'meta_input'  => [
					'blockendar_start_date' => '2027-03-09',
					'blockendar_end_date'   => '2027-03-09',
					'blockendar_start_time' => '19:00',
					'blockendar_end_time'   => '21:00',
					'blockendar_timezone'   => 'UTC',
				],
			]
		);

		$this->builder->build_for_post( $post_id );

		return $post_id;
	}

	/**
	 * The event's index rows, read from the table.
	 *
	 * @param int $post_id Post ID.
	 * @return object[]
	 */
	private function rows( int $post_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d', Schema::events_table(), $post_id ) );
	}

	/**
	 * Count the statements of one kind sent to the events table while a
	 * callback runs.
	 *
	 * @param string   $verb     "INSERT INTO" or "DELETE FROM".
	 * @param callable $callback What to run.
	 */
	private function count_writes( string $verb, callable $callback ): int {
		$count   = 0;
		$table   = Schema::events_table();
		$counter = static function ( $query ) use ( &$count, $verb, $table ) {
			if ( preg_match( '/^\s*' . $verb . '\s+`?' . preg_quote( $table, '/' ) . '`?[\s(]/i', (string) $query ) ) {
				++$count;
			}

			return $query;
		};

		add_filter( 'query', $counter );
		$callback();
		remove_filter( 'query', $counter );

		return $count;
	}

	// -------------------------------------------------------------------------
	// Writes that are not a save
	// -------------------------------------------------------------------------

	public function test_changing_the_start_date_meta_moves_the_index_row(): void {
		$post_id = $this->make_event();

		update_post_meta( $post_id, 'blockendar_start_date', '2027-04-01' );
		update_post_meta( $post_id, 'blockendar_end_date', '2027-04-01' );

		$this->assertSame( '2027-03-09', $this->rows( $post_id )[0]->start_date, 'Not before the flush: the two writes are one change.' );

		$this->builder->flush_dirty();

		$rows = $this->rows( $post_id );

		$this->assertCount( 1, $rows );
		$this->assertSame( '2027-04-01', $rows[0]->start_date );
		$this->assertSame( '2027-04-01 19:00:00', $rows[0]->start_datetime );
	}

	public function test_several_writes_to_one_event_build_it_once(): void {
		$post_id = $this->make_event();

		update_post_meta( $post_id, 'blockendar_start_date', '2027-04-01' );
		update_post_meta( $post_id, 'blockendar_end_date', '2027-04-01' );
		update_post_meta( $post_id, 'blockendar_start_time', '18:00' );
		delete_post_meta( $post_id, 'blockendar_end_time' );

		$inserts = $this->count_writes( 'INSERT INTO', fn() => $this->builder->flush_dirty() );

		$this->assertSame( 1, $inserts );
		$this->assertSame( [], IndexBuilder::dirty(), 'Nothing is left waiting after a flush.' );
	}

	public function test_setting_the_venue_updates_the_row(): void {
		$post_id = $this->make_event();
		$venue   = self::factory()->term->create( [ 'taxonomy' => 'blockendar_event_venue' ] );

		wp_set_object_terms( $post_id, [ $venue ], 'blockendar_event_venue' );
		$this->builder->flush_dirty();

		$this->assertSame( $venue, (int) $this->rows( $post_id )[0]->venue_term_id );
	}

	/**
	 * The row keeps the venue's ID. With the venue deleted it pointed at a term
	 * that no longer existed, and a filter on any other venue never saw it.
	 */
	public function test_deleting_a_venue_clears_it_from_the_rows_that_had_it(): void {
		$post_id = $this->make_event();
		$venue   = self::factory()->term->create( [ 'taxonomy' => 'blockendar_event_venue' ] );

		wp_set_object_terms( $post_id, [ $venue ], 'blockendar_event_venue' );
		$this->builder->build_for_post( $post_id );

		$this->assertSame( $venue, (int) $this->rows( $post_id )[0]->venue_term_id, 'Precondition: the row has the venue.' );

		wp_delete_term( $venue, 'blockendar_event_venue' );
		$this->builder->flush_dirty();

		$this->assertNull( $this->rows( $post_id )[0]->venue_term_id );
	}

	public function test_setting_the_event_type_updates_the_row(): void {
		$post_id = $this->make_event();
		$type    = self::factory()->term->create( [ 'taxonomy' => 'blockendar_event_type' ] );

		wp_set_object_terms( $post_id, [ $type ], 'blockendar_event_type' );
		$this->builder->flush_dirty();

		$this->assertSame( [ $type ], json_decode( (string) $this->rows( $post_id )[0]->type_term_ids, true ) );
	}

	// -------------------------------------------------------------------------
	// What marks nothing
	// -------------------------------------------------------------------------

	public function test_meta_the_index_does_not_read_marks_nothing(): void {
		$post_id = $this->make_event();

		update_post_meta( $post_id, 'blockendar_cost', '12' );
		update_post_meta( $post_id, 'blockendar_registration_url', 'https://example.org/' );
		update_post_meta( $post_id, '_edit_lock', '1' );
		wp_set_object_terms( $post_id, [ 'outdoors' ], 'blockendar_event_tag' );

		$this->assertSame( [], IndexBuilder::dirty() );
	}

	/**
	 * The watched keys are a list; what a row is built from is code. A key the
	 * builder starts reading has to be added to the list, or a change to it
	 * will not reach the index.
	 */
	public function test_the_watched_keys_are_the_keys_a_row_is_built_from(): void {
		$post_id = $this->make_event();
		$read    = [];

		$recorder = static function ( $value, $object_id, $meta_key ) use ( &$read ) {
			$read[] = $meta_key;

			return $value;
		};

		add_filter( 'get_post_metadata', $recorder, 10, 3 );
		$this->builder->get_event_meta( $post_id );
		remove_filter( 'get_post_metadata', $recorder, 10 );

		$this->assertEqualsCanonicalizing( IndexBuilder::INDEX_META_KEYS, array_unique( $read ) );
	}

	public function test_the_same_meta_key_on_another_post_type_marks_nothing(): void {
		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );

		update_post_meta( $page_id, 'blockendar_start_date', '2027-04-01' );

		$this->assertSame( [], IndexBuilder::dirty() );
	}

	/**
	 * Only published events are indexed. A draft whose date changes has no rows
	 * and must not be given any.
	 */
	public function test_a_draft_whose_meta_changes_gets_no_rows(): void {
		$post_id = $this->make_event( 'draft' );

		update_post_meta( $post_id, 'blockendar_start_date', '2027-04-01' );
		$this->builder->flush_dirty();

		$this->assertSame( [], $this->rows( $post_id ) );
	}

	public function test_an_event_built_by_hand_is_not_built_again_by_the_flush(): void {
		$post_id = $this->make_event();

		update_post_meta( $post_id, 'blockendar_start_date', '2027-04-01' );
		$this->builder->build_for_post( $post_id );

		$this->assertSame( 0, $this->count_writes( 'INSERT INTO', fn() => $this->builder->flush_dirty() ) );
	}

	public function test_under_the_cron_strategy_a_flush_queues_the_build(): void {
		$post_id = $this->make_event();

		update_option( SettingsPage::OPTION_NAME, [ 'generation_strategy' => 'cron' ] );
		update_post_meta( $post_id, 'blockendar_start_date', '2027-04-01' );

		$this->builder->flush_dirty();

		$this->assertNotFalse( wp_next_scheduled( IndexBuilder::DEFERRED_HOOK, [ $post_id ] ) );
		$this->assertSame( '2027-03-09', $this->rows( $post_id )[0]->start_date, 'The row waits for the queued build.' );
	}

	/**
	 * Deleting a venue that thousands of events use marks them all. Building
	 * them one after another as the request ends would run until it was
	 * killed, and lose the rest. Past a limit the work goes to the background
	 * rebuild, which does it in passes.
	 */
	public function test_too_many_dirty_events_are_left_to_a_background_rebuild(): void {
		$post_id = $this->make_event();

		update_post_meta( $post_id, 'blockendar_start_date', '2027-04-01' );
		update_post_meta( $post_id, 'blockendar_end_date', '2027-04-01' );

		for ( $id = 900000; $id < 900000 + IndexBuilder::DIRTY_FLUSH_LIMIT; $id++ ) {
			$this->builder->mark_dirty( $id );
		}

		$inserts = $this->count_writes( 'INSERT INTO', fn() => $this->builder->flush_dirty() );

		$this->assertSame( 0, $inserts );
		$this->assertSame( [], IndexBuilder::dirty() );
		$this->assertNotFalse( wp_next_scheduled( IndexBuilder::REBUILD_HOOK ) );
		$this->assertSame( '2027-03-09', $this->rows( $post_id )[0]->start_date, 'The row waits for the rebuild.' );
	}

	public function test_the_flush_runs_when_the_request_ends(): void {
		global $wp_filter;

		$found = false;

		foreach ( $wp_filter['shutdown']->callbacks ?? [] as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];

				if ( is_array( $function ) && $function[0] instanceof IndexBuilder && 'flush_dirty' === $function[1] ) {
					$found = true;
				}
			}
		}

		$this->assertTrue( $found );
	}

	// -------------------------------------------------------------------------
	// A save from the block editor
	// -------------------------------------------------------------------------

	/**
	 * The editor saves through the REST API, which writes the post and then
	 * its meta. The index was built on the first, from the old meta, and built
	 * again after the second.
	 */
	public function test_a_rest_save_builds_the_index_once(): void {
		$post_id = $this->make_event();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		do_action( 'rest_api_init' );

		$request = new WP_REST_Request( 'POST', "/wp/v2/blockendar-events/{$post_id}" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				[
					'title' => 'Moved',
					'meta'  => [
						'blockendar_start_date' => '2027-04-01',
						'blockendar_end_date'   => '2027-04-01',
					],
				]
			)
		);

		$response = null;
		$inserts  = $this->count_writes(
			'INSERT INTO',
			function () use ( $request, &$response ) {
				$response = rest_get_server()->dispatch( $request );
				$this->builder->flush_dirty();
			}
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $inserts );
		$this->assertSame( '2027-04-01', $this->rows( $post_id )[0]->start_date );
	}

	/**
	 * Outside the REST API nothing follows save_post to build the index, so
	 * the save itself still does.
	 */
	public function test_a_save_outside_the_rest_api_still_builds_at_once(): void {
		$post_id = $this->make_event();

		update_post_meta( $post_id, 'blockendar_start_date', '2027-04-01' );
		update_post_meta( $post_id, 'blockendar_end_date', '2027-04-01' );

		wp_update_post(
			[
				'ID'         => $post_id,
				'post_title' => 'Moved',
			]
		);

		$this->assertSame( '2027-04-01', $this->rows( $post_id )[0]->start_date );
		$this->assertSame( [], IndexBuilder::dirty() );
	}
}
