<?php
/**
 * Integration coverage for venue and event-type meta sanitizers.
 *
 * The venue save handler suppresses WordPress.Security.ValidatedSanitizedInput on
 * the grounds that these sanitizers neutralise their input. That claim was never
 * tested; these assertions hold it to account.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\Meta\VenueMeta;
use WP_UnitTestCase;

class VenueMetaSanitizersTest extends WP_UnitTestCase {

	private VenueMeta $meta;

	public function set_up(): void {
		parent::set_up();
		$this->meta = new VenueMeta();
	}

	// -------------------------------------------------------------------------
	// Latitude / longitude
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider latitude_provider
	 *
	 * @param mixed $input    Raw value.
	 * @param float $expected Sanitised result.
	 */
	public function test_sanitize_latitude( mixed $input, float $expected ): void {
		$this->assertSame( $expected, $this->meta->sanitize_latitude( $input ) );
	}

	public function latitude_provider(): array {
		return [
			'plain value'     => [ '51.5074', 51.5074 ],
			'negative'        => [ '-33.8688', -33.8688 ],
			'clamped high'    => [ '95.0', 90.0 ],
			'clamped low'     => [ '-120.0', -90.0 ],
			'sql injection'   => [ '0 OR 1=1; DROP TABLE wp_posts;--', 0.0 ],
			'script tag'      => [ '<script>alert(1)</script>', 0.0 ],
			'quote break-out' => [ '1" onmouseover="alert(1)', 1.0 ],
			'empty string'    => [ '', 0.0 ],
			'null'            => [ null, 0.0 ],
			'array'           => [ [ 'nested' ], 1.0 ],
		];
	}

	/**
	 * @dataProvider longitude_provider
	 *
	 * @param mixed $input    Raw value.
	 * @param float $expected Sanitised result.
	 */
	public function test_sanitize_longitude( mixed $input, float $expected ): void {
		$this->assertSame( $expected, $this->meta->sanitize_longitude( $input ) );
	}

	public function longitude_provider(): array {
		return [
			'plain value'   => [ '-0.1278', -0.1278 ],
			'clamped high'  => [ '200.0', 180.0 ],
			'clamped low'   => [ '-200.0', -180.0 ],
			'sql injection' => [ '0; DELETE FROM wp_options;--', 0.0 ],
			'empty string'  => [ '', 0.0 ],
		];
	}

	public function test_latitude_always_returns_a_float(): void {
		// The value is written straight into term meta, so the type matters as
		// much as the range — a string would carry markup through to output.
		$this->assertIsFloat( $this->meta->sanitize_latitude( '<b>51.5</b>' ) );
		$this->assertIsFloat( $this->meta->sanitize_longitude( 'not a number' ) );
	}

	// -------------------------------------------------------------------------
	// Hex colour
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider hex_colour_provider
	 *
	 * @param mixed  $input    Raw value.
	 * @param string $expected Sanitised result.
	 */
	public function test_sanitize_hex_color( mixed $input, string $expected ): void {
		$this->assertSame( $expected, $this->meta->sanitize_hex_color( $input ) );
	}

	public function hex_colour_provider(): array {
		return [
			'uppercased'         => [ '#aabbcc', '#AABBCC' ],
			'already upper'      => [ '#AABBCC', '#AABBCC' ],
			'shorthand rejected' => [ '#abc', '' ],
			'missing hash'       => [ 'aabbcc', '' ],
			'css injection'      => [ '#aabbcc;background:url(evil)', '' ],
			'script tag'         => [ '<script>alert(1)</script>', '' ],
			'quote break-out'    => [ '#aabbcc" onload="alert(1)', '' ],
			'empty'              => [ '', '' ],
			'null'               => [ null, '' ],
		];
	}

	// -------------------------------------------------------------------------
	// Capability gate on the save handler
	// -------------------------------------------------------------------------

	public function test_save_venue_fields_ignores_users_without_manage_categories(): void {
		$term_id = self::factory()->term->create( [ 'taxonomy' => 'event_venue' ] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$_POST['blockendar_venue_address'] = 'Injected Address';
		$this->meta->save_venue_fields( $term_id );
		unset( $_POST['blockendar_venue_address'] );

		$this->assertSame(
			'',
			(string) get_term_meta( $term_id, 'blockendar_venue_address', true ),
			'A subscriber must not be able to write venue meta.'
		);
	}

	public function test_save_venue_fields_sanitises_for_permitted_users(): void {
		$term_id = self::factory()->term->create( [ 'taxonomy' => 'event_venue' ] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$_POST['blockendar_venue_address'] = '<script>alert(1)</script>221B Baker St';
		$_POST['blockendar_venue_lat']     = '95.0';
		$_POST['blockendar_venue_lng']     = '-200.0';

		$this->meta->save_venue_fields( $term_id );

		unset(
			$_POST['blockendar_venue_address'],
			$_POST['blockendar_venue_lat'],
			$_POST['blockendar_venue_lng']
		);

		$address = (string) get_term_meta( $term_id, 'blockendar_venue_address', true );

		$this->assertStringNotContainsString( '<script>', $address );
		$this->assertStringContainsString( '221B Baker St', $address );
		$this->assertSame( 90.0, (float) get_term_meta( $term_id, 'blockendar_venue_lat', true ) );
		$this->assertSame( -180.0, (float) get_term_meta( $term_id, 'blockendar_venue_lng', true ) );
	}
}
