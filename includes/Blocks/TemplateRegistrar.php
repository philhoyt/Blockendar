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
	 */
	public function register_templates(): void {
		if ( ! function_exists( 'register_block_template' ) ) {
			return;
		}

		register_block_template(
			'blockendar//single-blockendar_event',
			[
				'title'       => __( 'Single Event', 'blockendar' ),
				'description' => __( 'Displays a single event with date, venue, description, and related events.', 'blockendar' ),
				'content'     => (string) file_get_contents( BLOCKENDAR_DIR . 'templates/single-blockendar_event.html' ),
			]
		);

		register_block_template(
			'blockendar//archive-blockendar_event',
			[
				'title'       => __( 'Events Archive', 'blockendar' ),
				'description' => __( 'Displays all events in a calendar view.', 'blockendar' ),
				'content'     => (string) file_get_contents( BLOCKENDAR_DIR . 'templates/archive-blockendar_event.html' ),
			]
		);

		register_block_template(
			'blockendar//taxonomy-event_type',
			[
				'title'       => __( 'Event Type Archive', 'blockendar' ),
				'description' => __( 'Displays a calendar filtered to a single event type.', 'blockendar' ),
				'content'     => (string) file_get_contents( BLOCKENDAR_DIR . 'templates/taxonomy-event_type.html' ),
			]
		);
	}
}
