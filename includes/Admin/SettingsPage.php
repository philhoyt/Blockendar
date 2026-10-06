<?php
/**
 * Plugin settings page registration.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Blockendar\DB\EventIndex;
use Blockendar\DB\IndexBuilder;

/**
 * Registers the Settings > Blockendar admin page and the blockendar_settings option.
 *
 * The page is a React SPA (src/admin/SettingsApp.jsx). Settings are stored as a
 * single serialised option and exposed to the REST API so the SPA can read/write
 * via wp.apiFetch without a custom endpoint.
 */
class SettingsPage {

	const OPTION_NAME = 'blockendar_settings';
	const MENU_SLUG   = 'blockendar-settings';
	const CAPABILITY  = 'manage_options';

	/**
	 * Attach hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
		add_action( 'init', [ $this, 'register_setting' ] );
		add_filter( 'rest_pre_get_setting', [ $this, 'rest_value' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'rest_api_init', [ $this, 'register_rebuild_stats_endpoint' ] );
		add_action( 'admin_notices', [ $this, 'maybe_show_truncation_notice' ] );
	}

	/**
	 * Warn on the settings screen when the calendar feed was cut short.
	 *
	 * The feed itself says so in X-WR-CALDESC, but nobody reads a subscribed
	 * calendar's description, so the site owner is told here as well.
	 */
	public function maybe_show_truncation_notice(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || 'blockendar_event_page_' . self::MENU_SLUG !== $screen->id ) {
			return;
		}

		$flag = get_transient( 'blockendar_ics_truncated' );

		if ( ! is_array( $flag ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of events the feed was limited to. */
					__( 'The calendar subscription feed was cut short at %d events. Subscribers are not seeing everything. Narrow the subscription window, or raise the limit with the blockendar_ics_max_events filter.', 'blockendar' ),
					(int) ( $flag['count'] ?? 0 )
				)
			)
		);
	}

	/**
	 * Add Settings submenu under the Events post type menu.
	 */
	public function add_menu_page(): void {
		add_submenu_page(
			'edit.php?post_type=blockendar_event',
			__( 'Blockendar Settings', 'blockendar' ),
			__( 'Settings', 'blockendar' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Render the mount point for the React SPA.
	 */
	public function render_page(): void {
		/*
		 * Defence in depth. add_submenu_page() already gates the menu entry on
		 * this capability and every endpoint the SPA talks to has its own
		 * permission_callback, but admin.php?page= URLs are directly reachable
		 * and a render callback should not assume it was only reached through
		 * the menu. The companion demo plugin's admin page does the same.
		 */
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		echo '<div id="blockendar-settings-root"></div>';
	}

	/**
	 * Register the blockendar_settings option with a full REST schema
	 * so the SPA can GET/POST via /wp/v2/settings.
	 */
	public function register_setting(): void {
		register_setting(
			'blockendar',
			self::OPTION_NAME,
			[
				'type'              => 'object',
				'description'       => 'Blockendar plugin settings.',
				'default'           => self::defaults(),
				'show_in_rest'      => [
					'schema' => [
						'type'       => 'object',
						'properties' => self::schema_properties(),
					],
				],
				'sanitize_callback' => [ $this, 'sanitize' ],
			]
		);
	}

	/**
	 * Give the settings endpoint a value it will accept.
	 *
	 * WordPress checks a setting against its schema before sending it, with
	 * nothing allowed beyond the listed properties, and sends null for one that
	 * does not fit. One key the schema has since dropped, or one value of the
	 * wrong kind, was enough to hide every setting from the settings page,
	 * which then showed the defaults and saved them over the real values.
	 *
	 * sanitize() keeps the known keys, fills the missing ones and casts the
	 * rest, so what it returns always fits. The stored option is not rewritten
	 * here; it is when the settings are next saved.
	 *
	 * @param mixed  $value Value another filter supplied, or null.
	 * @param string $name  Setting name.
	 * @return mixed The plugin's settings, or $value for every other setting.
	 */
	public function rest_value( mixed $value, string $name ): mixed {
		if ( self::OPTION_NAME !== $name ) {
			return $value;
		}

		$stored = get_option( self::OPTION_NAME );

		return is_array( $stored ) ? $this->sanitize( $stored ) : $value;
	}

	/**
	 * Enqueue the settings SPA assets — only on the Blockendar settings page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( string $hook ): void {
		if ( 'blockendar_event_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}

		$asset_file = BLOCKENDAR_DIR . 'build/admin/index.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'blockendar-settings',
			plugins_url( 'build/admin/index.js', BLOCKENDAR_FILE ),
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// See the note in BlockRegistrar: a hand-enqueued bundle needs its
		// translations wired up explicitly or every __() returns English.
		wp_set_script_translations(
			'blockendar-settings',
			'blockendar',
			BLOCKENDAR_DIR . 'languages'
		);

		wp_enqueue_style(
			'blockendar-settings',
			plugins_url( 'build/admin/style-index.css', BLOCKENDAR_FILE ),
			[ 'wp-components' ],
			$asset['version']
		);

		$raw_tz        = wp_timezone_string();
		$site_timezone = '' !== $raw_tz ? $raw_tz : 'UTC';

		// wp_add_inline_script() rather than wp_localize_script(): localisation casts
		// every scalar to a string, which would turn the booleans in defaults() into
		// "1"/"" before the settings UI reads them, and it is the wrong tool for a
		// REST nonce.
		$settings_data = [
			'restUrl'            => esc_url_raw( rest_url() ),
			'nonce'              => wp_create_nonce( 'wp_rest' ),
			'optionName'         => self::OPTION_NAME,
			'defaults'           => self::defaults(),
			'statsUrl'           => esc_url_raw( rest_url( 'blockendar/v1/settings/stats' ) ),
			'rebuildUrl'         => esc_url_raw( rest_url( 'blockendar/v1/index/rebuild' ) ),
			'version'            => BLOCKENDAR_VERSION,
			'siteTimezone'       => $site_timezone,
			'generalSettingsUrl' => esc_url( admin_url( 'options-general.php' ) ),
			// Token-free base URLs. The settings UI appends the token itself when
			// one is needed, so the field stays live as the token is edited.
			'feedUrl'            => \Blockendar\ICS\FeedUrl::build(),
			'feedUrlWebcal'      => \Blockendar\ICS\FeedUrl::build( [], true ),
		];

		wp_add_inline_script(
			'blockendar-settings',
			'window.blockendarSettings = ' . wp_json_encode( $settings_data ) . ';',
			'before'
		);
	}

	/**
	 * Register a lightweight stats endpoint used by the Performance section.
	 */
	public function register_rebuild_stats_endpoint(): void {
		register_rest_route(
			'blockendar/v1',
			'/settings/stats',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_stats' ],
				'permission_callback' => fn() => current_user_can( self::CAPABILITY ),
			]
		);
	}

	/**
	 * Return index stats for the Performance panel.
	 */
	public function get_stats(): \WP_REST_Response {
		$index   = new EventIndex();
		$builder = new IndexBuilder();

		$builder->resume_if_stalled();

		return new \WP_REST_Response(
			[
				'index_row_count'     => $index->get_total_row_count(),
				'last_rebuild'        => get_option( 'blockendar_last_index_rebuild', null ),
				'rebuild_in_progress' => $builder->is_rebuild_pending(),
				'db_version'          => get_option( 'blockendar_db_version', null ),
				'plugin_version'      => BLOCKENDAR_VERSION,
			]
		);
	}

	/**
	 * Sanitize incoming settings before save.
	 *
	 * @param mixed $raw Raw option value.
	 * @return array Sanitized settings merged with defaults.
	 */
	public function sanitize( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return self::defaults();
		}

		$d = self::defaults();

		// Each pair is validated together: a range whose end is not after its
		// start would give FullCalendar nothing to draw, so both reset.
		[ $slot_min, $slot_max ] = self::sanitize_time_range(
			$raw['calendar_slot_min_time'] ?? $d['calendar_slot_min_time'],
			$raw['calendar_slot_max_time'] ?? $d['calendar_slot_max_time'],
			$d['calendar_slot_min_time'],
			$d['calendar_slot_max_time']
		);

		[ $business_start, $business_end ] = self::sanitize_time_range(
			$raw['calendar_business_start'] ?? $d['calendar_business_start'],
			$raw['calendar_business_end'] ?? $d['calendar_business_end'],
			$d['calendar_business_start'],
			$d['calendar_business_end']
		);

		return [
			// General.
			'date_format'             => sanitize_text_field( $raw['date_format'] ?? $d['date_format'] ),
			'time_format'             => sanitize_text_field( $raw['time_format'] ?? $d['time_format'] ),
			'timezone_mode'           => in_array( $raw['timezone_mode'] ?? '', [ 'event', 'site' ], true )
				? $raw['timezone_mode'] : $d['timezone_mode'],

			// Calendar display.
			'calendar_default_view'   => self::sanitize_default_view( $raw['calendar_default_view'] ?? '', $d['calendar_default_view'] ),
			'calendar_first_day'      => max( 0, min( 6, (int) ( $raw['calendar_first_day'] ?? $d['calendar_first_day'] ) ) ),
			'calendar_slot_duration'  => sanitize_text_field( $raw['calendar_slot_duration'] ?? $d['calendar_slot_duration'] ),
			'calendar_slot_min_time'  => $slot_min,
			'calendar_slot_max_time'  => $slot_max,
			'calendar_all_day_slot'   => (bool) ( $raw['calendar_all_day_slot'] ?? $d['calendar_all_day_slot'] ),
			'calendar_business_hours' => (bool) ( $raw['calendar_business_hours'] ?? $d['calendar_business_hours'] ),
			'calendar_business_days'  => self::sanitize_weekdays( $raw['calendar_business_days'] ?? $d['calendar_business_days'], $d['calendar_business_days'] ),
			'calendar_business_start' => $business_start,
			'calendar_business_end'   => $business_end,

			// Permalinks.
			'events_slug'             => sanitize_title( $raw['events_slug'] ?? '' ) ?: $d['events_slug'],

			// Map.
			'map_default_zoom'        => max( 1, min( 20, (int) ( $raw['map_default_zoom'] ?? $d['map_default_zoom'] ) ) ),

			// Currency.
			'default_currency'        => strtoupper( sanitize_text_field( $raw['default_currency'] ?? $d['default_currency'] ) ),
			'currency_position'       => in_array( $raw['currency_position'] ?? '', [ 'before', 'after' ], true )
				? $raw['currency_position'] : $d['currency_position'],

			// Recurring events.
			'horizon_days'            => max( 30, min( 3650, (int) ( $raw['horizon_days'] ?? $d['horizon_days'] ) ) ),
			'max_instances'           => max( 1, min( 3650, (int) ( $raw['max_instances'] ?? $d['max_instances'] ) ) ),
			'subscribe_past_days'     => max( 0, min( 3650, (int) ( $raw['subscribe_past_days'] ?? $d['subscribe_past_days'] ) ) ),
			'subscribe_future_days'   => max( 1, min( 3650, (int) ( $raw['subscribe_future_days'] ?? $d['subscribe_future_days'] ) ) ),
			'generation_strategy'     => in_array( $raw['generation_strategy'] ?? '', [ 'on_save', 'cron' ], true )
				? $raw['generation_strategy'] : $d['generation_strategy'],

			// REST API.
			'rest_public'             => (bool) ( $raw['rest_public'] ?? $d['rest_public'] ),
			'rest_feed_token'         => self::sanitize_feed_token( $raw['rest_feed_token'] ?? '' ),
		];
	}

	/**
	 * Views the calendar block can open by default.
	 *
	 * The list view is the block's rolling 31-day custom view, not
	 * FullCalendar's listWeek: the two must agree or a site default has no
	 * toolbar button to light up.
	 */
	private const DEFAULT_VIEWS = [ 'dayGridMonth', 'timeGridWeek', 'timeGridDay', 'listNextMonth', 'multiMonthYear' ];

	/**
	 * Validate the default calendar view.
	 *
	 * Sites that saved a default before 2.3.0 could only pick `listWeek`,
	 * which the block never offered. It is read as the block's own list view
	 * so the settings page, the editor and the front end agree; the next
	 * save stores the mapped name.
	 *
	 * @param mixed  $value   Raw value.
	 * @param string $fallback Value used when the view is unknown.
	 */
	private static function sanitize_default_view( mixed $value, string $fallback ): string {
		if ( 'listWeek' === $value ) {
			return 'listNextMonth';
		}

		return in_array( $value, self::DEFAULT_VIEWS, true ) ? $value : $fallback;
	}

	/**
	 * Validate a time of day for FullCalendar.
	 *
	 * FullCalendar wants `HH:MM:SS`; anything else it silently turns into
	 * null, which would drop the option rather than report it. `24:00:00`
	 * is allowed because it is FullCalendar's own default for the last slot
	 * and the only way to say "the end of the day".
	 *
	 * @param mixed  $value    Raw value.
	 * @param string $fallback Value used when the time is unusable.
	 */
	private static function sanitize_time_of_day( mixed $value, string $fallback ): string {
		if ( ! is_string( $value ) || ! preg_match( '/^(\d{2}):(\d{2}):(\d{2})$/', $value, $m ) ) {
			return $fallback;
		}

		[ , $hours, $minutes, $seconds ] = array_map( 'intval', $m );

		if ( $minutes > 59 || $seconds > 59 || $hours > 24 ) {
			return $fallback;
		}

		if ( 24 === $hours && ( $minutes > 0 || $seconds > 0 ) ) {
			return $fallback;
		}

		return $value;
	}

	/**
	 * Validate a start and end time as a pair.
	 *
	 * `HH:MM:SS` strings compare correctly as strings, so an end that is not
	 * later than its start is caught without parsing. Either value failing
	 * on its own, or the pair being inverted, resets both to the defaults:
	 * a half-valid range is harder to reason about than none.
	 *
	 * @param mixed  $start         Raw start.
	 * @param mixed  $end           Raw end.
	 * @param string $default_start Default start.
	 * @param string $default_end   Default end.
	 * @return string[] Tuple of [ start, end ].
	 */
	private static function sanitize_time_range( mixed $start, mixed $end, string $default_start, string $default_end ): array {
		$start = self::sanitize_time_of_day( $start, '' );
		$end   = self::sanitize_time_of_day( $end, '' );

		if ( '' === $start || '' === $end || strcmp( $end, $start ) <= 0 ) {
			return [ $default_start, $default_end ];
		}

		return [ $start, $end ];
	}

	/**
	 * Validate a list of weekdays, 0 (Sunday) to 6.
	 *
	 * Accepts an array, as the settings screen sends, or a comma-separated
	 * string, as a query string or WP-CLI would. Members outside 0 to 6 are
	 * dropped rather than clamped, since clamping would invent a day the
	 * site never chose. A value that is not a list at all keeps the default.
	 * Always a list with no gaps, which is what the REST schema's `array`
	 * type requires.
	 *
	 * @param mixed $value    Raw value.
	 * @param int[] $fallback Default list.
	 * @return int[]
	 */
	private static function sanitize_weekdays( mixed $value, array $fallback ): array {
		if ( is_string( $value ) ) {
			if ( ! preg_match( '/^[\d,\s]*$/', $value ) ) {
				return $fallback;
			}

			$value = explode( ',', $value );
		}

		if ( ! is_array( $value ) ) {
			return $fallback;
		}

		$days = [];

		foreach ( $value as $day ) {
			if ( ! is_numeric( $day ) ) {
				continue;
			}

			$day = (int) $day;

			if ( $day >= 0 && $day <= 6 ) {
				$days[ $day ] = $day;
			}
		}

		return array_values( $days );
	}

	/**
	 * Minimum length accepted for a feed token.
	 *
	 * The generator produces 32 characters. This is the floor for a value typed
	 * in by hand, low enough not to reject a deliberate choice and high enough
	 * that the token is not guessable.
	 */
	private const MIN_TOKEN_LENGTH = 16;

	/**
	 * Clean a feed token, rejecting anything too short to be a credential.
	 *
	 * The token authenticates the calendar feed in place of a login, so a short
	 * or punctuation-laden value is worse than none: it reads as protection
	 * while being trivially guessable, and it travels in a URL where anything
	 * outside [A-Za-z0-9] risks being mangled by a client. A value that fails
	 * either test is cleared, which turns token access off rather than leaving
	 * a weak token in place.
	 *
	 * @param mixed $raw Submitted token value.
	 */
	private static function sanitize_feed_token( mixed $raw ): string {
		$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $raw );

		if ( null === $token || strlen( $token ) < self::MIN_TOKEN_LENGTH ) {
			return '';
		}

		return $token;
	}

	/**
	 * The URL base for events, e.g. "events" in /events/my-event/ and
	 * /events/type/exhibit/. Read by the post type and taxonomies when they
	 * register, so a change here needs a rewrite flush (Upgrader handles it).
	 */
	public static function events_slug(): string {
		$settings = get_option( self::OPTION_NAME );
		$slug     = is_array( $settings ) ? sanitize_title( (string) ( $settings['events_slug'] ?? '' ) ) : '';

		return '' !== $slug ? $slug : 'events';
	}

	/**
	 * Read one setting, falling back to its default.
	 *
	 * Every setting lives inside the single OPTION_NAME array. Reading one
	 * with get_option( 'blockendar_<key>' ) looks plausible but always returns
	 * the fallback, because no such standalone option is ever written — that
	 * is how the recurrence horizon silently ignored its own setting. Go
	 * through here instead of reaching for the option directly.
	 *
	 * @param string $key Setting key, as it appears in defaults().
	 * @return mixed The stored value, the default, or null for an unknown key.
	 */
	public static function get( string $key ): mixed {
		$defaults = self::defaults();

		if ( ! array_key_exists( $key, $defaults ) ) {
			return null;
		}

		$settings = get_option( self::OPTION_NAME );
		$settings = is_array( $settings ) ? $settings : [];

		// A key saved as an empty string means "unset" for every setting that
		// has a meaningful default, so fall through to the default rather than
		// handing back ''.
		if ( ! isset( $settings[ $key ] ) || '' === $settings[ $key ] ) {
			return $defaults[ $key ];
		}

		return $settings[ $key ];
	}

	/**
	 * Default settings values.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return [
			'date_format'             => get_option( 'date_format', 'F j, Y' ),
			'time_format'             => get_option( 'time_format', 'g:i a' ),
			'timezone_mode'           => 'site',
			'calendar_default_view'   => 'dayGridMonth',
			// WordPress's own "Week Starts On", until the site chooses otherwise.
			'calendar_first_day'      => max( 0, min( 6, (int) get_option( 'start_of_week', 0 ) ) ),
			'calendar_slot_duration'  => '00:30:00',
			// FullCalendar's own defaults: the whole day, with the all-day row.
			'calendar_slot_min_time'  => '00:00:00',
			'calendar_slot_max_time'  => '24:00:00',
			'calendar_all_day_slot'   => true,
			'calendar_business_hours' => false,
			'calendar_business_days'  => [ 1, 2, 3, 4, 5 ],
			'calendar_business_start' => '09:00:00',
			'calendar_business_end'   => '17:00:00',
			'events_slug'             => 'events',
			'map_default_zoom'        => 14,
			'default_currency'        => 'USD',
			'currency_position'       => 'before',
			'horizon_days'            => 365,
			'max_instances'           => 3650,
			'subscribe_past_days'     => 30,
			'subscribe_future_days'   => 365,
			'generation_strategy'     => 'on_save',
			'rest_public'             => true,
			'rest_feed_token'         => '',
		];
	}

	/**
	 * REST schema properties for register_setting().
	 *
	 * @return array
	 */
	private static function schema_properties(): array {
		return [
			'date_format'             => [ 'type' => 'string' ],
			'time_format'             => [ 'type' => 'string' ],
			'timezone_mode'           => [
				'type' => 'string',
				'enum' => [ 'event', 'site' ],
			],
			'calendar_default_view'   => [ 'type' => 'string' ],
			'calendar_first_day'      => [ 'type' => 'integer' ],
			'calendar_slot_duration'  => [ 'type' => 'string' ],
			'calendar_slot_min_time'  => [ 'type' => 'string' ],
			'calendar_slot_max_time'  => [ 'type' => 'string' ],
			'calendar_all_day_slot'   => [ 'type' => 'boolean' ],
			'calendar_business_hours' => [ 'type' => 'boolean' ],
			'calendar_business_days'  => [
				'type'  => 'array',
				'items' => [
					'type' => 'integer',
					'enum' => [ 0, 1, 2, 3, 4, 5, 6 ],
				],
			],
			'calendar_business_start' => [ 'type' => 'string' ],
			'calendar_business_end'   => [ 'type' => 'string' ],
			'events_slug'             => [ 'type' => 'string' ],
			'map_default_zoom'        => [ 'type' => 'integer' ],
			'default_currency'        => [ 'type' => 'string' ],
			'currency_position'       => [
				'type' => 'string',
				'enum' => [ 'before', 'after' ],
			],
			'horizon_days'            => [ 'type' => 'integer' ],
			'max_instances'           => [ 'type' => 'integer' ],
			'subscribe_past_days'     => [ 'type' => 'integer' ],
			'subscribe_future_days'   => [ 'type' => 'integer' ],
			'generation_strategy'     => [
				'type' => 'string',
				'enum' => [ 'on_save', 'cron' ],
			],
			'rest_public'             => [ 'type' => 'boolean' ],
			'rest_feed_token'         => [ 'type' => 'string' ],
		];
	}
}
