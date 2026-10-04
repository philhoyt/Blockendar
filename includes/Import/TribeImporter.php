<?php
/**
 * Importer for The Events Calendar (tribe_events) WXR exports.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Blockendar\Admin\SettingsPage;
use Blockendar\CPT\EventPostType;
use Blockendar\Taxonomy\EventType;
use Blockendar\DB\IndexBuilder;

/**
 * Parses a WordPress WXR file exported from The Events Calendar and creates
 * Blockendar events from tribe_events items.
 */
class TribeImporter {

	/**
	 * Post statuses carried over as they are.
	 *
	 * Core's export writes every status except auto-draft, so a file holds
	 * pending, scheduled and trashed events alongside the published ones.
	 */
	private const KEPT_STATUSES = [ 'publish', 'draft', 'private', 'pending', 'future' ];

	/**
	 * Import events from raw WXR XML.
	 *
	 * @param string $xml     Raw XML content.
	 * @param bool   $dry_run If true, parse and validate without writing anything.
	 * @return array{ imported: int, skipped: int, errors: string[], events: list<array> }
	 */
	public function import( string $xml, bool $dry_run = false ): array {
		$results = [
			'imported' => 0,
			'skipped'  => 0,
			'errors'   => [],
			'events'   => [],
		];

		libxml_use_internal_errors( true );
		$dom = new \DOMDocument();
		$ok  = $dom->loadXML( $xml, LIBXML_NOCDATA );

		if ( ! $ok ) {
			$errors              = libxml_get_errors();
			$results['errors'][] = ! empty( $errors )
				? $errors[0]->message
				: __( 'Failed to parse XML.', 'blockendar' );
			return $results;
		}

		$xpath = new \DOMXPath( $dom );
		$xpath->registerNamespace( 'wp', 'http://wordpress.org/export/1.2/' );
		$xpath->registerNamespace( 'content', 'http://purl.org/rss/1.0/modules/content/' );
		$xpath->registerNamespace( 'dc', 'http://purl.org/dc/elements/1.1/' );

		$items = $xpath->query( '//item[wp:post_type[normalize-space()="tribe_events"]]' );

		if ( ! $items || 0 === $items->length ) {
			$results['errors'][] = __( 'No tribe_events items found in the XML file.', 'blockendar' );
			return $results;
		}

		$builder = new IndexBuilder();

		foreach ( $items as $item ) {
			$result = $this->import_item( $xpath, $item, $builder, $dry_run );

			$results['events'][] = $result;

			if ( 'imported' === $result['status'] ) {
				++$results['imported'];
			} elseif ( 'skipped' === $result['status'] ) {
				++$results['skipped'];
			} else {
				$results['errors'][] = $result['message'];
			}
		}

		return $results;
	}

	/**
	 * Import a single WXR item.
	 *
	 * @param \DOMXPath    $xpath   XPath evaluator.
	 * @param \DOMElement  $item    The <item> element.
	 * @param IndexBuilder $builder Index builder for post-insert indexing.
	 * @param bool         $dry_run Skip writes when true.
	 * @return array{ title: string, status: string, message: string, notes: string[] }
	 */
	private function import_item(
		\DOMXPath $xpath,
		\DOMElement $item,
		IndexBuilder $builder,
		bool $dry_run
	): array {
		$title    = $this->node_text( $xpath, 'title', $item );
		$slug     = $this->node_text( $xpath, 'wp:post_name', $item );
		$status   = $this->node_text( $xpath, 'wp:status', $item );
		$content  = $this->node_text( $xpath, 'content:encoded', $item );
		$pub_gmt  = $this->node_text( $xpath, 'wp:post_date_gmt', $item );
		$password = $this->node_text( $xpath, 'wp:post_password', $item );

		// Everything the importer changes or drops, for the operator to read.
		$notes = [];

		// Checked before anything else, so a trashed item can never reach the
		// update branch and overwrite the event an earlier run created.
		if ( 'trash' === $status ) {
			return [
				'title'   => $title,
				'status'  => 'skipped',
				'message' => __( 'In the trash at the source.', 'blockendar' ),
				'notes'   => [],
			];
		}

		// Never fall back to 'publish': an unrecognised status is far more
		// likely to mean "not public" than "public".
		$post_status = $status;

		if ( ! in_array( $status, self::KEPT_STATUSES, true ) ) {
			$post_status = 'draft';
			$notes[]     = sprintf(
				/* translators: %s: a post status from the import file. */
				__( 'Status "%s" is not one Blockendar keeps; imported as a draft.', 'blockendar' ),
				$status
			);
		}

		// Build meta map.
		$meta  = $this->extract_meta( $xpath, $item );
		$first = static fn( string $key ): string => $meta[ $key ][0] ?? '';

		$start_raw = $first( '_EventStartDate' );
		$end_raw   = $first( '_EventEndDate' );
		// TEC v5+ stores 'yes'; older versions stored '1'.
		$all_day_raw = strtolower( trim( $first( '_EventAllDay' ) ) );
		$all_day     = in_array( $all_day_raw, [ '1', 'yes', 'true' ], true );
		$url         = $first( '_EventURL' );

		$timezone_raw = $first( '_EventTimezone' );
		$timezone     = blockendar_normalize_timezone( $timezone_raw );

		if ( $timezone !== $timezone_raw ) {
			$notes[] = sprintf(
				/* translators: 1: the timezone in the import file, 2: the timezone stored. */
				__( 'Timezone "%1$s" stored as "%2$s".', 'blockendar' ),
				$timezone_raw,
				$timezone
			);
		}

		$cost_rows = array_values(
			array_unique(
				array_filter(
					$meta['_EventCost'] ?? [],
					static fn( string $value ): bool => '' !== $value
				)
			)
		);
		$cost_raw  = $cost_rows[0] ?? '';
		$cost      = $this->cost_with_currency( $cost_raw, $first( '_EventCurrencySymbol' ), $first( '_EventCurrencyPosition' ) );

		if ( count( $cost_rows ) > 1 ) {
			$notes[] = sprintf(
				/* translators: 1: how many cost values the event has, 2: the one kept. */
				__( '%1$d cost values found; kept the first, "%2$s".', 'blockendar' ),
				count( $cost_rows ),
				$cost_raw
			);
		}

		if ( $cost !== $cost_raw ) {
			$notes[] = sprintf(
				/* translators: 1: the cost in the import file, 2: the cost stored. */
				__( 'Cost "%1$s" stored as "%2$s" to keep its currency.', 'blockendar' ),
				$cost_raw,
				$cost
			);
		}

		if ( ! $start_raw ) {
			return [
				'title'   => $title,
				'status'  => 'error',
				'message' => sprintf(
					/* translators: %s: the event title from the import file. */
					__( 'Missing start date: %s', 'blockendar' ),
					$title
				),
				'notes'   => [],
			];
		}

		// Parse via DateTime so the format (space or T separator) doesn't matter.
		$start_dt = date_create( $start_raw );
		$end_dt   = $end_raw ? date_create( $end_raw ) : null;

		if ( ! $start_dt ) {
			return [
				'title'   => $title,
				'status'  => 'error',
				'message' => sprintf(
					/* translators: 1: the unparseable date string, 2: the event title. */
					__( 'Could not parse start date "%1$s": %2$s', 'blockendar' ),
					$start_raw,
					$title
				),
				'notes'   => [],
			];
		}

		$start_date = $start_dt->format( 'Y-m-d' );
		$end_date   = $end_dt ? $end_dt->format( 'Y-m-d' ) : $start_date;

		// All-day events store no time. Timed events keep exactly what TEC
		// stored: 23:59 on a timed event is a real end time, and blanking it
		// leaves the index to fall back to the start.
		if ( $all_day ) {
			$start_time = '';
			$end_time   = '';

			$stored_end_date = $end_date;
			$end_date        = $this->all_day_end_date( $start_dt, $end_dt );

			if ( $end_date !== $stored_end_date ) {
				$notes[] = sprintf(
					/* translators: 1: the end datetime in the import file, 2: the end date stored. */
					__( 'All-day end "%1$s" read as %2$s.', 'blockendar' ),
					$end_raw,
					$end_date
				);
			}
		} else {
			$start_time = $start_dt->format( 'H:i' );
			$end_time   = $end_dt ? $end_dt->format( 'H:i' ) : '';
		}

		// Check for existing post by slug — update it rather than skip.
		$existing_id = null;
		if ( $slug ) {
			$existing = get_page_by_path( $slug, OBJECT, EventPostType::POST_TYPE );
			if ( $existing ) {
				$existing_id = (int) $existing->ID;
			}
		}

		if ( $dry_run ) {
			return [
				'title'   => $title,
				'status'  => 'imported',
				'message' => $existing_id
					? __( '(dry run — would update)', 'blockendar' )
					: __( '(dry run)', 'blockendar' ),
				'notes'   => $notes,
			];
		}

		// Shared by both branches, so a status or password changed at the
		// source arrives on a re-import, including a password that was removed.
		$postarr = [
			'post_title'    => wp_strip_all_tags( $title ),
			'post_content'  => $content,
			'post_status'   => $post_status,
			'post_password' => $password,
		];

		if ( $existing_id ) {
			$post_id = wp_update_post( array_merge( $postarr, [ 'ID' => $existing_id ] ), true );
		} else {
			$post_id = wp_insert_post(
				array_merge(
					$postarr,
					[
						'post_name'     => $slug,
						'post_type'     => EventPostType::POST_TYPE,
						'post_date_gmt' => $pub_gmt ?: current_time( 'mysql', true ),
					]
				),
				true
			);
		}

		if ( is_wp_error( $post_id ) ) {
			return [
				'title'   => $title,
				'status'  => 'error',
				'message' => $post_id->get_error_message(),
				'notes'   => [],
			];
		}

		// Core blanks the slug of a pending post unless the current user can
		// publish, and an import run from WP-CLI has no user. The slug is how a
		// second run finds this event again, so put the source's back.
		if ( 'pending' === $post_status && '' !== $slug && get_post_field( 'post_name', $post_id ) !== $slug ) {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- wp_update_post() would blank it again; the cache is cleared below.
			$wpdb->update( $wpdb->posts, [ 'post_name' => $slug ], [ 'ID' => $post_id ] );
			clean_post_cache( $post_id );
		}

		// Set event meta.
		update_post_meta( $post_id, 'blockendar_start_date', $start_date );
		update_post_meta( $post_id, 'blockendar_end_date', $end_date );
		update_post_meta( $post_id, 'blockendar_start_time', $start_time );
		update_post_meta( $post_id, 'blockendar_end_time', $end_time );
		update_post_meta( $post_id, 'blockendar_all_day', $all_day ? '1' : '' );

		if ( $timezone ) {
			update_post_meta( $post_id, 'blockendar_timezone', $timezone );
		}
		// Not a truthiness test: TEC stores a free event's cost as "0".
		if ( '' !== $cost ) {
			update_post_meta( $post_id, 'blockendar_cost', sanitize_text_field( $cost ) );
		}
		if ( $url ) {
			update_post_meta( $post_id, 'blockendar_registration_url', esc_url_raw( $url ) );
		}

		// Map tribe_events_cat → event_type.
		$this->assign_categories( $xpath, $item, $post_id );

		// Build index with complete meta now set.
		$builder->build_for_post( $post_id );

		return [
			'title'   => $title,
			'status'  => 'imported',
			'message' => '',
			'notes'   => $notes,
		];
	}

	/**
	 * Work out the last day of an all-day event.
	 *
	 * TEC's days run from its "end of day cutoff", not from midnight. An
	 * all-day event starts at the cutoff on its first day
	 * (tribe_beginning_of_day()) and ends one second before the cutoff on the
	 * day after its last (tribe_end_of_day()). With no cutoff that end is
	 * 23:59:59 on the last day; with a 06:00 cutoff it is 05:59:59 on the day
	 * after, and taking the date part as it stands makes the event a day too
	 * long. The start's time of day is the cutoff, so shifting the end back by
	 * it puts the end inside the calendar day it belongs to.
	 *
	 * @param \DateTimeInterface      $start Parsed _EventStartDate.
	 * @param \DateTimeInterface|null $end   Parsed _EventEndDate, if there is one.
	 * @return string Y-m-d.
	 */
	private function all_day_end_date( \DateTimeInterface $start, ?\DateTimeInterface $end ): string {
		$start_date = $start->format( 'Y-m-d' );

		if ( ! $end ) {
			return $start_date;
		}

		$cutoff = ( (int) $start->format( 'G' ) * HOUR_IN_SECONDS )
			+ ( (int) $start->format( 'i' ) * MINUTE_IN_SECONDS )
			+ (int) $start->format( 's' );

		$end_date = \DateTimeImmutable::createFromInterface( $end )
			->modify( "-{$cutoff} seconds" )
			->format( 'Y-m-d' );

		// Never before the start, whatever the file holds.
		return max( $end_date, $start_date );
	}

	/**
	 * Give a numeric cost its own currency symbol when it is not the site's.
	 *
	 * The cost block puts the site's currency symbol on a bare number. TEC
	 * stores the number and the symbol separately, so an event priced in euros
	 * would arrive as a bare 10 and be shown in dollars. A cost of 0 is left
	 * bare: it means free in any currency.
	 *
	 * @param string $cost     _EventCost.
	 * @param string $symbol   _EventCurrencySymbol.
	 * @param string $position _EventCurrencyPosition: 'prefix', 'suffix' or 'postfix'.
	 */
	private function cost_with_currency( string $cost, string $symbol, string $position ): string {
		if ( '' === $symbol || ! is_numeric( $cost ) || 0.0 === (float) $cost ) {
			return $cost;
		}

		$site_symbol = blockendar_currency_symbol( (string) SettingsPage::get( 'default_currency' ) );

		if ( $symbol === $site_symbol ) {
			return $cost;
		}

		return in_array( $position, [ 'suffix', 'postfix' ], true ) ? $cost . $symbol : $symbol . $cost;
	}

	/**
	 * Build a key→values map of all wp:postmeta for an item.
	 *
	 * Values are kept in file order, and a key can repeat: TEC writes more than
	 * one _EventCost row for an event with several prices. The first value is
	 * the one get_post_meta( …, true ) returned at the source.
	 *
	 * @param \DOMXPath   $xpath XPath evaluator.
	 * @param \DOMElement $item  The <item> element.
	 * @return array<string, list<string>>
	 */
	private function extract_meta( \DOMXPath $xpath, \DOMElement $item ): array {
		$map   = [];
		$nodes = $xpath->query( 'wp:postmeta', $item );

		if ( ! $nodes ) {
			return $map;
		}

		foreach ( $nodes as $node ) {
			$key   = $this->node_text( $xpath, 'wp:meta_key', $node );
			$value = $this->node_text( $xpath, 'wp:meta_value', $node );
			if ( $key ) {
				$map[ $key ][] = $value;
			}
		}

		return $map;
	}

	/**
	 * Read tribe_events_cat category elements and assign to event_type taxonomy.
	 * Terms are created if they don't already exist.
	 *
	 * @param \DOMXPath   $xpath   XPath evaluator.
	 * @param \DOMElement $item    The <item> element.
	 * @param int         $post_id Target post ID.
	 */
	private function assign_categories( \DOMXPath $xpath, \DOMElement $item, int $post_id ): void {
		$nodes = $xpath->query( 'category[@domain="tribe_events_cat"]', $item );

		if ( ! $nodes || 0 === $nodes->length ) {
			return;
		}

		$term_ids = [];

		foreach ( $nodes as $node ) {
			$slug = $node->getAttribute( 'nicename' );
			$name = trim( $node->nodeValue ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

			if ( ! $slug || ! $name ) {
				continue;
			}

			$term = get_term_by( 'slug', $slug, EventType::TAXONOMY );

			if ( $term ) {
				$term_ids[] = (int) $term->term_id;
			} else {
				$inserted = wp_insert_term( $name, EventType::TAXONOMY, [ 'slug' => $slug ] );
				if ( ! is_wp_error( $inserted ) ) {
					$term_ids[] = (int) $inserted['term_id'];
				}
			}
		}

		if ( $term_ids ) {
			wp_set_object_terms( $post_id, $term_ids, EventType::TAXONOMY );
		}
	}

	/**
	 * Get the trimmed text content of the first matching XPath node.
	 *
	 * @param \DOMXPath $xpath   XPath evaluator.
	 * @param string    $query   XPath expression.
	 * @param \DOMNode  $context Context node.
	 * @return string
	 */
	private function node_text( \DOMXPath $xpath, string $query, \DOMNode $context ): string {
		$nodes = $xpath->query( $query, $context );
		return ( $nodes && $nodes->length > 0 ) ? trim( $nodes->item( 0 )->nodeValue ) : '';
	}
}
