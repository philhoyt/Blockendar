<?php
/**
 * Integration coverage for two things other plugins need from Blockendar:
 * the block editor for events whatever Classic Editor is set to, and a
 * wpml-config.xml that names every field a translation has to carry.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\CPT\EventPostType;
use Blockendar\Meta\EventMeta;
use Blockendar\Meta\VenueMeta;
use Blockendar\Taxonomy\EventTag;
use Blockendar\Taxonomy\EventType;
use Blockendar\Taxonomy\Venue;
use WP_UnitTestCase;

class EditorAndTranslationCompatTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		// WP_UnitTestCase unregisters every meta key in tear_down().
		( new EventMeta() )->register_meta();
		( new VenueMeta() )->register_meta();
	}

	// -------------------------------------------------------------------------
	// The block editor
	// -------------------------------------------------------------------------

	/**
	 * An event's dates, recurrence and details are block-editor panels and
	 * nothing else. Classic Editor in its "classic for everyone" mode turns the
	 * block editor off for every post type at priority 100, which left an event
	 * with no way to give it a date.
	 */
	public function test_events_use_the_block_editor_when_it_is_turned_off_for_every_post_type(): void {
		add_filter( 'use_block_editor_for_post_type', '__return_false', 100 );

		$this->assertTrue( use_block_editor_for_post_type( EventPostType::POST_TYPE ) );
		$this->assertFalse( use_block_editor_for_post_type( 'post' ), 'Other post types keep whatever was chosen for them.' );
		$this->assertFalse( use_block_editor_for_post_type( 'page' ) );
	}

	/**
	 * In its per-user mode Classic Editor decides post by post, also at 100.
	 */
	public function test_events_use_the_block_editor_when_it_is_turned_off_post_by_post(): void {
		$event = self::factory()->post->create_and_get( [ 'post_type' => EventPostType::POST_TYPE ] );
		$post  = self::factory()->post->create_and_get();

		add_filter( 'use_block_editor_for_post', '__return_false', 100 );

		$this->assertTrue( use_block_editor_for_post( $event ) );
		$this->assertFalse( use_block_editor_for_post( $post ) );
	}

	/**
	 * Classic Editor asks which editors a post type supports, and uses the
	 * answer for its "Edit (Classic)" links and its editor switcher.
	 */
	public function test_classic_editor_is_told_events_have_no_classic_editor(): void {
		$both = [
			'classic_editor' => true,
			'block_editor'   => true,
		];

		$this->assertSame(
			[
				'classic_editor' => false,
				'block_editor'   => true,
			],
			apply_filters( 'classic_editor_enabled_editors_for_post_type', $both, EventPostType::POST_TYPE )
		);
		$this->assertSame( $both, apply_filters( 'classic_editor_enabled_editors_for_post_type', $both, 'post' ) );
	}

	// -------------------------------------------------------------------------
	// wpml-config.xml
	// -------------------------------------------------------------------------

	/**
	 * Load the configuration file.
	 */
	private function config(): \SimpleXMLElement {
		$path = dirname( __DIR__, 2 ) . '/wpml-config.xml';

		$this->assertFileExists( $path );

		$xml = simplexml_load_file( $path );

		$this->assertInstanceOf( \SimpleXMLElement::class, $xml, 'wpml-config.xml is not well-formed.' );
		$this->assertSame( 'wpml-config', $xml->getName() );

		return $xml;
	}

	/**
	 * The text of each matching element, mapped to one of its attributes.
	 *
	 * @param string $xpath     XPath from the root.
	 * @param string $attribute Attribute to read.
	 * @return array<string, string>
	 */
	private function entries( string $xpath, string $attribute ): array {
		$entries = [];

		foreach ( $this->config()->xpath( $xpath ) as $node ) {
			$entries[ trim( (string) $node ) ] = (string) $node[ $attribute ];
		}

		ksort( $entries );

		return $entries;
	}

	/**
	 * A translation of an event is another post. Without its dates it is not
	 * indexed and appears nowhere, so every field has to be named. This fails
	 * when a field is registered and not added to the file.
	 */
	public function test_every_registered_event_field_is_listed(): void {
		$registered = array_keys( get_registered_meta_keys( 'post', EventPostType::POST_TYPE ) );
		sort( $registered );

		$listed = $this->entries( '/wpml-config/custom-fields/custom-field', 'action' );

		$this->assertNotEmpty( $registered );
		$this->assertSame( $registered, array_keys( $listed ) );
	}

	public function test_every_registered_term_field_is_listed(): void {
		$registered = array_merge(
			array_keys( get_registered_meta_keys( 'term', Venue::TAXONOMY ) ),
			array_keys( get_registered_meta_keys( 'term', EventType::TAXONOMY ) )
		);
		sort( $registered );

		$listed = $this->entries( '/wpml-config/custom-term-fields/custom-term-field', 'action' );

		$this->assertNotEmpty( $registered );
		$this->assertSame( $registered, array_keys( $listed ) );
	}

	public function test_every_action_is_one_wpml_knows(): void {
		$actions = array_merge(
			$this->entries( '/wpml-config/custom-fields/custom-field', 'action' ),
			$this->entries( '/wpml-config/custom-term-fields/custom-term-field', 'action' )
		);

		foreach ( $actions as $field => $action ) {
			$this->assertContains( $action, [ 'copy', 'copy-once', 'translate', 'ignore' ], $field );
		}
	}

	/**
	 * What decides when and whether an event appears has to stay the same in
	 * every language, so it is copied rather than left to be translated.
	 */
	public function test_the_fields_the_index_is_built_from_are_kept_in_step(): void {
		$actions = $this->entries( '/wpml-config/custom-fields/custom-field', 'action' );

		foreach ( [ 'start_date', 'end_date', 'start_time', 'end_time', 'all_day', 'timezone', 'status', 'featured', 'hide_from_listings', 'ongoing' ] as $field ) {
			$this->assertSame( 'copy', $actions[ "blockendar_{$field}" ], $field );
		}
	}

	public function test_the_post_type_and_its_taxonomies_are_translatable(): void {
		$this->assertSame(
			[ EventPostType::POST_TYPE => '1' ],
			$this->entries( '/wpml-config/custom-types/custom-type', 'translate' )
		);

		$taxonomies = [ EventTag::TAXONOMY, EventType::TAXONOMY, Venue::TAXONOMY ];
		sort( $taxonomies );

		$this->assertSame(
			array_fill_keys( $taxonomies, '1' ),
			$this->entries( '/wpml-config/taxonomies/taxonomy', 'translate' )
		);
	}

	/**
	 * wp-scripts plugin-zip ships only what package.json lists.
	 */
	public function test_the_file_is_in_the_release_zip(): void {
		$package = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/package.json' ), true );

		$this->assertContains( 'wpml-config.xml', $package['files'] );
	}
}
