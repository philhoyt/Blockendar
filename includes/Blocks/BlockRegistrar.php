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
use Blockendar\Meta\EventMeta;

/**
 * Registers every block from the build-time blocks manifest and enqueues
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
	 * Register every block from build/blocks-manifest.php.
	 *
	 * wp-scripts writes the metadata of every built block.json into that one
	 * PHP array (the --blocks-manifest flag on the build and start scripts),
	 * keyed by block directory name. Registering it as a metadata collection
	 * lets core take each block's metadata from memory, where OPcache keeps
	 * it, instead of reading and decoding sixteen JSON files on every request.
	 * The built block.json files stay where they are: core still resolves the
	 * file: asset paths relative to them.
	 *
	 * The paths are built from BLOCKENDAR_DIR rather than WP_PLUGIN_DIR because
	 * __FILE__ resolves symlinks. On a dev site that symlinks the checkout the
	 * collection registers under the real path, and every later lookup has to
	 * use the same one.
	 */
	public function register_blocks(): void {
		$manifest = BLOCKENDAR_DIR . 'build/blocks-manifest.php';

		// Core raises _doing_it_wrong() twice for a missing manifest: once
		// registering the collection, once listing its blocks. An unbuilt
		// checkout should register nothing, quietly, as it always has.
		if ( ! file_exists( $manifest ) ) {
			return;
		}

		wp_register_block_types_from_metadata_collection(
			BLOCKENDAR_DIR . 'build/blocks',
			$manifest
		);
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

		/*
		 * Without this every __() call in the editor bundle returns its
		 * English source string, however complete the translation is: the
		 * JS i18n runtime only has a catalogue for handles that were
		 * registered for one. Blocks registered from block.json get this
		 * automatically from their "textdomain" field; a hand-enqueued
		 * bundle like this one does not.
		 */
		wp_set_script_translations(
			'blockendar-editor-panels',
			'blockendar',
			BLOCKENDAR_DIR . 'languages'
		);

		// Pass REST namespace and nonce to the editor panels.
		// wp_add_inline_script() rather than wp_localize_script(): localisation casts
		// every scalar to a string, and it is the wrong tool for a REST nonce.
		$timezones   = self::editor_timezones();
		$editor_data = [
			'restUrl'      => esc_url_raw( rest_url( 'blockendar/v1' ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'postType'     => EventPostType::POST_TYPE,
			'timezones'    => $timezones['options'],
			'siteTimezone' => $timezones['site'],
			'is12Hour'     => $this->is_12_hour_format(),
			'dateFormat'   => $this->get_date_format(),
			'timeFormat'   => $this->get_time_format(),
			'statuses'     => self::editor_statuses(),
		];

		wp_add_inline_script(
			'blockendar-editor-panels',
			'window.blockendarEditor = ' . wp_json_encode( $editor_data ) . ';',
			'before'
		);

		wp_enqueue_style(
			'blockendar-editor-panels',
			plugins_url( 'build/editor/index.css', BLOCKENDAR_FILE ),
			[],
			$asset['version']
		);
	}

	/**
	 * The timezone a new event is seeded with, and the list the picker offers.
	 *
	 * A site set to a manual offset ("UTC+5:30") has no timezone_string, and
	 * wp_timezone_string() answers "+05:30". That offset is the site's timezone
	 * and is what a new event has to be seeded with: handing the editor "UTC"
	 * in its place indexed every new event at the wrong instant. An offset is
	 * not one of PHP's identifiers, so it is added to the list for the picker
	 * to be able to show it.
	 *
	 * @return array{site: string, options: string[]}
	 */
	public static function editor_timezones(): array {
		$site    = wp_timezone_string();
		$options = \DateTimeZone::listIdentifiers();

		// What wp_timezone_string() gives a site that has never set a timezone.
		if ( '+00:00' === $site ) {
			$site = 'UTC';
		}

		if ( ! in_array( $site, $options, true ) ) {
			array_unshift( $options, $site );
		}

		return [
			'site'    => $site,
			'options' => $options,
		];
	}

	/**
	 * The statuses the Event Details panel offers, in SelectControl's shape.
	 *
	 * @return array<int, array{value: string, label: string}>
	 */
	public static function editor_statuses(): array {
		$options = [];

		foreach ( EventMeta::statuses() as $value => $label ) {
			$options[] = [
				'value' => $value,
				'label' => $label,
			];
		}

		return $options;
	}

	/**
	 * Return the active date format string (Blockendar setting or WP core fallback).
	 */
	private function get_date_format(): string {
		$settings = get_option( 'blockendar_settings', [] );
		return ( is_array( $settings ) && ! empty( $settings['date_format'] ) )
			? $settings['date_format']
			: get_option( 'date_format', 'F j, Y' );
	}

	/**
	 * Return the active time format string (Blockendar setting or WP core fallback).
	 */
	private function get_time_format(): string {
		$settings = get_option( 'blockendar_settings', [] );
		return ( is_array( $settings ) && ! empty( $settings['time_format'] ) )
			? $settings['time_format']
			: get_option( 'time_format', 'g:i a' );
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
}
