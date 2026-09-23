<?php
/**
 * Database schema creation and upgrades.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\DB;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the three custom tables:
 *   - {prefix}blockendar_events            — denormalised occurrence index
 *                                            (v3 adds the `ongoing` flag column;
 *                                            v4 adds the idx_visible_start and
 *                                            idx_past composite indexes)
 *   - {prefix}blockendar_recurrence        — RRULE storage
 *   - {prefix}blockendar_event_type_terms  — junction table for event type term filtering
 *
 * Column definitions below are written the way dbDelta() needs them, which is not
 * the way that reads best. dbDelta parses each column's type with the regex
 * `|`?field`? ([^ ]*( unsigned)?)|` — it takes the type as the run of non-space
 * characters following a SINGLE space after the column name. Padding the types
 * into aligned columns makes that capture an empty string, so dbDelta decides the
 * type has changed and emits `ALTER TABLE ... CHANGE COLUMN` on every single run,
 * for every padded column. That is why these are single-spaced and lowercase, and
 * why they must stay that way.
 *
 * For the same reason the three JSON columns are declared `longtext`. MariaDB
 * implements JSON as an alias for longtext and reports it as `longtext`, so a
 * column declared `json` never matches what the server reports back and rebuilds
 * forever. Nothing queries these columns with SQL JSON functions — they are
 * written with wp_json_encode() and read with json_decode() in PHP — so longtext
 * is the honest declaration as well as the stable one.
 */
class Schema {

	const DB_VERSION        = '4';
	const DB_VERSION_OPTION = 'blockendar_db_version';

	/**
	 * Indexes introduced in DB_VERSION 4, checked after dbDelta() before the
	 * version option is persisted. See create_tables().
	 */
	private const REQUIRED_EVENT_INDEXES = [ 'idx_visible_start', 'idx_past' ];

	/**
	 * Called on plugin activation and checked on every load via maybe_upgrade().
	 *
	 * @return bool True when the schema is confirmed current and the version was
	 *              persisted; false when a required index is missing, in which
	 *              case the version is left behind so the next load retries.
	 */
	public static function create_tables(): bool {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix;

		$sql = [];

		// Event occurrence index table.
		$sql[] = "CREATE TABLE {$prefix}blockendar_events (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			start_datetime datetime NOT NULL,
			end_datetime datetime NOT NULL,
			start_date date NOT NULL,
			end_date date NOT NULL,
			all_day tinyint(1) NOT NULL DEFAULT 0,
			recurrence_id bigint(20) unsigned DEFAULT NULL,
			status varchar(20) DEFAULT 'scheduled',
			venue_term_id bigint(20) unsigned DEFAULT NULL,
			type_term_ids longtext DEFAULT NULL,
			featured tinyint(1) NOT NULL DEFAULT 0,
			hide_from_listings tinyint(1) NOT NULL DEFAULT 0,
			ongoing tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY idx_start_datetime (start_datetime),
			KEY idx_end_datetime (end_datetime),
			KEY idx_start_date (start_date),
			KEY idx_post_id (post_id),
			KEY idx_venue (venue_term_id),
			KEY idx_status (status),
			KEY idx_featured (featured),
			KEY idx_hide_from_listings (hide_from_listings),
			KEY idx_ongoing (ongoing),
			KEY idx_visible_start (hide_from_listings,start_datetime),
			KEY idx_past (ongoing,end_datetime,start_datetime)
		) ENGINE=InnoDB $charset_collate;";

		// Junction table: event index rows ↔ event type terms.
		$sql[] = "CREATE TABLE {$prefix}blockendar_event_type_terms (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_index_id bigint(20) unsigned NOT NULL,
			type_term_id bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_event_index_id (event_index_id),
			KEY idx_type_term_id (type_term_id)
		) ENGINE=InnoDB $charset_collate;";

		// Recurrence rules table.
		$sql[] = "CREATE TABLE {$prefix}blockendar_recurrence (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			frequency varchar(10) NOT NULL,
			interval_val smallint(6) NOT NULL DEFAULT 1,
			byday varchar(50) DEFAULT NULL,
			bymonthday varchar(100) DEFAULT NULL,
			bysetpos varchar(50) DEFAULT NULL,
			until_date date DEFAULT NULL,
			count smallint(6) DEFAULT NULL,
			exceptions longtext DEFAULT NULL,
			additions longtext DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idx_post_id (post_id)
		) ENGINE=InnoDB $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		// Persist the version only once the indexes this version is defined by are
		// actually on the table. dbDelta() reports what it attempted, not what
		// succeeded, and ADD INDEX on a large table can fail for disk or lock
		// reasons. Recording version 4 after a partial apply would mean never
		// retrying, leaving the site permanently on a half-built schema.
		if ( ! self::has_required_indexes() ) {
			return false;
		}

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );

		return true;
	}

	/**
	 * Whether every index this DB_VERSION requires is present on the events table.
	 */
	private static function has_required_indexes(): bool {
		global $wpdb;

		$events_table = self::events_table();

		// Table names cannot be prepare() placeholders, and $wpdb->prefix is
		// server-controlled, not user input.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$present = $wpdb->get_col( "SHOW INDEX FROM {$events_table}", 2 );

		if ( ! is_array( $present ) ) {
			return false;
		}

		foreach ( self::REQUIRED_EVENT_INDEXES as $index ) {
			if ( ! in_array( $index, $present, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Run on every plugin load. Triggers a schema upgrade when the stored
	 * version is behind the current constant.
	 *
	 * NOTE: must be called after the recurrence Generator has registered its
	 * hooks (so that recurring events are correctly indexed during any rebuild).
	 */
	public static function maybe_upgrade(): void {
		$stored = get_option( self::DB_VERSION_OPTION );

		if ( $stored !== self::DB_VERSION ) {
			$is_upgrade = ( false !== $stored && '' !== $stored );
			$applied    = self::create_tables();

			// On upgrades (not fresh installs) schedule a background rebuild so
			// the new columns and junction table are populated without blocking
			// the current request. The cron event fires once, then clears itself.
			//
			// Skipped when create_tables() could not confirm the schema: the
			// version option is still behind, so the next load retries the
			// upgrade, and there is no point reindexing into a table that is
			// not yet shaped correctly.
			if ( $is_upgrade && $applied ) {
				wp_schedule_single_event( time(), 'blockendar_index_rebuild_after_upgrade' );
			}
		}
	}

	/**
	 * Drop all custom tables. Only called from uninstall.php.
	 */
	public static function drop_tables(): void {
		global $wpdb;

		$prefix = $wpdb->prefix;

		// Table names cannot be passed as prepare() placeholders, and $wpdb->prefix
		// is server-controlled, not user input.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$prefix}blockendar_event_type_terms" );
		$wpdb->query( "DROP TABLE IF EXISTS {$prefix}blockendar_events" );
		$wpdb->query( "DROP TABLE IF EXISTS {$prefix}blockendar_recurrence" );
		// phpcs:enable

		delete_option( self::DB_VERSION_OPTION );
	}

	/**
	 * Return the full table name for blockendar_events.
	 */
	public static function events_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'blockendar_events';
	}

	/**
	 * Return the full table name for blockendar_recurrence.
	 */
	public static function recurrence_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'blockendar_recurrence';
	}

	/**
	 * Return the full table name for blockendar_event_type_terms.
	 */
	public static function type_terms_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'blockendar_event_type_terms';
	}
}
