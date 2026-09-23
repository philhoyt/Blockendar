<?php
/**
 * Integration coverage for the admin list-table sort staying scoped to its own query.
 *
 * EventColumns sorts the events list by joining the index table through
 * posts_join / posts_fields / posts_groupby / posts_orderby. Those filters
 * apply to every WP_Query for the rest of the request, so an unscoped version
 * rewrites secondary queries too — adding a join they did not ask for and an
 * ORDER BY on an alias their SELECT never defined.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\Admin\EventColumns;
use Blockendar\DB\Schema;
use WP_Query;
use WP_UnitTestCase;

class EventColumnsQueryScopeTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		set_current_screen( 'edit-blockendar_event' );
	}

	public function tear_down(): void {
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Drive the pre_get_posts handler the way the events list screen would.
	 */
	private function trigger_admin_sort(): WP_Query {
		$columns = new EventColumns();

		$admin_query = new WP_Query();
		$admin_query->set( 'post_type', 'blockendar_event' );
		$admin_query->set( 'orderby', 'blockendar_start_date' );
		$admin_query->set( 'order', 'ASC' );
		$admin_query->is_main_query = true; // phpcs:ignore WordPress.NamingConventions

		// handle_sort() reads is_main_query() rather than the property, so run
		// the filter registration through the public entry point with the
		// global main query swapped in.
		global $wp_the_query;
		$previous     = $wp_the_query;
		$wp_the_query = $admin_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride

		$columns->handle_sort( $admin_query );

		$wp_the_query = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride

		return $admin_query;
	}

	/**
	 * The regression: a later, unrelated query must come back untouched.
	 */
	public function test_sort_filters_do_not_affect_a_later_query(): void {
		self::factory()->post->create_many( 3, [ 'post_type' => 'post' ] );

		$this->trigger_admin_sort();

		// A plain query for ordinary posts, exactly what another plugin might
		// run later in the same admin request.
		$other = new WP_Query(
			[
				'post_type'      => 'post',
				'posts_per_page' => 3,
			]
		);

		$this->assertStringNotContainsString(
			'blockendar_events',
			$other->request,
			'A secondary query must not inherit the index-table JOIN.'
		);
		$this->assertStringNotContainsString(
			'blockendar_start_datetime',
			$other->request,
			'A secondary query must not inherit an ORDER BY on an alias it never selected.'
		);
		$this->assertCount( 3, $other->posts, 'The secondary query must still return its own results.' );
	}

	/**
	 * Guard the guard: if the filters never applied to anything, the test above
	 * would pass even with the bug present.
	 */
	public function test_sort_filters_do_apply_to_the_targeted_query(): void {
		$admin_query = $this->trigger_admin_sort();

		$admin_query->get_posts();

		$this->assertStringContainsString(
			'blockendar_events',
			$admin_query->request,
			'The targeted admin query must still get the index-table JOIN.'
		);
		$this->assertStringContainsString(
			'blockendar_start_datetime',
			$admin_query->request,
			'The targeted admin query must still be ordered by the indexed start datetime.'
		);
	}
}
