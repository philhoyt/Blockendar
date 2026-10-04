<?php
/**
 * Guard: nothing is put in the index's cache without a lifetime.
 *
 * Every cached read is keyed on the index's last-changed stamp, so a write to
 * the index leaves the earlier entries behind for good. A persistent object
 * cache keeps an entry with no expiry until it runs out of room.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Unit;

use PHPUnit\Framework\TestCase;

class IndexCacheGuardTest extends TestCase {

	/**
	 * EventIndex.php with its comments removed.
	 */
	private function code(): string {
		$code = '';

		foreach ( token_get_all( (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/DB/EventIndex.php' ) ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) {
				continue;
			}

			$code .= is_array( $token ) ? $token[1] : $token;
		}

		return $code;
	}

	public function test_the_index_writes_to_the_cache_in_one_place(): void {
		$this->assertSame(
			1,
			preg_match_all( '/\bwp_cache_set\s*\(/', $this->code() ),
			'A cache write outside EventIndex::cache_set() has no expiry. Call $this->cache_set() instead.'
		);
	}

	public function test_that_place_sets_an_expiry(): void {
		$this->assertMatchesRegularExpression(
			'/\bwp_cache_set\s*\(\s*\$key,\s*\$value,\s*self::CACHE_GROUP,\s*self::CACHE_TTL\s*\)/',
			$this->code()
		);
	}
}
