<?php
/**
 * Integration coverage for the schema.org Event markup on single event pages.
 *
 * Each test loads the event's page, runs what wp_head runs, and reads the
 * object back out of the script element, as a search engine would.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\DB\EventIndex;
use Blockendar\DB\IndexBuilder;
use Blockendar\DB\Schema;
use Blockendar\Meta\EventMeta;
use Blockendar\Meta\VenueMeta;
use Blockendar\Recurrence\RuleRepository;
use Blockendar\SEO\JsonLd;
use WP_UnitTestCase;

class JsonLdTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		Schema::create_tables();

		foreach ( [ Schema::events_table(), Schema::type_terms_table(), Schema::recurrence_table() ] as $table ) {
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
		}

		// WP_UnitTestCase unregisters every meta key in tear_down().
		( new EventMeta() )->register_meta();
		( new VenueMeta() )->register_meta();

		$this->set_permalink_structure( '/%postname%/' );
		delete_option( 'blockendar_settings' );
		update_option( 'timezone_string', 'America/Chicago' );

		IndexBuilder::forget_dirty();
		( new EventIndex() )->flush_cache();
	}

	public function tear_down(): void {
		$_GET = [];
		wp_set_current_user( 0 );
		remove_all_filters( 'blockendar_json_ld_enabled' );
		remove_all_filters( 'blockendar_json_ld_event' );
		delete_option( 'timezone_string' );
		delete_option( 'blockendar_settings' );
		IndexBuilder::forget_dirty();
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Fixtures
	// -------------------------------------------------------------------------

	/**
	 * Create a venue. With no arguments, a hall with a full address.
	 *
	 * @param array $meta Term meta to override, without the prefix.
	 * @return int Term ID.
	 */
	private function make_venue( array $meta = [] ): int {
		$existing = get_term_by( 'name', 'City Hall', 'blockendar_event_venue' );

		// A second venue in one test gets its own term, under another name.
		$term_id = self::factory()->term->create(
			[
				'taxonomy' => 'blockendar_event_venue',
				'name'     => $existing ? 'City Hall Annexe ' . wp_rand() : 'City Hall',
			]
		);

		$meta += [
			'address'     => '1 Main Street',
			'city'        => 'Springfield',
			'state'       => 'IL',
			'postal_code' => '62701',
			'country'     => 'US',
			'lat'         => 39.7817,
			'lng'         => -89.6501,
		];

		foreach ( $meta as $key => $value ) {
			update_term_meta( $term_id, 'blockendar_venue_' . $key, $value );
		}

		return $term_id;
	}

	/**
	 * Create and index a published event in the future, at a venue by default.
	 *
	 * @param array      $meta  Event meta to override, without the prefix.
	 * @param int|null   $venue Venue term ID; null makes one; 0 means none.
	 * @param array      $post  Post fields to override.
	 * @param array|null $rule  Repeat rule.
	 * @return int Post ID.
	 */
	private function make_event( array $meta = [], ?int $venue = null, array $post = [], ?array $rule = null ): int {
		$meta += [
			'start_date' => '2027-03-09',
			'end_date'   => '2027-03-09',
			'start_time' => '19:00',
			'end_time'   => '21:00',
			'timezone'   => 'America/Chicago',
		];

		$post_id = self::factory()->post->create(
			$post + [
				'post_type'    => 'blockendar_event',
				'post_status'  => 'publish',
				'post_title'   => 'Council Meeting',
				'post_name'    => 'council-meeting',
				'post_excerpt' => 'The council meets to <em>vote</em> on the budget &amp; hear the public.',
			]
		);

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, 'blockendar_' . $key, $value );
		}

		$venue ??= $this->make_venue();

		if ( $venue ) {
			wp_set_object_terms( $post_id, [ $venue ], 'blockendar_event_venue' );
		}

		if ( null !== $rule ) {
			( new RuleRepository() )->upsert( $post_id, $rule );
		}

		( new IndexBuilder() )->build_for_post( $post_id );
		( new EventIndex() )->flush_cache();

		return $post_id;
	}

	/**
	 * Load an event's page and return what the markup hook prints.
	 *
	 * @param int    $post_id Event post ID.
	 * @param string $query   Query string to add, without the "?".
	 */
	private function head( int $post_id, string $query = '' ): string {
		$url = get_permalink( $post_id );

		if ( '' !== $query ) {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The request under test.
			parse_str( $query, $_GET );
			$url = add_query_arg( $_GET, $url );
			// phpcs:enable
		}

		$this->go_to( $url );

		ob_start();
		( new JsonLd() )->print_markup();

		return (string) ob_get_clean();
	}

	/**
	 * The Event on an event's page, decoded, or null when there is none.
	 *
	 * @param int    $post_id Event post ID.
	 * @param string $query   Query string to add.
	 * @return array|null
	 */
	private function event( int $post_id, string $query = '' ): ?array {
		$html = $this->head( $post_id, $query );

		if ( '' === $html ) {
			return null;
		}

		$this->assertSame( 1, preg_match_all( '#<script type="application/ld\+json">(.*?)</script>#s', $html, $found ), 'One script element.' );

		$data = json_decode( $found[1][0], true );

		$this->assertIsArray( $data, 'The script holds valid JSON.' );

		return $data;
	}

	// -------------------------------------------------------------------------
	// The Event
	// -------------------------------------------------------------------------

	public function test_a_published_event_at_a_venue_has_one_event_object(): void {
		$post_id = $this->make_event();
		$event   = $this->event( $post_id );

		$this->assertSame( 'https://schema.org', $event['@context'] );
		$this->assertSame( 'Event', $event['@type'] );
		$this->assertSame( 'Council Meeting', $event['name'] );
		$this->assertSame( get_permalink( $post_id ), $event['url'] );
		$this->assertSame( 'The council meets to vote on the budget & hear the public.', $event['description'] );
		$this->assertSame( 'https://schema.org/EventScheduled', $event['eventStatus'] );
		$this->assertSame( 'https://schema.org/OfflineEventAttendanceMode', $event['eventAttendanceMode'] );
	}

	/**
	 * Google asks for local time with its offset. March in Chicago is -06:00,
	 * July is -05:00; the offset is the one in force on the day.
	 */
	public function test_a_timed_event_gives_local_times_with_their_offset(): void {
		$winter = $this->event( $this->make_event() );
		$summer = $this->event(
			$this->make_event(
				[
					'start_date' => '2027-07-09',
					'end_date'   => '2027-07-09',
				],
				null,
				[ 'post_name' => 'summer-meeting' ]
			)
		);

		$this->assertSame( '2027-03-09T19:00:00-06:00', $winter['startDate'] );
		$this->assertSame( '2027-03-09T21:00:00-06:00', $winter['endDate'] );
		$this->assertSame( '2027-07-09T19:00:00-05:00', $summer['startDate'] );
	}

	/**
	 * The times are the event's own, wherever the site is.
	 */
	public function test_the_offset_is_the_events_timezone_not_the_sites(): void {
		$event = $this->event( $this->make_event( [ 'timezone' => 'Europe/Berlin' ] ) );

		$this->assertSame( '2027-03-09T19:00:00+01:00', $event['startDate'] );
	}

	public function test_an_all_day_event_gives_dates_with_no_time(): void {
		$event = $this->event(
			$this->make_event(
				[
					'all_day'  => true,
					'end_date' => '2027-03-11',
				]
			)
		);

		$this->assertSame( '2027-03-09', $event['startDate'] );
		$this->assertSame( '2027-03-11', $event['endDate'], 'The last day, not the day after.' );
	}

	public function test_an_occurrence_of_a_recurring_all_day_event_gives_its_own_dates(): void {
		$post_id = $this->make_event(
			[ 'all_day' => true ],
			null,
			[],
			[
				'frequency' => 'weekly',
				'count'     => 4,
			]
		);

		$event = $this->event( $post_id, 'occurrence_date=2027-03-23' );

		$this->assertSame( '2027-03-23', $event['startDate'] );
		$this->assertSame( '2027-03-23', $event['endDate'] );
		$this->assertStringContainsString( 'occurrence_date=2027-03-23', $event['url'] );
	}

	public function test_an_ongoing_event_has_no_end(): void {
		$event = $this->event(
			$this->make_event(
				[
					'ongoing'  => true,
					'end_date' => '',
				]
			)
		);

		$this->assertSame( '2027-03-09T19:00:00-06:00', $event['startDate'] );
		$this->assertArrayNotHasKey( 'endDate', $event );
	}

	/**
	 * An event with no end time is indexed as ending when it starts. That is
	 * not an end worth reporting.
	 */
	public function test_an_event_with_no_end_time_has_no_end(): void {
		$event = $this->event( $this->make_event( [ 'end_time' => '' ] ) );

		$this->assertArrayNotHasKey( 'endDate', $event );
	}

	/**
	 * An event that is over has no next occurrence, and its page goes on
	 * showing the date it was entered with. The markup says the same.
	 */
	public function test_an_event_that_is_over_is_still_described_with_its_date(): void {
		$event = $this->event(
			$this->make_event(
				[
					'start_date' => '2020-02-03',
					'end_date'   => '2020-02-03',
				]
			)
		);

		$this->assertSame( '2020-02-03T19:00:00-06:00', $event['startDate'] );
	}

	public function test_a_series_that_is_over_is_described_with_its_first_date_as_its_page_shows(): void {
		$post_id = $this->make_event(
			[
				'start_date' => '2020-02-03',
				'end_date'   => '2020-02-03',
			],
			null,
			[],
			[
				'frequency' => 'weekly',
				'count'     => 3,
			]
		);

		$this->assertSame( '2020-02-03T19:00:00-06:00', $this->event( $post_id )['startDate'] );
	}

	// -------------------------------------------------------------------------
	// The place
	// -------------------------------------------------------------------------

	public function test_the_venue_is_a_place_with_its_address_and_coordinates(): void {
		$event = $this->event( $this->make_event() );

		$this->assertSame(
			[
				'@type'   => 'Place',
				'name'    => 'City Hall',
				'address' => [
					'@type'           => 'PostalAddress',
					'streetAddress'   => '1 Main Street',
					'addressLocality' => 'Springfield',
					'addressRegion'   => 'IL',
					'postalCode'      => '62701',
					'addressCountry'  => 'US',
				],
				'geo'     => [
					'@type'     => 'GeoCoordinates',
					'latitude'  => 39.7817,
					'longitude' => -89.6501,
				],
			],
			$event['location']
		);
	}

	public function test_parts_of_the_address_the_venue_lacks_are_left_out(): void {
		$venue = $this->make_venue(
			[
				'state'       => '',
				'postal_code' => '',
				'country'     => '',
				'lat'         => 0,
				'lng'         => 0,
			]
		);

		$location = $this->event( $this->make_event( [], $venue ) )['location'];

		$this->assertSame(
			[
				'@type'           => 'PostalAddress',
				'streetAddress'   => '1 Main Street',
				'addressLocality' => 'Springfield',
			],
			$location['address']
		);
		$this->assertArrayNotHasKey( 'geo', $location );
	}

	// -------------------------------------------------------------------------
	// Events Google's Event does not cover
	// -------------------------------------------------------------------------

	/**
	 * Google requires a physical place with an address and says events with no
	 * real-world location are not supported. Markup for one would earn only an
	 * "invalid item" in Search Console, so none is printed unless a site asks.
	 *
	 * @return array<string, array{callable}>
	 */
	public function events_without_a_place(): array {
		return [
			'an online venue'                    => [
				fn( $t ) => $t->make_event(
					[],
					$t->make_venue(
						[
							'virtual'    => true,
							'stream_url' => 'https://example.org/stream',
						]
					)
				),
			],
			'no venue at all'                    => [ fn( $t ) => $t->make_event( [], 0 ) ],
			'a venue with a name and no address' => [
				fn( $t ) => $t->make_event(
					[],
					$t->make_venue(
						[
							'address'     => '',
							'city'        => '',
							'state'       => '',
							'postal_code' => '',
							'country'     => '',
						]
					)
				),
			],
		];
	}

	/**
	 * @dataProvider events_without_a_place
	 *
	 * @param callable $make Creates the event, given the test.
	 */
	public function test_an_event_without_a_place_and_address_has_no_markup_unless_asked( callable $make ): void {
		$post_id = $make( $this );

		$this->assertNull( $this->event( $post_id ) );

		add_filter( 'blockendar_json_ld_enabled', '__return_true' );

		$this->assertSame( 'Event', $this->event( $post_id )['@type'] );
	}

	public function test_an_online_event_is_described_as_one_when_a_site_asks_for_it(): void {
		$venue   = $this->make_venue(
			[
				'virtual'    => true,
				'stream_url' => 'https://example.org/stream',
			]
		);
		$post_id = $this->make_event( [], $venue );

		add_filter( 'blockendar_json_ld_enabled', '__return_true' );

		$event = $this->event( $post_id );

		$this->assertSame( 'https://schema.org/OnlineEventAttendanceMode', $event['eventAttendanceMode'] );
		$this->assertSame(
			[
				'@type' => 'VirtualLocation',
				'url'   => 'https://example.org/stream',
			],
			$event['location']
		);
	}

	public function test_an_online_venue_with_no_stream_has_the_mode_and_no_location(): void {
		$post_id = $this->make_event( [], $this->make_venue( [ 'virtual' => true ] ) );

		add_filter( 'blockendar_json_ld_enabled', '__return_true' );

		$event = $this->event( $post_id );

		$this->assertSame( 'https://schema.org/OnlineEventAttendanceMode', $event['eventAttendanceMode'] );
		$this->assertArrayNotHasKey( 'location', $event );
	}

	// -------------------------------------------------------------------------
	// Status and price
	// -------------------------------------------------------------------------

	public function test_a_postponed_event_says_so(): void {
		$event = $this->event( $this->make_event( [ 'status' => 'postponed' ] ) );

		$this->assertSame( 'https://schema.org/EventPostponed', $event['eventStatus'] );
	}

	/**
	 * The status is the occurrence's. The date stays: Google asks that a
	 * cancelled event keep its start date, which is how it is recognised.
	 */
	public function test_one_cancelled_date_of_a_series_is_cancelled_and_the_others_are_not(): void {
		$post_id = $this->make_event(
			[],
			null,
			[],
			[
				'frequency' => 'weekly',
				'count'     => 4,
			]
		);

		( new RuleRepository() )->add_cancellation( $post_id, '2027-03-16' );
		( new IndexBuilder() )->build_for_post( $post_id );
		( new EventIndex() )->flush_cache();

		$cancelled = $this->event( $post_id, 'occurrence_date=2027-03-16' );
		$other     = $this->event( $post_id, 'occurrence_date=2027-03-23' );

		$this->assertSame( 'https://schema.org/EventCancelled', $cancelled['eventStatus'] );
		$this->assertSame( '2027-03-16T19:00:00-05:00', $cancelled['startDate'] );
		$this->assertSame( 'https://schema.org/EventScheduled', $other['eventStatus'] );
	}

	public function test_a_cost_that_is_a_number_becomes_an_offer(): void {
		update_option( 'blockendar_settings', [ 'default_currency' => 'EUR' ] );

		$event = $this->event(
			$this->make_event(
				[
					'cost'             => '12.50',
					'registration_url' => 'https://tickets.example.org/council',
				]
			)
		);

		$this->assertSame(
			[
				'@type'         => 'Offer',
				'price'         => 12.5,
				'priceCurrency' => 'EUR',
				'availability'  => 'https://schema.org/InStock',
				'url'           => 'https://tickets.example.org/council',
			],
			$event['offers']
		);
	}

	/**
	 * Free is a price. PHP's empty() calls "0" empty, and the offer went with it.
	 */
	public function test_a_cost_of_nought_is_a_free_offer(): void {
		$post_id = $this->make_event( [ 'cost' => '0' ] );
		$event   = $this->event( $post_id );

		$this->assertSame( 0, $event['offers']['price'] );
		$this->assertSame( get_permalink( $post_id ), $event['offers']['url'], 'With no booking page, the event is where to go.' );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function costs_that_are_not_prices(): array {
		return [
			'words'       => [ 'Donation' ],
			'a range'     => [ '$10–$25' ],
			'with a sign' => [ '$15' ],
			'nothing'     => [ '' ],
		];
	}

	/**
	 * @dataProvider costs_that_are_not_prices
	 *
	 * @param string $cost Cost as entered.
	 */
	public function test_a_cost_that_is_not_a_number_is_no_offer( string $cost ): void {
		$this->assertArrayNotHasKey( 'offers', $this->event( $this->make_event( [ 'cost' => $cost ] ) ) );
	}

	/**
	 * Sold out is about the tickets. The event is still on.
	 */
	public function test_a_sold_out_event_is_scheduled_with_a_sold_out_offer(): void {
		$event = $this->event(
			$this->make_event(
				[
					'cost'   => '20',
					'status' => 'sold_out',
				]
			)
		);

		$this->assertSame( 'https://schema.org/EventScheduled', $event['eventStatus'] );
		$this->assertSame( 'https://schema.org/SoldOut', $event['offers']['availability'] );
	}

	/**
	 * PHP writes a float into JSON with as many digits as serialize_precision
	 * says. Hosts that set it to 17 got a latitude of 39.781700000000001 and a
	 * price of 19.989999999999998.
	 */
	public function test_numbers_are_written_as_they_were_entered_whatever_the_servers_precision(): void {
		$previous = ini_set( 'serialize_precision', '17' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- The setting under test.

		$html = $this->head( $this->make_event( [ 'cost' => '19.99' ] ) );

		ini_set( 'serialize_precision', (string) $previous ); // phpcs:ignore WordPress.PHP.IniSet.Risky

		$this->assertStringContainsString( '"price":19.99,', $html );
		$this->assertStringContainsString( '"latitude":39.7817,', $html );
		$this->assertStringContainsString( '"longitude":-89.6501}', $html );
		$this->assertSame( (string) $previous, (string) ini_get( 'serialize_precision' ), 'The setting is put back.' );
	}

	// -------------------------------------------------------------------------
	// Image and text
	// -------------------------------------------------------------------------

	public function test_a_featured_image_is_given_and_its_absence_is_not_invented(): void {
		$post_id = $this->make_event();

		$this->assertArrayNotHasKey( 'image', $this->event( $post_id ) );

		$attachment = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $post_id );
		set_post_thumbnail( $post_id, $attachment );

		$this->assertSame( [ wp_get_attachment_url( $attachment ) ], $this->event( $post_id )['image'] );
	}

	/**
	 * With no excerpt written, WordPress cuts one from the content and ends it
	 * with "[…]", which is page furniture, not description.
	 */
	public function test_a_description_cut_from_the_content_ends_with_an_ellipsis_alone(): void {
		$post_id = $this->make_event(
			[],
			null,
			[
				'post_excerpt' => '',
				'post_content' => str_repeat( 'The council meets in public. ', 40 ),
			]
		);

		$description = $this->event( $post_id )['description'];

		$this->assertStringEndsWith( '…', $description );
		$this->assertStringNotContainsString( '[', $description );
	}

	/**
	 * The object sits in a script element, and a title must not be able to
	 * end it. Tags are stripped from the text, but entities are decoded after
	 * that, so "&lt;/script&gt;" in a title arrives as the real thing.
	 */
	public function test_a_title_cannot_close_the_script_element(): void {
		$post_id = $this->make_event( [], null, [ 'post_title' => 'Talk: &lt;/script&gt;&lt;script&gt;alert(1)&lt;/script&gt; &amp; more' ] );
		$html    = $this->head( $post_id );

		$this->assertSame( 1, substr_count( $html, '</script>' ), 'Only the element\'s own closing tag.' );
		$this->assertStringNotContainsString( '<script>alert', $html );

		$this->assertSame( 'Talk: </script><script>alert(1)</script> & more', $this->event( $post_id )['name'], 'And the name still decodes to what the title says.' );
	}

	// -------------------------------------------------------------------------
	// Where it is not printed
	// -------------------------------------------------------------------------

	public function test_a_password_protected_event_has_no_markup(): void {
		$post_id = $this->make_event( [], null, [ 'post_password' => 'secret' ] );

		$this->assertSame( '', $this->head( $post_id ) );
	}

	public function test_a_draft_previewed_by_its_author_has_no_markup(): void {
		$post_id = $this->make_event( [], null, [ 'post_status' => 'draft' ] );

		// A draft has no rows in the index. One is put there so that the
		// status is what is being tested, not the absence of a date.
		( new EventIndex() )->insert(
			[
				'post_id'        => $post_id,
				'start_datetime' => '2027-03-10 01:00:00',
				'end_datetime'   => '2027-03-10 03:00:00',
				'start_date'     => '2027-03-09',
				'end_date'       => '2027-03-09',
			]
		);

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->go_to(
			add_query_arg(
				[
					'p'         => $post_id,
					'post_type' => 'blockendar_event',
					'preview'   => 'true',
				],
				home_url( '/' )
			)
		);

		$this->assertTrue( is_singular( 'blockendar_event' ), 'Precondition: the preview is a single event page.' );
		$this->assertNotNull( blockendar_resolve_occurrence( $post_id ), 'Precondition: there is an occurrence to describe.' );

		ob_start();
		( new JsonLd() )->print_markup();

		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_other_pages_have_no_markup(): void {
		$this->make_event();

		foreach ( [ home_url( '/' ), home_url( '/?post_type=blockendar_event' ), get_permalink( self::factory()->post->create() ) ] as $url ) {
			$this->go_to( $url );

			ob_start();
			( new JsonLd() )->print_markup();

			$this->assertSame( '', (string) ob_get_clean(), $url );
		}
	}

	public function test_the_markup_is_printed_in_the_head(): void {
		global $wp_filter;

		$found = false;

		foreach ( $wp_filter['wp_head']->callbacks ?? [] as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];

				if ( is_array( $function ) && $function[0] instanceof JsonLd && 'print_markup' === $function[1] ) {
					$found = true;
				}
			}
		}

		$this->assertTrue( $found );
	}

	// -------------------------------------------------------------------------
	// Filters
	// -------------------------------------------------------------------------

	public function test_a_site_can_turn_the_markup_off(): void {
		$post_id = $this->make_event();
		$seen    = [];

		add_filter(
			'blockendar_json_ld_enabled',
			static function ( $enabled, $id, $occurrence ) use ( &$seen ) {
				$seen = [ $enabled, $id, $occurrence->start_date ];

				return false;
			},
			10,
			3
		);

		$this->assertSame( '', $this->head( $post_id ) );
		$this->assertSame( [ true, $post_id, '2027-03-09' ], $seen );
	}

	public function test_a_site_can_change_what_is_printed(): void {
		$post_id = $this->make_event();

		add_filter(
			'blockendar_json_ld_event',
			static function ( array $data, int $id ) {
				$data['organizer'] = [
					'@type' => 'Organization',
					'name'  => "Springfield Council {$id}",
				];

				return $data;
			},
			10,
			2
		);

		$this->assertSame( "Springfield Council {$post_id}", $this->event( $post_id )['organizer']['name'] );
	}

	public function test_a_filter_that_returns_nothing_prints_nothing(): void {
		$post_id = $this->make_event();

		add_filter( 'blockendar_json_ld_event', '__return_empty_array' );

		$this->assertSame( '', $this->head( $post_id ) );
	}
}
