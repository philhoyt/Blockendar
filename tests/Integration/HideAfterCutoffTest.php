<?php
/**
 * Integration coverage for the Events Query's hideAfter rule.
 *
 * The block keeps an event in the upcoming list for a while after it ends —
 * until the end of its day by default — and the same cutoff decides when it
 * becomes past. The site timezone is pinned so that "now" is always around
 * midday locally, which keeps "earlier today" and "yesterday" unambiguous
 * whatever the wall clock says when the suite runs.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\Blocks\Cutoff;
use Blockendar\DB\EventIndex;
use Blockendar\DB\Schema;
use Blockendar\Taxonomy\Venue;
use WP_UnitTestCase;

class HideAfterCutoffTest extends WP_UnitTestCase {

	private EventIndex $index;

	private \DateTimeZone $tz;

	private \DateTimeImmutable $local_now;

	private const TEMPLATE = '<!-- wp:blockendar/event-template --><!-- wp:post-title /--><!-- /wp:blockendar/event-template -->';

	public function set_up(): void {
		parent::set_up();

		Schema::create_tables();
		$this->index = new EventIndex();
		$this->index->flush_cache();

		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Schema::events_table() ); // phpcs:ignore WordPress.DB

		// Pick the fixed-offset zone in which the current UTC hour reads as 12:00.
		// Etc/GMT+5 is UTC-5, hence the sign flip.
		$offset = 12 - (int) gmdate( 'G' );
		update_option( 'timezone_string', sprintf( 'Etc/GMT%+d', -$offset ) );

		$this->tz        = wp_timezone();
		$this->local_now = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->setTimezone( $this->tz );

		$this->assertSame( 12, (int) $this->local_now->format( 'G' ), 'the pinned zone must put local time at noon' );
	}

	public function tear_down(): void {
		$_GET = [];
		delete_option( 'timezone_string' );
		parent::tear_down();
	}

	/**
	 * Seed a timed event ending at a local time relative to now.
	 *
	 * @param string $title    Post title, used to find it in the output.
	 * @param string $modifier DateTime modifier for the end, relative to local now.
	 * @param array  $extra    Extra index columns.
	 * @return int Post ID.
	 */
	private function seed( string $title, string $modifier, array $extra = [] ): int {
		$end   = $this->local_now->modify( $modifier );
		$start = $end->modify( '-1 hour' );
		$utc   = new \DateTimeZone( 'UTC' );

		return $this->insert(
			$title,
			$start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			$end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			$start->format( 'Y-m-d' ),
			$end->format( 'Y-m-d' ),
			$extra
		);
	}

	private function insert( string $title, string $start, string $end, string $start_date, string $end_date, array $extra = [] ): int {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'publish',
				'post_title'  => $title,
			]
		);

		$this->index->insert(
			array_merge(
				[
					'post_id'        => $post_id,
					'start_datetime' => $start,
					'end_datetime'   => $end,
					'start_date'     => $start_date,
					'end_date'       => $end_date,
					'all_day'        => 0,
					'status'         => 'scheduled',
				],
				$extra
			)
		);

		return $post_id;
	}

	private function render( string $attrs ): string {
		return do_blocks(
			'<!-- wp:blockendar/events-query ' . $attrs . ' -->' . self::TEMPLATE . '<!-- /wp:blockendar/events-query -->'
		);
	}

	private function seed_the_cast(): void {
		$this->seed( 'Two Hours Ago', '-2 hours' );
		$this->seed( 'Ninety Minutes Ago', '-90 minutes' );
		$this->seed( 'Yesterday Evening', '-1 day 20:00' );
		$this->seed( 'Later Today', '+3 hours' );

		// Ongoing: opened last year, no end date, indexed with the sentinel.
		$this->insert(
			'Ongoing Exhibit',
			$this->local_now->modify( '-1 year' )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			EventIndex::ONGOING_END,
			$this->local_now->modify( '-1 year' )->format( 'Y-m-d' ),
			EventIndex::ONGOING_END_DATE,
			[ 'ongoing' => 1 ]
		);
	}

	public function test_the_default_keeps_events_that_ended_earlier_today(): void {
		$this->seed_the_cast();

		$upcoming = $this->render( '{}' );
		$past     = $this->render( '{"showPast":true}' );

		foreach ( [ 'Two Hours Ago', 'Ninety Minutes Ago', 'Later Today', 'Ongoing Exhibit' ] as $title ) {
			$this->assertStringContainsString( $title, $upcoming, "$title should be upcoming under the default" );
			$this->assertStringNotContainsString( $title, $past, "$title should not be past under the default" );
		}

		$this->assertStringNotContainsString( 'Yesterday Evening', $upcoming );
		$this->assertStringContainsString( 'Yesterday Evening', $past );
	}

	public function test_the_end_rule_drops_events_the_moment_they_end(): void {
		$this->seed_the_cast();

		$upcoming = $this->render( '{"hideAfter":"end"}' );
		$past     = $this->render( '{"hideAfter":"end","showPast":true}' );

		foreach ( [ 'Two Hours Ago', 'Ninety Minutes Ago', 'Yesterday Evening' ] as $title ) {
			$this->assertStringNotContainsString( $title, $upcoming, "$title has ended" );
			$this->assertStringContainsString( $title, $past, "$title is past" );
		}

		foreach ( [ 'Later Today', 'Ongoing Exhibit' ] as $title ) {
			$this->assertStringContainsString( $title, $upcoming );
			$this->assertStringNotContainsString( $title, $past );
		}
	}

	public function test_the_hours_rule_keeps_events_for_that_long(): void {
		$this->seed_the_cast();

		$two_hours = $this->render( '{"hideAfter":"hours","hideAfterHours":2}' );
		$one_hour  = $this->render( '{"hideAfter":"hours","hideAfterHours":1}' );

		$this->assertStringContainsString( 'Ninety Minutes Ago', $two_hours );
		$this->assertStringNotContainsString( 'Ninety Minutes Ago', $one_hour );
		$this->assertStringNotContainsString( 'Two Hours Ago', $two_hours, 'two hours exactly has elapsed, so it is out' );

		$past_one_hour = $this->render( '{"hideAfter":"hours","hideAfterHours":1,"showPast":true}' );
		$this->assertStringContainsString( 'Ninety Minutes Ago', $past_one_hour );
		$this->assertStringContainsString( 'Two Hours Ago', $past_one_hour );
		$this->assertStringNotContainsString( 'Later Today', $past_one_hour );
	}

	public function test_yesterday_is_past_and_ongoing_is_upcoming_under_every_rule(): void {
		$this->seed_the_cast();

		foreach ( [ '"end"', '"day"', '"hours"' ] as $rule ) {
			$upcoming = $this->render( '{"hideAfter":' . $rule . ',"hideAfterHours":72}' );
			$past     = $this->render( '{"hideAfter":' . $rule . ',"hideAfterHours":72,"showPast":true}' );

			$this->assertStringContainsString( 'Ongoing Exhibit', $upcoming, "rule $rule" );
			$this->assertStringNotContainsString( 'Ongoing Exhibit', $past, "rule $rule" );

			if ( '"hours"' !== $rule ) {
				$this->assertStringNotContainsString( 'Yesterday Evening', $upcoming, "rule $rule" );
				$this->assertStringContainsString( 'Yesterday Evening', $past, "rule $rule" );
			}
		}
	}

	public function test_an_event_with_no_end_time_stays_until_the_end_of_its_day(): void {
		// The indexer gives an event with an end date but no end time end = start,
		// so it used to vanish at showtime. Indexed as such, three hours ago.
		$when = $this->local_now->modify( '-3 hours' );
		$utc  = $when->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$this->insert( 'Showtime Only', $utc, $utc, $when->format( 'Y-m-d' ), $when->format( 'Y-m-d' ) );

		$this->assertStringContainsString( 'Showtime Only', $this->render( '{}' ) );
		$this->assertStringNotContainsString( 'Showtime Only', $this->render( '{"hideAfter":"end"}' ) );
	}

	public function test_an_unknown_rule_is_the_default_and_hours_are_clamped(): void {
		$this->seed_the_cast();

		$this->assertStringContainsString( 'Two Hours Ago', $this->render( '{"hideAfter":"never"}' ) );

		// 0 hours is clamped to 1, which is not enough for the ninety-minute event.
		$this->assertStringNotContainsString( 'Ninety Minutes Ago', $this->render( '{"hideAfter":"hours","hideAfterHours":0}' ) );

		// 5000 hours is clamped to 72, which does not reach a week back.
		$this->seed( 'Last Week', '-7 days' );
		$this->assertStringNotContainsString( 'Last Week', $this->render( '{"hideAfter":"hours","hideAfterHours":5000}' ) );
	}

	public function test_the_filter_can_move_the_cutoff_but_only_to_a_valid_datetime(): void {
		$this->seed_the_cast();

		$to_the_past = static fn(): string => '2000-01-01 00:00:00';
		add_filter( 'blockendar_events_query_cutoff', $to_the_past );
		$this->assertStringContainsString( 'Yesterday Evening', $this->render( '{"hideAfter":"end"}' ), 'a valid filtered cutoff applies' );
		remove_filter( 'blockendar_events_query_cutoff', $to_the_past );

		$malformed = static fn(): string => 'last year';
		add_filter( 'blockendar_events_query_cutoff', $malformed );
		$this->assertStringNotContainsString( 'Yesterday Evening', $this->render( '{"hideAfter":"end"}' ), 'a malformed value is ignored' );
		$this->assertStringNotContainsString( 'Two Hours Ago', $this->render( '{"hideAfter":"end"}' ), 'and the rule\'s own cutoff is kept, not now-as-default' );
		remove_filter( 'blockendar_events_query_cutoff', $malformed );

		$received = null;
		$spy      = static function ( string $cutoff, array $attributes ) use ( &$received ): string {
			$received = [ $cutoff, $attributes['hideAfter'] ?? null ];
			return $cutoff;
		};
		add_filter( 'blockendar_events_query_cutoff', $spy, 10, 2 );
		$this->render( '{"hideAfter":"day"}' );
		remove_filter( 'blockendar_events_query_cutoff', $spy, 10 );

		$this->assertSame( [ Cutoff::start_of_today(), 'day' ], $received );
	}

	public function test_a_date_filter_for_today_keeps_the_cutoff_rule_and_the_count_agrees(): void {
		$this->seed_the_cast();

		$today = $this->local_now->format( 'Y-m-d' );
		$_GET  = [
			'blockendar_date_start' => $today,
			'blockendar_date_end'   => $today,
		];

		$default = $this->render( '{"perPage":1,"showPagination":true}' );
		$this->assertStringContainsString( 'blockendar-events-query__pagination', $default );

		// Two Hours Ago, Ninety Minutes Ago, Later Today, and the ongoing event
		// whose window overlaps: one per page, so the numbered page links (the
		// current page is a span with the same class) equal the row count.
		$numbered = static fn( string $html ): int => preg_match_all( '/class="page-numbers(?: current)?"/', $html );

		$this->assertSame( 4, $numbered( $default ), 'page links must match the rows the cutoff admits' );

		$end_rule = $this->render( '{"hideAfter":"end","perPage":1,"showPagination":true}' );
		$this->assertSame( 2, $numbered( $end_rule ), 'under the end rule only Later Today and the ongoing event remain' );

		// And the same clamp in past mode: today's finished events are not past yet.
		$past = $this->render( '{"showPast":true}' );
		$this->assertStringNotContainsString( 'Two Hours Ago', $past );
	}

	public function test_a_venue_whose_only_event_ended_earlier_today_is_still_offered(): void {
		$venue = self::factory()->term->create(
			[
				'taxonomy' => Venue::TAXONOMY,
				'name'     => 'Lounge Upstairs',
			]
		);
		$this->seed( 'Two Hours Ago', '-2 hours', [ 'venue_term_id' => $venue ] );

		$html = do_blocks( '<!-- wp:blockendar/filter-venue /-->' );

		$this->assertStringContainsString( 'Lounge Upstairs', $html );
	}
}
