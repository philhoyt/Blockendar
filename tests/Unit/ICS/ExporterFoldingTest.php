<?php
/**
 * Unit tests for RFC 5545 line folding and text escaping.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Unit\ICS;

use Blockendar\ICS\Exporter;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class ExporterFoldingTest extends TestCase {

	private Exporter $exporter;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->exporter = new Exporter();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Split a folded value back into its physical lines.
	 *
	 * @param string $folded Folded output.
	 * @return string[]
	 */
	private function physical_lines( string $folded ): array {
		return explode( "\r\n", $folded );
	}

	/**
	 * Reverse the fold, per RFC 5545: remove CRLF plus the one space after it.
	 *
	 * @param string $folded Folded output.
	 */
	private function unfold( string $folded ): string {
		return str_replace( "\r\n ", '', $folded );
	}

	public function test_lines_at_or_below_the_limit_are_untouched(): void {
		$at    = str_repeat( 'a', 75 );
		$under = str_repeat( 'a', 74 );

		$this->assertSame( $under, $this->exporter->fold_line( $under ) );
		$this->assertSame( $at, $this->exporter->fold_line( $at ) );
	}

	public function test_one_octet_over_the_limit_folds(): void {
		$line   = str_repeat( 'a', 76 );
		$folded = $this->exporter->fold_line( $line );

		$this->assertCount( 2, $this->physical_lines( $folded ) );
		$this->assertSame( $line, $this->unfold( $folded ) );
	}

	public function test_no_physical_line_exceeds_the_octet_limit(): void {
		$folded = $this->exporter->fold_line( 'DESCRIPTION:' . str_repeat( 'long text ', 60 ) );

		foreach ( $this->physical_lines( $folded ) as $index => $line ) {
			$this->assertLessThanOrEqual(
				75,
				strlen( $line ),
				"Physical line {$index} is over the 75-octet limit."
			);
		}
	}

	public function test_continuation_lines_begin_with_a_single_space(): void {
		$folded = $this->exporter->fold_line( str_repeat( 'b', 400 ) );
		$lines  = $this->physical_lines( $folded );

		$this->assertGreaterThan( 1, count( $lines ) );

		foreach ( array_slice( $lines, 1 ) as $line ) {
			$this->assertSame( ' ', substr( $line, 0, 1 ) );
			$this->assertNotSame( '  ', substr( $line, 0, 2 ) );
		}
	}

	/**
	 * @dataProvider multibyte_provider
	 *
	 * @param string $label Case description.
	 * @param string $line  Line to fold.
	 */
	public function test_multibyte_sequences_are_never_split( string $label, string $line ): void {
		$folded = $this->exporter->fold_line( $line );

		foreach ( $this->physical_lines( $folded ) as $physical ) {
			$this->assertTrue(
				mb_check_encoding( ltrim( $physical, ' ' ), 'UTF-8' ),
				"{$label}: a physical line is not valid UTF-8, so a character was split."
			);
		}

		$this->assertSame( $line, $this->unfold( $folded ), "{$label}: unfold did not restore the input." );
	}

	public static function multibyte_provider(): array {
		return [
			// Pad lengths either side of the boundary so the fold lands mid-character.
			'emoji at 73'     => [ 'emoji at 73', str_repeat( 'c', 73 ) . str_repeat( '🎵', 10 ) ],
			'emoji at 74'     => [ 'emoji at 74', str_repeat( 'c', 74 ) . str_repeat( '🎵', 10 ) ],
			'emoji at 75'     => [ 'emoji at 75', str_repeat( 'c', 75 ) . str_repeat( '🎵', 10 ) ],
			'two-octet chars' => [ 'two-octet chars', str_repeat( 'é', 100 ) ],
			'three-octet CJK' => [ 'three-octet CJK', str_repeat( '東', 60 ) ],
			'mixed'           => [ 'mixed', 'SUMMARY:' . str_repeat( 'Café 東京 🎵 ', 20 ) ],
		];
	}

	public function test_escape_sequences_are_not_split_across_a_fold(): void {
		$line   = str_repeat( 'd', 73 ) . str_repeat( '\\,', 20 );
		$folded = $this->exporter->fold_line( $line );

		foreach ( $this->physical_lines( $folded ) as $physical ) {
			$trailing = strlen( $physical ) - strlen( rtrim( $physical, '\\' ) );

			$this->assertSame(
				0,
				$trailing % 2,
				'A physical line ended on an unmatched backslash, splitting an escape sequence.'
			);
		}

		$this->assertSame( $line, $this->unfold( $folded ) );
	}

	public function test_a_run_of_backslashes_still_round_trips(): void {
		$line = str_repeat( 'e', 70 ) . str_repeat( '\\', 12 ) . 'x';

		$this->assertSame( $line, $this->unfold( $this->exporter->fold_line( $line ) ) );
	}

	/**
	 * Call the private escaper.
	 *
	 * @param string $value Raw value.
	 */
	private function escape( string $value ): string {
		// No setAccessible() call: it has been a no-op since PHP 8.1, which is
		// this plugin's floor, and is deprecated from 8.5.
		return ( new \ReflectionMethod( Exporter::class, 'escape_text' ) )
			->invoke( $this->exporter, $value );
	}

	/**
	 * @dataProvider line_break_provider
	 *
	 * @param string $label Case description.
	 * @param string $value Value containing a line break.
	 */
	public function test_line_breaks_cannot_survive_escaping( string $label, string $value ): void {
		$escaped = $this->escape( $value );

		$this->assertStringNotContainsString( "\r", $escaped, "{$label}: a raw CR survived escaping." );
		$this->assertStringNotContainsString( "\n", $escaped, "{$label}: a raw LF survived escaping." );
	}

	public static function line_break_provider(): array {
		return [
			'CRLF'      => [ 'CRLF', "Concert\r\nSUMMARY:injected" ],
			'bare CR'   => [ 'bare CR', "Concert\rSUMMARY:injected" ],
			'bare LF'   => [ 'bare LF', "Concert\nSUMMARY:injected" ],
			'CR at end' => [ 'CR at end', "Concert\r" ],
			'many'      => [ 'many', "A\r\rB\n\nC\r\n\r\nD" ],
		];
	}

	public function test_an_injected_property_is_neutralised_onto_one_line(): void {
		$escaped = $this->escape( "Gig\r\nBEGIN:VEVENT" );

		// The text is kept, but as an escaped \n inside one value rather than a
		// break that would start a new content line.
		$this->assertSame( 'Gig\nBEGIN:VEVENT', $escaped );
		$this->assertCount( 1, explode( "\n", $escaped ) );
	}

	public function test_the_usual_special_characters_are_still_escaped(): void {
		$this->assertSame( 'a\\,b', $this->escape( 'a,b' ) );
		$this->assertSame( 'a\\;b', $this->escape( 'a;b' ) );
		$this->assertSame( 'a\\\\b', $this->escape( 'a\\b' ) );
	}
}
