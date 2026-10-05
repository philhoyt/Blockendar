<?php
/**
 * Integration coverage for the wording of the three event taxonomies.
 *
 * Each defined about a dozen labels and inherited the rest from WordPress,
 * which words them for categories and tags: the events list said "No
 * categories" under Venues, and the venue screen offered "Go to Categories".
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use WP_UnitTestCase;

class TaxonomyLabelsTest extends WP_UnitTestCase {

	/**
	 * Labels WordPress words generally enough to keep: they describe the
	 * fields of the term form and name no kind of term.
	 */
	private const GENERIC = [
		'name_field_description',
		'slug_field_description',
		'parent_field_description',
		'desc_field_description',
		'most_used',
	];

	public function set_up(): void {
		parent::set_up();

		register_taxonomy( 'blockendar_test_bare_hier', 'post', [ 'hierarchical' => true ] );
		register_taxonomy( 'blockendar_test_bare_flat', 'post', [ 'hierarchical' => false ] );
	}

	public function tear_down(): void {
		unregister_taxonomy( 'blockendar_test_bare_hier' );
		unregister_taxonomy( 'blockendar_test_bare_flat' );
		parent::tear_down();
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public function taxonomies(): array {
		return [
			'venues'      => [ 'blockendar_event_venue', 'blockendar_test_bare_hier' ],
			'event types' => [ 'blockendar_event_type', 'blockendar_test_bare_hier' ],
			'event tags'  => [ 'blockendar_event_tag', 'blockendar_test_bare_flat' ],
		];
	}

	/**
	 * Compared with what WordPress gives a taxonomy that sets no labels at
	 * all. Searching for the word "category" would not do: on event tags the
	 * inherited labels say "tag", which is a word that belongs there.
	 *
	 * @dataProvider taxonomies
	 *
	 * @param string $taxonomy One of the plugin's taxonomies.
	 * @param string $bare     A taxonomy of the same kind with no labels set.
	 */
	public function test_no_label_is_still_the_one_wordpress_supplies( string $taxonomy, string $bare ): void {
		$ours     = (array) get_taxonomy( $taxonomy )->labels;
		$defaults = (array) get_taxonomy( $bare )->labels;
		$shared   = [];

		foreach ( $ours as $key => $label ) {
			if ( null !== $label && ! in_array( $key, self::GENERIC, true ) && ( $defaults[ $key ] ?? null ) === $label ) {
				$shared[ $key ] = $label;
			}
		}

		$this->assertSame( [], $shared );
	}

	/**
	 * What the events list prints, for screen readers, behind the dash in the
	 * column of an event that has none.
	 */
	public function test_an_event_with_none_is_described_in_the_taxonomys_own_words(): void {
		$this->assertSame( 'No venues', get_taxonomy( 'blockendar_event_venue' )->labels->no_terms );
		$this->assertSame( 'No event types', get_taxonomy( 'blockendar_event_type' )->labels->no_terms );
		$this->assertSame( 'No event tags', get_taxonomy( 'blockendar_event_tag' )->labels->no_terms );
	}

	/**
	 * The status block's description promised a styled badge. It prints the
	 * status as text, to be styled with the block's own controls.
	 */
	public function test_the_status_block_does_not_claim_to_be_styled(): void {
		$description = \WP_Block_Type_Registry::get_instance()->get_registered( 'blockendar/event-status' )->description;

		$this->assertStringNotContainsStringIgnoringCase( 'styled', $description );
		$this->assertStringContainsString( 'Cancelled', $description );
	}
}
