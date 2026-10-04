<?php
/**
 * Integration coverage for the Google and Outlook links in the add-to-calendar block.
 *
 * Each link is a URL another service parses, so the only thing worth asserting
 * is what that service will read out of it: the block is rendered and its
 * hrefs are parsed back into parameters.
 *
 * The formats are the ones documented at
 * https://github.com/InteractionDesignFoundation/add-event-to-calendar-docs —
 * neither Google nor Microsoft publishes one.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\DB\EventIndex;
use Blockendar\DB\IndexBuilder;
use Blockendar\DB\Schema;
use Blockendar\Recurrence\Generator;
use Blockendar\Recurrence\RuleRepository;
use WP_UnitTestCase;

class AddToCalendarLinksTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		Schema::create_tables();
		( new EventIndex() )->flush_cache();

		global $wpdb;
		$events = Schema::events_table();
		$wpdb->query( "DELETE FROM {$events}" ); // phpcs:ignore WordPress.DB

		delete_option( 'blockendar_settings' );
	}

	public function tear_down(): void {
		unset( $GLOBALS['blockendar_current_occurrence'] );
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
	 * @param array $post Post field overrides.
	 * @return int Post ID.
	 */
	private function make_event( array $meta, array $post = [] ): int {
		$meta_input = [];

		foreach ( $meta as $key => $value ) {
			$meta_input[ "blockendar_{$key}" ] = $value;
		}

		$post_id = self::factory()->post->create(
			array_merge(
				[
					'post_type'    => 'blockendar_event',
					'post_status'  => 'publish',
					'post_title'   => 'Harvest Supper',
					'post_excerpt' => 'Long tables in the orchard.',
					'meta_input'   => $meta_input,
				],
				$post
			)
		);

		( new IndexBuilder() )->build_for_post( $post_id );
		( new EventIndex() )->flush_cache();

		return $post_id;
	}

	/**
	 * A 19:00–21:00 event in Chicago in November, when Chicago is UTC-6.
	 */
	private function timed(): array {
		return [
			'start_date' => '2026-11-10',
			'end_date'   => '2026-11-10',
			'start_time' => '19:00',
			'end_time'   => '21:00',
			'timezone'   => 'America/Chicago',
		];
	}

	/**
	 * An all-day event.
	 *
	 * @param string $start First day.
	 * @param string $end   Last day.
	 */
	private function all_day( string $start, string $end ): array {
		return [
			'start_date' => $start,
			'end_date'   => $end,
			'all_day'    => '1',
			'timezone'   => 'America/Chicago',
		];
	}

	/**
	 * Create a venue and attach it to an event.
	 *
	 * @param int   $post_id Event post ID.
	 * @param array $meta    Venue term meta, without the blockendar_venue_ prefix.
	 */
	private function attach_venue( int $post_id, array $meta ): void {
		$term = self::factory()->term->create_and_get(
			[
				'taxonomy' => 'blockendar_event_venue',
				'name'     => 'Orchard Hall',
			]
		);

		foreach ( $meta as $key => $value ) {
			update_term_meta( $term->term_id, "blockendar_venue_{$key}", $value );
		}

		wp_set_object_terms( $post_id, [ $term->term_id ], 'blockendar_event_venue' );
	}

	/**
	 * Render the block for an event and return each link's URL parts.
	 *
	 * @param int $post_id Event post ID.
	 * @return array<string, array{host: string, path: string, query: array<string, string>}> Keyed by link text.
	 */
	private function links( int $post_id ): array {
		$html = ( new \WP_Block(
			[
				'blockName' => 'blockendar/add-to-calendar',
				'attrs'     => [],
			],
			[ 'postId' => $post_id ]
		) )->render();

		preg_match_all( '/<a[^>]+href="([^"]+)"[^>]*>\s*([^<]+?)\s*<\/a>/', $html, $matches, PREG_SET_ORDER );

		$links = [];

		foreach ( $matches as [ , $href, $text ] ) {
			$parts = wp_parse_url( html_entity_decode( $href, ENT_QUOTES ) );

			parse_str( $parts['query'] ?? '', $query );

			$links[ $text ] = [
				'host'  => $parts['host'],
				'path'  => $parts['path'],
				'query' => $query,
			];
		}

		return $links;
	}

	// -------------------------------------------------------------------------
	// Timed events
	// -------------------------------------------------------------------------

	public function test_google_gets_a_timed_event_as_a_utc_range_with_its_timezone(): void {
		$google = $this->links( $this->make_event( $this->timed() ) )['Google Calendar'];

		$this->assertSame( 'calendar.google.com', $google['host'] );
		$this->assertSame( 'TEMPLATE', $google['query']['action'] );
		$this->assertSame( 'Harvest Supper', $google['query']['text'] );
		$this->assertSame( '20261111T010000Z/20261111T030000Z', $google['query']['dates'] );
		$this->assertSame( 'America/Chicago', $google['query']['ctz'] );
	}

	/**
	 * Outlook reads a time with no "Z" in the viewer's own timezone. Sent that
	 * way, a 19:00 Chicago event lands at 19:00 wherever the visitor is.
	 */
	public function test_outlook_gets_a_timed_event_as_utc_instants(): void {
		$links = $this->links( $this->make_event( $this->timed() ) );

		foreach ( [ 'Outlook 365', 'Outlook Live' ] as $service ) {
			$query = $links[ $service ]['query'];

			$this->assertSame( '2026-11-11T01:00:00Z', $query['startdt'], $service );
			$this->assertSame( '2026-11-11T03:00:00Z', $query['enddt'], $service );
			$this->assertSame( 'Harvest Supper', $query['subject'], $service );
			$this->assertArrayNotHasKey( 'allday', $query, $service );
		}
	}

	public function test_outlook_links_carry_the_compose_action(): void {
		$links = $this->links( $this->make_event( $this->timed() ) );

		$this->assertSame( 'outlook.office.com', $links['Outlook 365']['host'] );
		$this->assertSame( 'outlook.live.com', $links['Outlook Live']['host'] );

		foreach ( [ 'Outlook 365', 'Outlook Live' ] as $service ) {
			$this->assertSame( '/calendar/0/deeplink/compose', $links[ $service ]['path'], $service );
			$this->assertSame( '/calendar/action/compose', $links[ $service ]['query']['path'], $service );
			$this->assertSame( 'addevent', $links[ $service ]['query']['rru'], $service );
		}
	}

	public function test_an_offset_timezone_is_not_sent_to_google_as_a_named_zone(): void {
		$google = $this->links( $this->make_event( array_merge( $this->timed(), [ 'timezone' => '+05:30' ] ) ) )['Google Calendar'];

		$this->assertSame( '20261110T133000Z/20261110T153000Z', $google['query']['dates'] );
		$this->assertArrayNotHasKey( 'ctz', $google['query'] );
	}

	// -------------------------------------------------------------------------
	// All-day events
	// -------------------------------------------------------------------------

	/**
	 * Both services take an all-day end as the day after the last day.
	 *
	 * @return array<string, array{string, string, string, string}>
	 */
	public function all_day_spans(): array {
		return [
			'one day'  => [ '2026-11-10', '2026-11-10', '20261110/20261111', '2026-11-11' ],
			'two days' => [ '2026-11-10', '2026-11-11', '20261110/20261112', '2026-11-12' ],
		];
	}

	/**
	 * @dataProvider all_day_spans
	 *
	 * @param string $start        First day.
	 * @param string $end          Last day.
	 * @param string $google_dates Expected Google dates value.
	 * @param string $outlook_end  Expected Outlook enddt.
	 */
	public function test_an_all_day_event_ends_the_day_after_its_last_day( string $start, string $end, string $google_dates, string $outlook_end ): void {
		$links = $this->links( $this->make_event( $this->all_day( $start, $end ) ) );

		$this->assertSame( $google_dates, $links['Google Calendar']['query']['dates'] );

		foreach ( [ 'Outlook 365', 'Outlook Live' ] as $service ) {
			$query = $links[ $service ]['query'];

			$this->assertSame( $start, $query['startdt'], $service );
			$this->assertSame( $outlook_end, $query['enddt'], $service );
			$this->assertSame( 'true', $query['allday'], $service );
		}
	}

	/**
	 * The block reads its dates from the occurrence being shown. A recurring
	 * occurrence used to carry the day after its last day, so adding a day for
	 * the services would have made it two days long.
	 */
	public function test_a_recurring_all_day_occurrence_is_one_day_long(): void {
		$post_id = $this->make_event( $this->all_day( '2026-11-10', '2026-11-10' ) );

		( new RuleRepository() )->upsert(
			$post_id,
			[
				'frequency' => 'weekly',
				'interval'  => 1,
				'count'     => 3,
			]
		);
		( new Generator() )->generate_for_post( $post_id );
		( new EventIndex() )->flush_cache();

		$second = EventIndex::get_occurrence_by_date( $post_id, '2026-11-17' );

		$this->assertNotNull( $second, 'Precondition: the second occurrence is indexed.' );

		$GLOBALS['blockendar_current_occurrence'] = $second;

		$links = $this->links( $post_id );

		$this->assertSame( '20261117/20261118', $links['Google Calendar']['query']['dates'] );
		$this->assertSame( '2026-11-17', $links['Outlook 365']['query']['startdt'] );
		$this->assertSame( '2026-11-18', $links['Outlook 365']['query']['enddt'] );
	}

	// -------------------------------------------------------------------------
	// Location
	// -------------------------------------------------------------------------

	public function test_both_services_get_the_venue_and_its_address(): void {
		$post_id = $this->make_event( $this->timed() );

		$this->attach_venue(
			$post_id,
			[
				'address' => '12 Cider Lane',
				'city'    => 'Springfield',
				'state'   => 'IL',
				'country' => 'US',
			]
		);

		$links    = $this->links( $post_id );
		$expected = 'Orchard Hall, 12 Cider Lane, Springfield, IL, US';

		$this->assertSame( $expected, $links['Google Calendar']['query']['location'] );
		$this->assertSame( $expected, $links['Outlook 365']['query']['location'] );
		$this->assertSame( $expected, $links['Outlook Live']['query']['location'] );
	}

	public function test_a_virtual_venue_with_a_stream_url_gives_the_url(): void {
		$post_id = $this->make_event( $this->timed() );

		$this->attach_venue(
			$post_id,
			[
				'virtual'    => '1',
				'stream_url' => 'https://stream.example.com/supper',
				'address'    => '12 Cider Lane',
			]
		);

		$this->assertSame( 'https://stream.example.com/supper', $this->links( $post_id )['Google Calendar']['query']['location'] );
	}

	/**
	 * The virtual flag and the stream URL are separate fields, and the URL is
	 * optional. A virtual venue without one still has a name worth sending.
	 */
	public function test_a_virtual_venue_without_a_stream_url_gives_its_name(): void {
		$post_id = $this->make_event( $this->timed() );

		$this->attach_venue( $post_id, [ 'virtual' => '1' ] );

		$this->assertSame( 'Orchard Hall', $this->links( $post_id )['Outlook 365']['query']['location'] );
	}

	public function test_an_event_without_a_venue_sends_no_location(): void {
		$links = $this->links( $this->make_event( $this->timed() ) );

		$this->assertArrayNotHasKey( 'location', $links['Google Calendar']['query'] );
		$this->assertArrayNotHasKey( 'location', $links['Outlook 365']['query'] );
	}

	// -------------------------------------------------------------------------
	// Description
	// -------------------------------------------------------------------------

	public function test_both_services_get_the_excerpt_and_the_permalink(): void {
		$post_id  = $this->make_event( $this->timed() );
		$links    = $this->links( $post_id );
		$expected = 'Long tables in the orchard. ' . get_permalink( $post_id );

		$this->assertSame( $expected, $links['Google Calendar']['query']['details'] );
		$this->assertSame( $expected, $links['Outlook 365']['query']['body'] );
	}

	/**
	 * A link is a URL, and browsers and servers cap a URL's length. The
	 * permalink has to survive whatever the excerpt runs to.
	 */
	public function test_a_long_excerpt_is_cut_and_the_permalink_kept(): void {
		$post_id = $this->make_event( $this->timed(), [ 'post_excerpt' => str_repeat( 'Apples and pears. ', 200 ) ] );
		$details = $this->links( $post_id )['Google Calendar']['query']['details'];
		$url     = get_permalink( $post_id );

		$this->assertStringEndsWith( ' ' . $url, $details );
		$this->assertLessThanOrEqual( 901 + strlen( ' ' . $url ), mb_strlen( $details ) );
		$this->assertStringContainsString( '…', $details );
	}

	public function test_an_event_without_an_excerpt_sends_the_permalink_alone(): void {
		$post_id = $this->make_event(
			$this->timed(),
			[
				'post_excerpt' => '',
				'post_content' => '',
			]
		);

		$this->assertSame( get_permalink( $post_id ), $this->links( $post_id )['Google Calendar']['query']['details'] );
	}

	// -------------------------------------------------------------------------
	// Encoding
	// -------------------------------------------------------------------------

	/**
	 * Every value is one a visitor's event can put a "&", a "+" or a "#" in.
	 * Parsed back, each has to come out as it went in.
	 */
	public function test_awkward_characters_survive_in_every_parameter(): void {
		$post_id = $this->make_event(
			$this->timed(),
			[
				'post_title'   => 'Bread & Butter + Jam #3',
				'post_excerpt' => 'Bring 50% more; R&D "tasting" = fun?',
			]
		);

		$this->attach_venue( $post_id, [ 'address' => 'Unit #4, A&B Yard' ] );

		$links = $this->links( $post_id );

		$this->assertSame( 'Bread & Butter + Jam #3', $links['Google Calendar']['query']['text'] );
		$this->assertSame( 'Bread & Butter + Jam #3', $links['Outlook Live']['query']['subject'] );
		$this->assertSame( 'Orchard Hall, Unit #4, A&B Yard', $links['Google Calendar']['query']['location'] );
		$this->assertStringStartsWith( 'Bring 50% more; R&D "tasting" = fun? ', $links['Outlook 365']['query']['body'] );
	}
}
