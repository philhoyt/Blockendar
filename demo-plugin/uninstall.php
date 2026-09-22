<?php
/**
 * Uninstall the Blockendar demo content.
 *
 * Deliberately standalone. By the time this runs the main Blockendar plugin may
 * be deactivated or deleted, so nothing here may reference a Blockendar\ class
 * or assume its tables exist.
 *
 * @package BlockendarDemo
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$blockendar_demo_state = get_option( 'blockendar_demo_seeded' );

if ( is_array( $blockendar_demo_state ) ) {

	$blockendar_demo_event_ids = array_map( 'intval', (array) ( $blockendar_demo_state['events'] ?? [] ) );

	// Index and recurrence cleanup, only for tables that are actually present.
	if ( $blockendar_demo_event_ids ) {
		$blockendar_demo_events_tbl = $wpdb->prefix . 'blockendar_events';
		$blockendar_demo_junction   = $wpdb->prefix . 'blockendar_event_type_terms';
		$blockendar_demo_recur_tbl  = $wpdb->prefix . 'blockendar_recurrence';

		$blockendar_demo_ids_sql = implode( ',', $blockendar_demo_event_ids );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$blockendar_demo_has_events = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $blockendar_demo_events_tbl )
		);

		if ( $blockendar_demo_has_events ) {
			$blockendar_demo_has_junction = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $blockendar_demo_junction )
			);

			// Junction rows first — they reference the index rows by id.
			// $blockendar_demo_ids_sql is built from array_map( 'intval', … ) above.
			if ( $blockendar_demo_has_junction ) {
				$wpdb->query(
					"DELETE FROM {$blockendar_demo_junction}
					 WHERE event_index_id IN (
						SELECT id FROM {$blockendar_demo_events_tbl}
						WHERE post_id IN ({$blockendar_demo_ids_sql})
					 )"
				);
			}

			$wpdb->query(
				"DELETE FROM {$blockendar_demo_events_tbl} WHERE post_id IN ({$blockendar_demo_ids_sql})"
			);
		}

		$blockendar_demo_has_recur = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $blockendar_demo_recur_tbl )
		);

		if ( $blockendar_demo_has_recur ) {
			$wpdb->query(
				"DELETE FROM {$blockendar_demo_recur_tbl} WHERE post_id IN ({$blockendar_demo_ids_sql})"
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	foreach ( $blockendar_demo_event_ids as $blockendar_demo_id ) {
		wp_delete_post( $blockendar_demo_id, true );
	}

	foreach ( (array) ( $blockendar_demo_state['attachments'] ?? [] ) as $blockendar_demo_id ) {
		wp_delete_attachment( (int) $blockendar_demo_id, true );
	}

	foreach ( (array) ( $blockendar_demo_state['pages'] ?? [] ) as $blockendar_demo_id ) {
		wp_delete_post( (int) $blockendar_demo_id, true );
	}

	foreach ( (array) ( $blockendar_demo_state['terms'] ?? [] ) as $blockendar_demo_term ) {
		$blockendar_demo_term_id  = (int) ( $blockendar_demo_term['id'] ?? 0 );
		$blockendar_demo_taxonomy = (string) ( $blockendar_demo_term['taxonomy'] ?? '' );

		if ( $blockendar_demo_term_id && $blockendar_demo_taxonomy ) {
			wp_delete_term( $blockendar_demo_term_id, $blockendar_demo_taxonomy );
		}
	}

	// Restore the front page exactly as it was before seeding.
	$blockendar_demo_front = (array) ( $blockendar_demo_state['front'] ?? [] );

	if ( isset( $blockendar_demo_front['show_on_front'] ) ) {
		update_option( 'show_on_front', $blockendar_demo_front['show_on_front'] );
	}

	if ( isset( $blockendar_demo_front['page_on_front'] ) ) {
		update_option( 'page_on_front', (int) $blockendar_demo_front['page_on_front'] );
	}

	// Remove only the settings keys the demo added.
	$blockendar_demo_keys = (array) ( $blockendar_demo_state['settings_keys'] ?? [] );

	if ( $blockendar_demo_keys ) {
		$blockendar_demo_settings = get_option( 'blockendar_settings' );

		if ( is_array( $blockendar_demo_settings ) ) {
			foreach ( $blockendar_demo_keys as $blockendar_demo_key ) {
				unset( $blockendar_demo_settings[ (string) $blockendar_demo_key ] );
			}

			update_option( 'blockendar_settings', $blockendar_demo_settings );
		}
	}
}

delete_option( 'blockendar_demo_seeded' );
delete_option( 'blockendar_demo_pending_seed' );
delete_transient( 'blockendar_demo_dependency_notice' );
delete_transient( 'blockendar_demo_result' );
