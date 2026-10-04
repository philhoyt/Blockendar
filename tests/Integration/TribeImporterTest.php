<?php
/**
 * Integration coverage for the importer from The Events Calendar.
 *
 * Every fixture here is a WXR item shaped the way core's export writes one and
 * carrying the meta TEC 6.17.5 stores. The importer runs against real
 * WordPress because what it gets wrong is what WordPress then does with the
 * post: publish it, protect it, index it.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\DB\EventIndex;
use Blockendar\DB\Schema;
use Blockendar\Import\TribeImporter;
use WP_UnitTestCase;

class TribeImporterTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		Schema::create_tables();
		( new EventIndex() )->flush_cache();

		delete_option( 'blockendar_settings' );
	}

	public function tear_down(): void {
		delete_option( 'blockendar_settings' );
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Fixtures
	// -------------------------------------------------------------------------

	/**
	 * Build one <item> the way wp-admin/includes/export.php writes a tribe_events post.
	 *
	 * @param array $item {
	 *     @type string $title    Post title.
	 *     @type string $slug     Post name.
	 *     @type string $status   Post status.
	 *     @type string $password Post password.
	 *     @type string $date_gmt Post date, GMT.
	 *     @type array  $meta     List of [ key, value ] pairs; a list so a key can repeat.
	 * }
	 */
	private function item( array $item ): string {
		$item = array_merge(
			[
				'title'    => 'Imported Event',
				'slug'     => 'imported-event',
				'status'   => 'publish',
				'password' => '',
				'date_gmt' => '2026-01-05 12:00:00',
				'meta'     => [],
			],
			$item
		);

		$meta = '';

		foreach ( $item['meta'] as [ $key, $value ] ) {
			$meta .= "<wp:postmeta><wp:meta_key><![CDATA[{$key}]]></wp:meta_key>"
				. "<wp:meta_value><![CDATA[{$value}]]></wp:meta_value></wp:postmeta>";
		}

		return "<item>
			<title><![CDATA[{$item['title']}]]></title>
			<content:encoded><![CDATA[Body.]]></content:encoded>
			<wp:post_date_gmt><![CDATA[{$item['date_gmt']}]]></wp:post_date_gmt>
			<wp:post_name><![CDATA[{$item['slug']}]]></wp:post_name>
			<wp:status><![CDATA[{$item['status']}]]></wp:status>
			<wp:post_type><![CDATA[tribe_events]]></wp:post_type>
			<wp:post_password><![CDATA[{$item['password']}]]></wp:post_password>
			{$meta}
		</item>";
	}

	/**
	 * Wrap items in a WXR document.
	 *
	 * @param string ...$items Rendered <item> elements.
	 */
	private function wxr( string ...$items ): string {
		return '<?xml version="1.0" encoding="UTF-8"?>
			<rss version="2.0"
				xmlns:content="http://purl.org/rss/1.0/modules/content/"
				xmlns:dc="http://purl.org/dc/elements/1.1/"
				xmlns:wp="http://wordpress.org/export/1.2/">
			<channel>' . implode( '', $items ) . '</channel></rss>';
	}

	/**
	 * Meta for a timed event on one day.
	 *
	 * @param array $extra Further [ key, value ] pairs.
	 */
	private function timed_meta( array $extra = [] ): array {
		return array_merge(
			[
				[ '_EventStartDate', '2026-03-10 20:00:00' ],
				[ '_EventEndDate', '2026-03-10 22:00:00' ],
				[ '_EventTimezone', 'America/Chicago' ],
			],
			$extra
		);
	}

	/**
	 * Import one item and return the event it produced, or null.
	 *
	 * @param array $item Item definition for item().
	 */
	private function import_one( array $item ): ?\WP_Post {
		( new TribeImporter() )->import( $this->wxr( $this->item( $item ) ) );

		return $this->find_event( $item['slug'] ?? 'imported-event' );
	}

	/**
	 * Find an imported event by slug, whatever its status.
	 *
	 * @param string $slug Post name.
	 */
	private function find_event( string $slug ): ?\WP_Post {
		// Not get_posts( [ 'name' => … ] ): that is a singular query, and
		// WP_Query empties a singular result whose status is not public when
		// nobody is logged in — so a pending or scheduled event would read as
		// "not imported".
		$post = get_page_by_path( $slug, OBJECT, 'blockendar_event' );

		return $post instanceof \WP_Post ? $post : null;
	}

	// -------------------------------------------------------------------------
	// Status
	// -------------------------------------------------------------------------

	/**
	 * Core's export writes every status except auto-draft, so a TEC export
	 * carries pending, scheduled and trashed events. None of them may arrive
	 * published.
	 */
	public function test_a_pending_event_stays_pending(): void {
		$post = $this->import_one(
			[
				'status' => 'pending',
				'meta'   => $this->timed_meta(),
			]
		);

		$this->assertSame( 'pending', $post->post_status );
	}

	public function test_a_scheduled_event_stays_scheduled(): void {
		$post = $this->import_one(
			[
				'status'   => 'future',
				'date_gmt' => gmdate( 'Y-m-d H:i:s', time() + YEAR_IN_SECONDS ),
				'meta'     => $this->timed_meta(),
			]
		);

		$this->assertSame( 'future', $post->post_status );
	}

	public function test_a_trashed_event_is_skipped_and_reported(): void {
		$result = ( new TribeImporter() )->import(
			$this->wxr(
				$this->item(
					[
						'status' => 'trash',
						'meta'   => $this->timed_meta(),
					]
				)
			)
		);

		$this->assertNull( $this->find_event( 'imported-event' ) );
		$this->assertSame( 0, $result['imported'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( [], $result['errors'], 'A skipped item is not an error.' );
		$this->assertStringContainsString( 'trash', (string) wp_json_encode( $result['events'][0] ) );
	}

	public function test_an_unknown_status_becomes_a_draft(): void {
		$post = $this->import_one(
			[
				'status' => 'tribe-ea-pending',
				'meta'   => $this->timed_meta(),
			]
		);

		$this->assertSame( 'draft', $post->post_status );
	}

	public function test_a_published_event_stays_published(): void {
		$post = $this->import_one( [ 'meta' => $this->timed_meta() ] );

		$this->assertSame( 'publish', $post->post_status );
	}

	// -------------------------------------------------------------------------
	// Password
	// -------------------------------------------------------------------------

	public function test_a_password_protected_event_keeps_its_password(): void {
		$post = $this->import_one(
			[
				'password' => 'hunter2',
				'meta'     => $this->timed_meta(),
			]
		);

		$this->assertSame( 'hunter2', $post->post_password );
	}

	// -------------------------------------------------------------------------
	// Re-import
	// -------------------------------------------------------------------------

	/**
	 * A second run updates the event it created the first time. The update has
	 * to carry the same fields as the insert, or a status or password changed
	 * at the source never arrives — in either direction.
	 */
	public function test_a_reimport_applies_a_changed_status_and_password(): void {
		$this->import_one( [ 'meta' => $this->timed_meta() ] );

		$post = $this->import_one(
			[
				'status'   => 'pending',
				'password' => 'hunter2',
				'meta'     => $this->timed_meta(),
			]
		);

		$this->assertSame( 'pending', $post->post_status );
		$this->assertSame( 'hunter2', $post->post_password );
	}

	public function test_a_reimport_clears_a_password_removed_at_the_source(): void {
		$this->import_one(
			[
				'password' => 'hunter2',
				'meta'     => $this->timed_meta(),
			]
		);

		$post = $this->import_one( [ 'meta' => $this->timed_meta() ] );

		$this->assertSame( '', $post->post_password );
	}

	public function test_a_reimport_does_not_revive_a_trashed_source_event(): void {
		$first = $this->import_one( [ 'meta' => $this->timed_meta() ] );

		( new TribeImporter() )->import(
			$this->wxr(
				$this->item(
					[
						'title'  => 'Changed At Source',
						'status' => 'trash',
						'meta'   => $this->timed_meta(),
					]
				)
			)
		);

		$this->assertSame( 'Imported Event', get_post( $first->ID )->post_title, 'A skipped item must not touch the existing event.' );
	}

	// -------------------------------------------------------------------------
	// Times
	// -------------------------------------------------------------------------

	/**
	 * 23:59 on a timed event is a real end time. Blanking it made the index
	 * fall back to the start time, so the event had no length.
	 */
	public function test_a_timed_event_ending_at_2359_keeps_its_end_time(): void {
		$post = $this->import_one(
			[
				'meta' => [
					[ '_EventStartDate', '2026-03-10 20:00:00' ],
					[ '_EventEndDate', '2026-03-10 23:59:00' ],
					[ '_EventTimezone', 'UTC' ],
				],
			]
		);

		$this->assertSame( '23:59', get_post_meta( $post->ID, 'blockendar_end_time', true ) );

		$rows = ( new EventIndex() )->get_by_post_id( $post->ID );

		$this->assertCount( 1, $rows );
		$this->assertSame( '2026-03-10 20:00:00', $rows[0]->start_datetime );
		$this->assertSame( '2026-03-10 23:59:00', $rows[0]->end_datetime );
	}

	/**
	 * TEC stores an all-day event's start at its "end of day cutoff" and its
	 * end one second before the cutoff on the day after the last: 23:59:59 on
	 * the last day with no cutoff, 05:59:59 on the day after it with a 06:00
	 * cutoff (tribe_beginning_of_day(), tribe_end_of_day()).
	 *
	 * @return array<string, array{string, string, string, string}>
	 */
	public function all_day_ranges(): array {
		return [
			'one day, no cutoff'       => [ '2026-03-10 00:00:00', '2026-03-10 23:59:59', '2026-03-10', '2026-03-10' ],
			'three days, no cutoff'    => [ '2026-03-10 00:00:00', '2026-03-12 23:59:59', '2026-03-10', '2026-03-12' ],
			'one day, 06:00 cutoff'    => [ '2026-03-10 06:00:00', '2026-03-11 05:59:59', '2026-03-10', '2026-03-10' ],
			'three days, 06:00 cutoff' => [ '2026-03-10 06:00:00', '2026-03-13 05:59:59', '2026-03-10', '2026-03-12' ],
			'end equal to start'       => [ '2026-03-10 00:00:00', '2026-03-10 00:00:00', '2026-03-10', '2026-03-10' ],
			'end without seconds'      => [ '2026-03-10 00:00:00', '2026-03-12 23:59:00', '2026-03-10', '2026-03-12' ],
			'end before start'         => [ '2026-03-10 00:00:00', '2026-03-09 23:59:59', '2026-03-10', '2026-03-10' ],
		];
	}

	/**
	 * @dataProvider all_day_ranges
	 *
	 * @param string $start          _EventStartDate.
	 * @param string $end            _EventEndDate.
	 * @param string $expected_start Start date the event should have.
	 * @param string $expected_end   End date the event should have.
	 */
	public function test_an_all_day_event_gets_the_right_dates( string $start, string $end, string $expected_start, string $expected_end ): void {
		$post = $this->import_one(
			[
				'meta' => [
					[ '_EventStartDate', $start ],
					[ '_EventEndDate', $end ],
					[ '_EventAllDay', 'yes' ],
				],
			]
		);

		$this->assertSame( $expected_start, get_post_meta( $post->ID, 'blockendar_start_date', true ) );
		$this->assertSame( $expected_end, get_post_meta( $post->ID, 'blockendar_end_date', true ) );
		$this->assertSame( '', get_post_meta( $post->ID, 'blockendar_start_time', true ) );
	}

	// -------------------------------------------------------------------------
	// Timezone
	// -------------------------------------------------------------------------

	/**
	 * TEC stores a manual offset as "UTC+5.5". PHP's DateTimeZone rejects that
	 * form, so the meta sanitiser blanked it and the event took the importing
	 * site's timezone.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function timezones(): array {
		return [
			'named zone'     => [ 'America/Chicago', 'America/Chicago' ],
			'whole hours'    => [ 'UTC+5', '+05:00' ],
			'negative'       => [ 'UTC-5', '-05:00' ],
			'half hour'      => [ 'UTC+5.5', '+05:30' ],
			'three quarters' => [ 'UTC+5.75', '+05:45' ],
			'negative half'  => [ 'UTC-9.5', '-09:30' ],
			'zero'           => [ 'UTC+0', 'UTC' ],
			'plain UTC'      => [ 'UTC', 'UTC' ],
		];
	}

	/**
	 * @dataProvider timezones
	 *
	 * @param string $source   _EventTimezone.
	 * @param string $expected Timezone the event should have.
	 */
	public function test_a_timezone_is_stored_in_a_form_php_accepts( string $source, string $expected ): void {
		$post = $this->import_one(
			[
				'meta' => [
					[ '_EventStartDate', '2026-03-10 20:00:00' ],
					[ '_EventEndDate', '2026-03-10 22:00:00' ],
					[ '_EventTimezone', $source ],
				],
			]
		);

		$this->assertSame( $expected, get_post_meta( $post->ID, 'blockendar_timezone', true ) );
	}

	public function test_an_offset_timezone_is_used_to_build_the_index(): void {
		$post = $this->import_one(
			[
				'meta' => [
					[ '_EventStartDate', '2026-03-10 20:00:00' ],
					[ '_EventEndDate', '2026-03-10 22:00:00' ],
					[ '_EventTimezone', 'UTC+5.5' ],
				],
			]
		);

		$rows = ( new EventIndex() )->get_by_post_id( $post->ID );

		$this->assertSame( '2026-03-10 14:30:00', $rows[0]->start_datetime );
	}

	// -------------------------------------------------------------------------
	// Cost
	// -------------------------------------------------------------------------

	/**
	 * TEC shows a cost of 0 as "Free". It is a value, not an absence.
	 */
	public function test_a_cost_of_zero_is_kept(): void {
		$post = $this->import_one( [ 'meta' => $this->timed_meta( [ [ '_EventCost', '0' ] ] ) ] );

		$this->assertSame( '0', get_post_meta( $post->ID, 'blockendar_cost', true ) );
	}

	public function test_the_cost_block_renders_a_cost_of_zero(): void {
		$post = $this->import_one( [ 'meta' => $this->timed_meta( [ [ '_EventCost', '0' ] ] ) ] );

		$block = new \WP_Block(
			[
				'blockName' => 'blockendar/event-cost',
				'attrs'     => [],
			],
			[ 'postId' => $post->ID ]
		);

		$this->assertStringContainsString( '$0', $block->render() );
	}

	/**
	 * The cost block puts the importing site's currency symbol in front of a
	 * bare number. A TEC event priced in another currency has to carry its own
	 * symbol, or "€10" arrives as "$10".
	 *
	 * @return array<string, array{array, string}>
	 */
	public function costs(): array {
		return [
			'same currency as the site' => [ [ [ '_EventCost', '10' ], [ '_EventCurrencySymbol', '$' ] ], '10' ],
			'no symbol stored'          => [ [ [ '_EventCost', '10' ] ], '10' ],
			'other currency, prefix'    => [ [ [ '_EventCost', '10' ], [ '_EventCurrencySymbol', '€' ], [ '_EventCurrencyPosition', 'prefix' ] ], '€10' ],
			'other currency, suffix'    => [ [ [ '_EventCost', '10' ], [ '_EventCurrencySymbol', '€' ], [ '_EventCurrencyPosition', 'suffix' ] ], '10€' ],
			'other currency, unset'     => [ [ [ '_EventCost', '10' ], [ '_EventCurrencySymbol', '€' ] ], '€10' ],
			'free in another currency'  => [ [ [ '_EventCost', '0' ], [ '_EventCurrencySymbol', '€' ] ], '0' ],
			'text cost'                 => [ [ [ '_EventCost', 'Donation' ], [ '_EventCurrencySymbol', '€' ] ], 'Donation' ],
			'several cost rows'         => [ [ [ '_EventCost', '10' ], [ '_EventCost', '25' ] ], '10' ],
			'empty row before a value'  => [ [ [ '_EventCost', '' ], [ '_EventCost', '25' ] ], '25' ],
		];
	}

	/**
	 * @dataProvider costs
	 *
	 * @param array  $meta     Cost meta rows.
	 * @param string $expected Stored cost.
	 */
	public function test_a_cost_keeps_its_own_currency( array $meta, string $expected ): void {
		$post = $this->import_one( [ 'meta' => $this->timed_meta( $meta ) ] );

		$this->assertSame( $expected, get_post_meta( $post->ID, 'blockendar_cost', true ) );
	}

	// -------------------------------------------------------------------------
	// Reporting
	// -------------------------------------------------------------------------

	/**
	 * A dry run exists so the operator can see what the import will decide.
	 * Every value the importer changes or drops is named for the event it
	 * belongs to.
	 */
	public function test_a_dry_run_reports_each_decision_and_writes_nothing(): void {
		$result = ( new TribeImporter() )->import(
			$this->wxr(
				$this->item(
					[
						'slug'   => 'odd-status',
						'status' => 'tribe-ea-pending',
						'meta'   => [
							[ '_EventStartDate', '2026-03-10 20:00:00' ],
							[ '_EventEndDate', '2026-03-10 22:00:00' ],
							[ '_EventTimezone', 'UTC+5.5' ],
							[ '_EventCost', '10' ],
							[ '_EventCost', '25' ],
							[ '_EventCurrencySymbol', '€' ],
						],
					]
				),
				$this->item(
					[
						'slug'   => 'binned',
						'status' => 'trash',
						'meta'   => $this->timed_meta(),
					]
				)
			),
			true
		);

		$this->assertNull( $this->find_event( 'odd-status' ) );
		$this->assertSame( 1, $result['imported'] );
		$this->assertSame( 1, $result['skipped'] );

		$notes = implode( "\n", $result['events'][0]['notes'] );

		$this->assertStringContainsString( 'tribe-ea-pending', $notes );
		$this->assertStringContainsString( 'draft', $notes );
		$this->assertStringContainsString( 'UTC+5.5', $notes );
		$this->assertStringContainsString( '+05:30', $notes );
		$this->assertStringContainsString( '€10', $notes );
		$this->assertStringContainsString( '2 cost values', $notes );
	}

	public function test_an_event_with_nothing_to_report_has_no_notes(): void {
		$result = ( new TribeImporter() )->import( $this->wxr( $this->item( [ 'meta' => $this->timed_meta() ] ) ), true );

		$this->assertSame( [], $result['events'][0]['notes'] );
	}
}
