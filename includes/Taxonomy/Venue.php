<?php
/**
 * Venue taxonomy registration.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\Taxonomy;

use Blockendar\CPT\EventPostType;

/**
 * Hierarchical taxonomy for venues. Address and coordinate data is stored as term meta.
 * Modelled as a taxonomy (not CPT) for v1 — gives free archive URLs and REST filtering
 * without a relationship CPT overhead.
 */
class Venue {

	const TAXONOMY = 'event_venue';

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
			'name'              => _x( 'Venues', 'taxonomy general name', 'blockendar' ),
			'singular_name'     => _x( 'Venue', 'taxonomy singular name', 'blockendar' ),
			'search_items'      => __( 'Search Venues', 'blockendar' ),
			'all_items'         => __( 'All Venues', 'blockendar' ),
			'parent_item'       => __( 'Parent Venue', 'blockendar' ),
			'parent_item_colon' => __( 'Parent Venue:', 'blockendar' ),
			'edit_item'         => __( 'Edit Venue', 'blockendar' ),
			'update_item'       => __( 'Update Venue', 'blockendar' ),
			'add_new_item'      => __( 'Add New Venue', 'blockendar' ),
			'new_item_name'     => __( 'New Venue Name', 'blockendar' ),
			'menu_name'         => __( 'Venues', 'blockendar' ),
		];

		$args = [
			'labels'              => $labels,
			'hierarchical'        => true,
			'public'              => true,
			'publicly_queryable'  => true,
			'show_ui'             => true,
			'show_admin_column'   => true,
			'show_in_rest'        => true,
			'rest_base'           => 'event-venues',
			'rewrite'             => [
				'slug'       => 'events/venue',
				'with_front' => false,
			],
		];

		register_taxonomy( self::TAXONOMY, EventPostType::POST_TYPE, $args );
	}

	/**
	 * Explicitly register top-priority rewrite rules for the events/venue/* path.
	 */
	public function add_rewrite_rules(): void {
		add_rewrite_rule(
			'^events/venue/([^/]+)/page/([0-9]{1,})/?$',
			'index.php?event_venue=$matches[1]&paged=$matches[2]',
			'top'
		);
		add_rewrite_rule(
			'^events/venue/([^/]+)/?$',
			'index.php?event_venue=$matches[1]',
			'top'
		);
	}
}
