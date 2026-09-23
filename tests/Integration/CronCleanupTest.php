<?php
/**
 * Integration coverage for scheduled events being cleaned up.
 *
 * Deactivation used to clear only the daily horizon roll, leaving a one-shot
 * post-upgrade rebuild and (under the 'cron' generation strategy) any deferred
 * per-post builds in the cron array, pointing at callbacks that no longer
 * exist once the plugin is gone.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\DB\IndexBuilder;
use Blockendar\Recurrence\Cron;
use WP_UnitTestCase;

class CronCleanupTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		// Start from a known-empty cron array. Earlier tests in the suite save
		// events, and IndexBuilder queues a deferred build for each one under
		// the 'cron' strategy, so without this the preconditions below could be
		// satisfied by somebody else's leftovers.
		Cron::unschedule();
	}

	public function tear_down(): void {
		Cron::unschedule();
		parent::tear_down();
	}

	public function test_unschedule_clears_every_hook_the_plugin_queues(): void {
		wp_schedule_event( time(), 'daily', Cron::HOOK );
		wp_schedule_single_event( time() + 60, 'blockendar_index_rebuild_after_upgrade' );
		wp_schedule_single_event( time() + 60, IndexBuilder::DEFERRED_HOOK, [ 123 ] );
		wp_schedule_single_event( time() + 90, IndexBuilder::DEFERRED_HOOK, [ 456 ] );

		// Precondition: all four really are queued, or the assertions below
		// would pass against a cron array that was empty to begin with.
		$this->assertIsInt( wp_next_scheduled( Cron::HOOK ) );
		$this->assertIsInt( wp_next_scheduled( 'blockendar_index_rebuild_after_upgrade' ) );
		$this->assertIsInt( wp_next_scheduled( IndexBuilder::DEFERRED_HOOK, [ 123 ] ) );
		$this->assertIsInt( wp_next_scheduled( IndexBuilder::DEFERRED_HOOK, [ 456 ] ) );

		Cron::unschedule();

		$this->assertFalse( wp_next_scheduled( Cron::HOOK ) );
		$this->assertFalse( wp_next_scheduled( 'blockendar_index_rebuild_after_upgrade' ) );

		/*
		 * Deferred builds carry a post ID, so clearing by hook name alone
		 * would leave them behind — every argument variant must go.
		 */
		$this->assertFalse( wp_next_scheduled( IndexBuilder::DEFERRED_HOOK, [ 123 ] ) );
		$this->assertFalse( wp_next_scheduled( IndexBuilder::DEFERRED_HOOK, [ 456 ] ) );
	}
}
