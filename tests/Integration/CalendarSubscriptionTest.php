<?php
/**
 * Integration coverage for the iCalendar subscription feed.
 *
 * The feed only works if the whole route behaves: real WordPress serialization,
 * real permission callbacks, real index rows. The defect this suite was written
 * around — the feed going out JSON-encoded — was invisible to any test that
 * called the Exporter directly, because the Exporter was never the problem.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\DB\IndexBuilder;
use WP_REST_Request;
use WP_UnitTestCase;

class CalendarSubscriptionTest extends WP_UnitTestCase {

	private const ROUTE = '/blockendar/v1/calendar';

	public function set_up(): void {
		parent::set_up();

		delete_option( 'blockendar_settings' );
		delete_transient( 'blockendar_ics_truncated' );

		// rest_api_init has already run for the suite; make sure our routes exist.
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		delete_option( 'blockendar_settings' );
		delete_transient( 'blockendar_ics_truncated' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Create a published event and index it.
	 *
	 * @param string $title      Event title.
	 * @param string $start_date Y-m-d.
	 * @return int Post ID.
	 */
	private function make_event( string $title, string $start_date ): int {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_title'  => $title,
				'post_status' => 'publish',
			]
		);

		update_post_meta( $post_id, 'blockendar_start_date', $start_date );
		update_post_meta( $post_id, 'blockendar_end_date', $start_date );
		update_post_meta( $post_id, 'blockendar_start_time', '19:00' );
		update_post_meta( $post_id, 'blockendar_end_time', '21:00' );

		( new IndexBuilder() )->build_for_post( $post_id );

		return $post_id;
	}

	/**
	 * Dispatch a feed request and return the response.
	 *
	 * @param array $params Query parameters.
	 * @return \WP_REST_Response
	 */
	private function request( array $params = [] ) {
		$request = new WP_REST_Request( 'GET', self::ROUTE );
		$request->set_param( 'format', 'ics' );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_do_request( $request );
	}

	/**
	 * Return what the server actually writes to the client.
	 *
	 * rest_do_request() only dispatches: it stops before WP_REST_Server
	 * serializes the response, which is precisely where the JSON-encoding
	 * defect lived. Asserting on get_data() would pass either way, so anything
	 * about the wire format has to go through serve_request().
	 *
	 * @param array $params Query parameters.
	 */
	private function serve( array $params = [] ): string {
		$request = new WP_REST_Request( 'GET', self::ROUTE );
		$request->set_param( 'format', 'ics' );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		$response = rest_do_request( $request );

		// This is the exact seam the server uses. Returning false here is the
		// regression: the server would fall through and JSON-encode the body.
		ob_start();
		$served = apply_filters( 'rest_pre_serve_request', false, $response, $request, rest_get_server() );
		$output = (string) ob_get_clean();

		$this->assertTrue(
			$served,
			'The feed did not take over serving, so the body would be JSON-encoded.'
		);

		return $output;
	}

	// -------------------------------------------------------------------------
	// The body is iCalendar, not JSON
	//
	// These assert on served output, not on the response object.
	// -------------------------------------------------------------------------

	public function test_the_feed_body_is_raw_icalendar(): void {
		$this->make_event( 'Feed Body Test', gmdate( 'Y-m-d', strtotime( '+10 days' ) ) );

		$body = $this->serve();

		$this->assertStringStartsWith( 'BEGIN:VCALENDAR', $body );
		$this->assertStringEndsWith( "END:VCALENDAR\r\n", $body );
	}

	public function test_the_feed_uses_real_crlf_not_escaped_sequences(): void {
		$this->make_event( 'CRLF Test', gmdate( 'Y-m-d', strtotime( '+10 days' ) ) );

		$body = $this->serve();

		$this->assertStringContainsString( "\r\n", $body, 'The feed carries no real CRLF.' );
		$this->assertStringNotContainsString( '\r\n', $body, 'The feed contains literal backslash-r-backslash-n.' );
	}

	/**
	 * The exact regression: a JSON-encoded feed is a quoted string, and a
	 * quoted string is valid JSON. Real iCalendar is not.
	 */
	public function test_the_feed_is_not_json(): void {
		$this->make_event( 'JSON Guard', gmdate( 'Y-m-d', strtotime( '+10 days' ) ) );

		$body = $this->serve();

		$this->assertNull(
			json_decode( $body ),
			'The feed body decoded as JSON, so it is being serialized again.'
		);
		$this->assertStringNotContainsString( '\\/\\/Blockendar', $body, 'Slashes are JSON-escaped.' );

		// Not assertStringNotStartsWith(): a '"' needle trips a PHPUnit 9.6
		// constraint-rendering bug on PHP 8.5, and 9.x is pinned on purpose
		// because WordPress core's suite needs an API PHPUnit 10 removed.
		$this->assertNotSame( '"', substr( $body, 0, 1 ), 'The body is a quoted JSON string.' );
	}

	public function test_an_event_in_the_window_appears_as_a_vevent(): void {
		$this->make_event( 'Findable Gig', gmdate( 'Y-m-d', strtotime( '+10 days' ) ) );

		$body = $this->serve();

		$this->assertStringContainsString( 'BEGIN:VEVENT', $body );
		$this->assertStringContainsString( 'Findable Gig', $body );
	}

	// -------------------------------------------------------------------------
	// Folding
	// -------------------------------------------------------------------------

	public function test_no_physical_line_exceeds_the_octet_limit(): void {
		$this->make_event(
			'A very long event title that keeps going well past the seventy-five octet limit so the folder has to do real work',
			gmdate( 'Y-m-d', strtotime( '+10 days' ) )
		);

		$body = $this->serve();

		foreach ( explode( "\r\n", rtrim( $body, "\r\n" ) ) as $index => $line ) {
			$this->assertLessThanOrEqual(
				75,
				strlen( $line ),
				"Physical line {$index} exceeds 75 octets: {$line}"
			);
		}
	}

	// -------------------------------------------------------------------------
	// Headers
	// -------------------------------------------------------------------------

	public function test_the_content_type_is_text_calendar(): void {
		$headers = $this->request()->get_headers();

		$this->assertSame( 'text/calendar; charset=utf-8', $headers['Content-Type'] ?? '' );
	}

	public function test_the_feed_is_inline_by_default_and_attachable_on_request(): void {
		$this->assertSame(
			'inline',
			$this->request()->get_headers()['Content-Disposition'] ?? '',
			'A subscription should not be served as a download.'
		);

		$this->assertStringStartsWith(
			'attachment;',
			$this->request( [ 'download' => '1' ] )->get_headers()['Content-Disposition'] ?? '',
			'download=1 should still produce a file download.'
		);
	}

	public function test_the_feed_advertises_a_refresh_interval(): void {
		$body = $this->request()->get_data();

		$this->assertStringContainsString( 'REFRESH-INTERVAL;VALUE=DURATION:PT1H', $body );
		$this->assertStringContainsString( 'X-PUBLISHED-TTL:PT1H', $body );
	}

	// -------------------------------------------------------------------------
	// Caching
	// -------------------------------------------------------------------------

	public function test_an_open_feed_may_be_shared_cached(): void {
		update_option( 'blockendar_settings', [ 'rest_public' => true ] );

		$this->assertSame(
			'public, max-age=3600',
			$this->request()->get_headers()['Cache-Control'] ?? ''
		);
	}

	public function test_a_tokenised_feed_is_never_shared_cached(): void {
		update_option(
			'blockendar_settings',
			[
				'rest_public'     => false,
				'rest_feed_token' => 'CACHETOKEN012345',
			]
		);

		$this->assertSame(
			'private, no-store',
			$this->request( [ 'token' => 'CACHETOKEN012345' ] )->get_headers()['Cache-Control'] ?? '',
			'A URL carrying a credential must not be cached by an intermediary.'
		);
	}

	// -------------------------------------------------------------------------
	// Permissions
	// -------------------------------------------------------------------------

	public function test_a_private_feed_is_refused_without_a_token(): void {
		update_option(
			'blockendar_settings',
			[
				'rest_public'     => false,
				'rest_feed_token' => 'GOODTOKEN0123456',
			]
		);
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->request()->get_status() );
		$this->assertSame( 401, $this->request( [ 'token' => 'WRONG' ] )->get_status() );
	}

	public function test_a_private_feed_opens_with_the_right_token(): void {
		update_option(
			'blockendar_settings',
			[
				'rest_public'     => false,
				'rest_feed_token' => 'GOODTOKEN0123456',
			]
		);
		wp_set_current_user( 0 );

		$response = $this->request( [ 'token' => 'GOODTOKEN0123456' ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringStartsWith( 'BEGIN:VCALENDAR', $response->get_data() );
	}

	// -------------------------------------------------------------------------
	// Rolling window
	// -------------------------------------------------------------------------

	public function test_the_default_window_rolls_past_the_old_fixed_range(): void {
		// 200 days out is well beyond the JSON path's month-plus-one default.
		$this->make_event( 'Far Future Gala', gmdate( 'Y-m-d', strtotime( '+200 days' ) ) );

		$this->assertStringContainsString( 'Far Future Gala', $this->request()->get_data() );
	}

	public function test_events_outside_the_configured_window_are_excluded(): void {
		update_option(
			'blockendar_settings',
			[
				'subscribe_past_days'   => 1,
				'subscribe_future_days' => 5,
			]
		);

		$this->make_event( 'Inside Window', gmdate( 'Y-m-d', strtotime( '+2 days' ) ) );
		$this->make_event( 'Outside Window', gmdate( 'Y-m-d', strtotime( '+120 days' ) ) );

		$body = $this->request()->get_data();

		$this->assertStringContainsString( 'Inside Window', $body );
		$this->assertStringNotContainsString( 'Outside Window', $body );
	}

	public function test_the_window_honours_configured_history(): void {
		update_option(
			'blockendar_settings',
			[
				'subscribe_past_days'   => 30,
				'subscribe_future_days' => 30,
			]
		);

		$this->make_event( 'Recently Past', gmdate( 'Y-m-d', strtotime( '-10 days' ) ) );
		$this->make_event( 'Long Past', gmdate( 'Y-m-d', strtotime( '-120 days' ) ) );

		$body = $this->request()->get_data();

		$this->assertStringContainsString( 'Recently Past', $body );
		$this->assertStringNotContainsString( 'Long Past', $body );
	}

	public function test_an_explicit_range_still_overrides_the_rolling_window(): void {
		$this->make_event( 'Explicit Range Event', gmdate( 'Y-m-d', strtotime( '+300 days' ) ) );

		$body = $this->request(
			[
				'start' => gmdate( 'Y-m-d H:i:s', strtotime( '+299 days' ) ),
				'end'   => gmdate( 'Y-m-d H:i:s', strtotime( '+301 days' ) ),
			]
		)->get_data();

		$this->assertStringContainsString( 'Explicit Range Event', $body );
	}

	// -------------------------------------------------------------------------
	// Identity across refreshes
	// -------------------------------------------------------------------------

	public function test_rescheduling_keeps_the_uid_and_raises_the_sequence(): void {
		$post_id = $this->make_event( 'Movable Feast', gmdate( 'Y-m-d', strtotime( '+10 days' ) ) );

		$before = $this->request()->get_data();

		// Age the creation timestamp so the edit is measurably later.
		wp_update_post(
			[
				'ID'            => $post_id,
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', strtotime( '-2 hours' ) ),
				'post_date'     => gmdate( 'Y-m-d H:i:s', strtotime( '-2 hours' ) ),
			]
		);

		update_post_meta( $post_id, 'blockendar_start_date', gmdate( 'Y-m-d', strtotime( '+40 days' ) ) );
		update_post_meta( $post_id, 'blockendar_end_date', gmdate( 'Y-m-d', strtotime( '+40 days' ) ) );
		( new IndexBuilder() )->build_for_post( $post_id );

		$after = $this->request()->get_data();

		$uid_before = $this->first_property( $before, 'UID:' );
		$uid_after  = $this->first_property( $after, 'UID:' );

		$this->assertNotSame( '', $uid_before );
		$this->assertSame(
			$uid_before,
			$uid_after,
			'Rescheduling changed the UID, so subscribers would see two events.'
		);

		$this->assertGreaterThan(
			(int) str_replace( 'SEQUENCE:', '', $this->first_property( $before, 'SEQUENCE:' ) ),
			(int) str_replace( 'SEQUENCE:', '', $this->first_property( $after, 'SEQUENCE:' ) ),
			'SEQUENCE did not rise, so clients may ignore the update.'
		);
	}

	public function test_a_non_recurring_uid_carries_no_date(): void {
		$date = gmdate( 'Y-m-d', strtotime( '+10 days' ) );
		$this->make_event( 'Dateless UID', $date );

		$this->assertStringNotContainsString(
			$date,
			$this->first_property( $this->request()->get_data(), 'UID:' )
		);
	}

	/**
	 * Return the first content line starting with the given prefix.
	 *
	 * @param string $body   Feed body.
	 * @param string $prefix Property prefix, e.g. 'UID:'.
	 */
	private function first_property( string $body, string $prefix ): string {
		foreach ( explode( "\r\n", $body ) as $line ) {
			if ( str_starts_with( $line, $prefix ) ) {
				return $line;
			}
		}

		return '';
	}

	// -------------------------------------------------------------------------
	// Truncation
	// -------------------------------------------------------------------------

	public function test_a_truncated_feed_says_so_and_flags_the_admin(): void {
		$this->make_event( 'One', gmdate( 'Y-m-d', strtotime( '+5 days' ) ) );
		$this->make_event( 'Two', gmdate( 'Y-m-d', strtotime( '+6 days' ) ) );

		$limit = static fn() => 1;
		add_filter( 'blockendar_ics_max_events', $limit );

		$body = $this->request()->get_data();

		remove_filter( 'blockendar_ics_max_events', $limit );

		$this->assertStringContainsString( 'X-WR-CALDESC', $body );
		$this->assertIsArray(
			get_transient( 'blockendar_ics_truncated' ),
			'The settings screen has no way to know the feed was cut short.'
		);
	}

	/**
	 * A feed holding exactly the ceiling dropped nothing, so it must not claim
	 * it did. This is why the query fetches one row past the ceiling.
	 */
	public function test_a_feed_exactly_at_the_ceiling_does_not_claim_truncation(): void {
		$this->make_event( 'Exactly One', gmdate( 'Y-m-d', strtotime( '+5 days' ) ) );

		$limit = static fn() => 1;
		add_filter( 'blockendar_ics_max_events', $limit );

		$body = $this->request()->get_data();

		remove_filter( 'blockendar_ics_max_events', $limit );

		$this->assertSame( 1, substr_count( $body, 'BEGIN:VEVENT' ) );
		$this->assertStringNotContainsString( 'X-WR-CALDESC', $body );
		$this->assertFalse( get_transient( 'blockendar_ics_truncated' ) );
	}

	public function test_a_truncated_feed_carries_exactly_the_ceiling(): void {
		foreach ( [ 3, 4, 5 ] as $offset ) {
			$this->make_event( "Capped {$offset}", gmdate( 'Y-m-d', strtotime( "+{$offset} days" ) ) );
		}

		$limit = static fn() => 2;
		add_filter( 'blockendar_ics_max_events', $limit );

		$body = $this->request()->get_data();

		remove_filter( 'blockendar_ics_max_events', $limit );

		$this->assertSame( 2, substr_count( $body, 'BEGIN:VEVENT' ), 'The ceiling was not applied exactly.' );
		$this->assertStringContainsString( 'X-WR-CALDESC', $body );
	}

	public function test_an_untruncated_feed_stays_quiet(): void {
		$this->make_event( 'Only One', gmdate( 'Y-m-d', strtotime( '+5 days' ) ) );

		$body = $this->request()->get_data();

		$this->assertStringNotContainsString( 'X-WR-CALDESC', $body );
		$this->assertFalse( get_transient( 'blockendar_ics_truncated' ) );
	}

	// -------------------------------------------------------------------------
	// The JSON path is unaffected
	// -------------------------------------------------------------------------

	public function test_the_json_path_still_returns_an_array(): void {
		$this->make_event( 'JSON Path', gmdate( 'Y-m-d', strtotime( '+10 days' ) ) );

		$request  = new WP_REST_Request( 'GET', self::ROUTE );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertIsArray( $response->get_data() );
	}
}
