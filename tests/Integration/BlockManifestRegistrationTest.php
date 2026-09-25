<?php
/**
 * Integration coverage for block registration from build/blocks-manifest.php.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use WP_Block_Metadata_Registry;
use WP_Block_Type_Registry;
use WP_UnitTestCase;

/**
 * BlockRegistrar registers every block through
 * wp_register_block_types_from_metadata_collection(), so core takes each
 * block's metadata from the manifest wp-scripts wrote at build time rather
 * than reading its block.json. These tests pin three things: the collection
 * is really registered (not silently skipped), the manifest is in sync with
 * the built block.json files it stands in for, and the blocks that come out
 * of the registry carry the same callbacks and asset handles the metadata
 * declares.
 *
 * Everything here reads build/, so an unbuilt checkout fails loudly rather
 * than passing on an empty set.
 */
class BlockManifestRegistrationTest extends WP_UnitTestCase {

	private const BLOCKS_DIR = BLOCKENDAR_DIR . 'build/blocks';
	private const MANIFEST   = BLOCKENDAR_DIR . 'build/blocks-manifest.php';

	/**
	 * Block directory names under build/blocks, sorted.
	 *
	 * @return string[]
	 */
	private function built_block_dirs(): array {
		$dirs = array_map( 'basename', glob( self::BLOCKS_DIR . '/*', GLOB_ONLYDIR ) ?: [] );
		sort( $dirs );

		$this->assertNotEmpty( $dirs, 'build/blocks is empty: run npm run build first' );

		return $dirs;
	}

	/**
	 * The manifest as core loads it: directory name => decoded block.json.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function manifest(): array {
		$this->assertFileExists( self::MANIFEST, 'build/blocks-manifest.php is missing: is --blocks-manifest on the build script?' );

		$manifest = require self::MANIFEST;

		$this->assertIsArray( $manifest );

		return $manifest;
	}

	/**
	 * The registered blockendar/* block types, keyed by name.
	 *
	 * @return array<string, \WP_Block_Type>
	 */
	private function registered_blocks(): array {
		$all = WP_Block_Type_Registry::get_instance()->get_all_registered();

		return array_filter(
			$all,
			static fn( string $name ): bool => str_starts_with( $name, 'blockendar/' ),
			ARRAY_FILTER_USE_KEY
		);
	}

	public function test_every_built_block_has_metadata_in_the_collection_registry(): void {
		foreach ( $this->built_block_dirs() as $dir ) {
			$this->assertTrue(
				WP_Block_Metadata_Registry::has_metadata( self::BLOCKS_DIR . '/' . $dir ),
				"$dir is not covered by a registered metadata collection, so core read its block.json from disk"
			);
		}
	}

	public function test_the_manifest_matches_the_built_block_json_files(): void {
		$manifest = $this->manifest();
		$keys     = array_keys( $manifest );
		sort( $keys );

		$this->assertSame(
			$this->built_block_dirs(),
			$keys,
			'the manifest keys must be exactly the directories under build/blocks'
		);

		foreach ( $manifest as $dir => $metadata ) {
			$this->assertSame(
				wp_json_file_decode( self::BLOCKS_DIR . "/$dir/block.json", [ 'associative' => true ] ),
				$metadata,
				"the manifest entry for $dir differs from its built block.json: the manifest is stale"
			);
		}
	}

	public function test_the_registered_block_names_are_exactly_the_manifest_names(): void {
		$expected = array_column( $this->manifest(), 'name' );
		sort( $expected );

		$actual = array_keys( $this->registered_blocks() );
		sort( $actual );

		$this->assertSame( $expected, $actual );
	}

	public function test_registered_blocks_carry_the_callbacks_and_handles_their_metadata_declares(): void {
		$blocks = $this->registered_blocks();

		// block.json key => WP_Block_Type property holding its registered handles.
		$asset_keys = [
			'editorScript' => 'editor_script_handles',
			'viewScript'   => 'view_script_handles',
			'style'        => 'style_handles',
			'editorStyle'  => 'editor_style_handles',
			'viewStyle'    => 'view_style_handles',
		];

		$seen = array_fill_keys( array_keys( $asset_keys ), false );

		foreach ( $this->manifest() as $dir => $metadata ) {
			$block = $blocks[ $metadata['name'] ];

			$this->assertSame(
				isset( $metadata['render'] ),
				null !== $block->render_callback,
				"$dir: render_callback presence does not match the render key"
			);

			foreach ( $asset_keys as $key => $property ) {
				$declared     = ! empty( $metadata[ $key ] );
				$seen[ $key ] = $seen[ $key ] || $declared;

				$this->assertSame(
					$declared,
					[] !== $block->$property,
					"$dir: $property does not match the $key key"
				);
			}
		}

		// The assertion above is vacuous for a key no block declares; make sure
		// each one was exercised at least once.
		foreach ( $seen as $key => $exercised ) {
			$this->assertTrue( $exercised, "no built block declares $key, so that branch went untested" );
		}
	}
}
