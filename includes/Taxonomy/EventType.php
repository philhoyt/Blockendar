<?php
/**
 * Event Type taxonomy registration.
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
 * Hierarchical taxonomy for categorising events (like post categories).
 * Term meta stores a colour for calendar display.
 */
class EventType {

	const TAXONOMY = 'event_type';

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
			'name'              => _x( 'Event Types', 'taxonomy general name', 'blockendar' ),
			'singular_name'     => _x( 'Event Type', 'taxonomy singular name', 'blockendar' ),
			'search_items'      => __( 'Search Event Types', 'blockendar' ),
			'all_items'         => __( 'All Event Types', 'blockendar' ),
			'parent_item'       => __( 'Parent Event Type', 'blockendar' ),
			'parent_item_colon' => __( 'Parent Event Type:', 'blockendar' ),
			'edit_item'         => __( 'Edit Event Type', 'blockendar' ),
			'update_item'       => __( 'Update Event Type', 'blockendar' ),
			'add_new_item'      => __( 'Add New Event Type', 'blockendar' ),
			'new_item_name'     => __( 'New Event Type Name', 'blockendar' ),
			'menu_name'         => __( 'Event Types', 'blockendar' ),
		];

		$args = [
			'labels'            => $labels,
			'hierarchical'      => true,
			'public'            => true,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'rest_base'         => 'event-types',
			'rewrite'           => [
				'slug'       => 'events/type',
				'with_front' => false,
			],
		];

		register_taxonomy( self::TAXONOMY, EventPostType::POST_TYPE, $args );
	}

	/**
	 * Explicitly register top-priority rewrite rules for the events/type/* path.
	 *
	 * The taxonomy slug contains a slash which can lose the race against the
	 * CPT's own rewrite rules. Adding with 'top' priority guarantees they match
	 * first, before WordPress evaluates the CPT single-post rules.
	 */
	public function add_rewrite_rules(): void {
		add_rewrite_rule(
			'^events/type/([^/]+)/page/([0-9]{1,})/?$',
			'index.php?event_type=$matches[1]&paged=$matches[2]',
			'top'
		);
		add_rewrite_rule(
			'^events/type/([^/]+)/?$',
			'index.php?event_type=$matches[1]',
			'top'
		);
	}
}
