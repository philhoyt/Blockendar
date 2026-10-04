<?php
/**
 * Integration coverage for three things found by using the plugin on a site.
 *
 * - The status badge read the event's own status and nothing else, so the
 *   page for one cancelled occurrence of a series did not say it was cancelled.
 * - A date skipped from a series that ends "after N times" was made up for
 *   with an extra date at the end the next time the event was saved.
 * - The events route refused `order=desc`, the spelling WordPress's own
 *   routes use, and accepted only `DESC`.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\DB\EventIndex;
use Blockendar\DB\IndexBuilder;
use Blockendar\DB\Schema;
use Blockendar\Recurrence\RuleRepository;
use WP_REST_Request;
use WP_UnitTestCase;

class SpotTestFindingsTest extends WP_UnitTestCase {

	private RuleRepository $rules;

	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		Schema::create_tables();

		foreach ( [ Schema::events_table(), Schema::type_terms_table(), Schema::recurrence_table() ] as $table ) {
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
		}

		$this->rules = new RuleRepository();

		_set_cron_array( [] );
		delete_option( 'blockendar_settings' );
		IndexBuilder::forget_dirty();
		( new EventIndex() )->flush_cache();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
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
	 * Create and index a published event starting 9 March 2027.
	 *
	 * @param string     $title Post title.
	 * @param array|null $rule  Repeat rule, or null for a single event.
	 * @param array      $meta  Extra meta.
	 * @return int Post ID.
	 */
	private function make_event( string $title, ?array $rule = null, array $meta = [] ): int {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'publish',
				'post_title'  => $title,
				'meta_input'  => array_merge(
					[
						'blockendar_start_date' => '2027-03-09',
						'blockendar_end_date'   => '2027-03-09',
						'blockendar_start_time' => '19:00',
						'blockendar_end_time'   => '21:00',
						'blockendar_timezone'   => 'UTC',
					],
					$meta
				),
			]
		);

		if ( null !== $rule ) {
			$this->rules->upsert( $post_id, $rule );
		}

		( new IndexBuilder() )->build_for_post( $post_id );

		return $post_id;
	}

	/**
	 * A weekly series of six with its second date cancelled.
	 *
	 * @return int Post ID.
	 */
	private function make_series_with_a_cancelled_date(): int {
		$post_id = $this->make_event(
			'Tuesday Class',
			[
				'frequency' => 'weekly',
				'count'     => 6,
			]
		);

		$this->rules->add_cancellation( $post_id, '2027-03-16' );
		( new IndexBuilder() )->build_for_post( $post_id );
		( new EventIndex() )->flush_cache();

		return $post_id;
	}

	/**
	 * Render the status badge for an event, as text.
	 *
	 * @param int $post_id Event post ID.
	 */
	private function badge( int $post_id ): string {
		$html = ( new \WP_Block( [ 'blockName' => 'blockendar/event-status' ], [ 'postId' => $post_id ] ) )->render();

		return trim( wp_strip_all_tags( $html ) );
	}

	/**
	 * The start dates the index holds for an event, in order.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	private function dates( int $post_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_col( $wpdb->prepare( 'SELECT start_date FROM %i WHERE post_id = %d ORDER BY start_datetime', Schema::events_table(), $post_id ) );
	}

	// -------------------------------------------------------------------------
	// The status of one occurrence
	// -------------------------------------------------------------------------

	public function test_the_page_for_a_cancelled_occurrence_says_cancelled(): void {
		$post_id = $this->make_series_with_a_cancelled_date();

		$_GET['occurrence_date'] = '2027-03-16';

		$this->assertSame( 'Cancelled', $this->badge( $post_id ) );
	}

	public function test_the_page_for_another_occurrence_of_the_same_event_does_not(): void {
		$post_id = $this->make_series_with_a_cancelled_date();

		$_GET['occurrence_date'] = '2027-03-23';

		$this->assertSame( '', $this->badge( $post_id ) );
	}

	/**
	 * In a list every item is one occurrence, and each has to carry its own
	 * status: five rows of a class that is on, and one that is off.
	 */
	public function test_a_list_marks_the_cancelled_occurrence_and_only_that_one(): void {
		$this->make_series_with_a_cancelled_date();

		// Far enough back that March 2027 is "upcoming" whenever this runs.
		$html = do_blocks(
			'<!-- wp:blockendar/events-query {"perPage":10} -->'
			. '<!-- wp:blockendar/event-template --><!-- wp:blockendar/event-datetime {"dateFormat":"Y-m-d"} /--><!-- wp:blockendar/event-status /--><!-- /wp:blockendar/event-template -->'
			. '<!-- /wp:blockendar/events-query -->'
		);

		$this->assertSame( 1, substr_count( $html, 'blockendar-status--cancelled' ), 'One badge in a list of six occurrences.' );

		$items = explode( '</li>', $html );
		$mine  = array_values( array_filter( $items, static fn( $item ) => str_contains( $item, 'blockendar-status--cancelled' ) ) );

		$this->assertStringContainsString( '2027-03-16', $mine[0], 'And it is on the occurrence that was cancelled.' );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public function event_statuses(): array {
		return [
			'cancelled' => [ 'cancelled', 'Cancelled' ],
			'postponed' => [ 'postponed', 'Postponed' ],
			'sold out'  => [ 'sold_out', 'Sold Out' ],
		];
	}

	/**
	 * @dataProvider event_statuses
	 *
	 * @param string $status Event status.
	 * @param string $label  What the badge prints.
	 */
	public function test_an_events_own_status_still_shows_on_every_occurrence( string $status, string $label ): void {
		$post_id = $this->make_event(
			'Whole Series',
			[
				'frequency' => 'weekly',
				'count'     => 3,
			],
			[ 'blockendar_status' => $status ]
		);

		$_GET['occurrence_date'] = '2027-03-16';

		$this->assertSame( $label, $this->badge( $post_id ) );
	}

	/**
	 * A postponed series with one date called off outright: on that date the
	 * stronger of the two is what a visitor needs to see.
	 */
	public function test_a_cancelled_occurrence_of_a_postponed_event_says_cancelled(): void {
		$post_id = $this->make_event(
			'Postponed Series',
			[
				'frequency' => 'weekly',
				'count'     => 3,
			],
			[ 'blockendar_status' => 'postponed' ]
		);

		$this->rules->add_cancellation( $post_id, '2027-03-16' );
		( new IndexBuilder() )->build_for_post( $post_id );
		( new EventIndex() )->flush_cache();

		$_GET['occurrence_date'] = '2027-03-16';
		$this->assertSame( 'Cancelled', $this->badge( $post_id ) );

		$_GET['occurrence_date'] = '2027-03-23';
		$this->assertSame( 'Postponed', $this->badge( $post_id ) );
	}

	/**
	 * A draft has no rows in the index to read a cancellation from, and its
	 * own status is all there is.
	 */
	public function test_an_event_with_no_index_rows_falls_back_to_its_own_status(): void {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'draft',
				'meta_input'  => [ 'blockendar_status' => 'postponed' ],
			]
		);

		$this->assertSame( 'Postponed', $this->badge( $post_id ) );
	}

	// -------------------------------------------------------------------------
	// A skipped date in a series of N
	// -------------------------------------------------------------------------

	/**
	 * Skipping one class out of six leaves five. It did, until the event was
	 * next saved; then a seventh Tuesday appeared to bring the count back up.
	 */
	public function test_a_skipped_date_is_not_made_up_for_at_the_end(): void {
		$post_id = $this->make_event(
			'Six Classes',
			[
				'frequency' => 'weekly',
				'count'     => 6,
			]
		);

		$this->assertSame( '2027-04-13', $this->dates( $post_id )[5], 'Precondition: the sixth and last date.' );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'POST', "/blockendar/v1/events/{$post_id}/instances/2027-03-23/exception" ) );
		$this->assertSame( 200, $response->get_status() );

		$expected = [ '2027-03-09', '2027-03-16', '2027-03-30', '2027-04-06', '2027-04-13' ];

		$this->assertSame( $expected, $this->dates( $post_id ), 'Straight after skipping.' );

		( new IndexBuilder() )->build_for_post( $post_id );
		$this->assertSame( $expected, $this->dates( $post_id ), 'After the next save.' );

		( new IndexBuilder() )->rebuild_all();
		$this->assertSame( $expected, $this->dates( $post_id ), 'After a full rebuild.' );
	}

	// -------------------------------------------------------------------------
	// order=desc
	// -------------------------------------------------------------------------

	/**
	 * @return array<string, array{string, int, bool}>
	 */
	public function orders(): array {
		return [
			'upper case descending' => [ 'DESC', 200, true ],
			'lower case descending' => [ 'desc', 200, true ],
			'lower case ascending'  => [ 'asc', 200, false ],
			'upper case ascending'  => [ 'ASC', 200, false ],
			'nonsense'              => [ 'sideways', 400, false ],
		];
	}

	/**
	 * @dataProvider orders
	 *
	 * @param string $order    Value of the order parameter.
	 * @param int    $status   Expected response status.
	 * @param bool   $reversed Whether the newest event comes first.
	 */
	public function test_the_events_route_takes_order_in_either_case( string $order, int $status, bool $reversed ): void {
		$this->make_event( 'Earlier' );
		$this->make_event(
			'Later',
			null,
			[
				'blockendar_start_date' => '2027-03-20',
				'blockendar_end_date'   => '2027-03-20',
			]
		);

		$request = new WP_REST_Request( 'GET', '/blockendar/v1/events' );
		$request->set_query_params(
			[
				'start' => '2027-03-01',
				'end'   => '2027-03-31',
				'order' => $order,
			]
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( $status, $response->get_status() );

		if ( 200 === $status ) {
			$this->assertSame(
				$reversed ? [ 'Later', 'Earlier' ] : [ 'Earlier', 'Later' ],
				array_column( $response->get_data(), 'title' )
			);
		}
	}
}
