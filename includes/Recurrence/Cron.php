<?php
/**
 * WP-Cron job for rolling the recurrence horizon forward daily.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\Recurrence;

/**
 * Schedules and handles the daily horizon-rolling cron event.
 *
 * The cron job calls Generator::roll_horizon() once per day.
 * This generates new recurrence instances as they enter the lookahead window,
 * keeping the blockendar_events index populated for future dates.
 */
class Cron {

	const HOOK = 'blockendar_daily_recurrence_roll';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'schedule' ] );
		add_action( self::HOOK, [ $this, 'run' ] );
	}

	/**
	 * Schedule the daily cron event if it is not already scheduled.
	 */
	public function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::HOOK );
		}
	}

	/**
	 * Cron callback — rolls the recurrence horizon forward.
	 */
	public function run(): void {
		( new Generator() )->roll_horizon();
	}

	/**
	 * Unschedule the cron event on plugin deactivation.
	 */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}
}
