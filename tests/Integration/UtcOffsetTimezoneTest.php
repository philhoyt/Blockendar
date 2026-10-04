<?php
/**
 * Integration coverage for sites and events that use a UTC offset, not a named timezone.
 *
 * WordPress lets a site choose "UTC+5:30" in place of a city. Such a site has no
 * timezone_string, and wp_timezone_string() answers "+05:30". The editor was
 * told the site's timezone was "UTC" and seeded that into every new event, so
 * an event entered as 7 pm was indexed as 7 pm UTC — five and a half hours
 * late.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\Blocks\BlockRegistrar;
use Blockendar\DB\EventIndex;
use Blockendar\DB\IndexBuilder;
use Blockendar\DB\Schema;
use Blockendar\Meta\EventMeta;
use WP_UnitTestCase;

class UtcOffsetTimezoneTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		Schema::create_tables();
		( new EventIndex() )->flush_cache();

		// WP_UnitTestCase unregisters every meta key in tear_down(), and the
		// sanitiser under test is attached by the registration.
		( new EventMeta() )->register_meta();

		delete_option( 'blockendar_settings' );
	}

	public function tear_down(): void {
		unset( $GLOBALS['blockendar_current_occurrence'] );
		delete_option( 'timezone_string' );
		delete_option( 'gmt_offset' );
		delete_option( 'blockendar_settings' );
		parent::tear_down();
	}

	/**
	 * Put the site on a manual offset.
	 *
	 * @param float $hours Offset from UTC in hours.
	 */
	private function use_offset( float $hours ): void {
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', $hours );
	}

	/**
	 * Create and index a 19:00–21:00 event on 10 July 2026.
	 *
	 * @param string $timezone Value for blockendar_timezone.
	 * @return int Post ID.
	 */
	private function make_evening_event( string $timezone ): int {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'publish',
			]
		);

		update_post_meta( $post_id, 'blockendar_start_date', '2026-07-10' );
		update_post_meta( $post_id, 'blockendar_end_date', '2026-07-10' );
		update_post_meta( $post_id, 'blockendar_start_time', '19:00' );
		update_post_meta( $post_id, 'blockendar_end_time', '21:00' );
		update_post_meta( $post_id, 'blockendar_timezone', $timezone );

		( new IndexBuilder() )->build_for_post( $post_id );
		( new EventIndex() )->flush_cache();

		return $post_id;
	}

	/**
	 * Render the date block for an event, with the timezone shown, as text.
	 *
	 * @param int $post_id Event post ID.
	 */
	private function date_block( int $post_id ): string {
		$html = ( new \WP_Block(
			[
				'blockName' => 'blockendar/event-datetime',
				'attrs'     => [
					'showTimezone' => true,
					'timeFormat'   => 'H:i',
				],
			],
			[ 'postId' => $post_id ]
		) )->render();

		return trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) );
	}

	// -------------------------------------------------------------------------
	// What the editor is given
	// -------------------------------------------------------------------------

	public function test_the_editor_is_given_the_sites_offset_as_its_timezone(): void {
		$this->use_offset( 5.5 );

		$timezones = BlockRegistrar::editor_timezones();

		$this->assertSame( '+05:30', $timezones['site'] );
		$this->assertContains( '+05:30', $timezones['options'], 'The picker has to be able to show the value it is seeded with.' );
	}

	public function test_a_site_with_a_named_timezone_is_unchanged(): void {
		update_option( 'timezone_string', 'America/Chicago' );

		$timezones = BlockRegistrar::editor_timezones();

		$this->assertSame( 'America/Chicago', $timezones['site'] );
		$this->assertSame( \DateTimeZone::listIdentifiers(), $timezones['options'] );
	}

	// -------------------------------------------------------------------------
	// What the event does with it
	// -------------------------------------------------------------------------

	/**
	 * Seeded with the site's real offset, 7 pm is 13:30 UTC, and the date block
	 * prints 7 pm back.
	 */
	public function test_an_event_on_the_sites_offset_is_indexed_and_shown_at_its_own_time(): void {
		$this->use_offset( 5.5 );

		$post_id = $this->make_evening_event( BlockRegistrar::editor_timezones()['site'] );
		$rows    = ( new EventIndex() )->get_by_post_id( $post_id );

		$this->assertSame( '2026-07-10 13:30:00', $rows[0]->start_datetime );
		$this->assertStringContainsString( '19:00', $this->date_block( $post_id ) );
	}

	/**
	 * The form WordPress and other plugins write a manual offset in. PHP's
	 * DateTimeZone rejects it, so the sanitiser stored nothing and the event
	 * silently took the site's timezone.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function written_offsets(): array {
		return [
			'half hour'   => [ 'UTC+5.5', '+05:30' ],
			'whole hours' => [ 'UTC-5', '-05:00' ],
			'zero'        => [ 'UTC+0', 'UTC' ],
			'already php' => [ '+05:30', '+05:30' ],
			'named'       => [ 'Europe/Berlin', 'Europe/Berlin' ],
			'nonsense'    => [ 'Mars/Olympus', '' ],
		];
	}

	/**
	 * @dataProvider written_offsets
	 *
	 * @param string $written  Value saved.
	 * @param string $expected Value stored.
	 */
	public function test_a_written_offset_is_stored_in_a_form_php_accepts( string $written, string $expected ): void {
		$post_id = self::factory()->post->create( [ 'post_type' => 'blockendar_event' ] );

		update_post_meta( $post_id, 'blockendar_timezone', $written );

		$this->assertSame( $expected, get_post_meta( $post_id, 'blockendar_timezone', true ) );
	}

	// -------------------------------------------------------------------------
	// The label
	// -------------------------------------------------------------------------

	/**
	 * The block printed the raw identifier. The abbreviation in force on the
	 * day is what a reader expects, and it differs across the year.
	 */
	public function test_the_date_block_labels_a_named_timezone_with_its_abbreviation(): void {
		update_option( 'blockendar_settings', [ 'timezone_mode' => 'event' ] );

		$text = $this->date_block( $this->make_evening_event( 'America/Chicago' ) );

		$this->assertStringContainsString( '(CDT)', $text );
		$this->assertStringNotContainsString( 'America/Chicago', $text );
	}

	public function test_the_date_block_labels_an_offset_as_utc_plus_or_minus(): void {
		update_option( 'blockendar_settings', [ 'timezone_mode' => 'event' ] );

		$this->assertStringContainsString( '(UTC+05:30)', $this->date_block( $this->make_evening_event( '+05:30' ) ) );
	}
}
