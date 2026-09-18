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

	public function test_changing_the_events_slug_flushes(): void {
		( new Upgrader() )->register();

		// First save with a non-default slug: WordPress adds the option.
		update_option( SettingsPage::OPTION_NAME, [ 'events_slug' => 'whats-on' ] );
		$this->assertIsArray( get_option( 'rewrite_rules' ), 'A first save with a new slug regenerates the rules.' );

		// Subsequent change: WordPress updates the option.
		delete_option( 'rewrite_rules' );
		update_option( SettingsPage::OPTION_NAME, [ 'events_slug' => 'exhibitions' ] );
		$this->assertIsArray( get_option( 'rewrite_rules' ), 'A slug change regenerates the rules.' );

		delete_option( 'rewrite_rules' );
		update_option(
			SettingsPage::OPTION_NAME,
			[
				'events_slug'   => 'exhibitions',
				'timezone_mode' => 'site',
			]
		);
		$this->assertFalse( get_option( 'rewrite_rules' ), 'Other setting changes leave the rules alone.' );
	}
}
