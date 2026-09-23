<?php
/**
 * Integration coverage for the Recurring Events settings actually taking effect.
 *
 * The horizon and instance-cap settings were stored inside the
 * blockendar_settings array but read back as standalone options
 * ('blockendar_horizon_days') that nothing ever wrote, so generation always
 * used the compiled-in defaults and the admin controls did nothing.
 *
 * The unit tests could not catch it: they stub get_option() with a blanket
 * return, so they pass whether the code reads the right key or a name that
 * does not exist. Anything that depends on what is actually stored in the
 * database belongs here instead.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\Admin\SettingsPage;
use Blockendar\DB\EventIndex;
use Blockendar\DB\Schema;
use Blockendar\Recurrence\Generator;
use Blockendar\Recurrence\RuleRepository;
use WP_UnitTestCase;

class RecurrenceHorizonTest extends WP_UnitTestCase {

	private EventIndex $index;
	private RuleRepository $repo;

	public function set_up(): void {
		parent::set_up();

		Schema::create_tables();

		$this->index = new EventIndex();
		$this->repo  = new RuleRepository();

		delete_option( SettingsPage::OPTION_NAME );
		$this->index->flush_cache();
	}

	public function tear_down(): void {
		delete_option( SettingsPage::OPTION_NAME );
		parent::tear_down();
	}

	/**
	 * Create a daily-recurring event starting tomorrow.
	 *
	 * @return int Post ID.
	 */
	private function make_daily_event(): int {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'publish',
				'post_title'  => 'Daily standup',
			]
		);

		$start = gmdate( 'Y-m-d', strtotime( '+1 day' ) );

		update_post_meta( $post_id, 'blockendar_start_date', $start );
		update_post_meta( $post_id, 'blockendar_end_date', $start );
		update_post_meta( $post_id, 'blockendar_start_time', '09:00' );
		update_post_meta( $post_id, 'blockendar_end_time', '10:00' );

		$this->repo->upsert(
			$post_id,
			[
				'frequency' => 'daily',
				'interval'  => 1,
			]
		);

		return $post_id;
	}

	/**
	 * Generate and report how many index rows the event ended up with.
	 *
	 * @param int $post_id Event post ID.
	 */
	private function generate( int $post_id ): int {
		( new Generator() )->generate_for_post( $post_id );
		$this->index->flush_cache();

		return count( $this->index->get_by_post_id( $post_id ) );
	}

	/**
	 * A short horizon must produce fewer rows than a long one. If the setting
	 * is ignored, both runs generate the same number and this fails.
	 */
	public function test_horizon_days_setting_limits_generation(): void {
		$post_id = $this->make_daily_event();

		update_option( SettingsPage::OPTION_NAME, [ 'horizon_days' => 30 ] );
		$short = $this->generate( $post_id );

		update_option( SettingsPage::OPTION_NAME, [ 'horizon_days' => 200 ] );
		$long = $this->generate( $post_id );

		$this->assertGreaterThan(
			$short,
			$long,
			'Raising horizon_days must generate more occurrences — if it does not, the setting is not being read.'
		);

		// A daily rule over a 30-day horizon cannot reach a year's worth of rows.
		$this->assertLessThan( 60, $short, 'A 30-day horizon should produce roughly a month of daily occurrences.' );
	}

	/**
	 * The instance cap is the other half of the same setting group.
	 */
	public function test_max_instances_setting_caps_generation(): void {
		$post_id = $this->make_daily_event();

		update_option(
			SettingsPage::OPTION_NAME,
			[
				'horizon_days'  => 365,
				'max_instances' => 10,
			]
		);

		$this->assertLessThanOrEqual(
			10,
			$this->generate( $post_id ),
			'max_instances must cap the number of generated occurrences.'
		);
	}

	/**
	 * The accessor must not be fooled by a standalone option of a similar name,
	 * which is the shape of the original bug.
	 */
	public function test_setting_is_read_from_the_settings_array_not_a_standalone_option(): void {
		update_option( 'blockendar_horizon_days', 9999 );
		update_option( SettingsPage::OPTION_NAME, [ 'horizon_days' => 45 ] );

		$this->assertSame( 45, (int) SettingsPage::get( 'horizon_days' ) );

		delete_option( 'blockendar_horizon_days' );
	}

	/**
	 * An unset key falls back to its documented default rather than to 0.
	 */
	public function test_unset_setting_falls_back_to_its_default(): void {
		update_option( SettingsPage::OPTION_NAME, [ 'default_currency' => 'EUR' ] );

		$this->assertSame( 365, (int) SettingsPage::get( 'horizon_days' ) );
		$this->assertNull( SettingsPage::get( 'no_such_setting' ) );
	}
}
