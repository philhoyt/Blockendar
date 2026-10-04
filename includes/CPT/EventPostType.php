<?php
/**
 * Custom post type registration for blockendar_event.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\CPT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Blockendar\Admin\SettingsPage;

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

		/*
		 * An event's dates, recurrence and details exist only as block-editor
		 * panels, so an event opened in the classic editor cannot be given a
		 * date. The Classic Editor plugin turns the block editor off at priority
		 * 100 on one of these two filters, depending on its mode; these run
		 * after it. The third is the plugin's own way of asking.
		 */
		add_filter( 'use_block_editor_for_post_type', [ $this, 'require_block_editor_for_post_type' ], 1000, 2 );
		add_filter( 'use_block_editor_for_post', [ $this, 'require_block_editor_for_post' ], 1000, 2 );
		add_filter( 'classic_editor_enabled_editors_for_post_type', [ $this, 'classic_editor_editors' ], 10, 2 );
	}

	/**
	 * Keep the block editor on for the event post type.
	 *
	 * @param bool   $use_block_editor Whether the post type uses the block editor.
	 * @param string $post_type        Post type being checked.
	 */
	public function require_block_editor_for_post_type( $use_block_editor, $post_type ): bool {
		return self::POST_TYPE === $post_type ? true : (bool) $use_block_editor;
	}

	/**
	 * Keep the block editor on for an individual event.
	 *
	 * @param bool          $use_block_editor Whether the post uses the block editor.
	 * @param \WP_Post|null $post             Post being edited.
	 */
	public function require_block_editor_for_post( $use_block_editor, $post ): bool {
		return $post instanceof \WP_Post && self::POST_TYPE === $post->post_type ? true : (bool) $use_block_editor;
	}

	/**
	 * Tell the Classic Editor plugin events have no classic editor.
	 *
	 * It uses the answer for its "Edit (Classic)" row links and its editor
	 * switcher, neither of which should be offered.
	 *
	 * @param array  $editors   Editors enabled for the post type.
	 * @param string $post_type Post type being checked.
	 * @return array
	 */
	public function classic_editor_editors( $editors, $post_type ) {
		if ( self::POST_TYPE !== $post_type ) {
			return $editors;
		}

		return [
			'classic_editor' => false,
			'block_editor'   => true,
		];
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

		$slug = SettingsPage::events_slug();

		$args = [
			'labels'             => $labels,
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'query_var'          => true,
			'rewrite'            => [ 'slug' => $slug ],
			'capability_type'    => 'post',
			'has_archive'        => $slug,
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
