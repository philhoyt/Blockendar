<?php
/**
 * Integration coverage for the JavaScript probe the filter blocks print.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\Blocks\ScriptProbe;
use WP_UnitTestCase;

class ScriptProbeTest extends WP_UnitTestCase {

	private const PROBE = 'document.documentElement.classList.add("blockendar-js");';

	public function set_up(): void {
		parent::set_up();
		ScriptProbe::reset();
	}

	public function tear_down(): void {
		ScriptProbe::reset();
		unset( $_SERVER['HTTP_ACCEPT'] );
		parent::tear_down();
	}

	public function test_a_filter_block_prints_the_probe_ahead_of_its_markup(): void {
		$html = do_blocks( '<!-- wp:blockendar/filter-date-range /-->' );

		$this->assertStringContainsString( self::PROBE, $html );
		$this->assertLessThan(
			strpos( $html, 'data-blockendar-filter' ),
			strpos( $html, self::PROBE ),
			'the probe must come before the block it protects'
		);
	}

	public function test_the_probe_prints_once_per_request(): void {
		self::factory()->term->create( [ 'taxonomy' => 'event_venue' ] );
		self::factory()->term->create( [ 'taxonomy' => 'event_type' ] );

		$html = do_blocks(
			'<!-- wp:blockendar/filter-date-range /-->'
			. '<!-- wp:blockendar/filter-venue {"showEmpty":true} /-->'
			. '<!-- wp:blockendar/filter-event-type {"showEmptyTerms":true} /-->'
		);

		$this->assertSame( 3, substr_count( $html, 'data-blockendar-filter=' ) );
		$this->assertSame( 1, substr_count( $html, self::PROBE ) );
	}

	public function test_the_probe_is_a_script_tag_wordpress_can_decorate(): void {
		// wp_print_inline_script_tag() lets a CSP nonce or type be attached
		// through wp_inline_script_attributes; a bare echo would not.
		add_filter(
			'wp_inline_script_attributes',
			static fn( array $attributes ): array => $attributes + [ 'nonce' => 'probe-nonce' ]
		);

		$html = do_blocks( '<!-- wp:blockendar/filter-date-range /-->' );

		$this->assertStringContainsString( 'nonce="probe-nonce"', $html );
	}

	public function test_a_json_request_gets_no_probe(): void {
		// The block-renderer endpoint returns markup for the editor, where a
		// class on <html> means nothing.
		$_SERVER['HTTP_ACCEPT'] = 'application/json';

		$html = do_blocks( '<!-- wp:blockendar/filter-date-range /-->' );

		$this->assertStringContainsString( 'data-blockendar-filter', $html );
		$this->assertStringNotContainsString( 'blockendar-js', $html );
	}
}
