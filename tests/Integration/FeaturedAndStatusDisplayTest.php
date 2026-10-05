<?php
/**
 * Integration coverage for showing that an event is featured, or is not going
 * ahead as planned.
 *
 * The editor's "Featured event" toggle promised to highlight the event in
 * listings and on the calendar, and nothing read it. A cancelled event looked
 * like any other on the calendar. And a status had nowhere to say why.
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

class FeaturedAndStatusDisplayTest extends WP_UnitTestCase {

	private const TEMPLATE = '<!-- wp:blockendar/event-template --><!-- wp:post-title /--><!-- wp:blockendar/event-datetime {"dateFormat":"Y-m-d"} /--><!-- /wp:blockendar/event-template -->';

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
		wp_reset_postdata();
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
	 * The class attribute of the list item that mentions some text.
	 *
	 * @param string $html   Listing markup.
	 * @param string $needle Text inside the item.
	 */
	private function item_classes( string $html, string $needle ): string {
		foreach ( explode( '</li>', $html ) as $item ) {
			if ( str_contains( $item, $needle ) && preg_match( '/<li class="([^"]*blockendar-events-query__item[^"]*)"/', $item, $found ) ) {
				return $found[1];
			}
		}

		return '';
	}

	/**
	 * The calendar payload for March 2027, keyed by "title start".
	 *
	 * @return array<string, array>
	 */
	private function calendar(): array {
		$request = new WP_REST_Request( 'GET', '/blockendar/v1/calendar' );
		$request->set_query_params(
			[
				'start' => '2027-03-01T00:00:00',
				'end'   => '2027-04-01T00:00:00',
			]
		);

		$events = [];

		foreach ( rest_get_server()->dispatch( $request )->get_data() as $event ) {
			$events[ $event['title'] . ' ' . substr( $event['start'], 0, 10 ) ] = $event;
		}

		return $events;
	}

	/**
	 * Render the status block for an event, as text.
	 *
	 * @param int   $post_id Event post ID.
	 * @param array $attrs   Block attributes.
	 */
	private function badge( int $post_id, array $attrs = [] ): string {
		$html = ( new \WP_Block(
			[
				'blockName' => 'blockendar/event-status',
				'attrs'     => $attrs,
			],
			[ 'postId' => $post_id ]
		) )->render();

		return trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) );
	}

	// -------------------------------------------------------------------------
	// Lists
	// -------------------------------------------------------------------------

	public function test_each_item_in_a_list_says_what_it_is(): void {
		$this->make_event( 'Plain Evening' );
		$this->make_event( 'Gala Night', [ 'featured' => true ] );
		$this->make_event( 'Called Off', [ 'status' => 'cancelled' ] );

		$html = $this->listing();

		$this->assertSame( 'blockendar-events-query__item is-status-scheduled', $this->item_classes( $html, 'Plain Evening' ) );
		$this->assertSame( 'blockendar-events-query__item is-featured is-status-scheduled', $this->item_classes( $html, 'Gala Night' ) );
		$this->assertSame( 'blockendar-events-query__item is-status-cancelled', $this->item_classes( $html, 'Called Off' ) );
	}

	/**
	 * An item is one occurrence. One cancelled date of a series is marked, and
	 * the dates either side of it are not.
	 */
	public function test_one_cancelled_date_of_a_series_is_the_only_one_marked(): void {
		$post_id = $this->make_event(
			'Tuesday Class',
			[],
			[
				'frequency' => 'weekly',
				'count'     => 3,
			]
		);

		( new RuleRepository() )->add_cancellation( $post_id, '2027-03-16' );
		( new IndexBuilder() )->build_for_post( $post_id );
		( new EventIndex() )->flush_cache();

		$html = $this->listing();

		$this->assertStringContainsString( 'is-status-cancelled', $this->item_classes( $html, '2027-03-16' ) );
		$this->assertStringContainsString( 'is-status-scheduled', $this->item_classes( $html, '2027-03-09' ) );
		$this->assertStringContainsString( 'is-status-scheduled', $this->item_classes( $html, '2027-03-23' ) );
	}

	// -------------------------------------------------------------------------
	// Featured only
	// -------------------------------------------------------------------------

	public function test_a_list_can_show_featured_events_alone(): void {
		$this->make_event( 'Plain Evening' );
		$this->make_event( 'Gala Night', [ 'featured' => true ] );

		$html = $this->listing( '{"perPage":20,"featuredOnly":true}' );

		$this->assertStringContainsString( 'Gala Night', $html );
		$this->assertStringNotContainsString( 'Plain Evening', $html );
	}

	/**
	 * The page links are worked out from a count. Counting every event while
	 * listing only the featured ones would offer pages with nothing on them.
	 */
	public function test_the_pages_of_a_featured_list_count_featured_events_only(): void {
		foreach ( range( 1, 3 ) as $n ) {
			$this->make_event( "Gala {$n}", [ 'featured' => true ] );
		}

		foreach ( range( 1, 6 ) as $n ) {
			$this->make_event( "Plain {$n}" );
		}

		$every    = $this->listing( '{"perPage":2,"showPagination":true}' );
		$featured = $this->listing( '{"perPage":2,"showPagination":true,"featuredOnly":true}' );

		preg_match_all( '/class="page-numbers[^"]*"[^>]*>(\d+)</', $every, $every_pages );
		preg_match_all( '/class="page-numbers[^"]*"[^>]*>(\d+)</', $featured, $featured_pages );

		$this->assertSame( 5, (int) max( $every_pages[1] ), 'Precondition: nine events, two to a page.' );
		$this->assertSame( 2, (int) max( $featured_pages[1] ), 'Three featured events, two to a page.' );
	}

	public function test_featured_only_applies_on_an_archive_that_sets_the_query(): void {
		$type = self::factory()->term->create( [ 'taxonomy' => 'blockendar_event_type' ] );

		$plain = $this->make_event( 'Plain Evening' );
		$gala  = $this->make_event( 'Gala Night', [ 'featured' => true ] );

		wp_set_object_terms( $plain, [ $type ], 'blockendar_event_type' );
		wp_set_object_terms( $gala, [ $type ], 'blockendar_event_type' );
		( new IndexBuilder() )->flush_dirty();
		( new EventIndex() )->flush_cache();

		$this->go_to( '/?blockendar_event_type=' . get_term( $type )->slug );

		$every    = $this->listing( '{"perPage":20,"inherit":true}' );
		$featured = $this->listing( '{"perPage":20,"inherit":true,"featuredOnly":true}' );

		$this->assertStringContainsString( 'Plain Evening', $every, 'Precondition: the archive lists both.' );
		$this->assertStringContainsString( 'Gala Night', $featured );
		$this->assertStringNotContainsString( 'Plain Evening', $featured );
	}

	public function test_featured_only_applies_to_related_events(): void {
		$type = self::factory()->term->create( [ 'taxonomy' => 'blockendar_event_type' ] );

		$subject = $this->make_event( 'The Subject' );
		$plain   = $this->make_event( 'Plain Sibling' );
		$gala    = $this->make_event( 'Gala Sibling', [ 'featured' => true ] );

		foreach ( [ $subject, $plain, $gala ] as $id ) {
			wp_set_object_terms( $id, [ $type ], 'blockendar_event_type' );
		}

		( new IndexBuilder() )->flush_dirty();
		( new EventIndex() )->flush_cache();

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The related list reads the current post.
		$GLOBALS['post'] = get_post( $subject );
		setup_postdata( $GLOBALS['post'] );

		$every    = $this->listing( '{"perPage":20,"relatedTo":"type"}' );
		$featured = $this->listing( '{"perPage":20,"relatedTo":"type","featuredOnly":true}' );

		$this->assertStringContainsString( 'Plain Sibling', $every, 'Precondition: both siblings are related.' );
		$this->assertStringContainsString( 'Gala Sibling', $featured );
		$this->assertStringNotContainsString( 'Plain Sibling', $featured );
	}

	// -------------------------------------------------------------------------
	// The calendar
	// -------------------------------------------------------------------------

	/**
	 * FullCalendar puts an event's classNames on its element, which is what
	 * the stylesheet strikes through and makes bold.
	 */
	public function test_the_calendar_is_told_which_events_are_featured_and_which_are_off(): void {
		$this->make_event( 'Plain Evening' );
		$this->make_event( 'Gala Night', [ 'featured' => true ] );
		$this->make_event( 'Called Off', [ 'status' => 'cancelled' ] );

		$events = $this->calendar();

		$this->assertSame( [ 'is-status-scheduled' ], $events['Plain Evening 2027-03-09']['classNames'] );
		$this->assertSame( [ 'is-featured', 'is-status-scheduled' ], $events['Gala Night 2027-03-09']['classNames'] );
		$this->assertSame( [ 'is-status-cancelled' ], $events['Called Off 2027-03-09']['classNames'] );
	}

	public function test_the_calendars_plain_list_carries_the_same_classes(): void {
		$this->make_event(
			'Gala Night',
			[
				'featured' => true,
				'status'   => 'postponed',
			]
		);

		$html = do_blocks( '<!-- wp:blockendar/calendar-view /-->' );

		$this->assertMatchesRegularExpression( '/<li class="blockendar-calendar-fallback__item is-featured is-status-postponed">/', $html );
	}

	// -------------------------------------------------------------------------
	// The reason
	// -------------------------------------------------------------------------

	public function test_a_reason_is_shown_with_the_status(): void {
		$post_id = $this->make_event(
			'Postponed Talk',
			[
				'status'        => 'postponed',
				'status_reason' => 'The speaker is unwell. A new date will follow.',
			]
		);

		$this->assertSame( 'Postponed The speaker is unwell. A new date will follow.', $this->badge( $post_id ) );
	}

	public function test_the_reason_can_be_left_out_of_the_block(): void {
		$post_id = $this->make_event(
			'Postponed Talk',
			[
				'status'        => 'postponed',
				'status_reason' => 'The speaker is unwell.',
			]
		);

		$this->assertSame( 'Postponed', $this->badge( $post_id, [ 'showReason' => false ] ) );
	}

	/**
	 * A reason written while the event was postponed is still stored when it
	 * is put back on. It must not be shown under a status it does not explain.
	 */
	public function test_a_reason_left_over_from_an_earlier_status_is_not_shown(): void {
		$post_id = $this->make_event(
			'Back On',
			[
				'status'        => 'scheduled',
				'status_reason' => 'The speaker is unwell.',
			]
		);

		$this->assertSame( '', $this->badge( $post_id ) );
	}

	/**
	 * The reason explains the event's status. One date cancelled on its own
	 * has no reason of its own to give, and does not borrow the event's.
	 */
	public function test_a_single_cancelled_date_does_not_borrow_the_events_reason(): void {
		$post_id = $this->make_event(
			'Postponed Series',
			[
				'status'        => 'postponed',
				'status_reason' => 'The hall is being repaired.',
			],
			[
				'frequency' => 'weekly',
				'count'     => 3,
			]
		);

		( new RuleRepository() )->add_cancellation( $post_id, '2027-03-16' );
		( new IndexBuilder() )->build_for_post( $post_id );
		( new EventIndex() )->flush_cache();

		$_GET['occurrence_date'] = '2027-03-16';
		$this->assertSame( 'Cancelled', $this->badge( $post_id ) );

		$_GET['occurrence_date'] = '2027-03-23';
		$this->assertSame( 'Postponed The hall is being repaired.', $this->badge( $post_id ) );
	}

	public function test_a_reason_is_plain_text(): void {
		$post_id = self::factory()->post->create( [ 'post_type' => 'blockendar_event' ] );

		update_post_meta( $post_id, 'blockendar_status_reason', " <b>Flooding</b> in the hall<script>alert(1)</script>\n" );

		$this->assertSame( 'Flooding in the hall', get_post_meta( $post_id, 'blockendar_status_reason', true ) );
	}

	// -------------------------------------------------------------------------
	// The reason in the REST API
	// -------------------------------------------------------------------------

	public function test_the_event_route_gives_the_reason_only_while_it_applies(): void {
		$postponed = $this->make_event(
			'Postponed Talk',
			[
				'status'        => 'postponed',
				'status_reason' => 'The speaker is unwell.',
			]
		);
		$back_on   = $this->make_event(
			'Back On',
			[
				'status'        => 'scheduled',
				'status_reason' => 'The speaker is unwell.',
			]
		);

		$first  = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/blockendar/v1/events/{$postponed}" ) )->get_data();
		$second = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/blockendar/v1/events/{$back_on}" ) )->get_data();

		$this->assertSame( 'The speaker is unwell.', $first['meta']['status_reason'] );
		$this->assertSame( '', $second['meta']['status_reason'], 'The route is public. An old reason is not served.' );
	}

	/**
	 * The editor hides the field when the event is back on, and the text stays
	 * in the post's meta, which WordPress's own route serves to anyone. Saving
	 * the event as scheduled clears it.
	 */
	public function test_saving_an_event_as_scheduled_clears_its_reason(): void {
		$post_id = $this->make_event(
			'Postponed Talk',
			[
				'status'        => 'postponed',
				'status_reason' => 'The speaker is unwell.',
			]
		);

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$request = new WP_REST_Request( 'POST', "/wp/v2/blockendar-events/{$post_id}" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( [ 'meta' => [ 'blockendar_status' => 'scheduled' ] ] ) );

		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );
		$this->assertSame( '', get_post_meta( $post_id, 'blockendar_status_reason', true ) );
	}

	public function test_saving_an_event_that_is_still_postponed_keeps_its_reason(): void {
		$post_id = $this->make_event(
			'Postponed Talk',
			[
				'status'        => 'postponed',
				'status_reason' => 'The speaker is unwell.',
			]
		);

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$request = new WP_REST_Request( 'POST', "/wp/v2/blockendar-events/{$post_id}" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( [ 'title' => 'Postponed Talk (new date soon)' ] ) );

		rest_get_server()->dispatch( $request );

		$this->assertSame( 'The speaker is unwell.', get_post_meta( $post_id, 'blockendar_status_reason', true ) );
	}
}
