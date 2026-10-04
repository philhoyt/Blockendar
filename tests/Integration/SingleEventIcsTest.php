<?php
/**
 * Integration coverage for the single-event .ics export.
 *
 * The download and the subscription feed have to agree about what an event is.
 * They did not: the endpoint assembled its own VEVENT with a different UID, so a
 * client holding both a downloaded event and a subscription saw the same event
 * twice with no way to tell they were the same thing.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\DB\EventIndex;
use Blockendar\DB\IndexBuilder;
use Blockendar\ICS\Exporter;
use Blockendar\Recurrence\Generator;
use Blockendar\Recurrence\RuleRepository;
use WP_REST_Request;
use WP_UnitTestCase;

class SingleEventIcsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( 'blockendar_settings' );
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		delete_option( 'blockendar_settings' );
		parent::tear_down();
	}

	/**
	 * Create a published, indexed event.
	 *
	 * @param string $title Event title.
	 * @param array  $meta  Meta overrides.
	 * @return int Post ID.
	 */
	private function make_event( string $title, array $meta = [] ): int {
		$post_id = self::factory()->post->create(
			[
				'post_type'    => 'blockendar_event',
				'post_title'   => $title,
				'post_status'  => 'publish',
				'post_content' => 'An evening of music.',
			]
		);

		$date = gmdate( 'Y-m-d', strtotime( '+9 days' ) );

		$meta = array_merge(
			[
				'blockendar_start_date' => $date,
				'blockendar_end_date'   => $date,
				'blockendar_start_time' => '19:00',
				'blockendar_end_time'   => '21:00',
			],
			$meta
		);

		foreach ( $meta as $key => $value ) {
			if ( null === $value ) {
				delete_post_meta( $post_id, $key );
				continue;
			}

			update_post_meta( $post_id, $key, $value );
		}

		( new IndexBuilder() )->build_for_post( $post_id );

		return $post_id;
	}

	/**
	 * Return the first content line with the given prefix.
	 *
	 * @param string $ics    iCalendar content.
	 * @param string $prefix Property prefix.
	 */
	private function property( string $ics, string $prefix ): string {
		foreach ( explode( "\r\n", $ics ) as $line ) {
			if ( str_starts_with( $line, $prefix ) ) {
				return $line;
			}
		}

		return '';
	}

	/**
	 * The feed body for the current window.
	 */
	private function feed(): string {
		$request = new WP_REST_Request( 'GET', '/blockendar/v1/calendar' );
		$request->set_param( 'format', 'ics' );

		return (string) rest_do_request( $request )->get_data();
	}

	// -------------------------------------------------------------------------
	// Identity
	// -------------------------------------------------------------------------

	public function test_the_download_and_the_feed_agree_on_the_uid(): void {
		$post_id = $this->make_event( 'Autumn Concert' );

		$download = (string) ( new Exporter() )->generate_single( $post_id );

		$this->assertSame(
			$this->property( $this->feed(), 'UID:' ),
			$this->property( $download, 'UID:' ),
			'A client holding both would show this event twice.'
		);
	}

	public function test_the_download_uid_is_the_prefixed_form(): void {
		$post_id = $this->make_event( 'Prefixed UID' );

		$download = (string) ( new Exporter() )->generate_single( $post_id );

		$this->assertStringStartsWith( "UID:blockendar-{$post_id}@", $this->property( $download, 'UID:' ) );
	}

	// -------------------------------------------------------------------------
	// Spec compliance
	// -------------------------------------------------------------------------

	public function test_download_lines_are_folded(): void {
		$post_id = $this->make_event(
			'Autumn Concert at the Really Quite Long Named Venue Hall Downtown By The River'
		);

		$ics = (string) ( new Exporter() )->generate_single( $post_id );

		foreach ( explode( "\r\n", rtrim( $ics, "\r\n" ) ) as $index => $line ) {
			$this->assertLessThanOrEqual(
				75,
				strlen( $line ),
				"Physical line {$index} exceeds 75 octets."
			);
		}
	}

	public function test_the_download_carries_the_revision_properties(): void {
		$post_id = $this->make_event( 'Full Properties' );

		$ics = (string) ( new Exporter() )->generate_single( $post_id );

		foreach ( [ 'SEQUENCE:', 'LAST-MODIFIED:', 'STATUS:', 'DESCRIPTION:' ] as $property ) {
			$this->assertNotSame( '', $this->property( $ics, $property ), "Missing {$property}" );
		}
	}

	// -------------------------------------------------------------------------
	// A download is not a subscription
	// -------------------------------------------------------------------------

	public function test_a_single_event_file_advertises_no_refresh_or_calendar_name(): void {
		$post_id = $this->make_event( 'One Off' );

		$ics = (string) ( new Exporter() )->generate_single( $post_id );

		// Both would be wrong on a file that is imported once rather than polled,
		// and a calendar name makes some clients offer to create a new calendar.
		$this->assertStringNotContainsString( 'REFRESH-INTERVAL', $ics );
		$this->assertStringNotContainsString( 'X-PUBLISHED-TTL', $ics );
		$this->assertStringNotContainsString( 'X-WR-CALNAME', $ics );

		// The feed still advertises them.
		$this->assertStringContainsString( 'REFRESH-INTERVAL', $this->feed() );
	}

	// -------------------------------------------------------------------------
	// Leniency the endpoint used to provide itself
	// -------------------------------------------------------------------------

	public function test_an_event_without_an_end_date_still_exports(): void {
		$post_id = $this->make_event( 'No End Date', [ 'blockendar_end_date' => null ] );

		$ics = (string) ( new Exporter() )->generate_single( $post_id );

		$this->assertStringContainsString( 'BEGIN:VEVENT', $ics );
		$this->assertNotSame( '', $this->property( $ics, 'DTSTART' ) );
	}

	public function test_an_event_with_no_dates_at_all_is_not_exportable(): void {
		$post_id = $this->make_event(
			'Dateless',
			[
				'blockendar_start_date' => null,
				'blockendar_end_date'   => null,
			]
		);

		$this->assertNull( ( new Exporter() )->generate_single( $post_id ) );
	}

	public function test_a_non_event_post_is_not_exportable(): void {
		$post_id = self::factory()->post->create( [ 'post_type' => 'post' ] );

		$this->assertNull( ( new Exporter() )->generate_single( $post_id ) );
	}

	// -------------------------------------------------------------------------
	// Recurring events
	// -------------------------------------------------------------------------

	/**
	 * Create a published weekly event with four occurrences.
	 *
	 * @param int   $first_in_days Days from today to the first occurrence; negative for the past.
	 * @param array $meta          Meta overrides.
	 * @return array{0: int, 1: string[]} Post ID and the four occurrence dates.
	 */
	private function make_weekly_event( int $first_in_days = 9, array $meta = [] ): array {
		$first = gmdate( 'Y-m-d', time() + $first_in_days * DAY_IN_SECONDS );

		$post_id = $this->make_event(
			'Weekly Session',
			array_merge(
				[
					'blockendar_start_date' => $first,
					'blockendar_end_date'   => $first,
				],
				$meta
			)
		);

		( new RuleRepository() )->upsert(
			$post_id,
			[
				'frequency' => 'weekly',
				'interval'  => 1,
				'count'     => 4,
			]
		);
		( new Generator() )->generate_for_post( $post_id );
		( new EventIndex() )->flush_cache();

		$dates = [];

		for ( $week = 0; $week < 4; $week++ ) {
			$dates[] = gmdate( 'Y-m-d', time() + ( $first_in_days + 7 * $week ) * DAY_IN_SECONDS );
		}

		return [ $post_id, $dates ];
	}

	/**
	 * Every UID line in an iCalendar body.
	 *
	 * @param string $ics iCalendar content.
	 * @return string[]
	 */
	private function uids( string $ics ): array {
		return array_values( array_filter( explode( "\r\n", $ics ), static fn( $line ) => str_starts_with( $line, 'UID:' ) ) );
	}

	/**
	 * The download was built from post meta, which holds the series' first date
	 * and nothing to say the event recurs. So it exported the first occurrence
	 * whichever one was asked for, under a UID with no date in it — a second,
	 * unrelated event to a client that also holds the feed.
	 */
	public function test_a_recurring_events_download_is_the_occurrence_asked_for(): void {
		[ $post_id, $dates ] = $this->make_weekly_event();

		$download = (string) ( new Exporter() )->generate_single( $post_id, $dates[2] );
		$host     = wp_parse_url( home_url(), PHP_URL_HOST );
		$uid      = "UID:blockendar-{$post_id}-{$dates[2]}@{$host}";

		$this->assertSame( [ $uid ], $this->uids( $download ) );
		$this->assertContains( $uid, $this->uids( $this->feed() ), 'The feed names this occurrence the same way.' );
		$this->assertSame( 'DTSTART:' . str_replace( '-', '', $dates[2] ) . 'T190000Z', $this->property( $download, 'DTSTART' ) );
		$this->assertSame( 'DTEND:' . str_replace( '-', '', $dates[2] ) . 'T210000Z', $this->property( $download, 'DTEND' ) );
		$this->assertSame( 'SUMMARY:Weekly Session', $this->property( $download, 'SUMMARY' ) );
	}

	public function test_with_no_date_a_recurring_events_download_is_its_next_occurrence(): void {
		[ $post_id, $dates ] = $this->make_weekly_event();

		$download = (string) ( new Exporter() )->generate_single( $post_id );
		$host     = wp_parse_url( home_url(), PHP_URL_HOST );

		$this->assertSame( [ "UID:blockendar-{$post_id}-{$dates[0]}@{$host}" ], $this->uids( $download ) );
	}

	public function test_a_series_that_is_over_downloads_its_last_occurrence(): void {
		[ $post_id, $dates ] = $this->make_weekly_event( -60 );

		$download = (string) ( new Exporter() )->generate_single( $post_id );
		$host     = wp_parse_url( home_url(), PHP_URL_HOST );

		$this->assertSame( [ "UID:blockendar-{$post_id}-{$dates[3]}@{$host}" ], $this->uids( $download ) );
	}

	public function test_an_all_day_occurrence_downloads_as_one_day(): void {
		[ $post_id, $dates ] = $this->make_weekly_event(
			9,
			[
				'blockendar_all_day'    => '1',
				'blockendar_start_time' => '',
				'blockendar_end_time'   => '',
			]
		);

		$download = (string) ( new Exporter() )->generate_single( $post_id, $dates[1] );
		$next_day = gmdate( 'Ymd', (int) date_create( $dates[1] . ' UTC' )->getTimestamp() + DAY_IN_SECONDS );

		$this->assertSame( 'DTSTART;VALUE=DATE:' . str_replace( '-', '', $dates[1] ), $this->property( $download, 'DTSTART' ) );
		$this->assertSame( 'DTEND;VALUE=DATE:' . $next_day, $this->property( $download, 'DTEND' ) );
	}

	public function test_a_date_the_series_does_not_fall_on_is_not_exportable(): void {
		[ $post_id ] = $this->make_weekly_event();

		$this->assertNull( ( new Exporter() )->generate_single( $post_id, '1999-01-01' ) );
	}

	/**
	 * A single event's download is still built from its meta, and comes out the
	 * same whether or not its own date is named.
	 */
	public function test_a_single_events_download_does_not_change(): void {
		$post_id = $this->make_event( 'Autumn Concert' );
		$date    = get_post_meta( $post_id, 'blockendar_start_date', true );
		$host    = wp_parse_url( home_url(), PHP_URL_HOST );

		$strip = static fn( string $ics ): string => (string) preg_replace( '/^DTSTAMP:.*$/m', '', $ics );

		$plain = (string) ( new Exporter() )->generate_single( $post_id );
		$dated = (string) ( new Exporter() )->generate_single( $post_id, $date );

		$this->assertSame( [ "UID:blockendar-{$post_id}@{$host}" ], $this->uids( $plain ) );
		$this->assertSame( $strip( $plain ), $strip( $dated ) );
		$this->assertNull( ( new Exporter() )->generate_single( $post_id, '1999-01-01' ) );
	}

	// -------------------------------------------------------------------------
	// The route and the block
	// -------------------------------------------------------------------------

	public function test_the_route_refuses_a_value_that_is_not_a_date(): void {
		[ $post_id ] = $this->make_weekly_event();

		foreach ( [ 'tomorrow', '2026-02-30', '20261110' ] as $value ) {
			$request = new WP_REST_Request( 'GET', "/blockendar/v1/events/{$post_id}/ical" );
			$request->set_query_params( [ 'occurrence_date' => $value ] );

			$this->assertSame( 400, rest_do_request( $request )->get_status(), $value );
		}
	}

	public function test_the_route_answers_404_for_a_date_the_series_does_not_fall_on(): void {
		[ $post_id ] = $this->make_weekly_event();

		$request = new WP_REST_Request( 'GET', "/blockendar/v1/events/{$post_id}/ical" );
		$request->set_query_params( [ 'occurrence_date' => '1999-01-01' ] );

		try {
			rest_do_request( $request );
			$this->fail( 'The route served a file for an occurrence that does not exist.' );
		} catch ( \WPDieException $e ) {
			$this->assertStringContainsString( 'not found', strtolower( $e->getMessage() ) );
		}
	}

	/**
	 * The block's iCalendar link, decoded.
	 *
	 * Without pretty permalinks the route travels in ?rest_route=, percent-encoded,
	 * so the link is found by where it points once decoded rather than by its text.
	 *
	 * @param string $html Rendered block.
	 */
	private function icalendar_href( string $html ): string {
		preg_match_all( '/href="([^"]+)"/', $html, $matches );

		foreach ( $matches[1] as $href ) {
			$href = urldecode( html_entity_decode( $href, ENT_QUOTES ) );

			if ( str_contains( $href, '/blockendar/v1/events/' ) && str_contains( $href, '/ical' ) ) {
				return $href;
			}
		}

		$this->fail( 'The block rendered no iCalendar link.' );
	}

	/**
	 * The block's Google and Outlook links describe the occurrence on screen.
	 * Its iCalendar link has to name the same one.
	 */
	public function test_the_blocks_icalendar_link_names_the_occurrence_on_screen(): void {
		[ $post_id, $dates ] = $this->make_weekly_event();

		$GLOBALS['blockendar_current_occurrence'] = EventIndex::get_occurrence_by_date( $post_id, $dates[2] );

		$html = ( new \WP_Block(
			[
				'blockName' => 'blockendar/add-to-calendar',
				'attrs'     => [],
			],
			[ 'postId' => $post_id ]
		) )->render();

		unset( $GLOBALS['blockendar_current_occurrence'] );

		$this->assertStringContainsString( 'occurrence_date=' . $dates[2], $this->icalendar_href( $html ) );
	}

	public function test_the_blocks_icalendar_link_is_unchanged_for_a_single_event(): void {
		$post_id = $this->make_event( 'Autumn Concert' );

		$html = ( new \WP_Block(
			[
				'blockName' => 'blockendar/add-to-calendar',
				'attrs'     => [],
			],
			[ 'postId' => $post_id ]
		) )->render();

		$this->assertStringNotContainsString( 'occurrence_date', $this->icalendar_href( $html ) );
	}
}
