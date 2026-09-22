<?php
/**
 * Unit tests for subscription URL building.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Unit\ICS;

use Blockendar\ICS\FeedUrl;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class FeedUrlTest extends TestCase {

	private const BASE = 'https://example.com/wp-json/blockendar/v1/calendar';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Monkey\Functions\when( 'rest_url' )->justReturn( self::BASE );
		Monkey\Functions\when( 'get_option' )->justReturn( [] );

		// Stand-in for add_query_arg that appends in the given order.
		Monkey\Functions\when( 'add_query_arg' )->alias(
			function ( $args, $url ) {
				$pairs = [];

				foreach ( $args as $key => $value ) {
					$pairs[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
				}

				return $url . '?' . implode( '&', $pairs );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_bare_url_asks_for_the_ics_format(): void {
		$this->assertSame( self::BASE . '?format=ics', FeedUrl::build() );
	}

	public function test_the_webcal_form_swaps_only_the_scheme(): void {
		$this->assertSame(
			'webcal://example.com/wp-json/blockendar/v1/calendar?format=ics',
			FeedUrl::build( [], true )
		);
	}

	public function test_filters_are_comma_joined_not_array_encoded(): void {
		$url = FeedUrl::build(
			[
				'venue_ids' => [ 12, 7 ],
				'type_ids'  => [ 5, 9 ],
				'featured'  => true,
			]
		);

		// parse_id_list() splits on commas; venue[0]=12 would match nothing.
		$this->assertStringContainsString( 'venue=12%2C7', $url );
		$this->assertStringContainsString( 'type=5%2C9', $url );
		$this->assertStringContainsString( 'featured=1', $url );
		$this->assertStringNotContainsString( 'venue%5B0%5D', $url );
	}

	public function test_empty_filters_are_omitted_entirely(): void {
		$url = FeedUrl::build(
			[
				'venue_ids' => [],
				'type_ids'  => [],
				'featured'  => false,
			]
		);

		$this->assertSame( self::BASE . '?format=ics', $url );
	}

	/**
	 * absint() would turn -4 into a request for term 4, which the caller never
	 * asked for. parse_id_list() drops negatives, so this must too.
	 */
	public function test_non_positive_and_junk_ids_are_dropped(): void {
		$url = FeedUrl::build( [ 'venue_ids' => [ '12abc', -4, 0, 'x', 7 ] ] );

		// Native parse_url: no WordPress is loaded in the Brain Monkey suite.
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url

		// -4 must be dropped, not turned into 4 the way absint() would.
		$this->assertSame( '12,7', $query['venue'] ?? null );
	}

	public function test_the_token_is_withheld_unless_it_is_asked_for(): void {
		Monkey\Functions\when( 'get_option' )->justReturn(
			[ 'rest_feed_token' => 'SECRET' ]
		);

		$this->assertStringNotContainsString( 'SECRET', FeedUrl::build() );
		$this->assertStringNotContainsString( 'SECRET', FeedUrl::build( [], true ) );
		$this->assertStringNotContainsString( 'SECRET', FeedUrl::build( [], true, false ) );
		$this->assertStringContainsString( 'token=SECRET', FeedUrl::build( [], true, true ) );
	}

	public function test_asking_for_a_token_that_is_not_set_adds_nothing(): void {
		Monkey\Functions\when( 'get_option' )->justReturn( [ 'rest_feed_token' => '' ] );

		$this->assertStringNotContainsString( 'token', FeedUrl::build( [], false, true ) );
	}

	public function test_public_readability_follows_the_rest_public_setting(): void {
		Monkey\Functions\when( 'get_option' )->justReturn( [ 'rest_public' => true ] );
		$this->assertTrue( FeedUrl::is_publicly_readable() );

		Monkey\Functions\when( 'get_option' )->justReturn( [ 'rest_public' => false ] );
		$this->assertFalse( FeedUrl::is_publicly_readable() );
	}

	public function test_public_readability_defaults_to_true_when_unset(): void {
		Monkey\Functions\when( 'get_option' )->justReturn( [] );

		$this->assertTrue( FeedUrl::is_publicly_readable() );
	}
}
