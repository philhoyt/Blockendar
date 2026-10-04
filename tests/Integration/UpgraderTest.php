<?php
/**
 * Integration coverage for the version-change upgrader.
 *
 * Updates through the update checker never re-activate the plugin, so rewrite
 * rules added or changed by a release stayed stale until flushed by hand. The
 * Upgrader flushes exactly once per version, and again when the events slug
 * setting changes.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\Admin\SettingsPage;
use Blockendar\DB\EventIndex;
use Blockendar\DB\Schema;
use Blockendar\Upgrader;
use WP_UnitTestCase;

class UpgraderTest extends WP_UnitTestCase {

	private const TYPE_RULE = '^events/type/([^/]+)/?$';

	public function set_up(): void {
		parent::set_up();

		Schema::create_tables();

		// Pretty permalinks, otherwise there are no rewrite rules to flush.
		$this->set_permalink_structure( '/%postname%/' );
		delete_option( 'rewrite_rules' );
		delete_option( SettingsPage::OPTION_NAME );
		wp_clear_scheduled_hook( 'blockendar_index_rebuild_after_upgrade' );

		global $wpdb;
		$events = Schema::events_table();
		$wpdb->query( "DELETE FROM {$events}" ); // phpcs:ignore WordPress.DB
		( new EventIndex() )->flush_cache();
	}

	public function tear_down(): void {
		delete_option( SettingsPage::OPTION_NAME );
		delete_option( Upgrader::PREVIOUS_VERSION_OPTION );
		wp_clear_scheduled_hook( 'blockendar_index_rebuild_after_upgrade' );
		parent::tear_down();
	}

	private function seed_row(): void {
		$post_id = self::factory()->post->create( [ 'post_type' => 'blockendar_event' ] );
		( new EventIndex() )->insert(
			[
				'post_id'        => $post_id,
				'start_datetime' => '2025-09-13 10:00:00',
				'end_datetime'   => '2025-09-13 11:00:00',
				'start_date'     => '2025-09-13',
				'end_date'       => '2025-09-13',
			]
		);
	}

	public function test_a_stale_version_option_regenerates_the_taxonomy_rules(): void {
		update_option( Upgrader::VERSION_OPTION, '1.2.0' );
		$this->assertFalse( get_option( 'rewrite_rules' ), 'Precondition: no stored rules.' );

		( new Upgrader() )->maybe_upgrade();

		$rules = get_option( 'rewrite_rules' );
		$this->assertIsArray( $rules );
		$this->assertArrayHasKey( self::TYPE_RULE, $rules );
		$this->assertArrayHasKey( '^events/venue/([^/]+)/?$', $rules );
		$this->assertSame( BLOCKENDAR_VERSION, get_option( Upgrader::VERSION_OPTION ) );
	}

	public function test_a_version_change_on_an_indexed_site_queues_a_rebuild(): void {
		update_option( Upgrader::VERSION_OPTION, '1.3.0' );
		$this->seed_row();

		( new Upgrader() )->maybe_upgrade();

		$this->assertNotFalse( wp_next_scheduled( 'blockendar_index_rebuild_after_upgrade' ) );
	}

	public function test_a_version_change_with_an_empty_index_does_not_queue_a_rebuild(): void {
		update_option( Upgrader::VERSION_OPTION, '1.3.0' );

		( new Upgrader() )->maybe_upgrade();

		$this->assertFalse( wp_next_scheduled( 'blockendar_index_rebuild_after_upgrade' ) );
	}

	/**
	 * Every release used to rebuild the index, which emptied it first. Most
	 * releases do not change what a row holds.
	 */
	public function test_an_upgrade_that_crosses_no_listed_release_queues_no_rebuild(): void {
		$versions = Upgrader::REBUILD_VERSIONS;

		// Just past the newest listed release, so nothing lies between it and
		// whatever the plugin is now.
		update_option( Upgrader::VERSION_OPTION, end( $versions ) . '.1' );
		$this->seed_row();

		( new Upgrader() )->maybe_upgrade();

		$this->assertFalse( wp_next_scheduled( 'blockendar_index_rebuild_after_upgrade' ) );
		$this->assertSame( BLOCKENDAR_VERSION, get_option( Upgrader::VERSION_OPTION ), 'The upgrade still ran.' );
	}

	/**
	 * @return array<string, array{string, string, bool}>
	 */
	public function upgrades(): array {
		return [
			'onto a listed release'      => [ '2.0.2', '2.1.0', true ],
			'over a listed release'      => [ '1.8.2', '2.3.0', true ],
			'a patch after it'           => [ '2.1.0', '2.1.1', false ],
			'two releases before it'     => [ '1.8.2', '2.0.2', false ],
			'from before versions began' => [ '0', '2.1.0', true ],
		];
	}

	/**
	 * @dataProvider upgrades
	 *
	 * @param string $from     Version upgraded from.
	 * @param string $to       Version upgraded to.
	 * @param bool   $expected Whether the index has to be rebuilt.
	 */
	public function test_only_an_upgrade_across_a_listed_release_rebuilds( string $from, string $to, bool $expected ): void {
		$this->assertSame( $expected, Upgrader::crosses_rebuild_version( $from, $to ) );
	}

	public function test_a_site_with_rows_and_no_stored_version_queues_a_rebuild(): void {
		delete_option( Upgrader::VERSION_OPTION );
		$this->seed_row();

		( new Upgrader() )->maybe_upgrade();

		$this->assertNotFalse( wp_next_scheduled( 'blockendar_index_rebuild_after_upgrade' ) );
	}

	public function test_an_upgrade_records_the_version_it_came_from(): void {
		update_option( Upgrader::VERSION_OPTION, '1.3.0' );

		( new Upgrader() )->maybe_upgrade();

		$this->assertSame( '1.3.0', get_option( Upgrader::PREVIOUS_VERSION_OPTION ) );
	}

	public function test_a_current_version_does_not_flush(): void {
		update_option( Upgrader::VERSION_OPTION, BLOCKENDAR_VERSION );
		$this->seed_row();

		( new Upgrader() )->maybe_upgrade();

		$this->assertFalse( get_option( 'rewrite_rules' ), 'An ordinary request must not regenerate rules.' );
		$this->assertFalse( wp_next_scheduled( 'blockendar_index_rebuild_after_upgrade' ) );
	}

	public function test_a_fresh_install_records_the_version(): void {
		delete_option( Upgrader::VERSION_OPTION );

		( new Upgrader() )->maybe_upgrade();

		$this->assertSame( BLOCKENDAR_VERSION, get_option( Upgrader::VERSION_OPTION ) );
		$this->assertIsArray( get_option( 'rewrite_rules' ) );
	}

	public function test_changing_the_events_slug_resets_the_rules(): void {
		( new Upgrader() )->register();
		flush_rewrite_rules( false );
		$this->assertIsArray( get_option( 'rewrite_rules' ), 'Precondition: rules are stored.' );

		// First save with a non-default slug: WordPress adds the option.
		update_option( SettingsPage::OPTION_NAME, [ 'events_slug' => 'whats-on' ] );
		$this->assertFalse( get_option( 'rewrite_rules' ), 'A first save with a new slug drops the stored rules.' );

		// Subsequent change: WordPress updates the option.
		flush_rewrite_rules( false );
		update_option( SettingsPage::OPTION_NAME, [ 'events_slug' => 'exhibitions' ] );
		$this->assertFalse( get_option( 'rewrite_rules' ), 'A slug change drops the stored rules.' );

		flush_rewrite_rules( false );
		update_option(
			SettingsPage::OPTION_NAME,
			[
				'events_slug'   => 'exhibitions',
				'timezone_mode' => 'site',
			]
		);
		$this->assertIsArray( get_option( 'rewrite_rules' ), 'Other setting changes leave the rules alone.' );
	}
}
