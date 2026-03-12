<?php
/**
 * Event Tag taxonomy registration.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\Taxonomy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Blockendar\CPT\EventPostType;

/**
 * Non-hierarchical flat taxonomy for ad-hoc event tagging.
 */
class EventTag {

	const TAXONOMY = 'event_tag';

	/**
	 * Attach hooks.
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'register_taxonomy' ] );
		add_action( 'init', [ $this, 'add_rewrite_rules' ], 20 );
	}

	/**
	 * Register the taxonomy.
	 */
	public function register_taxonomy(): void {
		$labels = [
			'name'                       => _x( 'Event Tags', 'taxonomy general name', 'blockendar' ),
			'singular_name'              => _x( 'Event Tag', 'taxonomy singular name', 'blockendar' ),
			'search_items'               => __( 'Search Event Tags', 'blockendar' ),
			'popular_items'              => __( 'Popular Event Tags', 'blockendar' ),
			'all_items'                  => __( 'All Event Tags', 'blockendar' ),
			'edit_item'                  => __( 'Edit Event Tag', 'blockendar' ),
			'update_item'                => __( 'Update Event Tag', 'blockendar' ),
			'add_new_item'               => __( 'Add New Event Tag', 'blockendar' ),
			'new_item_name'              => __( 'New Event Tag Name', 'blockendar' ),
			'separate_items_with_commas' => __( 'Separate event tags with commas', 'blockendar' ),
			'add_or_remove_items'        => __( 'Add or remove event tags', 'blockendar' ),
			'choose_from_most_used'      => __( 'Choose from the most used event tags', 'blockendar' ),
			'not_found'                  => __( 'No event tags found.', 'blockendar' ),
			'menu_name'                  => __( 'Event Tags', 'blockendar' ),
		];

		$args = [
			'labels'            => $labels,
			'hierarchical'      => false,
			'public'            => true,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'rest_base'         => 'event-tags',
			'rewrite'           => [
				'slug'       => 'events/tag',
				'with_front' => false,
			],
		];

		register_taxonomy( self::TAXONOMY, EventPostType::POST_TYPE, $args );
	}

	/**
	 * Explicitly register top-priority rewrite rules for the events/tag/* path.
	 */
	public function add_rewrite_rules(): void {
		add_rewrite_rule(
			'^events/tag/([^/]+)/page/([0-9]{1,})/?$',
			'index.php?event_tag=$matches[1]&paged=$matches[2]',
			'top'
		);
		add_rewrite_rule(
			'^events/tag/([^/]+)/?$',
			'index.php?event_tag=$matches[1]',
			'top'
		);
	}
}
