<?php
/**
 * Registers all Blockendar blocks and the editor sidebar script.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Blockendar\CPT\EventPostType;

/**
 * Registers blocks from their block.json metadata files and enqueues
 * the editor sidebar panel script for the blockendar_event post type.
 */
class BlockRegistrar {

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'register_blocks' ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_panels' ] );
	}

	/**
	 * Register every block from its block.json file.
	 */
	public function register_blocks(): void {
		$blocks_dir = BLOCKENDAR_DIR . 'build/blocks/';

		if ( ! is_dir( $blocks_dir ) ) {
			return;
		}

		foreach ( glob( $blocks_dir . '*', GLOB_ONLYDIR ) as $block_dir ) {
			register_block_type( $block_dir );
		}
	}

	/**
	 * Enqueue the editor sidebar panels only on blockendar_event edit screens.
	 */
	public function enqueue_editor_panels(): void {
		$screen = get_current_screen();

		if ( ! $screen || EventPostType::POST_TYPE !== $screen->post_type ) {
			return;
		}

		$asset_file = BLOCKENDAR_DIR . 'build/editor/index.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'blockendar-editor-panels',
			plugins_url( 'build/editor/index.js', BLOCKENDAR_FILE ),
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// Pass REST namespace and nonce to the editor panels.
		wp_localize_script(
			'blockendar-editor-panels',
			'blockendarEditor',
			[
				'restUrl'      => esc_url_raw( rest_url( 'blockendar/v1' ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'postType'     => EventPostType::POST_TYPE,
				'timezones'    => $this->get_timezone_list(),
				'currencies'   => $this->get_currency_list(),
				'siteTimezone' => $this->get_site_iana_timezone(),
				'is12Hour'     => $this->is_12_hour_format(),
			]
		);

		wp_enqueue_style(
			'blockendar-editor-panels',
			plugins_url( 'build/editor/index.css', BLOCKENDAR_FILE ),
			[],
			$asset['version']
		);
	}

	/**
	 * Return the site's IANA timezone identifier.
	 *
	 * wp_timezone_string() can return a UTC-offset string like '+05:30' when
	 * the site is configured with a manual UTC offset rather than a named timezone.
	 * Those offset strings are not in DateTimeZone::listIdentifiers(), so the
	 * editor select would fall back to the first alphabetical entry (Africa/Abidjan).
	 * We fall back to 'UTC' in that case so the value is always selectable.
	 */
	private function get_site_iana_timezone(): string {
		$tz = get_option( 'timezone_string', '' );
		return ( is_string( $tz ) && '' !== $tz ) ? $tz : 'UTC';
	}

	/**
	 * Return true if the saved time_format setting uses 12-hour notation.
	 * Falls back to the WordPress core time_format option.
	 */
	private function is_12_hour_format(): bool {
		$settings    = get_option( 'blockendar_settings', [] );
		$time_format = is_array( $settings ) && ! empty( $settings['time_format'] )
			? $settings['time_format']
			: get_option( 'time_format', 'g:i a' );

		// PHP 'g' = 12-hour no leading zero, 'h' = 12-hour with leading zero.
		return str_contains( $time_format, 'g' ) || str_contains( $time_format, 'h' );
	}

	/**
	 * Return a flat list of IANA timezone identifiers for the timezone selector.
	 *
	 * @return string[]
	 */
	private function get_timezone_list(): array {
		return \DateTimeZone::listIdentifiers();
	}

	/**
	 * Return common ISO 4217 currency codes for the currency selector.
	 *
	 * @return string[]
	 */
	private function get_currency_list(): array {
		return [
			'USD',
			'EUR',
			'GBP',
			'CAD',
			'AUD',
			'JPY',
			'CHF',
			'CNY',
			'INR',
			'MXN',
			'BRL',
			'KRW',
			'SEK',
			'NOK',
			'DKK',
			'NZD',
			'SGD',
			'HKD',
			'ZAR',
			'AED',
			'PLN',
			'CZK',
			'HUF',
			'THB',
		];
	}
}
