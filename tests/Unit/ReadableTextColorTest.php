<?php
/**
 * Unit coverage for choosing a text colour that can be read on a background.
 *
 * Calendar chips take their background from the event type's colour, which a
 * site owner picks freely, and the text on them was always white. On a pale
 * colour that is white on pale.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Unit;

use PHPUnit\Framework\TestCase;

class ReadableTextColorTest extends TestCase {

	/**
	 * Contrast ratio between two hex colours, per WCAG 2.
	 *
	 * Worked out here independently of the helper under test.
	 *
	 * @param string $one A colour as #rrggbb.
	 * @param string $two Another.
	 */
	private function contrast( string $one, string $two ): float {
		$luminance = static function ( string $hex ): float {
			$parts = array_map(
				static function ( string $pair ): float {
					$channel = hexdec( $pair ) / 255;

					return $channel <= 0.04045 ? $channel / 12.92 : ( ( $channel + 0.055 ) / 1.055 ) ** 2.4;
				},
				str_split( ltrim( $hex, '#' ), 2 )
			);

			return 0.2126 * $parts[0] + 0.7152 * $parts[1] + 0.0722 * $parts[2];
		};

		$a = $luminance( $one );
		$b = $luminance( $two );

		return ( max( $a, $b ) + 0.05 ) / ( min( $a, $b ) + 0.05 );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public function backgrounds(): array {
		return [
			'white'              => [ '#ffffff', '#000000' ],
			'yellow'             => [ '#ffeb3b', '#000000' ],
			'pale green'         => [ '#a7f3d0', '#000000' ],
			'mid grey'           => [ '#808080', '#000000' ],
			'the old default'    => [ '#3788d8', '#000000' ],
			'the type purple'    => [ '#7c3aed', '#ffffff' ],
			'the type green'     => [ '#059669', '#000000' ],
			'navy'               => [ '#1e3a8a', '#ffffff' ],
			'black'              => [ '#000000', '#ffffff' ],
			'three digits'       => [ '#fc0', '#000000' ],
			'upper case'         => [ '#1E3A8A', '#ffffff' ],
			'without the hash'   => [ '1e3a8a', '#ffffff' ],
			'padded with spaces' => [ ' #1e3a8a ', '#ffffff' ],
		];
	}

	/**
	 * @dataProvider backgrounds
	 *
	 * @param string $background Background colour.
	 * @param string $expected   Text colour to use on it.
	 */
	public function test_the_text_colour_is_the_one_that_contrasts_more( string $background, string $expected ): void {
		$this->assertSame( $expected, blockendar_readable_text_color( $background ) );
	}

	/**
	 * Black or white always reaches 4.5:1 on any colour; the test is that the
	 * helper picks the one that does.
	 */
	public function test_every_pair_meets_the_minimum_for_normal_text(): void {
		foreach ( [ '#ffffff', '#ffeb3b', '#a7f3d0', '#808080', '#3788d8', '#7c3aed', '#059669', '#1e3a8a', '#000000', '#777777', '#767676' ] as $background ) {
			$text = blockendar_readable_text_color( $background );

			$this->assertGreaterThanOrEqual( 4.5, $this->contrast( $background, $text ), "{$text} on {$background}" );
		}
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function not_hex_colours(): array {
		return [
			'empty'        => [ '' ],
			'a name'       => [ 'rebeccapurple' ],
			'rgb()'        => [ 'rgb(1, 2, 3)' ],
			'too short'    => [ '#12' ],
			'too long'     => [ '#1234567' ],
			'not hex'      => [ '#ggg' ],
			'a css attack' => [ '#fff;background:url(x)' ],
		];
	}

	/**
	 * Anything that is not a hex colour gets no answer, and the caller sets no
	 * text colour: nothing here is ever echoed back.
	 *
	 * @dataProvider not_hex_colours
	 *
	 * @param string $value Value that is not a hex colour.
	 */
	public function test_anything_else_gets_no_answer( string $value ): void {
		$this->assertSame( '', blockendar_readable_text_color( $value ) );
	}
}
