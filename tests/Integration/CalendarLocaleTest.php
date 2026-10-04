<?php
/**
 * Integration coverage for what the calendar block tells its script about the site.
 *
 * FullCalendar brings its own month names, day names and button labels. It can
 * only use the right ones if it is told the site's language, its text
 * direction and how it writes a time; none of the three was passed.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\Admin\SettingsPage;
use Blockendar\DB\Schema;
use WP_UnitTestCase;

class CalendarLocaleTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		Schema::create_tables();
		delete_option( 'blockendar_settings' );
	}

	public function tear_down(): void {
		delete_option( 'blockendar_settings' );
		delete_option( 'start_of_week' );
		parent::tear_down();
	}

	/**
	 * Render the block and return one of its data attributes.
	 *
	 * @param string $name Attribute name, without the data- prefix.
	 */
	private function data( string $name ): ?string {
		$html = ( new \WP_Block(
			[
				'blockName' => 'blockendar/calendar-view',
				'attrs'     => [],
			]
		) )->render();

		return preg_match( '/data-' . preg_quote( $name, '/' ) . '="([^"]*)"/', $html, $match )
			? html_entity_decode( $match[1] )
			: null;
	}

	public function test_the_block_names_the_sites_language(): void {
		$this->assertSame( 'en_US', $this->data( 'locale' ) );

		add_filter( 'locale', static fn() => 'de_DE' );

		$this->assertSame( 'de_DE', $this->data( 'locale' ) );
	}

	public function test_the_block_gives_the_text_direction(): void {
		$this->assertSame( 'ltr', $this->data( 'direction' ) );

		$GLOBALS['wp_locale']->text_direction = 'rtl';

		$direction = $this->data( 'direction' );

		$GLOBALS['wp_locale']->text_direction = 'ltr';

		$this->assertSame( 'rtl', $direction );
	}

	public function test_the_block_gives_the_time_format_the_site_chose(): void {
		$this->assertSame( get_option( 'time_format' ), $this->data( 'time-format' ) );

		update_option( 'blockendar_settings', [ 'time_format' => 'H:i' ] );

		$this->assertSame( 'H:i', $this->data( 'time-format' ) );
	}

	/**
	 * A site that has never chosen a first day for its calendar gets the one
	 * WordPress is set to. The default was Sunday whatever that said.
	 */
	public function test_the_week_starts_on_the_wordpress_setting_until_one_is_chosen(): void {
		update_option( 'start_of_week', 3 );

		// Asserted on defaults() rather than get(): the settings option is
		// registered on init with the defaults as they stood then, which in a
		// test is before the line above. On a site, init runs on every request.
		$this->assertSame( 3, SettingsPage::defaults()['calendar_first_day'] );

		update_option( 'blockendar_settings', [ 'calendar_first_day' => 0 ] );

		$this->assertSame( 0, SettingsPage::get( 'calendar_first_day' ) );
		$this->assertSame( '0', $this->data( 'first-day' ) );
	}

	public function test_a_first_day_wordpress_cannot_mean_is_kept_in_range(): void {
		update_option( 'start_of_week', 9 );

		$this->assertSame( 6, SettingsPage::defaults()['calendar_first_day'] );
	}
}
