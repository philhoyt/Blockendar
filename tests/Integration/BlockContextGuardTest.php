<?php
/**
 * Integration coverage for single-event blocks rendered with no post context.
 *
 * A block placed on a page, in a template part, or rendered through
 * do_blocks() from WP-CLI has no event to show. get_the_ID() returns false
 * there, which used to reach a helper typed `int` and fatal. Every
 * single-event block must render nothing instead.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\DB\Schema;
use WP_UnitTestCase;

class BlockContextGuardTest extends WP_UnitTestCase {

	private const BLOCKS = [
		'blockendar/event-datetime',
		'blockendar/event-cost',
		'blockendar/event-venue',
		'blockendar/event-status',
		'blockendar/event-countdown',
		'blockendar/event-map',
		'blockendar/add-to-calendar',
	];

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		// Make sure there is no global post to fall back on.
		$GLOBALS['post'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		wp_reset_postdata();
	}

	/**
	 * @dataProvider blocks
	 */
	public function test_block_renders_nothing_without_a_post_context( string $name ): void {
		$this->assertFalse( get_the_ID(), 'Precondition: no global post.' );

		$html = do_blocks( "<!-- wp:{$name} /-->" );

		$this->assertSame( '', trim( $html ) );
	}

	/**
	 * @dataProvider blocks
	 */
	public function test_block_renders_nothing_for_a_non_event_post( string $name ): void {
		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );

		$html = do_blocks( "<!-- wp:{$name} {\"pinnedPostId\":{$page_id}} /-->" );
		$this->assertSame( '', trim( $html ), 'A pinned non-event must be ignored.' );

		$GLOBALS['post'] = get_post( $page_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		setup_postdata( $GLOBALS['post'] );

		$html = do_blocks( "<!-- wp:{$name} /-->" );
		$this->assertSame( '', trim( $html ), 'A page as the global post must be ignored.' );
	}

	public function test_blocks_still_render_for_a_real_event(): void {
		// Control: the empty results above must come from the guard, not from
		// the blocks being unregistered.
		$event_id = self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'publish',
				'meta_input'  => [
					'blockendar_start_date' => '2025-09-13',
					'blockendar_end_date'   => '2025-09-13',
					'blockendar_status'     => 'cancelled',
				],
			]
		);

		$this->assertTrue( \WP_Block_Type_Registry::get_instance()->is_registered( 'blockendar/event-status' ) );

		$html = do_blocks( '<!-- wp:blockendar/event-status {"pinnedPostId":0} /-->' );
		$this->assertSame( '', trim( $html ), 'Still nothing without context.' );

		$GLOBALS['post'] = get_post( $event_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		setup_postdata( $GLOBALS['post'] );

		$this->assertStringContainsString( 'blockendar-event-status', do_blocks( '<!-- wp:blockendar/event-status /-->' ) );
		$this->assertStringContainsString( 'blockendar-event-datetime', do_blocks( '<!-- wp:blockendar/event-datetime /-->' ) );
	}

	public function test_resolve_occurrence_tolerates_a_missing_post(): void {
		$this->assertNull( blockendar_resolve_occurrence( false ) );
		$this->assertNull( blockendar_resolve_occurrence( null ) );
		$this->assertNull( blockendar_resolve_occurrence( 0 ) );
	}

	public function test_block_event_id_returns_the_event_from_context(): void {
		$event_id = self::factory()->post->create( [ 'post_type' => 'blockendar_event' ] );
		$page_id  = self::factory()->post->create( [ 'post_type' => 'page' ] );

		$block = new \WP_Block( [ 'blockName' => 'blockendar/event-status' ], [ 'postId' => $event_id ] );
		$this->assertSame( $event_id, blockendar_block_event_id( $block ) );

		$block = new \WP_Block( [ 'blockName' => 'blockendar/event-status' ], [ 'postId' => $page_id ] );
		$this->assertSame( 0, blockendar_block_event_id( $block ) );

		$block = new \WP_Block( [ 'blockName' => 'blockendar/event-status' ], [] );
		$this->assertSame( 0, blockendar_block_event_id( $block ) );
		$this->assertSame( $event_id, blockendar_block_event_id( $block, $event_id ) );
	}

	public function blocks(): array {
		return array_map( fn( $name ) => [ $name ], self::BLOCKS );
	}
}
