<?php
/**
 * Integration coverage for the admin settings actually reaching the code that
 * should obey them.
 *
 * The audit found eight settings that were stored but read by nothing, so the
 * controls appeared to work and silently did not. These tests assert the wiring
 * end to end — change the setting, observe the behaviour change — rather than
 * asserting that a getter returns what was just written, which would pass even
 * if no consumer existed.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\Admin\SettingsPage;
use Blockendar\DB\IndexBuilder;
use Blockendar\DB\Schema;
use WP_UnitTestCase;

class SettingsAreHonouredTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		delete_option( SettingsPage::OPTION_NAME );
	}

	public function tear_down(): void {
		delete_option( SettingsPage::OPTION_NAME );
		parent::tear_down();
	}

	/**
	 * Render a block and return its markup.
	 *
	 * @param string $name  Block name.
	 * @param array  $attrs Block attributes.
	 */
	private function render( string $name, array $attrs = [] ): string {
		return (string) render_block(
			[
				'blockName'    => $name,
				'attrs'        => $attrs,
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	// -------------------------------------------------------------------------
	// calendar_* — calendar-view
	// -------------------------------------------------------------------------

	public function test_calendar_defaults_come_from_the_settings(): void {
		update_option(
			SettingsPage::OPTION_NAME,
			[
				'calendar_default_view'  => 'timeGridWeek',
				'calendar_first_day'     => 1,
				'calendar_slot_duration' => '00:15:00',
			]
		);

		$html = $this->render( 'blockendar/calendar-view' );

		$this->assertStringContainsString( 'data-default-view="timeGridWeek"', $html );
		$this->assertStringContainsString( 'data-first-day="1"', $html );
		$this->assertStringContainsString( 'data-slot-duration="00:15:00"', $html );
	}

	/**
	 * A block that sets the attribute keeps its own choice — the setting is the
	 * default, not an override.
	 */
	public function test_a_block_attribute_still_overrides_the_site_default(): void {
		update_option(
			SettingsPage::OPTION_NAME,
			[
				'calendar_default_view' => 'timeGridWeek',
				'calendar_first_day'    => 1,
			]
		);

		$html = $this->render(
			'blockendar/calendar-view',
			[
				'defaultView' => 'dayGridMonth',
				'firstDay'    => 6,
			]
		);

		$this->assertStringContainsString( 'data-default-view="dayGridMonth"', $html );
		$this->assertStringContainsString( 'data-first-day="6"', $html );
	}

	// -------------------------------------------------------------------------
	// timezone_mode — event-datetime
	// -------------------------------------------------------------------------

	/**
	 * Build an event whose own timezone differs from the site's.
	 */
	private function make_event_in_tokyo(): int {
		update_option( 'timezone_string', 'America/New_York' );

		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'publish',
				'post_title'  => 'Tokyo event',
			]
		);

		update_post_meta( $post_id, 'blockendar_start_date', '2026-10-01' );
		update_post_meta( $post_id, 'blockendar_end_date', '2026-10-01' );
		update_post_meta( $post_id, 'blockendar_start_time', '09:00' );
		update_post_meta( $post_id, 'blockendar_end_time', '10:00' );
		update_post_meta( $post_id, 'blockendar_timezone', 'Asia/Tokyo' );

		return $post_id;
	}

	public function test_timezone_mode_event_shows_the_authored_time(): void {
		$post_id = $this->make_event_in_tokyo();
		update_option( SettingsPage::OPTION_NAME, [ 'timezone_mode' => 'event' ] );

		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		setup_postdata( $GLOBALS['post'] );

		$html = $this->render( 'blockendar/event-datetime', [ 'showStartTime' => true ] );

		wp_reset_postdata();

		$this->assertStringContainsString( '9:00', $html, 'Event mode must show the time as authored in Tokyo.' );
	}

	public function test_timezone_mode_site_converts_into_the_site_timezone(): void {
		$post_id = $this->make_event_in_tokyo();
		update_option( SettingsPage::OPTION_NAME, [ 'timezone_mode' => 'site' ] );

		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		setup_postdata( $GLOBALS['post'] );

		$html = $this->render( 'blockendar/event-datetime', [ 'showStartTime' => true ] );

		wp_reset_postdata();

		/*
		 * 09:00 Tokyo on 2026-10-01 is 20:00 on 2026-09-30 in New York, so both
		 * the clock time and the calendar date move.
		 *
		 * The date is the discriminator here, not the time: the event's 10:00
		 * end converts to 21:00, which also renders as "9:00", so asserting the
		 * absence of "9:00" would fail against correct output.
		 */
		$this->assertStringContainsString( '8:00', $html, 'Site mode must convert the clock time.' );
		$this->assertStringContainsString( 'September 30', $html, 'The date moves back a day with the conversion.' );
		$this->assertStringNotContainsString(
			'October 1',
			$html,
			'The authored Tokyo date must not survive conversion into the site timezone.'
		);
	}

	// -------------------------------------------------------------------------
	// generation_strategy — IndexBuilder
	// -------------------------------------------------------------------------

	/**
	 * Create a published event with dates but no recurrence.
	 */
	private function make_simple_event(): int {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'publish',
			]
		);

		update_post_meta( $post_id, 'blockendar_start_date', '2026-11-05' );
		update_post_meta( $post_id, 'blockendar_end_date', '2026-11-05' );

		return $post_id;
	}

	public function test_on_save_strategy_indexes_immediately(): void {
		update_option( SettingsPage::OPTION_NAME, [ 'generation_strategy' => 'on_save' ] );

		$post_id = $this->make_simple_event();
		$builder = new IndexBuilder();
		$builder->on_save( $post_id, get_post( $post_id ) );

		$this->assertNotEmpty(
			( new \Blockendar\DB\EventIndex() )->get_by_post_id( $post_id ),
			'on_save must write index rows in the same request.'
		);
		$this->assertFalse( wp_next_scheduled( IndexBuilder::DEFERRED_HOOK, [ $post_id ] ) );
	}

	public function test_cron_strategy_defers_the_rebuild(): void {
		update_option( SettingsPage::OPTION_NAME, [ 'generation_strategy' => 'cron' ] );

		$post_id = $this->make_simple_event();

		// Clear anything the factory's own save already wrote.
		( new \Blockendar\DB\EventIndex() )->delete_by_post_id( $post_id );

		$builder = new IndexBuilder();
		$builder->on_save( $post_id, get_post( $post_id ) );

		$this->assertIsInt(
			wp_next_scheduled( IndexBuilder::DEFERRED_HOOK, [ $post_id ] ),
			'cron strategy must queue a deferred build for this event.'
		);
		$this->assertEmpty(
			( new \Blockendar\DB\EventIndex() )->get_by_post_id( $post_id ),
			'cron strategy must not index in the save request.'
		);

		// And running the queued job does the work.
		$builder->build_for_post( $post_id );

		$this->assertNotEmpty( ( new \Blockendar\DB\EventIndex() )->get_by_post_id( $post_id ) );

		wp_clear_scheduled_hook( IndexBuilder::DEFERRED_HOOK, [ $post_id ] );
	}

	public function test_repeated_saves_collapse_into_one_pending_job(): void {
		update_option( SettingsPage::OPTION_NAME, [ 'generation_strategy' => 'cron' ] );

		$post_id = $this->make_simple_event();
		$builder = new IndexBuilder();

		$builder->on_save( $post_id, get_post( $post_id ) );
		$first = wp_next_scheduled( IndexBuilder::DEFERRED_HOOK, [ $post_id ] );

		$builder->on_save( $post_id, get_post( $post_id ) );
		$second = wp_next_scheduled( IndexBuilder::DEFERRED_HOOK, [ $post_id ] );

		$this->assertSame( $first, $second, 'A second save must not queue a duplicate job.' );

		wp_clear_scheduled_hook( IndexBuilder::DEFERRED_HOOK, [ $post_id ] );
	}
}
