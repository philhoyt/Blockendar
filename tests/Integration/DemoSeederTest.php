<?php
/**
 * Integration coverage for the companion demo plugin's seeder.
 *
 * The demo plugin is the only thing standing between the Playground badge URL
 * and an empty site, and it runs against real sites too — so the cases that
 * matter most here are the destructive ones: reset must not take content it did
 * not create, and it must not leave junction rows behind.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\DB\Schema;
use Blockendar\Demo\Content;
use Blockendar\Demo\Dependency;
use Blockendar\Demo\Fixtures;
use Blockendar\Demo\Pages;
use Blockendar\Demo\Plugin;
use Blockendar\Demo\Seeder;
use WP_UnitTestCase;

class DemoSeederTest extends WP_UnitTestCase {

	private Seeder $seeder;

	public function set_up(): void {
		parent::set_up();

		Schema::create_tables();

		$this->seeder = new Seeder();

		delete_option( Plugin::STATE_OPTION );

		global $wpdb;
		foreach ( [ Schema::events_table(), Schema::recurrence_table(), Schema::type_terms_table() ] as $table ) {
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
		}
	}

	public function tear_down(): void {
		remove_all_filters( 'blockendar_demo_event_fixtures' );
		parent::tear_down();
	}

	/**
	 * Shrink the fixture set for tests that do not need the whole matrix.
	 *
	 * Doubles as coverage for the blockendar_demo_event_fixtures filter.
	 *
	 * @param int $count How many single events to keep.
	 */
	private function limit_fixtures( int $count ): void {
		add_filter(
			'blockendar_demo_event_fixtures',
			static fn( array $events ): array => array_slice( $events, 0, $count )
		);
	}

	private function count_rows( string $table ): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB
	}

	public function test_full_seed_creates_the_expected_content(): void {
		$result = $this->seeder->seed();

		$expected = count( Fixtures::events() ) + count( Fixtures::recurring() );

		$this->assertFalse( $result['skipped'] );
		$this->assertSame( $expected, $result['created'], 'Every fixture should produce an event.' );
		$this->assertSame( count( Content::PAGES ), $result['pages'] );
		$this->assertSame( [], $result['errors'] );

		$state = get_option( Plugin::STATE_OPTION );
		$this->assertIsArray( $state );
		$this->assertCount( $expected, $state['events'] );
		$this->assertCount( count( Content::PAGES ), $state['pages'] );

		// Six pages, each with the intended slug and non-empty content.
		foreach ( array_keys( Content::PAGES ) as $slug ) {
			$page = get_page_by_path( $slug );
			$this->assertInstanceOf( \WP_Post::class, $page, "Missing tour page: {$slug}" );
			$this->assertNotSame( '', trim( (string) $page->post_content ), "Empty tour page: {$slug}" );
		}

		$this->assertSame( 'page', get_option( 'show_on_front' ) );
		$this->assertGreaterThan( 0, (int) get_option( 'page_on_front' ) );
	}

	/**
	 * The whole point of the demo plugin: dates are generated relative to now,
	 * so the demo never expires.
	 *
	 * This asserts on the POSTS, not the index rows. The yearly fixture uses
	 * count=3, so the generator legitimately materialises occurrences years out.
	 */
	public function test_every_seeded_event_starts_within_180_days(): void {
		$this->seeder->seed();

		$state = get_option( Plugin::STATE_OPTION );
		$lower = strtotime( '-180 days' );
		$upper = strtotime( '+180 days' );
		$posts = 0;

		foreach ( $state['events'] as $post_id ) {
			$start = get_post_meta( (int) $post_id, 'blockendar_start_date', true );
			$this->assertNotEmpty( $start, "Event {$post_id} has no start date." );

			$stamp = strtotime( (string) $start );
			$this->assertGreaterThanOrEqual( $lower, $stamp, "Event {$post_id} starts more than 180 days ago." );
			$this->assertLessThanOrEqual( $upper, $stamp, "Event {$post_id} starts more than 180 days out." );
			++$posts;
		}

		$this->assertGreaterThan( 25, $posts );
	}

	public function test_recurring_events_expand_into_multiple_occurrences(): void {
		$this->limit_fixtures( 0 );
		$this->seeder->seed();

		$state = get_option( Plugin::STATE_OPTION );

		// With no single events, every recorded event is a recurring series.
		$this->assertCount( count( Fixtures::recurring() ), $state['events'] );

		global $wpdb;
		$events_table = Schema::events_table();

		foreach ( $state['events'] as $post_id ) {
			// Table name is interpolated because it cannot be parameterised; the
			// post ID is still bound via prepare().
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$occurrences = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$events_table} WHERE post_id = %d", (int) $post_id )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			$this->assertGreaterThan(
				1,
				$occurrences,
				"Recurring event {$post_id} produced {$occurrences} occurrence(s) — the generator was not listening."
			);
		}

		$this->assertSame( count( Fixtures::recurring() ), $this->count_rows( Schema::recurrence_table() ) );
	}

	public function test_reset_removes_everything_it_created(): void {
		$original_front = get_option( 'show_on_front' );
		$original_page  = (int) get_option( 'page_on_front' );

		$this->limit_fixtures( 4 );
		$this->seeder->seed();

		$this->assertGreaterThan( 0, $this->count_rows( Schema::events_table() ) );
		$this->assertGreaterThan( 0, $this->count_rows( Schema::type_terms_table() ) );

		$state = get_option( Plugin::STATE_OPTION );
		$this->seeder->reset();

		foreach ( $state['events'] as $post_id ) {
			$this->assertNull( get_post( (int) $post_id ), "Event {$post_id} survived reset." );
		}

		foreach ( $state['pages'] as $page_id ) {
			$this->assertNull( get_post( (int) $page_id ), "Page {$page_id} survived reset." );
		}

		foreach ( $state['terms'] as $term ) {
			$this->assertNull( get_term( (int) $term['id'], (string) $term['taxonomy'] ) );
		}

		$this->assertSame( 0, $this->count_rows( Schema::events_table() ) );
		$this->assertSame( 0, $this->count_rows( Schema::recurrence_table() ) );
		$this->assertSame(
			0,
			$this->count_rows( Schema::type_terms_table() ),
			'Junction rows were orphaned — reset did not go through EventIndex::delete_by_post_id().'
		);

		$this->assertFalse( get_option( Plugin::STATE_OPTION ) );
		$this->assertSame( $original_front, get_option( 'show_on_front' ) );
		$this->assertSame( $original_page, (int) get_option( 'page_on_front' ) );
	}

	public function test_seeding_twice_does_not_duplicate_content(): void {
		$this->limit_fixtures( 3 );

		$first = $this->seeder->seed();
		$this->assertFalse( $first['skipped'] );

		$events_after_first = $this->count_rows( Schema::events_table() );
		$pages_after_first  = $this->count_demo_pages();

		$second = $this->seeder->seed();

		$this->assertTrue( $second['skipped'], 'A second seed must not build a second dataset.' );
		$this->assertSame( 0, $second['created'] );
		$this->assertSame( $events_after_first, $this->count_rows( Schema::events_table() ) );
		$this->assertSame( $pages_after_first, $this->count_demo_pages(), 'Re-seeding must not create more pages.' );
	}

	/**
	 * A site seeded before a markup change has to be able to pick up the new
	 * pages. Reset is not an answer: it deletes the events too, and their dates
	 * are generated relative to the day they were made.
	 */
	public function test_reseeding_rewrites_page_content_in_place(): void {
		$this->limit_fixtures( 3 );
		$this->seeder->seed();

		$state = get_option( Plugin::STATE_OPTION );
		$this->assertIsArray( $state );

		$before = [];
		foreach ( array_keys( Content::PAGES ) as $slug ) {
			$page = get_page_by_path( $slug );
			$this->assertInstanceOf( \WP_Post::class, $page );

			$before[ $slug ] = $page->ID;

			// Stand in for a page built by an older version of the demo.
			wp_update_post(
				[
					'ID'           => $page->ID,
					'post_content' => '<!-- wp:paragraph --><p>stale</p><!-- /wp:paragraph -->',
				]
			);
		}

		$result = $this->seeder->seed();

		$this->assertTrue( $result['skipped'] );
		$this->assertSame( count( Content::PAGES ), $result['refreshed'] );

		foreach ( $before as $slug => $page_id ) {
			$page = get_post( $page_id );
			$this->assertInstanceOf( \WP_Post::class, $page );
			$this->assertStringNotContainsString( 'stale', (string) $page->post_content, "Stale content left on {$slug}." );
			$this->assertStringContainsString( '<!-- wp:', (string) $page->post_content, "No blocks written to {$slug}." );

			// Same post, rewritten — not a replacement at a new ID.
			$this->assertSame( $page_id, (int) get_page_by_path( $slug )->ID );
		}

		$this->assertSame( count( Content::PAGES ), $this->count_demo_pages() );
	}

	/**
	 * refresh() writes one page's content over another only if it can tell them
	 * apart. A page the user deleted must not come back, and one that is no
	 * longer ours must not be touched.
	 */
	public function test_reseeding_skips_pages_it_no_longer_owns(): void {
		$this->limit_fixtures( 3 );
		$this->seeder->seed();

		$calendar = get_page_by_path( 'calendar' );
		$this->assertInstanceOf( \WP_Post::class, $calendar );

		// Someone took this page over: the marker is gone.
		delete_post_meta( $calendar->ID, Seeder::MARKER );
		wp_update_post(
			[
				'ID'           => $calendar->ID,
				'post_content' => 'theirs now',
			]
		);

		// And this one they deleted outright.
		$subscribe = get_page_by_path( 'subscribe' );
		$this->assertInstanceOf( \WP_Post::class, $subscribe );
		wp_delete_post( $subscribe->ID, true );

		$result = $this->seeder->seed();

		$this->assertSame( count( Content::PAGES ) - 2, $result['refreshed'] );
		$this->assertSame( 'theirs now', (string) get_post( $calendar->ID )->post_content );
		$this->assertNull( get_page_by_path( 'subscribe' ), 'A deleted tour page must not be recreated.' );
	}

	/**
	 * Demos seeded before Pages::SLUG_META existed have only a post_name to go
	 * on, and that name may carry the "-2" suffix wp_insert_post() adds when the
	 * slug was already taken.
	 */
	public function test_reseeding_recovers_pages_seeded_without_the_slug_meta(): void {
		$this->limit_fixtures( 3 );
		$this->seeder->seed();

		foreach ( array_keys( Content::PAGES ) as $slug ) {
			$page = get_page_by_path( $slug );
			$this->assertInstanceOf( \WP_Post::class, $page );

			delete_post_meta( $page->ID, Pages::SLUG_META );
			wp_update_post(
				[
					'ID'           => $page->ID,
					'post_content' => 'stale',
				]
			);
		}

		$result = $this->seeder->seed();

		$this->assertSame( count( Content::PAGES ), $result['refreshed'] );

		foreach ( array_keys( Content::PAGES ) as $slug ) {
			$page = get_page_by_path( $slug );
			$this->assertStringContainsString( '<!-- wp:', (string) $page->post_content );
			$this->assertSame( $slug, get_post_meta( $page->ID, Pages::SLUG_META, true ), 'The meta should be backfilled.' );
		}
	}

	private function count_demo_pages(): int {
		return count(
			get_posts(
				[
					'post_type'   => 'page',
					'post_status' => 'any',
					'numberposts' => -1,
					'fields'      => 'ids',
					'meta_key'    => Seeder::MARKER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				]
			)
		);
	}

	/**
	 * The destructive case. A real site installing the demo must not lose its
	 * own "calendar" page or "music" term.
	 */
	public function test_pre_existing_slugs_are_never_claimed_or_deleted(): void {
		$their_page = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_title'  => 'Calendar',
				'post_name'   => 'calendar',
				'post_status' => 'publish',
			]
		);

		$their_term = self::factory()->term->create(
			[
				'taxonomy' => 'event_type',
				'name'     => 'Music',
				'slug'     => 'music',
			]
		);

		$this->limit_fixtures( 2 );
		$this->seeder->seed();

		$state = get_option( Plugin::STATE_OPTION );

		$this->assertNotContains( $their_page, array_map( 'intval', $state['pages'] ), 'Demo claimed an existing page.' );
		$this->assertNotContains(
			$their_term,
			array_map( static fn( $t ) => (int) $t['id'], $state['terms'] ),
			'Demo claimed an existing term.'
		);

		$this->seeder->reset();

		$this->assertInstanceOf( \WP_Post::class, get_post( $their_page ), 'Reset deleted a page it did not create.' );
		$this->assertInstanceOf( \WP_Term::class, get_term( $their_term, 'event_type' ), 'Reset deleted a term it did not create.' );
		$this->assertSame( 'calendar', get_post( $their_page )->post_name );
	}

	public function test_featured_images_are_attached_with_generated_metadata(): void {
		$this->limit_fixtures( 1 );
		$this->seeder->seed();

		$state         = get_option( Plugin::STATE_OPTION );
		$attachment_id = (int) ( $state['attachments'][0] ?? 0 );

		$this->assertGreaterThan( 0, $attachment_id, 'No attachment was created — media_handle_sideload() failed.' );
		$this->assertSame( 'attachment', get_post_type( $attachment_id ) );

		$meta = wp_get_attachment_metadata( $attachment_id );
		$this->assertIsArray( $meta, 'Attachment has no generated metadata.' );
		$this->assertArrayHasKey( 'width', $meta );

		$event_id = (int) $state['events'][0];
		$this->assertSame( $attachment_id, get_post_thumbnail_id( $event_id ) );
	}

	/**
	 * The demo must not touch blockendar_settings at all.
	 *
	 * An earlier version seeded "demo defaults" into this option. Two of the
	 * three were already the plugin's own defaults, and the third
	 * ('map_provider') is not a registered key at all — SettingsPage::sanitize()
	 * rebuilds the option from its known keys, so it was silently stripped on
	 * write. The whole step was a no-op wrapped around a bug, and seeding now
	 * leaves the option alone.
	 */
	public function test_seeding_does_not_touch_site_settings(): void {
		update_option( 'blockendar_settings', [ 'default_currency' => 'GBP' ] );

		$before = get_option( 'blockendar_settings' );

		$this->limit_fixtures( 1 );
		$this->seeder->seed();

		$after = get_option( 'blockendar_settings' );
		$this->assertSame( 'GBP', $after['default_currency'], 'Demo overwrote an existing setting.' );
		$this->assertSame( $before, $after, 'Seeding changed blockendar_settings.' );

		$this->seeder->reset();

		$this->assertSame(
			$before,
			get_option( 'blockendar_settings' ),
			'Reset changed blockendar_settings.'
		);
	}

	public function test_dependency_guard_rejects_missing_or_old_blockendar(): void {
		$this->assertNotSame( '', Dependency::evaluate( null ), 'A missing Blockendar must be refused.' );
		$this->assertNotSame( '', Dependency::evaluate( '' ) );
		$this->assertNotSame( '', Dependency::evaluate( '0.9.0' ), 'A too-old Blockendar must be refused.' );

		$this->assertSame( '', Dependency::evaluate( BLOCKENDAR_DEMO_MIN_BLOCKENDAR ) );
		$this->assertSame( '', Dependency::evaluate( '99.0.0' ) );
		$this->assertTrue( Dependency::is_satisfied(), 'The loaded Blockendar should satisfy the demo plugin.' );
	}
}
