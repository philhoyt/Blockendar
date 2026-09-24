<?php
/**
 * Integration coverage for carrying the view switcher's choice through the
 * controls that navigate: filter forms and pagination.
 *
 * The filter forms submit with GET, which replaces the action URL's query
 * string with the form's own fields. Each filter therefore re-injects the
 * request's view as a hidden input, and the events query stamps its ID on the
 * pagination nav so the switcher's script can rewrite those links after an
 * in-place swap.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\Blocks\FilterContext;
use Blockendar\DB\EventIndex;
use Blockendar\DB\Schema;
use Blockendar\Taxonomy\EventType;
use Blockendar\Taxonomy\Venue;
use WP_UnitTestCase;

class ViewParamPreservationTest extends WP_UnitTestCase {

	private EventIndex $index;

	public function set_up(): void {
		parent::set_up();

		Schema::create_tables();
		$this->index = new EventIndex();
		$this->index->flush_cache();

		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Schema::events_table() ); // phpcs:ignore WordPress.DB

		// Terms are enough for the venue and type filters to render once the
		// blocks are told to keep empty terms; no indexed event needs to carry them.
		self::factory()->term->create( [ 'taxonomy' => Venue::TAXONOMY ] );
		self::factory()->term->create( [ 'taxonomy' => EventType::TAXONOMY ] );
	}

	public function tear_down(): void {
		$_GET = [];
		parent::tear_down();
	}

	/**
	 * The three filter blocks, each configured so it renders with no events.
	 *
	 * @return array<string, array{string}>
	 */
	public function filter_block_provider(): array {
		return [
			'venue'      => [ '<!-- wp:blockendar/filter-venue {"showEmpty":true} /-->' ],
			'event type' => [ '<!-- wp:blockendar/filter-event-type {"showEmptyTerms":true} /-->' ],
			'date range' => [ '<!-- wp:blockendar/filter-date-range /-->' ],
		];
	}

	/**
	 * @dataProvider filter_block_provider
	 */
	public function test_a_requested_view_travels_as_a_hidden_input( string $block ): void {
		$_GET = [ 'blockendar_view' => 'grid' ];

		$html = do_blocks( $block );

		$this->assertStringContainsString( '<form', $html, 'the filter must render for the assertion to mean anything' );
		$this->assertStringContainsString( '<input type="hidden" name="blockendar_view" value="grid">', $html );
	}

	/**
	 * @dataProvider filter_block_provider
	 */
	public function test_no_view_means_no_hidden_input( string $block ): void {
		$html = do_blocks( $block );

		$this->assertStringContainsString( '<form', $html );
		$this->assertStringNotContainsString( 'name="blockendar_view"', $html );
	}

	/**
	 * @dataProvider filter_block_provider
	 */
	public function test_a_mode_outside_the_allow_list_is_dropped( string $block ): void {
		$_GET = [ 'blockendar_view' => 'masonry' ];

		$html = do_blocks( $block );

		$this->assertStringContainsString( '<form', $html );
		$this->assertStringNotContainsString( 'name="blockendar_view"', $html );
		$this->assertStringNotContainsString( 'masonry', $html );
	}

	/**
	 * @dataProvider filter_block_provider
	 */
	public function test_an_explicit_default_is_passed_through( string $block ): void {
		// The server cannot know which mode the switcher treats as default, so it
		// carries whatever was requested; dropping the parameter is the script's job.
		$_GET = [ 'blockendar_view' => 'list' ];

		$html = do_blocks( $block );

		$this->assertStringContainsString( '<input type="hidden" name="blockendar_view" value="list">', $html );
	}

	public function test_the_hidden_input_is_scoped_to_its_query(): void {
		$_GET = [
			'blockendar_view'         => 'grid',
			'blockendar_view_sidebar' => 'list',
		];

		$html = do_blocks(
			'<!-- wp:blockendar/query-filters {"queryId":"sidebar"} -->'
			. '<!-- wp:blockendar/filter-date-range /-->'
			. '<!-- /wp:blockendar/query-filters -->'
		);

		$this->assertStringContainsString( '<input type="hidden" name="blockendar_view_sidebar" value="list">', $html );
		$this->assertStringNotContainsString( 'name="blockendar_view"', $html );
	}

	public function test_hidden_view_input_helper_escapes_and_scopes(): void {
		$_GET = [ 'blockendar_view_side' => 'grid' ];

		$this->assertSame( '', FilterContext::hidden_view_input( '' ) );
		$this->assertSame(
			'<input type="hidden" name="blockendar_view_side" value="grid">',
			FilterContext::hidden_view_input( 'side' )
		);
	}

	public function test_pagination_nav_carries_its_query_id(): void {
		$this->seed_event( '2025-09-10' );
		$this->seed_event( '2025-09-11' );

		$html = do_blocks(
			'<!-- wp:blockendar/query-filters {"queryId":"Side Bar"} -->'
			. '<!-- wp:blockendar/events-query {"perPage":1,"showPagination":true,"showPast":true} -->'
			. '<!-- wp:blockendar/event-template --><!-- wp:post-title /--><!-- /wp:blockendar/event-template -->'
			. '<!-- /wp:blockendar/events-query -->'
			. '<!-- /wp:blockendar/query-filters -->'
		);

		// Normalised with sanitize_key(), the same as the wrapper and the switcher.
		$this->assertStringContainsString( 'class="blockendar-events-query__pagination" data-query-id="sidebar"', $html );
		$this->assertStringContainsString( 'data-blockendar-query-id="sidebar"', $html );
		$this->assertMatchesRegularExpression( '/<ul[^>]*data-query-id="sidebar"/', $html );
	}

	/**
	 * Insert an indexed occurrence.
	 *
	 * @param string $date Y-m-d occurrence date.
	 */
	private function seed_event( string $date ): void {
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
				'type_term_ids'  => [],
			]
		);
	}
}
