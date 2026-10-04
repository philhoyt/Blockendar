<?php
/**
 * Integration coverage for the repeat rule as a REST field of the event.
 *
 * The editor saves an event with one request to wp/v2. The rule travels in
 * that request, so it is stored if and only if the event is, and it is in
 * place before the index is rebuilt.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\DB\EventIndex;
use Blockendar\DB\IndexBuilder;
use Blockendar\DB\Schema;
use Blockendar\Meta\EventMeta;
use Blockendar\Recurrence\RuleRepository;
use WP_REST_Request;
use WP_UnitTestCase;

class RecurrenceFieldTest extends WP_UnitTestCase {

	private RuleRepository $rules;

	private int $post_id;

	public function set_up(): void {
		parent::set_up();

		Schema::create_tables();
		( new EventIndex() )->flush_cache();

		// WP_UnitTestCase unregisters every meta key in tear_down().
		( new EventMeta() )->register_meta();

		$this->rules = new RuleRepository();

		$this->post_id = self::factory()->post->create(
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

		( new IndexBuilder() )->build_for_post( $this->post_id );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Save the event through the route the block editor uses.
	 *
	 * @param array $body Request body.
	 */
	private function save( array $body ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', "/wp/v2/blockendar-events/{$this->post_id}" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Read the event through the same route.
	 *
	 * @param string $context 'view' or 'edit'.
	 */
	private function read( string $context ): array {
		$request = new WP_REST_Request( 'GET', "/wp/v2/blockendar-events/{$this->post_id}" );
		$request->set_param( 'context', $context );

		return rest_get_server()->dispatch( $request )->get_data();
	}

	/**
	 * How many occurrences the event has in the index.
	 */
	private function occurrences(): int {
		( new EventIndex() )->flush_cache();

		return count( ( new EventIndex() )->get_by_post_id( $this->post_id ) );
	}

	/**
	 * A weekly rule that stops after three occurrences, as the editor sends it.
	 */
	private function weekly(): array {
		return [
			'frequency'    => 'weekly',
			'interval_val' => 1,
			'byday'        => 'TU',
			'bymonthday'   => null,
			'bysetpos'     => null,
			'until_date'   => null,
			'count'        => 3,
		];
	}

	// -------------------------------------------------------------------------
	// Reading
	// -------------------------------------------------------------------------

	public function test_an_event_that_does_not_repeat_reads_as_a_frequency_of_none(): void {
		$this->assertSame( [ 'frequency' => 'none' ], $this->read( 'edit' )['blockendar_recurrence'] );
	}

	/**
	 * What is read has to equal what the editor writes, key for key, or opening
	 * a recurring event would mark it as edited.
	 */
	public function test_a_rule_reads_back_in_the_shape_it_was_written_in(): void {
		$this->save( [ 'blockendar_recurrence' => $this->weekly() ] );

		$this->assertSame( $this->weekly(), $this->read( 'edit' )['blockendar_recurrence'] );
	}

	public function test_the_rule_is_not_part_of_the_public_response(): void {
		$this->save( [ 'blockendar_recurrence' => $this->weekly() ] );

		wp_set_current_user( 0 );

		$this->assertArrayNotHasKey( 'blockendar_recurrence', $this->read( 'view' ) );
	}

	// -------------------------------------------------------------------------
	// Writing
	// -------------------------------------------------------------------------

	/**
	 * One request: the rule is stored and the index already holds the series.
	 */
	public function test_saving_the_event_with_a_rule_stores_it_and_indexes_the_series(): void {
		$response = $this->save( [ 'blockendar_recurrence' => $this->weekly() ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'weekly', $this->rules->get( $this->post_id )->frequency );
		$this->assertSame( 3, $this->occurrences() );
	}

	/**
	 * Not null: core skips a field whose value is null, so the rule would stay.
	 */
	public function test_saving_a_frequency_of_none_removes_the_rule(): void {
		$this->save( [ 'blockendar_recurrence' => $this->weekly() ] );
		$this->save( [ 'blockendar_recurrence' => [ 'frequency' => 'none' ] ] );

		$this->assertNull( $this->rules->get( $this->post_id ) );
		$this->assertSame( 1, $this->occurrences() );
	}

	/**
	 * The editor sends the field only when the author changed it.
	 */
	public function test_saving_without_the_field_leaves_the_rule_alone(): void {
		$this->save( [ 'blockendar_recurrence' => $this->weekly() ] );
		$this->save( [ 'title' => 'Renamed' ] );

		$this->assertSame( 'weekly', $this->rules->get( $this->post_id )->frequency );
		$this->assertSame( 3, $this->occurrences() );
	}

	/**
	 * Skipped and added dates have no controls in the editor. Saving the rule
	 * from there must not be what removes them.
	 */
	public function test_saving_the_rule_keeps_its_skipped_and_added_dates(): void {
		$this->save( [ 'blockendar_recurrence' => $this->weekly() ] );

		$this->rules->add_exception( $this->post_id, '2027-03-16' );
		$this->rules->add_extra_date( $this->post_id, '2027-05-01' );

		$this->save( [ 'blockendar_recurrence' => array_merge( $this->weekly(), [ 'count' => 5 ] ) ] );

		$rule = $this->rules->get( $this->post_id );

		$this->assertSame( 5, $rule->count );
		$this->assertSame( [ '2027-03-16' ], $rule->exceptions );
		$this->assertSame( [ '2027-05-01' ], $rule->additions );
	}

	public function test_a_frequency_that_does_not_exist_is_refused(): void {
		$response = $this->save( [ 'blockendar_recurrence' => array_merge( $this->weekly(), [ 'frequency' => 'fortnightly' ] ) ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertNull( $this->rules->get( $this->post_id ) );
	}

	public function test_a_reader_cannot_set_a_rule(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$response = $this->save( [ 'blockendar_recurrence' => $this->weekly() ] );

		$this->assertContains( $response->get_status(), [ 401, 403 ] );
		$this->assertNull( $this->rules->get( $this->post_id ) );
	}
}
