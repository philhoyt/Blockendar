<?php
/**
 * Main plugin loader.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar;

use Blockendar\CPT\EventPostType;
use Blockendar\Taxonomy\EventType;
use Blockendar\Taxonomy\EventTag;
use Blockendar\Taxonomy\Venue;
use Blockendar\Meta\EventMeta;
use Blockendar\Meta\VenueMeta;
use Blockendar\DB\Schema;
use Blockendar\DB\IndexBuilder;
use Blockendar\Recurrence\Generator;
use Blockendar\Recurrence\Cron;
use Blockendar\REST\EventsController;
use Blockendar\REST\CalendarController;
use Blockendar\REST\VenuesController;
use Blockendar\Blocks\BlockRegistrar;
use Blockendar\Blocks\TemplateRegistrar;
use Blockendar\Admin\SettingsPage;

/**
 * Bootstraps all plugin components.
 */
class Plugin {

	/**
	 * Attach all component hooks to WordPress.
	 */
	public function boot(): void {
		// Run schema upgrades if needed.
		Schema::maybe_upgrade();

		// Register post types, taxonomies, and meta.
		( new EventPostType() )->register();
		( new EventType() )->register();
		( new EventTag() )->register();
		( new Venue() )->register();
		( new EventMeta() )->register();
		( new VenueMeta() )->register();

		// Keep the event index in sync with post saves/deletes.
		( new IndexBuilder() )->register();

		// Recurrence engine — handles the blockendar_generate_recurrence_index action.
		( new Generator() )->register();

		// Daily cron job to roll the recurrence horizon forward.
		( new Cron() )->register();

		// REST API.
		( new EventsController() )->register();
		( new CalendarController() )->register();
		( new VenuesController() )->register();

		// Block registration + editor sidebar enqueue.
		( new BlockRegistrar() )->register();

		// Block template for single event pages.
		( new TemplateRegistrar() )->register();

		// Admin settings page.
		( new SettingsPage() )->register();

		// Register block category.
		add_filter( 'block_categories_all', [ $this, 'register_block_category' ] );
	}

	/**
	 * Add a Blockendar block inserter category.
	 *
	 * @param array[] $categories Existing categories.
	 * @return array[]
	 */
	public function register_block_category( array $categories ): array {
		return array_merge(
			[
				[
					'slug'  => 'blockendar',
					'title' => __( 'Blockendar', 'blockendar' ),
					'icon'  => 'calendar-alt',
				],
			],
			$categories
		);
	}
}
