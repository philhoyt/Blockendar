<?php
/**
 * Integration coverage for what happens to a repeat rule around its event.
 *
 * The rule is a row in the plugin's own table, keyed by post ID. Nothing ties
 * it to the post, so it has to be removed when the event is, and a partial
 * write must not blank the parts it did not mention.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\DB\EventIndex;
use Blockendar\DB\IndexBuilder;
use Blockendar\DB\Schema;
use Blockendar\Recurrence\RuleRepository;
use Blockendar\Upgrader;
use WP_REST_Request;
use WP_UnitTestCase;

class RecurrenceRuleLifecycleTest extends WP_UnitTestCase {

	private RuleRepository $rules;

	public function set_up(): void {
		parent::set_up();

		Schema::create_tables();
		( new EventIndex() )->flush_cache();

		// A scheduled rebuild from an earlier test would satisfy assertions here.
		_set_cron_array( [] );

		$this->rules = new RuleRepository();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		delete_option( Upgrader::VERSION_OPTION );
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Fixtures
	// -------------------------------------------------------------------------

	/**
	 * Create and index a weekly event with four occurrences.
	 *
	 * @return int Post ID.
	 */
	private function make_weekly_event(): int {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'publish',
				'meta_input'  => [
					'blockendar_start_date' => '2027-03-09',
					'blockendar_end_date'   => '2027-03-09',
					'blockendar_start_time' => '19:00',
					'blockendar_end_time'   => '21:00',
					'blockendar_timezone'   => 'UTC',
				],
			]
		);

		$this->rules->upsert(
			$post_id,
			[
				'frequency' => 'weekly',
				'interval'  => 1,
				'count'     => 4,
			]
		);

		( new IndexBuilder() )->build_for_post( $post_id );

		return $post_id;
	}

	/**
	 * How many rule rows the table holds.
	 */
	private function rule_rows(): int {
		global $wpdb;

		$table = Schema::recurrence_table();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB
	}

	// -------------------------------------------------------------------------
	// Deleting the event
	// -------------------------------------------------------------------------

	/**
	 * The index rows went with the post; the rule stayed. Rows for posts that
	 * no longer exist piled up in the table.
	 */
	public function test_deleting_an_event_deletes_its_rule(): void {
		$post_id = $this->make_weekly_event();

		$this->assertNotNull( $this->rules->get( $post_id ), 'Precondition: the event has a rule.' );

		wp_delete_post( $post_id, true );

		$this->assertNull( $this->rules->get( $post_id ) );
	}

	/**
	 * The trash is not deletion: an event restored from it has to come back as
	 * the series it was.
	 */
	public function test_trashing_an_event_keeps_its_rule(): void {
		$post_id = $this->make_weekly_event();

		wp_trash_post( $post_id );

		$this->assertSame( 'weekly', $this->rules->get( $post_id )->frequency );
	}

	public function test_deleting_another_post_type_touches_no_rule(): void {
		$event_id = $this->make_weekly_event();
		$page_id  = self::factory()->post->create( [ 'post_type' => 'page' ] );

		wp_delete_post( $page_id, true );

		$this->assertNotNull( $this->rules->get( $event_id ) );
	}

	/**
	 * Rows already orphaned by earlier versions are cleared when the plugin
	 * next upgrades.
	 */
	public function test_an_upgrade_removes_rules_whose_event_is_gone(): void {
		global $wpdb;

		$kept_id = $this->make_weekly_event();
		$before  = $this->rule_rows();

		// phpcs:ignore WordPress.DB
		$wpdb->insert(
			Schema::recurrence_table(),
			[
				'post_id'      => 999999,
				'frequency'    => 'daily',
				'interval_val' => 1,
			]
		);

		$this->assertSame( $before + 1, $this->rule_rows(), 'Precondition: an orphaned rule exists.' );

		update_option( Upgrader::VERSION_OPTION, '0.0.1' );
		( new Upgrader() )->maybe_upgrade();

		$this->assertSame( $before, $this->rule_rows() );
		$this->assertNotNull( $this->rules->get( $kept_id ), 'A rule whose event exists is left alone.' );
	}

	// -------------------------------------------------------------------------
	// Writing part of a rule
	// -------------------------------------------------------------------------

	/**
	 * upsert() wrote every column from what it was given, so a caller that sent
	 * only the frequency blanked the skipped and added dates. The recurrence
	 * route is such a caller.
	 */
	public function test_updating_a_rule_keeps_what_the_update_does_not_mention(): void {
		$post_id = $this->make_weekly_event();

		$this->rules->add_exception( $post_id, '2027-03-16' );
		$this->rules->add_extra_date( $post_id, '2027-05-01' );

		$this->rules->upsert(
			$post_id,
			[
				'frequency' => 'weekly',
				'interval'  => 1,
				'count'     => 6,
			]
		);

		$rule = $this->rules->get( $post_id );

		$this->assertSame( 6, $rule->count );
		$this->assertSame( [ '2027-03-16' ], $rule->exceptions );
		$this->assertSame( [ '2027-05-01' ], $rule->additions );
	}

	public function test_an_update_can_still_clear_them_by_saying_so(): void {
		$post_id = $this->make_weekly_event();

		$this->rules->add_exception( $post_id, '2027-03-16' );

		$this->rules->upsert(
			$post_id,
			[
				'frequency'  => 'weekly',
				'exceptions' => [],
			]
		);

		$this->assertSame( [], $this->rules->get( $post_id )->exceptions );
	}

	public function test_the_recurrence_route_keeps_skipped_and_added_dates(): void {
		$post_id = $this->make_weekly_event();

		$this->rules->add_exception( $post_id, '2027-03-16' );
		$this->rules->add_extra_date( $post_id, '2027-05-01' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		do_action( 'rest_api_init' );

		$request = new WP_REST_Request( 'POST', "/blockendar/v1/events/{$post_id}/recurrence" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( [ 'frequency' => 'daily' ] ) );

		$response = rest_get_server()->dispatch( $request );
		$rule     = $this->rules->get( $post_id );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'daily', $rule->frequency );
		$this->assertSame( [ '2027-03-16' ], $rule->exceptions );
		$this->assertSame( [ '2027-05-01' ], $rule->additions );
	}
}
