<?php
/**
 * Integration coverage for what the settings page is given to show.
 *
 * The page reads the settings from WordPress's own settings endpoint, which
 * checks each setting against its schema and answers null for one that does
 * not fit. A site whose settings were last saved before 1.0.0 still has two
 * keys the schema dropped then, so its settings came back null, the page
 * showed the plugin's defaults as if they were the saved values, and Save
 * wrote those defaults over the real ones.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\Admin\SettingsPage;
use WP_REST_Request;
use WP_UnitTestCase;

class SettingsRestValueTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		delete_option( SettingsPage::OPTION_NAME );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		delete_option( SettingsPage::OPTION_NAME );
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Fixtures
	// -------------------------------------------------------------------------

	/**
	 * Put a value in the options table as it is, bypassing the sanitiser.
	 *
	 * update_option() would run SettingsPage::sanitize() on the way in, which
	 * drops the very keys these tests need stored. The value then reads back
	 * clean and the test passes whether or not anything was fixed.
	 *
	 * @param array $value Value to store.
	 */
	private function store_raw( array $value ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->replace(
			$wpdb->options,
			[
				'option_name'  => SettingsPage::OPTION_NAME,
				'option_value' => maybe_serialize( $value ),
				'autoload'     => 'yes',
			]
		);

		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( SettingsPage::OPTION_NAME, 'options' );
	}

	/**
	 * Settings as a site that last saved them on 0.x has them.
	 *
	 * @return array
	 */
	private function settings_from_before_one_point_oh(): array {
		return [
			'date_format'            => 'F j',
			'time_format'            => 'g:i a',
			'timezone_mode'          => 'site',
			'calendar_default_view'  => 'dayGridMonth',
			'calendar_first_day'     => 0,
			'calendar_slot_duration' => '00:30:00',
			'events_slug'            => 'whats-on',
			'map_provider'           => 'openstreetmap',
			'google_maps_api_key'    => '',
			'map_default_zoom'       => 14,
			'default_currency'       => 'USD',
			'currency_position'      => 'before',
			'horizon_days'           => 400,
			'max_instances'          => 3650,
			'generation_strategy'    => 'on_save',
			'rest_public'            => true,
			'rest_feed_token'        => '',
		];
	}

	/**
	 * What the settings endpoint answers for the plugin's setting.
	 *
	 * @return mixed
	 */
	private function from_the_endpoint(): mixed {
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/settings' ) );

		$this->assertSame( 200, $response->get_status() );

		return $response->get_data()[ SettingsPage::OPTION_NAME ];
	}

	// -------------------------------------------------------------------------
	// What the page is shown
	// -------------------------------------------------------------------------

	public function test_settings_holding_a_retired_key_are_still_given_to_the_page(): void {
		$this->store_raw( $this->settings_from_before_one_point_oh() );

		$this->assertArrayHasKey( 'map_provider', get_option( SettingsPage::OPTION_NAME ), 'Precondition: the retired key is really stored.' );

		$settings = $this->from_the_endpoint();

		$this->assertIsArray( $settings, 'The page was given null, and showed the defaults in place of the saved settings.' );
		$this->assertSame( 'F j', $settings['date_format'] );
		$this->assertSame( 'whats-on', $settings['events_slug'] );
		$this->assertSame( 400, $settings['horizon_days'] );
		$this->assertArrayNotHasKey( 'map_provider', $settings );
	}

	/**
	 * One value that does not fit its type used to cost the page every other
	 * setting as well.
	 */
	public function test_one_value_of_the_wrong_kind_does_not_hide_the_rest(): void {
		$stored                 = $this->settings_from_before_one_point_oh();
		$stored['horizon_days'] = 'a year';

		unset( $stored['map_provider'], $stored['google_maps_api_key'] );

		$this->store_raw( $stored );

		$settings = $this->from_the_endpoint();

		$this->assertIsArray( $settings );
		$this->assertSame( 'F j', $settings['date_format'] );
		$this->assertIsInt( $settings['horizon_days'] );
	}

	public function test_a_site_that_never_saved_its_settings_is_given_the_defaults(): void {
		$this->assertSame( SettingsPage::defaults(), $this->from_the_endpoint() );
	}

	public function test_other_settings_are_left_to_wordpress(): void {
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/settings' ) );

		$this->assertSame( get_option( 'blogname' ), $response->get_data()['title'] );
	}

	/**
	 * Reading does not rewrite what is stored. The retired keys go when the
	 * settings are next saved, and nothing else changes with them.
	 */
	public function test_saving_what_the_page_was_given_drops_the_retired_keys_and_keeps_the_rest(): void {
		$stored = $this->settings_from_before_one_point_oh();
		$this->store_raw( $stored );

		$shown = $this->from_the_endpoint();

		$this->assertArrayHasKey( 'map_provider', get_option( SettingsPage::OPTION_NAME ), 'Looking at the settings leaves them as they were.' );

		$request = new WP_REST_Request( 'POST', '/wp/v2/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( [ SettingsPage::OPTION_NAME => $shown ] ) );

		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );

		$saved = get_option( SettingsPage::OPTION_NAME );

		$this->assertArrayNotHasKey( 'map_provider', $saved );
		$this->assertArrayNotHasKey( 'google_maps_api_key', $saved );

		unset( $stored['map_provider'], $stored['google_maps_api_key'] );

		foreach ( $stored as $key => $value ) {
			$this->assertSame( $value, $saved[ $key ], "{$key} changed on save." );
		}
	}

	// -------------------------------------------------------------------------
	// So that it does not happen again
	// -------------------------------------------------------------------------

	/**
	 * The schema, the defaults and the sanitiser each list the settings. When
	 * 1.0.0 took two out of one list the others were not checked against it.
	 */
	public function test_the_defaults_the_schema_and_the_sanitiser_list_the_same_settings(): void {
		$schema   = get_registered_settings()[ SettingsPage::OPTION_NAME ]['show_in_rest']['schema'];
		$defaults = SettingsPage::defaults();
		$cleaned  = ( new SettingsPage() )->sanitize( $defaults );

		$this->assertEqualsCanonicalizing( array_keys( $schema['properties'] ), array_keys( $defaults ) );
		$this->assertEqualsCanonicalizing( array_keys( $schema['properties'] ), array_keys( $cleaned ) );
	}

	/**
	 * As WordPress applies the schema: nothing beyond the listed settings.
	 */
	public function test_what_the_sanitiser_returns_always_fits_the_schema(): void {
		$schema = get_registered_settings()[ SettingsPage::OPTION_NAME ]['show_in_rest']['schema'];
		$strict = array_merge( $schema, [ 'additionalProperties' => false ] );
		$page   = new SettingsPage();

		$inputs = [
			'the defaults'       => SettingsPage::defaults(),
			'nothing at all'     => [],
			'a 0.x site'         => $this->settings_from_before_one_point_oh(),
			// A list arrives as a comma string, the way a query string would
			// carry it; strval() on an array would only warn.
			'everything as text' => array_map(
				static fn( $v ) => is_array( $v ) ? implode( ',', $v ) : (string) ( is_bool( $v ) ? (int) $v : $v ),
				SettingsPage::defaults()
			),
			'nonsense'           => array_fill_keys( array_keys( SettingsPage::defaults() ), 'nonsense' ),
		];

		foreach ( $inputs as $name => $input ) {
			$result = rest_validate_value_from_schema( $page->sanitize( $input ), $strict, SettingsPage::OPTION_NAME );

			$this->assertTrue( $result, "Sanitising {$name}: " . ( is_wp_error( $result ) ? $result->get_error_message() : '' ) );
		}
	}
}
