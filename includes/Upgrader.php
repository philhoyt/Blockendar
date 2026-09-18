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
	 * Flush rewrite rules and queue an index rebuild once per plugin version.
	 *
	 * Schema changes are handled separately by Schema::maybe_upgrade(), which
	 * runs on plugins_loaded with its own version option. The rebuild here
	 * covers releases that change how rows are built without changing the
	 * schema; it is skipped on a fresh install, where there is nothing to
	 * rebuild. wp_schedule_single_event() ignores a duplicate of an event the
	 * schema upgrade already queued.
	 */
	public function maybe_upgrade(): void {
		if ( get_option( self::VERSION_OPTION ) === BLOCKENDAR_VERSION ) {
			return;
		}

		flush_rewrite_rules( false );

		if ( ( new EventIndex() )->get_total_row_count() > 0 ) {
			wp_schedule_single_event( time(), 'blockendar_index_rebuild_after_upgrade' );
		}

		update_option( self::VERSION_OPTION, BLOCKENDAR_VERSION );
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
