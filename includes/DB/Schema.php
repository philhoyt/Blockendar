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
 *   - {prefix}blockendar_recurrence        — RRULE storage
 *   - {prefix}blockendar_event_type_terms  — junction table for event type term filtering
 */
class Schema {

	const DB_VERSION        = '2';
	const DB_VERSION_OPTION = 'blockendar_db_version';

	/**
	 * Called on plugin activation and checked on every load via maybe_upgrade().
	 */
	public static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix;

		$sql = [];

		// Event occurrence index table.
		$sql[] = "CREATE TABLE {$prefix}blockendar_events (
			id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id            BIGINT UNSIGNED NOT NULL,
			start_datetime     DATETIME        NOT NULL,
			end_datetime       DATETIME        NOT NULL,
			start_date         DATE            NOT NULL,
			end_date           DATE            NOT NULL,
			all_day            TINYINT(1)      NOT NULL DEFAULT 0,
			recurrence_id      BIGINT UNSIGNED          DEFAULT NULL,
			status             VARCHAR(20)              DEFAULT 'scheduled',
			venue_term_id      BIGINT UNSIGNED          DEFAULT NULL,
			type_term_ids      JSON                     DEFAULT NULL,
			featured           TINYINT(1)      NOT NULL DEFAULT 0,
			hide_from_listings TINYINT(1)      NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY idx_start_datetime     (start_datetime),
			KEY idx_end_datetime       (end_datetime),
			KEY idx_start_date         (start_date),
			KEY idx_post_id            (post_id),
			KEY idx_venue              (venue_term_id),
			KEY idx_status             (status),
			KEY idx_featured           (featured),
			KEY idx_hide_from_listings (hide_from_listings)
		) ENGINE=InnoDB $charset_collate;";

		// Junction table: event index rows ↔ event type terms.
		$sql[] = "CREATE TABLE {$prefix}blockendar_event_type_terms (
			id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_index_id BIGINT UNSIGNED NOT NULL,
			type_term_id   BIGINT UNSIGNED NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_event_index_id (event_index_id),
			KEY idx_type_term_id   (type_term_id)
		) ENGINE=InnoDB $charset_collate;";

		// Recurrence rules table.
		$sql[] = "CREATE TABLE {$prefix}blockendar_recurrence (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id      BIGINT UNSIGNED NOT NULL,
			frequency    VARCHAR(10)     NOT NULL,
			interval_val SMALLINT        NOT NULL DEFAULT 1,
			byday        VARCHAR(50)              DEFAULT NULL,
			bymonthday   VARCHAR(100)             DEFAULT NULL,
			bysetpos     VARCHAR(50)              DEFAULT NULL,
			until_date   DATE                     DEFAULT NULL,
			count        SMALLINT                 DEFAULT NULL,
			exceptions   JSON                     DEFAULT NULL,
			additions    JSON                     DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idx_post_id (post_id)
		) ENGINE=InnoDB $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
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
			self::create_tables();

			// On upgrades (not fresh installs) schedule a background rebuild so
			// the new columns and junction table are populated without blocking
			// the current request. The cron event fires once, then clears itself.
			if ( $is_upgrade ) {
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

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
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
