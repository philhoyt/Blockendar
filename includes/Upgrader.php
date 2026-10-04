<?php
/**
 * One-time work that has to run after the plugin's files change.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Blockendar\Admin\SettingsPage;
use Blockendar\DB\EventIndex;
use Blockendar\DB\IndexBuilder;
use Blockendar\Recurrence\RuleRepository;

/**
 * Detects a version change and refreshes what activation would have.
 *
 * Activation flushes rewrite rules, but an update through the update checker
 * never re-activates, so a release that adds or changes a rewrite rule left
 * `/events/type/...` and `/events/venue/...` pointing at stale rules until
 * someone flushed by hand. The stored version is compared on every load; the
 * flush only happens when it differs, never on an ordinary request.
 */
class Upgrader {

	const VERSION_OPTION = 'blockendar_version';

	/**
	 * The version the site ran before the one it runs now. Kept for support:
	 * it says which upgrade a site last went through.
	 */
	const PREVIOUS_VERSION_OPTION = 'blockendar_previous_version';

	/**
	 * Releases that changed how index rows are built from an event.
	 *
	 * Rows written by an earlier version are wrong under these, so an upgrade
	 * that crosses one rebuilds the index. Every other release leaves the rows
	 * as they are. A release that changes the table itself is not listed:
	 * Schema::maybe_upgrade() queues the rebuild for those.
	 *
	 * 2.1.0 — a recurring all-day event's end_date became its last day rather
	 *         than the day after, and UTC-offset timezones started to index.
	 */
	const REBUILD_VERSIONS = [ '2.1.0' ];

	/**
	 * Register hooks.
	 */
	public function register(): void {
		// After the post type (init 10) and the taxonomies' explicit rules
		// (init 20) exist, so the flush has something to write.
		add_action( 'init', [ $this, 'maybe_upgrade' ], 30 );

		// The events slug is the rewrite base; a change needs fresh rules. The
		// option is added rather than updated the first time it is saved (and
		// whenever WordPress finds only the registered default), so hook both.
		add_action( 'update_option_' . SettingsPage::OPTION_NAME, [ $this, 'on_settings_saved' ], 10, 2 );
		add_action( 'add_option_' . SettingsPage::OPTION_NAME, [ $this, 'on_settings_added' ], 10, 2 );
	}

	/**
	 * Flush rewrite rules once per plugin version, and queue an index rebuild
	 * when the upgrade calls for one.
	 *
	 * Schema changes are handled separately by Schema::maybe_upgrade(), which
	 * runs on plugins_loaded with its own version option. The rebuild here
	 * covers releases that change how rows are built without changing the
	 * schema; it is skipped on a fresh install, where there is nothing to
	 * rebuild.
	 */
	public function maybe_upgrade(): void {
		$previous = get_option( self::VERSION_OPTION );

		if ( BLOCKENDAR_VERSION === $previous ) {
			return;
		}

		flush_rewrite_rules( false );

		// Rules whose event was deleted before deletion removed them.
		( new RuleRepository() )->delete_orphans();

		// No stored version on a site that has rows: it predates this class,
		// so it predates everything in the list as well.
		$from = is_string( $previous ) && '' !== $previous ? $previous : '0';

		if ( self::crosses_rebuild_version( $from, BLOCKENDAR_VERSION ) && ( new EventIndex() )->get_total_row_count() > 0 ) {
			( new IndexBuilder() )->queue_full_rebuild();
		}

		if ( '0' !== $from ) {
			update_option( self::PREVIOUS_VERSION_OPTION, $from, false );
		}

		update_option( self::VERSION_OPTION, BLOCKENDAR_VERSION );
	}

	/**
	 * Whether an upgrade passes a release that changed how rows are built.
	 *
	 * @param string $from Version upgraded from.
	 * @param string $to   Version upgraded to.
	 */
	public static function crosses_rebuild_version( string $from, string $to ): bool {
		foreach ( self::REBUILD_VERSIONS as $version ) {
			if ( version_compare( $from, $version, '<' ) && version_compare( $to, $version, '>=' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Refresh rewrite rules when the events slug changes.
	 *
	 * @param mixed $old_value Previous settings array.
	 * @param mixed $new_value New settings array.
	 */
	public function on_settings_saved( mixed $old_value, mixed $new_value ): void {
		if ( $this->slug_of( $old_value ) !== $this->slug_of( $new_value ) ) {
			$this->reset_rewrite_rules();
		}
	}

	/**
	 * Refresh rewrite rules when the settings are first stored with a
	 * non-default slug.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  New settings array.
	 */
	public function on_settings_added( string $option, mixed $value ): void {
		if ( $this->slug_of( $value ) !== $this->slug_of( SettingsPage::defaults() ) ) {
			$this->reset_rewrite_rules();
		}
	}

	/**
	 * Drop the stored rules so the next request regenerates them.
	 *
	 * Flushing here would be wrong: the post type and taxonomies registered
	 * earlier in this request with the old slug, so a flush now would write
	 * the old rules back. WordPress rebuilds the option lazily on the next
	 * request, after registration has picked up the new slug.
	 */
	private function reset_rewrite_rules(): void {
		delete_option( 'rewrite_rules' );
	}

	/**
	 * Read the events slug out of a settings value.
	 *
	 * @param mixed $settings Settings array (or anything else).
	 */
	private function slug_of( mixed $settings ): string {
		return is_array( $settings ) ? (string) ( $settings['events_slug'] ?? '' ) : '';
	}
}
