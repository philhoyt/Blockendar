<?php
/**
 * Integration coverage for a full index rebuild.
 *
 * The index is derived from the posts, so it can always be rebuilt. It used to
 * be rebuilt by emptying it first and refilling it in one request: until that
 * finished the site had no events, and if the request died it stayed that way.
 * A rebuild now replaces each event's rows in turn, within a time budget, and
 * picks up where it stopped.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\DB\EventIndex;
use Blockendar\DB\IndexBuilder;
use Blockendar\DB\Schema;
use Blockendar\Recurrence\RuleRepository;
use WP_UnitTestCase;

class ResumableRebuildTest extends WP_UnitTestCase {

	private EventIndex $index;

	private IndexBuilder $builder;

	public function set_up(): void {
		parent::set_up();

		Schema::create_tables();

		$this->index   = new EventIndex();
		$this->builder = new IndexBuilder();

		global $wpdb;
		foreach ( [ Schema::events_table(), Schema::type_terms_table(), Schema::recurrence_table() ] as $table ) {
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
		}

		// A rebuild queued by an earlier test would satisfy assertions here.
		_set_cron_array( [] );

		delete_option( IndexBuilder::CURSOR_OPTION );
		delete_option( 'blockendar_last_index_rebuild' );
		delete_option( 'blockendar_settings' );

		$this->index->flush_cache();
	}

	public function tear_down(): void {
		remove_all_actions( 'blockendar_generate_recurrence_index' );
		( new \Blockendar\Recurrence\Generator() )->register();

		delete_option( IndexBuilder::CURSOR_OPTION );
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Fixtures
	// -------------------------------------------------------------------------

	/**
	 * Create and index a published event.
	 *
	 * @param string $date Start and end date.
	 * @return int Post ID.
	 */
	private function make_event( string $date ): int {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'publish',
				'meta_input'  => [
					'blockendar_start_date' => $date,
					'blockendar_end_date'   => $date,
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
	 * How many index rows a post has.
	 *
	 * @param int $post_id Post ID.
	 */
	private function rows( int $post_id ): int {
		$this->index->flush_cache();

		return count( $this->index->get_by_post_id( $post_id ) );
	}

	// -------------------------------------------------------------------------
	// Nothing disappears while it runs
	// -------------------------------------------------------------------------

	/**
	 * Seen from inside the rebuild: while the first event is being rebuilt, the
	 * last one has not been reached yet, and its rows have to be there still.
	 * With the index emptied up front they were not.
	 */
	public function test_an_event_not_yet_reached_keeps_its_rows_during_a_rebuild(): void {
		$first = $this->make_event( '2027-03-09' );
		$last  = $this->make_event( '2027-03-10' );

		// A recurring event fires an action as it is rebuilt, which gives the
		// test somewhere to stand in the middle of the run.
		( new RuleRepository() )->upsert(
			$first,
			[
				'frequency' => 'daily',
				'count'     => 2,
			]
		);
		$this->builder->build_for_post( $first );

		$seen = null;

		add_action(
			'blockendar_generate_recurrence_index',
			function ( $post_id ) use ( $first, $last, &$seen ) {
				if ( (int) $post_id === $first && null === $seen ) {
					$seen = $this->rows( $last );
				}
			},
			1
		);

		$this->builder->rebuild_all();

		$this->assertSame( 1, $seen, 'The event not yet rebuilt had no rows in the middle of the rebuild.' );
		$this->assertSame( 2, $this->rows( $first ) );
		$this->assertSame( 1, $this->rows( $last ) );
	}

	// -------------------------------------------------------------------------
	// Stopping and resuming
	// -------------------------------------------------------------------------

	public function test_a_rebuild_that_runs_out_of_budget_resumes_where_it_stopped(): void {
		$ids = [
			$this->make_event( '2027-03-09' ),
			$this->make_event( '2027-03-10' ),
			$this->make_event( '2027-03-11' ),
		];

		$first = $this->builder->rebuild_step( 0.0 );

		$this->assertFalse( $first['done'], 'A zero budget still makes progress, one event at a time.' );
		$this->assertSame( 1, $first['rebuilt'] );
		$this->assertTrue( $this->builder->is_rebuilding() );
		$this->assertFalse( get_option( 'blockendar_last_index_rebuild' ), 'A rebuild is not recorded until it finishes.' );

		$this->builder->rebuild_step( 0.0 );
		$this->builder->rebuild_step( 0.0 );
		$last = $this->builder->rebuild_step( 0.0 );

		$this->assertTrue( $last['done'] );
		$this->assertSame( 3, $last['rebuilt'], 'The totals cover the whole rebuild, not the last pass.' );
		$this->assertFalse( $this->builder->is_rebuilding() );
		$this->assertNotFalse( get_option( 'blockendar_last_index_rebuild' ) );

		foreach ( $ids as $id ) {
			$this->assertSame( 1, $this->rows( $id ) );
		}
	}

	public function test_the_scheduled_rebuild_queues_itself_again_until_it_is_done(): void {
		$this->make_event( '2027-03-09' );
		$this->make_event( '2027-03-10' );

		$this->builder->run_scheduled_rebuild( 0.0 );

		$this->assertTrue( $this->builder->is_rebuilding() );
		$this->assertNotFalse( wp_next_scheduled( IndexBuilder::REBUILD_HOOK ) );

		_set_cron_array( [] );

		$this->builder->run_scheduled_rebuild();

		$this->assertFalse( $this->builder->is_rebuilding() );
		$this->assertFalse( wp_next_scheduled( IndexBuilder::REBUILD_HOOK ) );
	}

	/**
	 * The settings page and the background run can both be working through a
	 * rebuild. Two passes over the same event would each clear its rows and
	 * each write them. The second connection stands in for the other request.
	 */
	public function test_a_pass_does_nothing_while_another_holds_the_lock(): void {
		global $wpdb;

		$this->make_event( '2027-03-09' );

		// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- A second connection is the point.
		$other = new \mysqli( ...$this->connection_arguments() );
		$name  = 'blockendar_rebuild_' . $wpdb->prefix;
		$other->query( "SELECT GET_LOCK( '" . $other->real_escape_string( $name ) . "', 0 )" );

		$blocked = $this->builder->rebuild_step();

		$other->close();

		$this->assertFalse( $blocked['done'] );
		$this->assertSame( 0, $blocked['rebuilt'] );
		$this->assertFalse( $this->builder->is_rebuilding(), 'A pass that could not start leaves no cursor.' );

		$this->assertTrue( $this->builder->rebuild_step()['done'], 'Once the lock is free the rebuild runs.' );
	}

	/**
	 * Host, user, password, database and port for a second connection.
	 *
	 * @return array{string, string, string, string, int}
	 */
	private function connection_arguments(): array {
		$host = DB_HOST;
		$port = 3306;

		if ( str_contains( $host, ':' ) ) {
			[ $host, $port ] = explode( ':', $host, 2 );
		}

		return [ $host, DB_USER, DB_PASSWORD, DB_NAME, (int) $port ];
	}

	/**
	 * An upgrade that changes what a row holds cannot pick up a rebuild that
	 * was already partway: the events it had done were done the old way.
	 */
	public function test_a_rebuild_queued_by_an_upgrade_starts_from_the_beginning(): void {
		$this->make_event( '2027-03-09' );
		$this->make_event( '2027-03-10' );

		$this->builder->rebuild_step( 0.0 );
		$this->assertTrue( $this->builder->is_rebuilding(), 'Precondition: a rebuild is partway.' );

		$this->builder->queue_full_rebuild();

		$this->assertFalse( $this->builder->is_rebuilding() );
		$this->assertNotFalse( wp_next_scheduled( IndexBuilder::REBUILD_HOOK ) );
		$this->assertSame( 2, $this->builder->rebuild_step()['rebuilt'] );
	}

	public function test_rebuild_all_runs_to_the_end_and_reports_the_totals(): void {
		$this->make_event( '2027-03-09' );
		$this->make_event( '2027-03-10' );

		$undated = self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'publish',
			]
		);

		$result = $this->builder->rebuild_all();

		$this->assertSame( 2, $result['rebuilt'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( 0, $this->rows( $undated ) );
		$this->assertFalse( $this->builder->is_rebuilding() );
	}

	// -------------------------------------------------------------------------
	// What the settings page is told
	// -------------------------------------------------------------------------

	public function test_the_rebuild_route_and_the_stats_route_report_progress(): void {
		$this->make_event( '2027-03-09' );
		$this->make_event( '2027-03-10' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		do_action( 'rest_api_init' );

		// Left partway by an earlier pass.
		$this->builder->rebuild_step( 0.0 );

		$stats = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/blockendar/v1/settings/stats' ) )->get_data();

		$this->assertTrue( $stats['rebuild_in_progress'] );

		$data = rest_get_server()->dispatch( new \WP_REST_Request( 'POST', '/blockendar/v1/index/rebuild' ) )->get_data();

		$this->assertFalse( $data['in_progress'] );
		$this->assertSame( 2, $data['rebuilt'], 'The route carries on from the cursor and reports the whole rebuild.' );
		$this->assertNotEmpty( $data['rebuilt_at'] );

		$stats = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/blockendar/v1/settings/stats' ) )->get_data();

		$this->assertFalse( $stats['rebuild_in_progress'] );

		wp_set_current_user( 0 );
	}

	// -------------------------------------------------------------------------
	// What the old truncate used to take care of
	// -------------------------------------------------------------------------

	/**
	 * Emptying the table removed rows nothing else would. Without it, a rebuild
	 * has to find them.
	 */
	public function test_a_rebuild_removes_rows_whose_event_is_gone_or_unpublished(): void {
		global $wpdb;

		$kept  = $this->make_event( '2027-03-09' );
		$draft = $this->make_event( '2027-03-10' );

		// Straight to the database: wp_update_post() would fire save_post and
		// clear the rows this test needs to be left behind.
		$wpdb->update( $wpdb->posts, [ 'post_status' => 'draft' ], [ 'ID' => $draft ] ); // phpcs:ignore WordPress.DB
		clean_post_cache( $draft );

		$this->index->insert(
			[
				'post_id'        => 999999,
				'start_datetime' => '2027-03-11 19:00:00',
				'end_datetime'   => '2027-03-11 21:00:00',
				'start_date'     => '2027-03-11',
				'end_date'       => '2027-03-11',
				'all_day'        => 0,
				'status'         => 'scheduled',
			]
		);

		$this->assertSame( 1, $this->rows( $draft ), 'Precondition: the unpublished event still has a row.' );
		$this->assertSame( 1, $this->rows( 999999 ), 'Precondition: a row exists for a post that does not.' );

		$this->builder->rebuild_all();

		$this->assertSame( 1, $this->rows( $kept ) );
		$this->assertSame( 0, $this->rows( $draft ) );
		$this->assertSame( 0, $this->rows( 999999 ) );
	}

	/**
	 * The old rebuild skipped an event with no start date before reaching the
	 * code that clears its rows. That was harmless only because the table had
	 * just been emptied.
	 */
	public function test_an_event_that_lost_its_start_date_loses_its_rows(): void {
		$post_id = $this->make_event( '2027-03-09' );

		delete_post_meta( $post_id, 'blockendar_start_date' );

		$this->assertSame( 1, $this->rows( $post_id ), 'Precondition: the row outlived the meta.' );

		$result = $this->builder->rebuild_all();

		$this->assertSame( 0, $this->rows( $post_id ) );
		$this->assertSame( 1, $result['skipped'] );
	}
}
