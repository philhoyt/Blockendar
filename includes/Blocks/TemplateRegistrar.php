<?php
/**
 * Block template registration for Blockendar.
 *
 * Requires WordPress 6.7+ (register_block_template).
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Blockendar\Migration\TaxonomyPrefixMigration;

/**
 * Registers plugin block templates via register_block_template().
 */
class TemplateRegistrar {

	/**
	 * Attach hooks.
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'register_templates' ] );
	}

	/**
	 * Register all plugin templates.
	 *
	 * A theme file still named after a pre-2.0.0 taxonomy is served under the
	 * new slug from here, replacing the plugin default of the same slug, so
	 * the design keeps applying; see legacy_theme_templates().
	 */
	public function register_templates(): void {
		if ( ! function_exists( 'register_block_template' ) ) {
			return;
		}

		$templates = [
			'single-blockendar_event'        => [
				'title'       => __( 'Single Event', 'blockendar' ),
				'description' => __( 'Displays a single event with date, venue, description, and related events.', 'blockendar' ),
				'content'     => (string) file_get_contents( BLOCKENDAR_DIR . 'templates/single-blockendar_event.html' ),
			],
			'archive-blockendar_event'       => [
				'title'       => __( 'Events Archive', 'blockendar' ),
				'description' => __( 'Displays all events in a calendar view.', 'blockendar' ),
				'content'     => (string) file_get_contents( BLOCKENDAR_DIR . 'templates/archive-blockendar_event.html' ),
			],
			'taxonomy-blockendar_event_type' => [
				'title'       => __( 'Event Type Archive', 'blockendar' ),
				'description' => __( 'Displays a calendar filtered to a single event type.', 'blockendar' ),
				'content'     => (string) file_get_contents( BLOCKENDAR_DIR . 'templates/taxonomy-blockendar_event_type.html' ),
			],
		];

		foreach ( $this->legacy_theme_templates() as $slug => $override ) {
			$templates[ $slug ] = array_merge( $templates[ $slug ] ?? [ 'title' => $slug ], $override );
		}

		foreach ( $templates as $slug => $args ) {
			register_block_template( 'blockendar//' . $slug, $args );
		}
	}

	/**
	 * Theme template files still named after the pre-2.0.0 taxonomies.
	 *
	 * A theme template is matched by its file name, and WordPress now asks for
	 * `taxonomy-blockendar_event_type`; a theme's `taxonomy-event_type.html`
	 * would never match again and the archive would fall back to the plugin
	 * default — a design lost without a word. The migration renames Site
	 * Editor copies in the database but cannot rename files, so each such file
	 * is served under its new slug as a plugin template: below a theme file at
	 * the new name and any Site Editor customisation, exactly where the theme
	 * file itself would sit once renamed. Per-term files
	 * (`taxonomy-event_type-concerts.html`) are carried the same way. The
	 * child theme's file wins over the parent's, as it does for the theme
	 * itself.
	 *
	 * @return array<string, array<string, string>> New slug => title, description and content.
	 */
	private function legacy_theme_templates(): array {
		$folder = get_block_theme_folders()['wp_template'] ?? 'templates';
		$dirs   = array_unique( [ get_stylesheet_directory(), get_template_directory() ] );
		$found  = [];

		foreach ( $dirs as $dir ) {
			foreach ( TaxonomyPrefixMigration::MAP as $old => $new ) {
				foreach ( glob( "{$dir}/{$folder}/taxonomy-{$old}*.html" ) ?: [] as $file ) {
					$rest = substr( basename( $file, '.html' ), strlen( "taxonomy-{$old}" ) );

					// taxonomy-event_typography.html belongs to someone else.
					if ( '' !== $rest && '-' !== $rest[0] ) {
						continue;
					}

					$slug = "taxonomy-{$new}{$rest}";

					if ( isset( $found[ $slug ] ) ) {
						continue;
					}

					foreach ( $dirs as $other ) {
						if ( file_exists( "{$other}/{$folder}/{$slug}.html" ) ) {
							continue 2; // The theme has caught up; its file wins on its own.
						}
					}

					$relative = $folder . '/' . basename( $file );

					$found[ $slug ] = [
						'description' => sprintf(
							/* translators: 1: path of the template file inside the theme, 2: the file name it should have now. */
							__( 'Served from your theme’s %1$s, which is named after a taxonomy renamed in Blockendar 2.0.0. Rename that file to %2$s to edit it as a theme template again.', 'blockendar' ),
							$relative,
							$slug . '.html'
						),
						'content'     => (string) file_get_contents( $file ),
					];
				}
			}
		}

		return $found;
	}
}
