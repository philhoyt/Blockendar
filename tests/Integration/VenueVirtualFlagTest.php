<?php
/**
 * Integration coverage for the venue "virtual" flag surviving saves that are
 * not the venue form.
 *
 * The flag is a checkbox, so an unchecked box sends nothing. The save handler
 * read "not in $_POST" as "unchecked" on every created_/edited_ hook, so Quick
 * Edit, a REST term update or wp_update_term() from code turned a virtual
 * venue back into a physical one.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\Taxonomy\Venue;
use WP_UnitTestCase;

class VenueVirtualFlagTest extends WP_UnitTestCase {

	private int $term_id;

	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->term_id = self::factory()->term->create(
			[
				'taxonomy' => Venue::TAXONOMY,
				'name'     => 'Online',
			]
		);
		update_term_meta( $this->term_id, 'blockendar_venue_virtual', true );
	}

	public function tear_down(): void {
		unset( $_POST['blockendar_venue_form'], $_POST['blockendar_venue_virtual'] );
		parent::tear_down();
	}

	private function is_virtual(): bool {
		return (bool) get_term_meta( $this->term_id, 'blockendar_venue_virtual', true );
	}

	public function test_a_programmatic_update_keeps_the_flag(): void {
		$this->assertTrue( $this->is_virtual(), 'Precondition: the venue starts virtual.' );

		wp_update_term( $this->term_id, Venue::TAXONOMY, [ 'name' => 'Online stream' ] );

		$this->assertTrue( $this->is_virtual() );
	}

	public function test_a_rest_update_that_omits_the_meta_keeps_the_flag(): void {
		$request = new \WP_REST_Request( 'POST', '/wp/v2/event-venues/' . $this->term_id );
		$request->set_param( 'name', 'Online stream' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $this->is_virtual() );
	}

	public function test_the_venue_form_can_still_clear_the_flag(): void {
		$_POST['blockendar_venue_form'] = '1';

		wp_update_term( $this->term_id, Venue::TAXONOMY, [ 'name' => 'Main hall' ] );

		$this->assertFalse( $this->is_virtual() );
	}

	public function test_the_venue_form_can_still_set_the_flag(): void {
		update_term_meta( $this->term_id, 'blockendar_venue_virtual', false );

		$_POST['blockendar_venue_form']    = '1';
		$_POST['blockendar_venue_virtual'] = '1';

		wp_update_term( $this->term_id, Venue::TAXONOMY, [ 'name' => 'Online stream' ] );

		$this->assertTrue( $this->is_virtual() );
	}
}
