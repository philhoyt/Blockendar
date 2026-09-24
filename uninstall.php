<?php
/**
 * Plugin uninstall routine.
 *
 * Runs when the plugin is deleted (not deactivated) from the WordPress admin.
 * Removes custom tables, options, transients and scheduled events — does NOT
 * remove CPT posts or taxonomy terms (user data is preserved on uninstall by
 * convention), and therefore leaves their meta in place too.
 *
 * @package Blockendar
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Autoloader needed to reference Schema.
spl_autoload_register(
	// phpcs:disable Universal.NamingConventions.NoReservedKeywordParameterNames
	function ( string $class ): void {
		// phpcs:enable Universal.NamingConventions.NoReservedKeywordParameterNames
		$prefix   = 'Blockendar\\';
		$base_dir = __DIR__ . '/includes/';

		if ( ! str_starts_with( $class, $prefix ) ) {
				return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

/**
 * Remove everything this plugin created on the current site.
 *
 * Both the tables and the options are per-site: Schema uses $wpdb->prefix,
 * which is the current blog's prefix, and delete_option() writes to the
 * current blog's options table. On a network install Schema::maybe_upgrade()
 * creates the tables lazily on every site that loads the plugin, so this has
 * to run once per site or those tables and rows are orphaned.
 */
function blockendar_uninstall_site(): void {
	Blockendar\Recurrence\Cron::unschedule();
	Blockendar\DB\Schema::drop_tables();

	$options = [
		'blockendar_db_version',
		'blockendar_version',
		'blockendar_last_index_rebuild',
		'blockendar_settings',

		/*
		 * Never written by the plugin — Generator used to read it by mistake
		 * while the real value lived in blockendar_settings. Removed anyway in
		 * case a site set it by hand to work around that bug.
		 */
		'blockendar_horizon_days',

		// State of the 2.0.0 taxonomy rename.
		Blockendar\Migration\TaxonomyPrefixMigration::GATE_OPTION,
		Blockendar\Migration\TaxonomyPrefixMigration::LOCK_OPTION,
		Blockendar\Migration\TaxonomyPrefixMigration::CURSOR_OPTION,
		Blockendar\Migration\TaxonomyPrefixMigration::LOG_OPTION,
	];

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Set by CalendarController when an ICS feed is truncated; a week-long
	// transient outlives the plugin otherwise.
	delete_transient( 'blockendar_ics_truncated' );
	delete_transient( Blockendar\Migration\TaxonomyPrefixMigration::BLOCKED_TRANSIENT );

	// Per-post originals the taxonomy migration kept for rollback.
	delete_post_meta_by_key( Blockendar\Migration\TaxonomyPrefixMigration::BACKUP_META );
}

if ( is_multisite() ) {
	$blockendar_site_ids = get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	);

	foreach ( $blockendar_site_ids as $blockendar_site_id ) {
		switch_to_blog( (int) $blockendar_site_id );
		blockendar_uninstall_site();
		restore_current_blog();
	}
} else {
	blockendar_uninstall_site();
}
