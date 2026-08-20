<?php
/**
 * Integration coverage for FilterContext URL-parameter parsing.
 *
 * The filter blocks emit term IDs in two different shapes — a `name="...[]"`
 * checkbox array (so multi-select survives with JavaScript disabled) and a
 * comma-separated string (hidden inputs, and what view.js builds). Both must
 * resolve identically. Reading the raw value with sanitize_text_field() used to
 * discard every array submission, because that function returns '' for arrays,
 * which left the event-type filter silently doing nothing.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\Blocks\FilterContext;
use WP_UnitTestCase;

class FilterContextTest extends WP_UnitTestCase {

	public function tear_down(): void {
		$_GET = [];
		parent::tear_down();
	}

	/**
	 * @dataProvider type_shape_provider
	 *
	 * @param mixed $raw      Raw $_GET value.
	 * @param array $expected Expected term IDs.
	 */
	public function test_type_ids_parse_from_every_supported_shape( mixed $raw, array $expected ): void {
		$_GET = [ 'blockendar_type' => $raw ];

		$this->assertSame(
			$expected,
			FilterContext::get_active_filters( '' )['type_ids']
		);
	}

	public function type_shape_provider(): array {
		return [
			'checkbox array, no JS'      => [ [ '12', '34' ], [ 12, 34 ] ],
			'comma string hidden input'  => [ '12,34', [ 12, 34 ] ],
			'array holding comma string' => [ [ '12,34' ], [ 12, 34 ] ],
			'single scalar'              => [ '12', [ 12 ] ],
			'empty string'               => [ '', [] ],
			'empty array'                => [ [], [] ],
			'duplicates collapse'        => [ [ '12', '12', '34' ], [ 12, 34 ] ],
			'zero dropped'               => [ [ '0', '7' ], [ 7 ] ],
			'non-numeric dropped'        => [ [ 'abc' ], [] ],
			'nested array skipped'       => [ [ [ '9' ], '3' ], [ 3 ] ],
		];
	}

	public function test_array_and_comma_shapes_agree(): void {
		$_GET  = [ 'blockendar_type' => [ '5', '9' ] ];
		$array = FilterContext::get_active_filters( '' )['type_ids'];

		$_GET  = [ 'blockendar_type' => '5,9' ];
		$comma = FilterContext::get_active_filters( '' )['type_ids'];

		$this->assertSame( $array, $comma, 'Both wire formats must resolve identically.' );
	}

	public function test_sql_payload_is_reduced_to_an_integer(): void {
		$_GET = [ 'blockendar_type' => [ "12'; DROP TABLE wp_posts;--" ] ];

		$this->assertSame(
			[ 12 ],
			FilterContext::get_active_filters( '' )['type_ids'],
			'Values are cast to int, so no SQL fragment can survive.'
		);
	}

	public function test_negative_ids_are_absolute_valued_not_dropped(): void {
		// absint() takes the absolute value rather than rejecting, matching how
		// EventIndex already treats venue and type IDs. Recorded so the behaviour
		// is deliberate rather than incidental.
		$_GET = [ 'blockendar_type' => [ '-5' ] ];

		$this->assertSame( [ 5 ], FilterContext::get_active_filters( '' )['type_ids'] );
	}

	// -------------------------------------------------------------------------
	// Other params
	// -------------------------------------------------------------------------

	public function test_venue_id_parses_and_rejects_junk(): void {
		$_GET = [ 'blockendar_venue' => '42' ];
		$this->assertSame( 42, FilterContext::get_active_filters( '' )['venue_id'] );

		$_GET = [ 'blockendar_venue' => 'abc' ];
		$this->assertNull( FilterContext::get_active_filters( '' )['venue_id'] );
	}

	/**
	 * @dataProvider date_provider
	 *
	 * @param string      $raw      Raw date value.
	 * @param string|null $expected Expected parsed value.
	 */
	public function test_dates_are_validated( string $raw, ?string $expected ): void {
		$_GET = [ 'blockendar_date_start' => $raw ];

		$this->assertSame(
			$expected,
			FilterContext::get_active_filters( '' )['date_start']
		);
	}

	public function date_provider(): array {
		return [
			'valid'            => [ '2026-09-01', '2026-09-01' ],
			'impossible day'   => [ '2026-02-30', null ],
			'wrong format'     => [ '01/09/2026', null ],
			'empty'            => [ '', null ],
			'sql payload'      => [ "2026-09-01' OR '1'='1", null ],
			'leap day valid'   => [ '2028-02-29', '2028-02-29' ],
			'leap day invalid' => [ '2027-02-29', null ],
		];
	}

	// -------------------------------------------------------------------------
	// Array-shaped input on scalar params
	// -------------------------------------------------------------------------

	public function test_array_venue_param_is_rejected_not_coerced(): void {
		// absint() turns an array into 1, so without a scalar guard this URL
		// silently filtered by term 1 instead of being ignored.
		$_GET = [ 'blockendar_venue' => [ '99' ] ];

		$this->assertNull( FilterContext::get_active_filters( '' )['venue_id'] );
	}

	public function test_scalar_venue_param_still_works(): void {
		$_GET = [ 'blockendar_venue' => '99' ];

		$this->assertSame( 99, FilterContext::get_active_filters( '' )['venue_id'] );
	}

	public function test_array_date_params_are_rejected(): void {
		$_GET = [
			'blockendar_date_start' => [ '2026-09-01' ],
			'blockendar_date_end'   => [ '2026-09-30' ],
		];

		$filters = FilterContext::get_active_filters( '' );

		$this->assertNull( $filters['date_start'] );
		$this->assertNull( $filters['date_end'] );
	}

	// -------------------------------------------------------------------------
	// Inverted ranges
	// -------------------------------------------------------------------------

	public function test_backwards_range_is_swapped_rather_than_returning_nothing(): void {
		$_GET = [
			'blockendar_date_start' => '2027-12-31',
			'blockendar_date_end'   => '2026-01-01',
		];

		$filters = FilterContext::get_active_filters( '' );

		$this->assertSame( '2026-01-01', $filters['date_start'] );
		$this->assertSame( '2027-12-31', $filters['date_end'] );
	}

	public function test_correctly_ordered_range_is_left_alone(): void {
		$_GET = [
			'blockendar_date_start' => '2026-01-01',
			'blockendar_date_end'   => '2027-12-31',
		];

		$filters = FilterContext::get_active_filters( '' );

		$this->assertSame( '2026-01-01', $filters['date_start'] );
		$this->assertSame( '2027-12-31', $filters['date_end'] );
	}

	public function test_equal_dates_are_left_alone(): void {
		$_GET = [
			'blockendar_date_start' => '2026-05-05',
			'blockendar_date_end'   => '2026-05-05',
		];

		$filters = FilterContext::get_active_filters( '' );

		$this->assertSame( '2026-05-05', $filters['date_start'] );
		$this->assertSame( '2026-05-05', $filters['date_end'] );
	}

	public function test_swap_needs_both_dates_to_be_valid(): void {
		// Only one usable bound: there is nothing to swap against, and the invalid
		// half must still be discarded rather than promoted into the other slot.
		$_GET = [
			'blockendar_date_start' => '2027-12-31',
			'blockendar_date_end'   => 'nonsense',
		];

		$filters = FilterContext::get_active_filters( '' );

		$this->assertSame( '2027-12-31', $filters['date_start'] );
		$this->assertNull( $filters['date_end'] );
	}

	// -------------------------------------------------------------------------
	// View mode
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider view_provider
	 *
	 * @param mixed       $raw      Raw $_GET value.
	 * @param string|null $expected Expected resolved view.
	 */
	public function test_view_is_restricted_to_the_allow_list( mixed $raw, ?string $expected ): void {
		$_GET = [ 'blockendar_view' => $raw ];

		$this->assertSame( $expected, FilterContext::get_view( '' ) );
	}

	public function view_provider(): array {
		return [
			'list'            => [ 'list', 'list' ],
			'grid'            => [ 'grid', 'grid' ],
			'unknown mode'    => [ 'masonry', null ],
			'empty'           => [ '', null ],
			'array shape'     => [ [ 'grid' ], null ],
			'markup payload'  => [ '<script>alert(1)</script>', null ],
			'class injection' => [ 'grid" onload="alert(1)', null ],
			'path traversal'  => [ '../../etc/passwd', null ],
		];
	}

	public function test_view_appears_in_the_active_filter_payload(): void {
		$_GET = [ 'blockendar_view' => 'grid' ];

		$this->assertSame( 'grid', FilterContext::get_active_filters( '' )['view'] );
	}

	public function test_view_is_not_treated_as_an_active_filter(): void {
		// Choosing a layout does not narrow the results, so it must not light up
		// the "filters are active" state or a Clear control would appear for it.
		$_GET = [ 'blockendar_view' => 'grid' ];

		$this->assertFalse( FilterContext::has_active_filters( '' ) );
	}

	public function test_a_theme_can_register_an_extra_view_mode(): void {
		$add = static fn( array $modes ): array => [ ...$modes, 'masonry' ];
		add_filter( 'blockendar_filter_view_modes', $add );

		$_GET = [ 'blockendar_view' => 'masonry' ];
		$this->assertSame( 'masonry', FilterContext::get_view( '' ) );

		remove_filter( 'blockendar_filter_view_modes', $add );
	}

	public function test_a_filtered_mode_cannot_smuggle_markup_into_the_output(): void {
		/*
		 * sanitize_key() is applied to the registered modes as well as to the
		 * request, so a mangled mode matches its own mangled form rather than
		 * being rejected outright. That is fine — what matters is that nothing
		 * capable of breaking out of a class attribute survives the round trip.
		 */
		$add = static fn( array $modes ): array => [ ...$modes, 'grid" onload="alert(1)' ];
		add_filter( 'blockendar_filter_view_modes', $add );

		$_GET     = [ 'blockendar_view' => 'grid" onload="alert(1)' ];
		$resolved = FilterContext::get_view( '' );

		$this->assertSame( 'gridonloadalert1', $resolved );
		$this->assertSame(
			$resolved,
			sanitize_key( (string) $resolved ),
			'the resolved mode must already be a safe key'
		);

		remove_filter( 'blockendar_filter_view_modes', $add );
	}

	public function test_clearing_filters_drops_the_view_param(): void {
		$this->assertStringNotContainsString(
			'blockendar_view',
			FilterContext::clear_filters_url( '' )
		);
	}

	// -------------------------------------------------------------------------
	// Param naming and query-ID isolation
	// -------------------------------------------------------------------------

	public function test_query_id_isolates_two_filter_groups(): void {
		$_GET = [
			'blockendar_type'         => '1',
			'blockendar_type_sidebar' => '2',
		];

		$this->assertSame( [ 1 ], FilterContext::get_active_filters( '' )['type_ids'] );
		$this->assertSame( [ 2 ], FilterContext::get_active_filters( 'sidebar' )['type_ids'] );
	}

	public function test_has_active_filters_reflects_state(): void {
		$_GET = [];
		$this->assertFalse( FilterContext::has_active_filters( '' ) );

		$_GET = [ 'blockendar_type' => [ '3' ] ];
		$this->assertTrue(
			FilterContext::has_active_filters( '' ),
			'An array submission must register as an active filter.'
		);
	}
}
