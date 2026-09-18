<?php
/**
 * Integration coverage for the schema upgrade path.
 *
 * Schema::maybe_upgrade() relies on dbDelta() to add columns that a stored
 * version is missing, then schedules a one-shot index rebuild. This pins the
 * version-3 upgrade that introduced the `ongoing` column, against a real
 * database rather than a stub.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\DB\Schema;
use WP_UnitTestCase;

class SchemaUpgradeTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		wp_clear_scheduled_hook( 'blockendar_index_rebuild_after_upgrade' );
	}

	public function tear_down(): void {
		// Leave the schema the way the rest of the suite expects it.
		Schema::create_tables();
		wp_clear_scheduled_hook( 'blockendar_index_rebuild_after_upgrade' );
		parent::tear_down();
	}

	/**
	 * Whether the events table currently has the named column.
	 */
	private function has_column( string $column ): bool {
		global $wpdb;

		$table = Schema::events_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $column ) );

		return null !== $found;
	}

	public function test_a_fresh_install_is_at_the_current_version(): void {
		$this->assertSame( Schema::DB_VERSION, get_option( Schema::DB_VERSION_OPTION ) );
		$this->assertTrue( $this->has_column( 'ongoing' ) );
	}

	public function test_upgrading_from_version_2_adds_the_ongoing_column_and_schedules_a_rebuild(): void {
		global $wpdb;

		// Simulate a site that installed at schema version 2.
		$table = Schema::events_table();
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN ongoing" ); // phpcs:ignore WordPress.DB
		update_option( Schema::DB_VERSION_OPTION, '2' );

		$this->assertFalse( $this->has_column( 'ongoing' ), 'Precondition: the column must be gone.' );

		// Seed a row the old way so we can check the default for existing data.
		$post_id = self::factory()->post->create( [ 'post_type' => 'blockendar_event' ] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$table,
			[
				'post_id'        => $post_id,
				'start_datetime' => '2025-09-13 10:00:00',
				'end_datetime'   => '2025-09-13 11:00:00',
				'start_date'     => '2025-09-13',
				'end_date'       => '2025-09-13',
			]
		);

		Schema::maybe_upgrade();

		$this->assertTrue( $this->has_column( 'ongoing' ), 'dbDelta should have added the column.' );
		$this->assertSame( '3', get_option( Schema::DB_VERSION_OPTION ) );
		$this->assertNotFalse(
			wp_next_scheduled( 'blockendar_index_rebuild_after_upgrade' ),
			'An upgrade (not a fresh install) must queue the one-shot rebuild.'
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ongoing = $wpdb->get_var( $wpdb->prepare( 'SELECT ongoing FROM %i WHERE post_id = %d', $table, $post_id ) );
		$this->assertSame( 0, (int) $ongoing, 'Existing rows default to not ongoing.' );
	}

	public function test_a_fresh_install_does_not_schedule_a_rebuild(): void {
		delete_option( Schema::DB_VERSION_OPTION );

		Schema::maybe_upgrade();

		$this->assertSame( Schema::DB_VERSION, get_option( Schema::DB_VERSION_OPTION ) );
		$this->assertFalse( wp_next_scheduled( 'blockendar_index_rebuild_after_upgrade' ) );
	}
}
