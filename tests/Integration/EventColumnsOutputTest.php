<?php
/**
 * Integration coverage for what the Start Date and End Date admin columns print.
 *
 * The index stores an occurrence's start and end in UTC. The columns printed
 * the time straight from those, beside a date taken from the event's own
 * calendar day, so a 7 pm Chicago event read "November 10, 2026 1:00 am".
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\Admin\EventColumns;
use Blockendar\DB\EventIndex;
use Blockendar\DB\IndexBuilder;
use Blockendar\DB\Schema;
use Blockendar\Recurrence\Generator;
use Blockendar\Recurrence\RuleRepository;
use WP_UnitTestCase;

class EventColumnsOutputTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		Schema::create_tables();
		( new EventIndex() )->flush_cache();

		global $wpdb;
		$events = Schema::events_table();
		$wpdb->query( "DELETE FROM {$events}" ); // phpcs:ignore WordPress.DB

		delete_option( 'blockendar_settings' );
		update_option( 'timezone_string', 'America/Chicago' );
	}

	public function tear_down(): void {
		delete_option( 'timezone_string' );
		delete_option( 'blockendar_settings' );
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Fixtures
	// -------------------------------------------------------------------------

	/**
	 * Create and index an event.
	 *
	 * @param array $meta Event meta, without the blockendar_ prefix.
	 * @return int Post ID.
	 */
	private function make_event( array $meta ): int {
		$meta_input = [];

		foreach ( $meta as $key => $value ) {
			$meta_input[ "blockendar_{$key}" ] = $value;
		}

		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'publish',
				'meta_input'  => $meta_input,
			]
		);

		( new IndexBuilder() )->build_for_post( $post_id );

		return $post_id;
	}

	/**
	 * A 19:00–21:00 event in Chicago on 10 November 2026.
	 *
	 * @param array $overrides Meta to change.
	 */
	private function evening( array $overrides = [] ): array {
		return array_merge(
			[
				'start_date' => '2026-11-10',
				'end_date'   => '2026-11-10',
				'start_time' => '19:00',
				'end_time'   => '21:00',
				'timezone'   => 'America/Chicago',
			],
			$overrides
		);
	}

	/**
	 * What a column prints for an event, as text.
	 *
	 * @param EventColumns $columns Instance, so a test can reuse one across rows.
	 * @param string       $column  'start' or 'end'.
	 * @param int          $post_id Event post ID.
	 */
	private function cell( EventColumns $columns, string $column, int $post_id ): string {
		ob_start();
		$columns->render_column( "blockendar_{$column}_date", $post_id );

		return trim( (string) preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( (string) ob_get_clean() ) ) ) );
	}

	// -------------------------------------------------------------------------
	// Times
	// -------------------------------------------------------------------------

	public function test_a_timed_event_shows_its_local_start_and_end(): void {
		$post_id = $this->make_event( $this->evening() );
		$columns = new EventColumns();

		$this->assertSame( 'November 10, 2026 7:00 pm', $this->cell( $columns, 'start', $post_id ) );
		$this->assertSame( 'November 10, 2026 9:00 pm', $this->cell( $columns, 'end', $post_id ) );
	}

	/**
	 * Under the default timezone mode every event is shown in the site's
	 * timezone, and 11:30 pm in Chicago is the next day in New York. The date
	 * has to move with the time, as it does in the date block.
	 */
	public function test_site_mode_converts_the_date_as_well_as_the_time(): void {
		update_option( 'timezone_string', 'America/New_York' );

		$post_id = $this->make_event(
			$this->evening(
				[
					'start_time' => '23:30',
					'end_date'   => '2026-11-11',
					'end_time'   => '01:00',
				]
			)
		);
		$columns = new EventColumns();

		$this->assertSame( 'November 11, 2026 12:30 am', $this->cell( $columns, 'start', $post_id ) );
		$this->assertSame( 'November 11, 2026 2:00 am', $this->cell( $columns, 'end', $post_id ) );
	}

	public function test_event_mode_shows_the_time_in_the_events_own_timezone(): void {
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'blockendar_settings', [ 'timezone_mode' => 'event' ] );

		$post_id = $this->make_event( $this->evening() );

		$this->assertSame( 'November 10, 2026 7:00 pm', $this->cell( new EventColumns(), 'start', $post_id ) );
	}

	// -------------------------------------------------------------------------
	// Events without times
	// -------------------------------------------------------------------------

	public function test_an_all_day_event_shows_dates_only(): void {
		$post_id = $this->make_event(
			[
				'start_date' => '2026-11-10',
				'end_date'   => '2026-11-12',
				'all_day'    => '1',
				'timezone'   => 'America/Chicago',
			]
		);
		$columns = new EventColumns();

		$this->assertSame( 'November 10, 2026', $this->cell( $columns, 'start', $post_id ) );
		$this->assertSame( 'November 12, 2026', $this->cell( $columns, 'end', $post_id ) );
	}

	public function test_an_ongoing_event_says_so_instead_of_printing_an_end(): void {
		$post_id = $this->make_event(
			[
				'start_date' => '2026-11-10',
				'start_time' => '19:00',
				'ongoing'    => '1',
				'timezone'   => 'America/Chicago',
			]
		);
		$columns = new EventColumns();

		$this->assertSame( 'November 10, 2026 7:00 pm', $this->cell( $columns, 'start', $post_id ) );
		$this->assertSame( 'Ongoing', $this->cell( $columns, 'end', $post_id ) );
	}

	public function test_an_event_with_no_occurrence_shows_a_dash(): void {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'draft',
			]
		);
		$columns = new EventColumns();

		$this->assertSame( '—', $this->cell( $columns, 'start', $post_id ) );
		$this->assertSame( '—', $this->cell( $columns, 'end', $post_id ) );
	}

	public function test_a_recurring_event_shows_its_first_occurrence(): void {
		$post_id = $this->make_event( $this->evening() );

		( new RuleRepository() )->upsert(
			$post_id,
			[
				'frequency' => 'weekly',
				'interval'  => 1,
				'count'     => 4,
			]
		);
		( new Generator() )->generate_for_post( $post_id );

		$this->assertSame( 'November 10, 2026 7:00 pm', $this->cell( new EventColumns(), 'start', $post_id ) );
	}

	// -------------------------------------------------------------------------
	// Queries
	// -------------------------------------------------------------------------

	/**
	 * The list table calls the column callback once per column per row. Each
	 * call ran its own query against the index: forty on a page of twenty.
	 */
	public function test_a_page_of_events_reads_the_index_once(): void {
		$post_ids = [];

		for ( $day = 1; $day <= 20; $day++ ) {
			$date       = sprintf( '2026-11-%02d', $day );
			$post_ids[] = $this->make_event(
				$this->evening(
					[
						'start_date' => $date,
						'end_date'   => $date,
					]
				)
			);
		}

		// The list table's own query, which the columns take their page from.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Standing in for the list table; WP_UnitTestCase resets it.
		$GLOBALS['wp_query'] = new \WP_Query(
			[
				'post_type'      => 'blockendar_event',
				'post__in'       => $post_ids,
				'posts_per_page' => 20,
			]
		);

		$table   = Schema::events_table();
		$queries = 0;
		$counter = static function ( string $query ) use ( $table, &$queries ): string {
			if ( str_contains( $query, $table ) ) {
				++$queries;
			}

			return $query;
		};

		$columns = new EventColumns();

		add_filter( 'query', $counter );

		foreach ( $post_ids as $post_id ) {
			$this->assertStringEndsWith( '7:00 pm', $this->cell( $columns, 'start', $post_id ) );
			$this->assertStringEndsWith( '9:00 pm', $this->cell( $columns, 'end', $post_id ) );
		}

		remove_filter( 'query', $counter );

		$this->assertSame( 1, $queries );
	}
}
