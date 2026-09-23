<?php
/**
 * Integration coverage for the feed token sanitizer and the block-gap style.
 *
 * Both take a value from outside and put it somewhere that trusts it: the
 * token authenticates the calendar feed in place of a login, and the block gap
 * lands inside an inline style attribute.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\Admin\SettingsPage;
use Blockendar\DB\EventIndex;
use Blockendar\DB\Schema;
use WP_UnitTestCase;

class FeedTokenAndStyleTest extends WP_UnitTestCase {

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
	 * Save a token through the real sanitizer and read back what was stored.
	 *
	 * @param string $token Candidate token.
	 */
	private function save_token( string $token ): string {
		$page = new SettingsPage();

		$clean = $page->sanitize( [ 'rest_feed_token' => $token ] );

		return (string) $clean['rest_feed_token'];
	}

	// -------------------------------------------------------------------------
	// Feed token
	// -------------------------------------------------------------------------

	public function test_a_long_alphanumeric_token_is_kept(): void {
		$token = 'aB3dE5gH7jK9mN1pQ2rS4tU6vW8xY0zA';

		$this->assertSame( $token, $this->save_token( $token ) );
	}

	/**
	 * A short token reads as protection while being guessable, so it is
	 * cleared rather than stored — which turns token access off outright.
	 */
	public function test_a_short_token_is_rejected(): void {
		$this->assertSame( '', $this->save_token( 'abc123' ) );
		$this->assertSame( '', $this->save_token( 'fifteenchars123' ) );
	}

	/**
	 * The token travels in a URL, so anything outside [A-Za-z0-9] is stripped.
	 * A value that is only long enough because of punctuation is then too
	 * short and gets cleared.
	 */
	public function test_punctuation_is_stripped_and_can_take_it_below_the_floor(): void {
		$this->assertSame(
			'abcdefghijklmnop',
			$this->save_token( 'abcdef-ghij/klmn op' )
		);

		$this->assertSame( '', $this->save_token( '!!!!!!!!!!!!!!!!!!!!!!' ) );
	}

	public function test_an_empty_token_stays_empty(): void {
		$this->assertSame( '', $this->save_token( '' ) );
	}

	// -------------------------------------------------------------------------
	// Block gap
	// -------------------------------------------------------------------------

	/**
	 * Render events-query with a given blockGap style value.
	 *
	 * @param mixed $gap The raw attribute value.
	 */
	private function render_with_gap( mixed $gap ): string {
		// The block short-circuits to its empty message before rendering the
		// wrapper, so without an event in range there is no style attribute to
		// assert on at all and every one of these would pass vacuously.
		$this->seed_upcoming_event();

		return (string) render_block(
			[
				'blockName'    => 'blockendar/events-query',
				'attrs'        => [
					'style' => [ 'spacing' => [ 'blockGap' => $gap ] ],
				],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	/**
	 * Index one published event inside the upcoming window.
	 */
	private function seed_upcoming_event(): void {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'blockendar_event',
				'post_status' => 'publish',
				'post_title'  => 'Gap fixture',
			]
		);

		$day = gmdate( 'Y-m-d', strtotime( '+7 days' ) );

		( new EventIndex() )->insert(
			[
				'post_id'        => $post_id,
				'start_datetime' => "{$day} 09:00:00",
				'end_datetime'   => "{$day} 10:00:00",
				'start_date'     => $day,
				'end_date'       => $day,
				'all_day'        => 0,
				'status'         => 'scheduled',
			]
		);
	}

	public function test_a_valid_length_is_applied(): void {
		$html = $this->render_with_gap( '1.5rem' );

		// No trailing semicolon: get_block_wrapper_attributes() normalises the
		// style attribute and drops it.
		$this->assertStringContainsString( '--wp--style--block-gap:1.5rem', $html );
	}

	public function test_a_preset_reference_is_applied(): void {
		$html = $this->render_with_gap( 'var:preset|spacing|40' );

		$this->assertStringContainsString(
			'--wp--style--block-gap:var(--wp--preset--spacing--40)',
			$html
		);
	}

	/**
	 * The attribute is escaped on output, so it cannot break out of the style
	 * attribute — but without validation a hand-edited block comment could
	 * still append further declarations inside it.
	 */
	public function test_extra_css_declarations_are_refused(): void {
		$html = $this->render_with_gap( '1rem;position:fixed;inset:0' );

		$this->assertStringNotContainsString( 'position:fixed', $html );
		$this->assertStringNotContainsString( '--wp--style--block-gap', $html );
	}

	/**
	 * The preset branch interpolates its segments too, so it needs the same
	 * treatment as the literal one.
	 */
	public function test_a_preset_path_cannot_smuggle_css(): void {
		$html = $this->render_with_gap( 'var:preset|spacing|40);position:fixed;--x:(' );

		$this->assertStringNotContainsString( 'position:fixed', $html );
	}
}
