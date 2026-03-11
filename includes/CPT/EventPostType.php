<?php
/**
 * Custom post type registration for blockendar_event.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\CPT;

/**
 * Registers the blockendar_event CPT.
 */
class EventPostType {

	const POST_TYPE = 'blockendar_event';

	/**
	 * Attach hooks.
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'register_post_type' ] );
	}

	/**
	 * Register the CPT.
	 */
	public function register_post_type(): void {
		$labels = [
			'name'                  => _x( 'Events', 'post type general name', 'blockendar' ),
			'singular_name'         => _x( 'Event', 'post type singular name', 'blockendar' ),
			'add_new'               => __( 'Add New', 'blockendar' ),
			'add_new_item'          => __( 'Add New Event', 'blockendar' ),
			'edit_item'             => __( 'Edit Event', 'blockendar' ),
			'new_item'              => __( 'New Event', 'blockendar' ),
			'view_item'             => __( 'View Event', 'blockendar' ),
			'view_items'            => __( 'View Events', 'blockendar' ),
			'search_items'          => __( 'Search Events', 'blockendar' ),
			'not_found'             => __( 'No events found.', 'blockendar' ),
			'not_found_in_trash'    => __( 'No events found in Trash.', 'blockendar' ),
			'all_items'             => __( 'All Events', 'blockendar' ),
			'archives'              => __( 'Event Archives', 'blockendar' ),
			'attributes'            => __( 'Event Attributes', 'blockendar' ),
			'insert_into_item'      => __( 'Insert into event', 'blockendar' ),
			'uploaded_to_this_item' => __( 'Uploaded to this event', 'blockendar' ),
			'menu_name'             => _x( 'Events', 'admin menu', 'blockendar' ),
			'name_admin_bar'        => _x( 'Event', 'add new on admin bar', 'blockendar' ),
		];

		$args = [
			'labels'             => $labels,
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'query_var'          => true,
			'rewrite'            => [ 'slug' => 'events' ],
			'capability_type'    => 'post',
			'has_archive'        => 'events',
			'hierarchical'       => false,
			'menu_position'      => 20,
			'menu_icon'          => 'dashicons-calendar-alt',
			'supports'           => [ 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields', 'revisions' ],
			'show_in_rest'       => true,
			'rest_base'          => 'blockendar-events',
		];

		register_post_type( self::POST_TYPE, $args );
	}
}
