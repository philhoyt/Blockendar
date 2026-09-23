<?php
/**
 * WP-CLI command: import events from a The Events Calendar WXR export.
 *
 * Usage:
 *   wp blockendar import-tribe /path/to/export.xml
 *   wp blockendar import-tribe /path/to/export.xml --dry-run
 *
 * This replaced an admin upload screen and a REST route. A one-off migration
 * does not need a permanent HTTP endpoint, and the route carried real cost:
 * it parsed an arbitrary uploaded XML document into a DOM with no cap on how
 * many events it would then import in a single request, so a real export of a
 * few thousand events exhausted memory or hit max_execution_time and left a
 * partial import with no way to resume.
 *
 * On the command line none of that applies. There is no upload, no request
 * timeout, memory is whatever the operator allows, and the file is one they
 * already have shell access to. It also matches the decision recorded in
 * plans/ticketweb-sync.md, where the TicketWeb migration path is a WP-CLI
 * table import with no upload UI.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Blockendar\Import\TribeImporter;

/**
 * Imports events from The Events Calendar.
 */
class ImportTribeCommand {

	/**
	 * Imports events from a WordPress WXR export produced by The Events Calendar.
	 *
	 * On the source site go to Tools > Export and export only "Events", then
	 * run this against the resulting file.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the WXR (.xml) export file.
	 *
	 * [--dry-run]
	 * : Report what would be imported without writing anything.
	 *
	 * ## EXAMPLES
	 *
	 *   wp blockendar import-tribe ./tribe-events.xml --dry-run
	 *   wp blockendar import-tribe ./tribe-events.xml
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments: the file path.
	 * @param array $assoc_args Named arguments.
	 */
	public function import( array $args, array $assoc_args ): void {
		$path    = (string) ( $args[0] ?? '' );
		$dry_run = (bool) ( $assoc_args['dry-run'] ?? false );

		if ( '' === $path || ! is_readable( $path ) ) {
			\WP_CLI::error( sprintf( 'Cannot read file: %s', $path ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file named by the operator.
		$xml = file_get_contents( $path );

		if ( false === $xml || '' === $xml ) {
			\WP_CLI::error( 'File is empty.' );
		}

		\WP_CLI::log( $dry_run ? 'Parsing (dry run)…' : 'Importing…' );

		$results = ( new TribeImporter() )->import( $xml, $dry_run );

		foreach ( $results['errors'] as $error ) {
			\WP_CLI::warning( $error );
		}

		$imported = (int) $results['imported'];
		$skipped  = (int) $results['skipped'];

		// An export whose every item failed is a failure, not a quiet success.
		if ( 0 === $imported && ! empty( $results['errors'] ) ) {
			\WP_CLI::error( 'Nothing was imported.' );
		}

		\WP_CLI::success(
			sprintf(
				$dry_run
					? 'Dry run complete. Would import: %1$d, skipped: %2$d.'
					: 'Done. Imported: %1$d, skipped: %2$d.',
				$imported,
				$skipped
			)
		);
	}
}
