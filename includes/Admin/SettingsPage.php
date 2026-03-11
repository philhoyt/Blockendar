<?php
/**
 * Plugin settings page registration.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\Admin;

use Blockendar\DB\EventIndex;

/**
 * Registers the Settings > Blockendar admin page and the blockendar_settings option.
 *
 * The page is a React SPA (src/admin/SettingsApp.jsx). Settings are stored as a
 * single serialised option and exposed to the REST API so the SPA can read/write
 * via wp.apiFetch without a custom endpoint.
 */
class SettingsPage {

	const OPTION_NAME   = 'blockendar_settings';
	const MENU_SLUG     = 'blockendar-settings';
	const CAPABILITY    = 'manage_options';

	/**
	 * Attach hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu',  [ $this, 'add_menu_page' ] );
		add_action( 'init',        [ $this, 'register_setting' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'rest_api_init', [ $this, 'register_rebuild_stats_endpoint' ] );
	}

	/**
	 * Add Settings > Blockendar submenu page.
	 */
	public function add_menu_page(): void {
		add_options_page(
			__( 'Blockendar Settings', 'blockendar' ),
			__( 'Blockendar', 'blockendar' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Render the mount point for the React SPA.
	 */
	public function render_page(): void {
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
				'type'         => 'object',
				'description'  => 'Blockendar plugin settings.',
				'default'      => self::defaults(),
				'show_in_rest' => [
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
	 * Enqueue the settings SPA assets — only on the Blockendar settings page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( string $hook ): void {
		if ( 'settings_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}

		$asset_file = BLOCKENDAR_DIR . 'build/admin/index.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'blockendar-settings',
			BLOCKENDAR_URL . 'build/admin/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style(
			'blockendar-settings',
			BLOCKENDAR_URL . 'build/admin/index.css',
			[ 'wp-components' ],
			$asset['version']
		);

		wp_localize_script( 'blockendar-settings', 'blockendarSettings', [
			'restUrl'    => esc_url_raw( rest_url() ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'optionName' => self::OPTION_NAME,
			'defaults'   => self::defaults(),
			'statsUrl'   => esc_url_raw( rest_url( 'blockendar/v1/settings/stats' ) ),
			'rebuildUrl' => esc_url_raw( rest_url( 'blockendar/v1/index/rebuild' ) ),
			'version'    => BLOCKENDAR_VERSION,
		] );
	}

	/**
	 * Register a lightweight stats endpoint used by the Performance section.
	 */
	public function register_rebuild_stats_endpoint(): void {
		register_rest_route( 'blockendar/v1', '/settings/stats', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_stats' ],
			'permission_callback' => fn() => current_user_can( self::CAPABILITY ),
		] );
	}

	/**
	 * Return index stats for the Performance panel.
	 */
	public function get_stats(): \WP_REST_Response {
		$index = new EventIndex();

		return new \WP_REST_Response( [
			'index_row_count'    => $index->get_total_row_count(),
			'last_rebuild'       => get_option( 'blockendar_last_index_rebuild', null ),
			'db_version'         => get_option( 'blockendar_db_version', null ),
			'plugin_version'     => BLOCKENDAR_VERSION,
		] );
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

		return [
			// General.
			'date_format'          => sanitize_text_field( $raw['date_format']          ?? $d['date_format'] ),
			'time_format'          => sanitize_text_field( $raw['time_format']          ?? $d['time_format'] ),
			'timezone_mode'        => in_array( $raw['timezone_mode'] ?? '', [ 'event', 'site' ], true )
				? $raw['timezone_mode'] : $d['timezone_mode'],

			// Calendar display.
			'calendar_default_view' => in_array(
				$raw['calendar_default_view'] ?? '',
				[ 'dayGridMonth', 'timeGridWeek', 'timeGridDay', 'listWeek' ], true
			) ? $raw['calendar_default_view'] : $d['calendar_default_view'],
			'calendar_first_day'   => max( 0, min( 6, (int) ( $raw['calendar_first_day'] ?? $d['calendar_first_day'] ) ) ),
			'calendar_slot_duration' => sanitize_text_field( $raw['calendar_slot_duration'] ?? $d['calendar_slot_duration'] ),

			// Permalinks.
			'events_slug'          => sanitize_title( $raw['events_slug'] ?? $d['events_slug'] ),

			// Map.
			'map_provider'         => in_array( $raw['map_provider'] ?? '', [ 'openstreetmap', 'google' ], true )
				? $raw['map_provider'] : $d['map_provider'],
			'google_maps_api_key'  => sanitize_text_field( $raw['google_maps_api_key'] ?? '' ),
			'map_default_zoom'     => max( 1, min( 20, (int) ( $raw['map_default_zoom'] ?? $d['map_default_zoom'] ) ) ),

			// Currency.
			'default_currency'     => strtoupper( sanitize_text_field( $raw['default_currency'] ?? $d['default_currency'] ) ),
			'currency_position'    => in_array( $raw['currency_position'] ?? '', [ 'before', 'after' ], true )
				? $raw['currency_position'] : $d['currency_position'],

			// Recurring events.
			'horizon_days'         => max( 30, min( 3650, (int) ( $raw['horizon_days']  ?? $d['horizon_days'] ) ) ),
			'max_instances'        => max( 1, min( 3650, (int) ( $raw['max_instances']  ?? $d['max_instances'] ) ) ),
			'generation_strategy'  => in_array( $raw['generation_strategy'] ?? '', [ 'on_save', 'cron' ], true )
				? $raw['generation_strategy'] : $d['generation_strategy'],

			// REST API.
			'rest_public'          => (bool) ( $raw['rest_public']     ?? $d['rest_public'] ),
			'rest_feed_token'      => sanitize_text_field( $raw['rest_feed_token'] ?? '' ),
		];
	}

	/**
	 * Default settings values.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return [
			'date_format'            => get_option( 'date_format', 'F j, Y' ),
			'time_format'            => get_option( 'time_format', 'g:i a' ),
			'timezone_mode'          => 'event',
			'calendar_default_view'  => 'dayGridMonth',
			'calendar_first_day'     => 0,
			'calendar_slot_duration' => '00:30:00',
			'events_slug'            => 'events',
			'map_provider'           => 'openstreetmap',
			'google_maps_api_key'    => '',
			'map_default_zoom'       => 14,
			'default_currency'       => 'USD',
			'currency_position'      => 'before',
			'horizon_days'           => 365,
			'max_instances'          => 3650,
			'generation_strategy'    => 'on_save',
			'rest_public'            => true,
			'rest_feed_token'        => '',
		];
	}

	/**
	 * REST schema properties for register_setting().
	 *
	 * @return array
	 */
	private static function schema_properties(): array {
		return [
			'date_format'            => [ 'type' => 'string' ],
			'time_format'            => [ 'type' => 'string' ],
			'timezone_mode'          => [ 'type' => 'string', 'enum' => [ 'event', 'site' ] ],
			'calendar_default_view'  => [ 'type' => 'string' ],
			'calendar_first_day'     => [ 'type' => 'integer' ],
			'calendar_slot_duration' => [ 'type' => 'string' ],
			'events_slug'            => [ 'type' => 'string' ],
			'map_provider'           => [ 'type' => 'string', 'enum' => [ 'openstreetmap', 'google' ] ],
			'google_maps_api_key'    => [ 'type' => 'string' ],
			'map_default_zoom'       => [ 'type' => 'integer' ],
			'default_currency'       => [ 'type' => 'string' ],
			'currency_position'      => [ 'type' => 'string', 'enum' => [ 'before', 'after' ] ],
			'horizon_days'           => [ 'type' => 'integer' ],
			'max_instances'          => [ 'type' => 'integer' ],
			'generation_strategy'    => [ 'type' => 'string', 'enum' => [ 'on_save', 'cron' ] ],
			'rest_public'            => [ 'type' => 'boolean' ],
			'rest_feed_token'        => [ 'type' => 'string' ],
		];
	}
}
