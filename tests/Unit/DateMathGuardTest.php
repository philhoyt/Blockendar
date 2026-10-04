<?php
/**
 * Guard: the date code that feeds the index, the recurrence engine and the
 * feed does not read dates through PHP's default timezone.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Unit;

use PHPUnit\Framework\TestCase;

class DateMathGuardTest extends TestCase {

	private const DIRECTORIES = [ 'includes/DB', 'includes/Recurrence', 'includes/ICS' ];

	/**
	 * PHP source files under the guarded directories.
	 *
	 * @return array<string, array{string}>
	 */
	public function guarded_files(): array {
		$root  = dirname( __DIR__, 2 );
		$files = [];

		foreach ( self::DIRECTORIES as $directory ) {
			foreach ( glob( "{$root}/{$directory}/*.php" ) ?: [] as $path ) {
				$files[ "{$directory}/" . basename( $path ) ] = [ $path ];
			}
		}

		return $files;
	}

	public function test_the_guard_finds_files_to_check(): void {
		$this->assertGreaterThan( 5, count( $this->guarded_files() ) );
	}

	/**
	 * strtotime() reads a string with no zone in PHP's default timezone.
	 * WordPress sets that to UTC, but any plugin can change it, and a date
	 * worked out through it then moves. In these directories a wrong date is
	 * stored, or published in a feed. blockendar_next_day() and a
	 * DateTimeImmutable with an explicit zone do the same work without it.
	 *
	 * @dataProvider guarded_files
	 *
	 * @param string $path File to check.
	 */
	public function test_no_date_is_read_through_strtotime( string $path ): void {
		// Comments are allowed to mention it; code is not allowed to call it.
		$code = '';

		foreach ( token_get_all( (string) file_get_contents( $path ) ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) {
				continue;
			}

			$code .= is_array( $token ) ? $token[1] : $token;
		}

		$this->assertDoesNotMatchRegularExpression( '/\bstrtotime\s*\(/', $code );
	}
}
