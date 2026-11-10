<?php
/**
 * Integration coverage for single-event blocks on events a visitor may not read.
 *
 * The blocks in the single-event template sit outside core/post-content, so
 * core's password form never covered them: a password-protected event printed
 * its address, stream link, dates and cost to anyone. Separately, the
 * countdown block takes a pinned post ID from its attributes, and rendered
 * the dates of a draft or private event pinned by any block author.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\DB\EventIndex;
use Blockendar\DB\IndexBuilder;
use Blockendar\DB\Schema;
use Blockendar\Taxonomy\Venue;
use WP_UnitTestCase;

class ProtectedEventBlocksTest extends WP_UnitTestCase {

	private const BLOCKS = [
		'blockendar/event-datetime',
		'blockendar/event-cost',
		'blockendar/event-venue',
		'blockendar/add-to-calendar',
		'blockendar/event-countdown',
	];

	public function set_up(): void {
		parent::set_up();

		Schema::create_tables();
		( new EventIndex() )->flush_cache();
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		$GLOBALS['post'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		wp_reset_postdata();
		parent::tear_down();
	}

	/**
	 * Create an indexed future event with a venue and a cost.
	 *
	 * @param array $postarr Post field overrides.
	 * @return int Post ID.
	 */
	private function make_event( array $postarr = [] ): int {
		$post_id = self::factory()->post->create(
			array_merge(
				[
					'post_type'   => 'blockendar_event',
					'post_status' => 'publish',
					'post_title'  => 'Members evening',
					'meta_input'  => [
						'blockendar_start_date' => '2031-03-05',
						'blockendar_end_date'   => '2031-03-05',
						'blockendar_start_time' => '19:00',
						'blockendar_end_time'   => '21:00',
						'blockendar_timezone'   => 'UTC',
						'blockendar_cost'       => '25',
					],
				],
				$postarr
			)
		);

		$venue = self::factory()->term->create( [ 'taxonomy' => Venue::TAXONOMY ] );
		update_term_meta( $venue, 'blockendar_venue_address', '1 Secret Lane' );
		wp_set_object_terms( $post_id, [ $venue ], Venue::TAXONOMY );

		( new IndexBuilder() )->build_for_post( $post_id );
		( new EventIndex() )->flush_cache();

		return $post_id;
	}

	private function render_in_context( string $name, int $post_id ): string {
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		setup_postdata( $GLOBALS['post'] );

		return trim( do_blocks( "<!-- wp:{$name} /-->" ) );
	}

	/**
	 * @dataProvider blocks
	 */
	public function test_block_renders_for_a_public_event( string $name ): void {
		// Control: the empty output below must come from the password, not the fixture.
		$this->assertNotSame( '', $this->render_in_context( $name, $this->make_event() ) );
	}

	/**
	 * @dataProvider blocks
	 */
	public function test_block_renders_nothing_for_a_password_protected_event( string $name ): void {
		$post_id = $this->make_event( [ 'post_password' => 'members' ] );

		$this->assertTrue( post_password_required( $post_id ), 'Precondition: no password cookie.' );
		$this->assertSame( '', $this->render_in_context( $name, $post_id ) );
	}

	public function test_protected_event_details_do_not_reach_the_page(): void {
		$post_id = $this->make_event( [ 'post_password' => 'members' ] );

		$html = '';
		foreach ( self::BLOCKS as $name ) {
			$html .= $this->render_in_context( $name, $post_id );
		}

		$this->assertStringNotContainsString( '1 Secret Lane', $html );
		$this->assertStringNotContainsString( '2031', $html );
	}

	public function test_pinned_countdown_renders_a_published_event(): void {
		$post_id = $this->make_event();

		$html = do_blocks( "<!-- wp:blockendar/event-countdown {\"pinnedPostId\":{$post_id}} /-->" );

		$this->assertStringContainsString( 'data-target="2031-03-05T19:00:00+00:00"', $html );
	}

	/**
	 * @dataProvider unpublished_statuses
	 */
	public function test_pinned_countdown_ignores_an_unpublished_event( string $status ): void {
		$author  = self::factory()->user->create( [ 'role' => 'editor' ] );
		$post_id = $this->make_event(
			[
				'post_status' => $status,
				'post_author' => $author,
			]
		);

		// A contributor writing their own post pins someone else's draft.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );

		$html = do_blocks( "<!-- wp:blockendar/event-countdown {\"pinnedPostId\":{$post_id}} /-->" );

		$this->assertSame( '', trim( $html ) );
	}

	public function test_pinned_countdown_shows_an_unpublished_event_to_its_editors(): void {
		$post_id = $this->make_event( [ 'post_status' => 'draft' ] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$html = do_blocks( "<!-- wp:blockendar/event-countdown {\"pinnedPostId\":{$post_id}} /-->" );

		$this->assertStringContainsString( 'blockendar-event-countdown', $html );
	}

	public function test_pinned_countdown_ignores_a_password_protected_event(): void {
		$post_id = $this->make_event( [ 'post_password' => 'members' ] );

		$html = do_blocks( "<!-- wp:blockendar/event-countdown {\"pinnedPostId\":{$post_id}} /-->" );

		$this->assertSame( '', trim( $html ) );
	}

	public function blocks(): array {
		return array_map( fn( $name ) => [ $name ], self::BLOCKS );
	}

	public function unpublished_statuses(): array {
		return [
			'draft'   => [ 'draft' ],
			'private' => [ 'private' ],
			'pending' => [ 'pending' ],
		];
	}
}
