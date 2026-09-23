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

		// Simulate a site that installed at schema version 2: no `ongoing` column
		// and none of the indexes later versions added.
		//
		// The composites must go first. Dropping a column does not drop an index
		// that references it — MySQL rewrites the index without that column — so
		// leaving idx_visible_past in place would turn it into
		// (hide_from_listings, start_datetime). dbDelta only ever ADDs a missing index, it never
		// reshapes one whose columns changed, so it would then retry
		// `ADD KEY idx_visible_past` on every run and fail with "Duplicate key name".
		$table = Schema::events_table();
		$this->drop_v4_indexes();
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
		$this->assertSame( Schema::DB_VERSION, get_option( Schema::DB_VERSION_OPTION ) );

		// A v2 site jumps straight to v4 in one pass: the column and the
		// composites that depend on it have to land in the same dbDelta run.
		$names = $this->index_names( $table );
		$this->assertContains( 'idx_visible_start', $names );
		$this->assertContains( 'idx_visible_past', $names );
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

	/**
	 * Index names present on a table, de-duplicated (SHOW INDEX returns one row
	 * per column, so a composite appears once per part).
	 *
	 * @return string[]
	 */
	private function index_names( string $table ): array {
		global $wpdb;

		// SHOW INDEX takes no placeholders for the table name in all supported
		// versions; the name is built from $wpdb->prefix, not user input.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_values( array_unique( (array) $wpdb->get_col( "SHOW INDEX FROM {$table}", 2 ) ) );
	}

	/**
	 * Drop the two version-4 composites, putting the table back to its v3 shape.
	 */
	private function drop_v4_indexes(): void {
		global $wpdb;

		$table = Schema::events_table();

		foreach ( [ 'idx_visible_start', 'idx_visible_past' ] as $index ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX {$index}" );
		}
	}

	public function test_a_fresh_install_creates_both_composite_indexes(): void {
		$names = $this->index_names( Schema::events_table() );

		$this->assertContains( 'idx_visible_start', $names );
		$this->assertContains( 'idx_visible_past', $names );
	}

	public function test_the_composites_cover_the_expected_columns_in_order(): void {
		global $wpdb;

		$table = Schema::events_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SHOW INDEX FROM {$table}" );

		$columns = [];
		foreach ( $rows as $row ) {
			// SHOW INDEX column names are MySQL's, not ours.
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$columns[ $row->Key_name ][ (int) $row->Seq_in_index ] = $row->Column_name;
		}

		foreach ( $columns as $key => $parts ) {
			ksort( $parts );
			$columns[ $key ] = array_values( $parts );
		}

		// Column order is the whole point of a composite: a prefix in the wrong
		// order serves a different set of queries.
		$this->assertSame( [ 'hide_from_listings', 'start_datetime' ], $columns['idx_visible_start'] );
		$this->assertSame( [ 'hide_from_listings', 'ongoing', 'start_datetime' ], $columns['idx_visible_past'] );
	}

	public function test_upgrading_from_version_3_adds_both_composites_without_a_reindex(): void {
		global $wpdb;

		$table = Schema::events_table();

		// Simulate a site sitting at schema version 3.
		$this->drop_v4_indexes();
		update_option( Schema::DB_VERSION_OPTION, '3' );

		$names = $this->index_names( $table );
		$this->assertNotContains( 'idx_visible_start', $names, 'Precondition: the composite must be gone.' );
		$this->assertNotContains( 'idx_visible_past', $names, 'Precondition: the composite must be gone.' );

		// Seed a row so we can prove the upgrade does not disturb existing data.
		$post_id = self::factory()->post->create( [ 'post_type' => 'blockendar_event' ] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$table,
			[
				'post_id'        => $post_id,
				'start_datetime' => '2026-01-05 10:00:00',
				'end_datetime'   => '2026-01-05 11:00:00',
				'start_date'     => '2026-01-05',
				'end_date'       => '2026-01-05',
				'type_term_ids'  => '[41,42]',
			]
		);

		Schema::maybe_upgrade();

		$names = $this->index_names( $table );
		$this->assertContains( 'idx_visible_start', $names );
		$this->assertContains( 'idx_visible_past', $names );
		$this->assertSame( '4', get_option( Schema::DB_VERSION_OPTION ) );

		// The row is untouched — this migration adds indexes only, so no reindex
		// is needed to make the data correct.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d', $table, $post_id ) );
		$this->assertSame( '2026-01-05 10:00:00', $row->start_datetime );
		$this->assertSame( '[41,42]', $row->type_term_ids );
	}

	/**
	 * dbDelta re-reads the type as the run of non-space characters after a single
	 * space, so aligned column padding made it emit ALTER TABLE ... CHANGE COLUMN
	 * on every run — rebuilding the very tables this version is meant to speed up.
	 * A run against an already-correct schema must issue no ALTER at all.
	 */
	public function test_creating_tables_against_a_current_schema_issues_no_alter(): void {
		Schema::create_tables();

		$statements = [];
		$recorder   = static function ( $query ) use ( &$statements ) {
			if ( false !== stripos( (string) $query, 'ALTER TABLE' ) ) {
				$statements[] = preg_replace( '/\s+/', ' ', trim( (string) $query ) );
			}
			return $query;
		};

		add_filter( 'query', $recorder );
		Schema::create_tables();
		remove_filter( 'query', $recorder );

		$this->assertSame(
			[],
			$statements,
			"dbDelta rewrote a schema that was already correct:\n" . implode( "\n", $statements )
		);
	}

	public function test_the_upgrade_works_on_a_non_default_table_prefix(): void {
		global $wpdb;

		// Set the property rather than calling set_prefix(): set_prefix() also
		// repoints $wpdb->options and every other core table, so update_option()
		// inside create_tables() would write to a table that does not exist.
		// Schema builds its names from $wpdb->prefix directly, which is exactly
		// the coupling under test.
		$original     = $wpdb->prefix;
		$wpdb->prefix = 'bkdr_alt_';

		try {
			$table = Schema::events_table();
			$this->assertSame( 'bkdr_alt_blockendar_events', $table );

			$this->assertTrue( Schema::create_tables(), 'create_tables() must confirm the schema under any prefix.' );

			$names = $this->index_names( $table );
			$this->assertContains( 'idx_visible_start', $names );
			$this->assertContains( 'idx_visible_past', $names );
		} finally {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( 'DROP TABLE IF EXISTS bkdr_alt_blockendar_event_type_terms' );
			$wpdb->query( 'DROP TABLE IF EXISTS bkdr_alt_blockendar_events' );
			$wpdb->query( 'DROP TABLE IF EXISTS bkdr_alt_blockendar_recurrence' );
			// phpcs:enable
			$wpdb->prefix = $original;
		}
	}

	public function test_the_version_is_not_persisted_when_a_required_index_is_missing(): void {
		global $wpdb;

		$table = Schema::events_table();

		// Put the table back to its v3 shape, then intercept the statements that
		// would add the composites so the schema stays half-applied.
		$this->drop_v4_indexes();
		update_option( Schema::DB_VERSION_OPTION, '3' );

		// Block only the ADD INDEX statements dbDelta issues for the composites.
		$blocker = static function ( $query ) {
			if ( preg_match( '/ADD (?:INDEX|KEY) `?(?:idx_visible_start|idx_visible_past)`?/i', (string) $query ) ) {
				return 'SELECT 1';
			}
			return $query;
		};

		add_filter( 'query', $blocker );
		$applied = Schema::create_tables();
		remove_filter( 'query', $blocker );

		$this->assertFalse( $applied, 'create_tables() must report failure when an index is missing.' );
		$this->assertSame(
			'3',
			get_option( Schema::DB_VERSION_OPTION ),
			'A half-applied schema must leave the version behind so the next load retries.'
		);

		// And the retry, unblocked, completes.
		$this->assertTrue( Schema::create_tables() );
		$this->assertSame( '4', get_option( Schema::DB_VERSION_OPTION ) );
		$names = $this->index_names( $table );
		$this->assertContains( 'idx_visible_past', $names );
	}
}
